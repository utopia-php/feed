<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client;
use Utopia\Feed\Journal\Http;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;
use Utopia\Tests\Unit\Support\FakeTransport;

class HttpJournalTest extends TestCase
{
    /**
     * @param list<ResponseInterface|\Throwable> $responses
     * @return array{Feed, FakeTransport}
     */
    private function feed(array $responses = []): array
    {
        $transport = FakeTransport::of($responses);
        $journal = new Http($transport, 'https://cloud.example.com/v1/feeds', 'edge');

        return [new Feed($journal), $transport];
    }

    public function testReadsAFeedOverHttp(): void
    {
        [$feed] = $this->feed([FakeTransport::json(Protocol::encode([
            new CloudEvent(id: '1-0', type: 'io.appwrite.edge.invalidate-rule', data: ['tags' => ['domain' => 'example.com']]),
            new CloudEvent(id: '1-1', type: 'io.appwrite.edge.invalidate'),
        ]))]);

        $events = $feed->read();

        $this->assertCount(2, $events);
        $this->assertSame('1-0', $events[0]->id);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    public function testAppendsTheFeedNameToTheEndpoint(): void
    {
        [$feed, $transport] = $this->feed();

        $feed->read();

        $this->assertStringStartsWith('https://cloud.example.com/v1/feeds/edge', $transport->recorder->last()['uri']);
    }

    public function testEncodesAFeedNameThatNeedsIt(): void
    {
        $transport = FakeTransport::of([]);

        (new Feed(new Http($transport, 'https://cloud.example.com/v1/feeds/', 'a b/c')))->read();

        $this->assertStringStartsWith(
            'https://cloud.example.com/v1/feeds/a%20b%2Fc',
            $transport->recorder->last()['uri'],
        );
    }

    public function testReadsWithGet(): void
    {
        [$feed, $transport] = $this->feed();

        $feed->read();

        $this->assertSame('GET', $transport->recorder->last()['method']);
    }

    public function testAsksForJson(): void
    {
        [$feed, $transport] = $this->feed();

        $feed->read();

        $this->assertSame('application/json', $transport->recorder->last()['headers']['Accept'] ?? null);
    }

    public function testSendsThePositionAndLimit(): void
    {
        [$feed, $transport] = $this->feed();

        $feed->read('1-0', 250);

        $uri = $transport->recorder->last()['uri'];

        $this->assertStringContainsString('lastEventId=1-0', $uri);
        $this->assertStringContainsString('limit=250', $uri);
    }

    public function testSendsNoParametersOnAFirstFullRead(): void
    {
        [$feed, $transport] = $this->feed();

        $feed->read(null, Feed::MAX_BATCH);

        $this->assertStringNotContainsString('lastEventId', $transport->recorder->last()['uri']);
    }

    /**
     * The producer does the waiting, so a poll is one request rather than a
     * client-side loop.
     */
    public function testDelegatesLongPollingToTheProducer(): void
    {
        [$feed, $transport] = $this->feed();

        $started = \microtime(true);
        $feed->poll(null, 100, 5000);

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
        [$feed, $transport] = $this->feed();

        $feed->poll(null, 100, 5000);

        // Seconds, which is what the client takes; the protocol margin is in
        // milliseconds, like the timeout the producer is given.
        $this->assertSame(15.0, $transport->recorder->last()['timeout']);
        $this->assertSame((float) ((5000 + Protocol::TIMEOUT_MARGIN) / 1000), $transport->recorder->last()['timeout']);
    }

    public function testLeavesTheConfiguredTimeoutAloneWhenNotLongPolling(): void
    {
        [$feed, $transport] = $this->feed();

        $feed->read();

        $this->assertNull($transport->recorder->last()['timeout'], 'A plain read must not override the client');
    }

    /**
     * The status is carried on the exception so a consumer can tell a producer
     * that does not serve the feed yet — normal during a staged rollout — from
     * one that is broken.
     */
    public function testCarriesTheStatusOfARejectedRead(): void
    {
        [$feed] = $this->feed([FakeTransport::json([], 404)]);

        try {
            $feed->read();
            $this->fail('A 404 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(404, $error->getCode());
        }
    }

    public function testRaisesServerErrors(): void
    {
        [$feed] = $this->feed([FakeTransport::json([], 503)]);

        try {
            $feed->read();
            $this->fail('A 503 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(503, $error->getCode());
        }
    }

    /**
     * PSR-18 returns 4xx and 5xx rather than throwing, so the journal has to
     * check the status itself — a producer error must not read as an empty
     * batch, which the consumer would take for "caught up".
     */
    public function testAnErrorStatusIsNotMistakenForAnEmptyBatch(): void
    {
        [$feed] = $this->feed([FakeTransport::json(['total' => 0, 'events' => []], 500)]);

        $this->expectException(Transport::class);

        $feed->read();
    }

    public function testWrapsATransportFailure(): void
    {
        [$feed] = $this->feed([FakeTransport::offline()]);

        $this->expectException(Transport::class);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $feed->read();
    }

    public function testWrapsABodyThatIsNotJson(): void
    {
        [$feed] = $this->feed([FakeTransport::raw('<html>502 Bad Gateway</html>')]);

        $this->expectException(Transport::class);

        $feed->read();
    }

    public function testRejectsABodyThatIsNotABatch(): void
    {
        [$feed] = $this->feed([FakeTransport::raw('"a string"')]);

        $this->expectException(Invalid::class);

        $feed->read();
    }

    public function testCannotAppendToAFeedItDoesNotOwn(): void
    {
        $journal = new Http(FakeTransport::of([]), 'https://cloud.example.com/v1/feeds', 'edge');
        $feed = new Feed($journal, 'urn:appwrite:edge:fra');

        $this->expectException(Unsupported::class);

        $feed->append('io.appwrite.edge.invalidate');
    }

    /**
     * Anything implementing the client's adapter interface works, including
     * the client itself wrapping a transport — which is how this is actually
     * built in a service.
     */
    public function testWorksThroughTheClientItself(): void
    {
        $transport = FakeTransport::of([FakeTransport::json(Protocol::encode([new CloudEvent(id: '1-0', type: 'a')]))]);

        $client = (new Client($transport))->withHeaders(['x-appwrite-jwt' => 'token']);
        $feed = new Feed(new Http($client, 'https://cloud.example.com/v1/feeds', 'edge'));

        $events = $feed->read();

        $this->assertCount(1, $events);
        $this->assertSame('token', $transport->recorder->last()['headers']['x-appwrite-jwt'] ?? null);
    }

    /**
     * The point of this journal: a remote feed is consumed with exactly the
     * code a local one is.
     */
    public function testConsumesARemoteFeedThroughTheSameConsumer(): void
    {
        [$feed, $transport] = $this->feed([
            FakeTransport::json(Protocol::encode([
                new CloudEvent(id: '1-0', type: 'a'),
                new CloudEvent(id: '1-1', type: 'b'),
            ])),
            FakeTransport::json(Protocol::encode([new CloudEvent(id: '1-2', type: 'c')])),
            FakeTransport::json(Protocol::encode([])),
        ]);

        $cursor = new MemoryCursor();
        $consumer = new Consumer($feed, 'invalidator', $cursor);

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
}
