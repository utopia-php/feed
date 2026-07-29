<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Adapter\Http;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;
use Utopia\Fetch\Client;
use Utopia\Tests\Unit\Support\FakeTransport;

class HttpAdapterTest extends TestCase
{
    /**
     * @param list<\Utopia\Fetch\Response|\Throwable> $responses
     * @return array{Feed, FakeTransport}
     */
    private function feed(array $responses): array
    {
        $transport = new FakeTransport($responses);
        $adapter = new Http(new Client($transport), 'https://cloud.example.com/v1/feeds', 'edge');

        return [new Feed($adapter), $transport];
    }

    public function testReadsAFeedOverHttp(): void
    {
        [$feed] = $this->feed([FakeTransport::ok(Protocol::encode([
            new Event(id: '1-0', type: 'io.appwrite.edge.invalidate-rule', data: ['tags' => ['domain' => 'example.com']]),
            new Event(id: '1-1', type: 'io.appwrite.edge.invalidate'),
        ]))]);

        $events = $feed->read();

        $this->assertCount(2, $events);
        $this->assertSame('1-0', $events[0]->id);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    public function testAppendsTheFeedNameToTheEndpoint(): void
    {
        [$feed, $transport] = $this->feed([FakeTransport::ok([])]);

        $feed->read();

        $this->assertStringStartsWith('https://cloud.example.com/v1/feeds/edge', $transport->lastRequest()['url']);
    }

    public function testEncodesAFeedNameThatNeedsIt(): void
    {
        $adapter = new Http(new Client(new FakeTransport([])), 'https://cloud.example.com/v1/feeds/', 'a b/c');

        $this->assertSame('https://cloud.example.com/v1/feeds/a%20b%2Fc', $adapter->getUrl());
    }

    public function testSendsThePositionAndLimit(): void
    {
        [$feed, $transport] = $this->feed([FakeTransport::ok([])]);

        $feed->read('1-0', 250);

        $url = $transport->lastRequest()['url'];

        $this->assertStringContainsString('lastEventId=1-0', $url);
        $this->assertStringContainsString('limit=250', $url);
    }

    public function testSendsNoParametersOnAFirstFullRead(): void
    {
        [$feed, $transport] = $this->feed([FakeTransport::ok([])]);

        $feed->read(null, Feed::MAX_BATCH);

        $this->assertStringNotContainsString('lastEventId', $transport->lastRequest()['url']);
    }

    /**
     * The producer does the waiting, so a poll is one request rather than a
     * client-side loop.
     */
    public function testDelegatesLongPollingToTheProducer(): void
    {
        [$feed, $transport] = $this->feed([FakeTransport::ok([])]);

        $started = \microtime(true);
        $feed->poll(null, 100, 5000);

        $this->assertLessThan(1, \microtime(true) - $started, 'Must not wait client-side');
        $this->assertCount(1, $transport->requests, 'Must not poll in a loop');
        $this->assertStringContainsString('timeout=5000', $transport->lastRequest()['url']);
    }

    /**
     * Without the margin the client's deadline races the producer's, and a
     * poll that correctly waits out its timeout surfaces as a failure on every
     * quiet tick.
     */
    public function testAllowsTheClientLongerThanTheLongPollTimeout(): void
    {
        [$feed, $transport] = $this->feed([FakeTransport::ok([])]);

        $feed->poll(null, 100, 5000);

        $this->assertSame(5000 + Protocol::TIMEOUT_MARGIN, $transport->lastRequest()['timeout']);
    }

    public function testUsesTheClientDefaultTimeoutWhenNotLongPolling(): void
    {
        $transport = new FakeTransport([FakeTransport::ok([])]);
        $client = (new Client($transport))->setTimeout(1234);

        (new Feed(new Http($client, 'https://cloud.example.com/v1/feeds', 'edge')))->read();

        $this->assertSame(1234, $transport->lastRequest()['timeout']);
    }

    /**
     * The status is carried on the exception so a consumer can tell a producer
     * that does not serve the feed yet — normal during a staged rollout — from
     * one that is broken.
     */
    public function testCarriesTheStatusOfARejectedRead(): void
    {
        [$feed] = $this->feed([FakeTransport::status(404)]);

        try {
            $feed->read();
            $this->fail('A 404 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(404, $error->getCode());
        }
    }

    public function testRaisesServerErrors(): void
    {
        [$feed] = $this->feed([FakeTransport::status(503)]);

        try {
            $feed->read();
            $this->fail('A 503 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(503, $error->getCode());
        }
    }

    public function testWrapsATransportFailure(): void
    {
        [$feed] = $this->feed([new \RuntimeException('Connection refused')]);

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
        [$feed] = $this->feed([FakeTransport::ok([])]);

        $this->expectException(Unsupported::class);

        $feed->append('io.appwrite.edge.invalidate');
    }

    /**
     * The point of the adapter: a remote feed is consumed with exactly the
     * code a local one is.
     */
    public function testConsumesARemoteFeedThroughTheSameConsumer(): void
    {
        [$feed, $transport] = $this->feed([
            FakeTransport::ok(Protocol::encode([
                new Event(id: '1-0', type: 'a'),
                new Event(id: '1-1', type: 'b'),
            ])),
            FakeTransport::ok(Protocol::encode([new Event(id: '1-2', type: 'c')])),
            FakeTransport::ok(Protocol::encode([])),
        ]);

        $cursor = new MemoryCursor('edge');
        $consumer = new Consumer($feed, 'invalidator', $cursor);

        $seen = [];
        $handler = function (Event $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(2, $consumer->consume($handler));
        $this->assertSame('1-1', $cursor->load('invalidator'));

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(0, $consumer->consume($handler));

        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertStringContainsString('lastEventId=1-1', $transport->requests[1]['url']);
    }
}
