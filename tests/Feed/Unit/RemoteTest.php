<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Producer;
use Utopia\Feed\Protocol;
use Utopia\Feed\Remote;
use Utopia\Feed\Server;
use Utopia\Tests\Unit\Support\FakeTransport;
use Utopia\Tests\Unit\Support\FeedServer;
use Utopia\Tests\Unit\Support\MidPollStore;

class RemoteTest extends TestCase
{
    /**
     * @param list<ResponseInterface|\Throwable> $responses
     * @return array{Remote, FakeTransport}
     */
    private function remote(array $responses = []): array
    {
        $transport = FakeTransport::of($responses);

        return [new Remote($transport, 'edge'), $transport];
    }

    public function testReadsAFeedOverHttp(): void
    {
        [$remote] = $this->remote([FakeTransport::json(Protocol::encode([
            new CloudEvent(id: '1-0', type: 'io.appwrite.edge.invalidate-rule', source: 'urn:test', data: ['tags' => ['domain' => 'example.com']]),
            new CloudEvent(id: '1-1', type: 'io.appwrite.edge.invalidate', source: 'urn:test'),
        ]))]);

        $events = $remote->read();

        $this->assertCount(2, $events);
        $this->assertSame('1-0', $events[0]->id);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    /**
     * The endpoint lives on the client — the feed asks for its name as a
     * relative path and the client resolves it against its base URI.
     */
    public function testResolvesTheFeedNameAgainstTheClientsBaseUri(): void
    {
        $transport = FakeTransport::of([]);
        $client = (new Client($transport))->withBaseUri('https://cloud.example.com/v1/feeds');

        (new Remote($client, 'edge'))->read();

        $this->assertStringStartsWith('https://cloud.example.com/v1/feeds/edge', $transport->recorder->last()['uri']);
    }

    public function testEncodesAFeedNameThatNeedsIt(): void
    {
        $transport = FakeTransport::of([]);
        $client = (new Client($transport))->withBaseUri('https://cloud.example.com/v1/feeds/');

        (new Remote($client, 'a b/c'))->read();

        $this->assertStringStartsWith(
            'https://cloud.example.com/v1/feeds/a%20b%2Fc',
            $transport->recorder->last()['uri'],
        );
    }

    public function testRejectsAnEmptyFeedName(): void
    {
        $this->expectException(Invalid::class);

        new Remote(FakeTransport::of([]), '');
    }

    public function testExposesTheFeedItReads(): void
    {
        [$remote] = $this->remote();

        $this->assertSame('edge', $remote->getName());
    }

    /**
     * The tip is the producer's to resolve — a consumer starting at the tip
     * sends the sentinel instead of asking for the newest id first.
     */
    public function testARemoteFeedHasNoLocalTip(): void
    {
        [$remote] = $this->remote();

        $this->expectException(Unsupported::class);

        $remote->tip();
    }

    public function testReadsWithGet(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read();

        $this->assertSame('GET', $transport->recorder->last()['method']);
    }

    public function testAsksForTheFeedMediaType(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read();

        $this->assertSame(Protocol::MEDIA_TYPE, $transport->recorder->last()['headers']['Accept'] ?? null);
        $this->assertSame('application/cloudevents-batch+json', Protocol::MEDIA_TYPE);
    }

    public function testSendsThePositionAndLimit(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read('1-0', 250);

        $uri = $transport->recorder->last()['uri'];

        $this->assertStringContainsString('lastEventId=1-0', $uri);
        $this->assertStringContainsString('limit=250', $uri);
    }

    public function testSendsNoPositionOnAFirstFullRead(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read(null, Protocol::MAX_BATCH);

        $this->assertStringNotContainsString('lastEventId', $transport->recorder->last()['uri']);
    }

    /**
     * The producer does the waiting, so a poll is one request rather than a
     * client-side loop.
     */
    public function testDelegatesLongPollingToTheProducer(): void
    {
        [$remote, $transport] = $this->remote();

        $started = \microtime(true);
        $remote->poll(null, 100, 5000);

        $this->assertLessThan(1, \microtime(true) - $started, 'Must not wait client-side');
        $this->assertCount(1, $transport->recorder->requests, 'Must not poll in a loop');
        $this->assertStringContainsString('timeout=5000', $transport->recorder->last()['uri']);
    }

    /**
     * Without the margin the client's deadline races the producer's, and a
     * poll that correctly waits out its timeout surfaces as a failure on every
     * quiet tick.
     */
    public function testAllowsTheClientLongerThanTheLongPollTimeout(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->poll(null, 100, 5000);

        // Seconds, which is what the client takes; the protocol margin is in
        // milliseconds, like the timeout the producer is given.
        $this->assertSame(15.0, $transport->recorder->last()['timeout']);
        $this->assertSame((float) ((5000 + Protocol::TIMEOUT_MARGIN) / 1000), $transport->recorder->last()['timeout']);
    }

    public function testLeavesTheConfiguredTimeoutAloneWhenNotLongPolling(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read();

        $this->assertNull($transport->recorder->last()['timeout'], 'A plain read must not override the client');
    }

    /**
     * The status is carried on the exception so a consumer can tell a producer
     * that does not serve the feed yet — normal during a staged rollout — from
     * one that is broken.
     */
    public function testCarriesTheStatusOfARejectedRead(): void
    {
        [$remote] = $this->remote([FakeTransport::json([], 404)]);

        try {
            $remote->read();
            $this->fail('A 404 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(404, $error->getCode());
        }
    }

    public function testRaisesServerErrors(): void
    {
        [$remote] = $this->remote([FakeTransport::json([], 503)]);

        try {
            $remote->read();
            $this->fail('A 503 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(503, $error->getCode());
        }
    }

    /**
     * PSR-18 returns 4xx and 5xx rather than throwing, so the transport has to
     * check the status itself — a producer error must not read as an empty
     * batch, which the consumer would take for "caught up".
     */
    public function testAnErrorStatusIsNotMistakenForAnEmptyBatch(): void
    {
        [$remote] = $this->remote([FakeTransport::json([], 500)]);

        $this->expectException(Transport::class);

        $remote->read();
    }

    public function testWrapsATransportFailure(): void
    {
        [$remote] = $this->remote([FakeTransport::offline()]);

        $this->expectException(Transport::class);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $remote->read();
    }

    public function testWrapsABodyThatIsNotJson(): void
    {
        [$remote] = $this->remote([FakeTransport::raw('<html>502 Bad Gateway</html>')]);

        $this->expectException(Transport::class);

        $remote->read();
    }

    public function testRejectsABodyThatIsNotABatch(): void
    {
        [$remote] = $this->remote([FakeTransport::raw('"a string"')]);

        $this->expectException(Invalid::class);

        $remote->read();
    }

    /**
     * Anything implementing the client's adapter interface works, including
     * the client itself wrapping a transport — which is how this is actually
     * built in a service.
     */
    public function testWorksThroughTheClientItself(): void
    {
        $transport = FakeTransport::of([FakeTransport::json(Protocol::encode([new CloudEvent(id: '1-0', type: 'a', source: 'urn:test')]))]);

        $client = (new Client($transport))
            ->withBaseUri('https://cloud.example.com/v1/feeds')
            ->withHeaders(['x-appwrite-jwt' => 'token']);
        $remote = new Remote($client, 'edge');

        $events = $remote->read();

        $this->assertCount(1, $events);
        $this->assertSame('token', $transport->recorder->last()['headers']['x-appwrite-jwt'] ?? null);
    }

    /**
     * The point of this class: a remote feed is consumed with exactly the
     * code a local one is. The consumer is built straight over the client —
     * it wraps the feed name and the client into a Remote itself.
     */
    public function testConsumesARemoteFeedThroughTheSameConsumer(): void
    {
        $transport = FakeTransport::of([
            FakeTransport::json(Protocol::encode([
                new CloudEvent(id: '1-0', type: 'a', source: 'urn:test'),
                new CloudEvent(id: '1-1', type: 'b', source: 'urn:test'),
            ])),
            FakeTransport::json(Protocol::encode([new CloudEvent(id: '1-2', type: 'c', source: 'urn:test')])),
            FakeTransport::json(Protocol::encode([])),
        ]);

        $cursor = new MemoryCursor();
        $consumer = new Consumer($transport, $cursor, 'invalidator', feed: 'edge');

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(2, $consumer->consume($handler));
        $this->assertSame('1-1', $cursor->load('edge', 'invalidator'));

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(0, $consumer->consume($handler));

        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertStringContainsString('lastEventId=1-1', $transport->recorder->uris()[1]);
    }

    /**
     * The tip sentinel crosses the wire as `lastEventId=$` and the producer
     * resolves it inside the held request — end to end, a tip consumer skips
     * the backlog and still gets what lands mid-poll.
     */
    public function testTipStartWorksOverHttp(): void
    {
        $store = new MidPollStore('edge');
        $producer = new Producer($store, 'urn:test');
        $producer->produce('old');

        $endpoint = new FeedServer(new Server($store));
        $consumer = new Consumer($endpoint, new MemoryCursor(), 'notifier', feed: 'edge', timeout: 5_000, start: Consumer::START_TIP);

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(['landed'], $seen, 'The backlog is skipped; the mid-wait event is not');
        $this->assertMatchesRegularExpression('/lastEventId=(%24|\$)/', $endpoint->recorder->last()['uri']);

        // The position now saves as a real id, so the sentinel never
        // appears on the wire again.
        $producer->produce('after');

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(['landed', 'after'], $seen);
        $this->assertDoesNotMatchRegularExpression('/lastEventId=(%24|\$)/', $endpoint->recorder->last()['uri']);
    }
}
