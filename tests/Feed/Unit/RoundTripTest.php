<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Journal\Http;
use Utopia\Feed\Journal\Memory as MemoryJournal;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Cache as CacheCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Feed;
use Utopia\Feed\Producer;
use Utopia\Feed\Protocol;
use Utopia\Tests\Unit\Support\FeedServer;

/**
 * A producer and a consumer joined by the HTTP contract, which is the pair this
 * library exists to keep from drifting apart. Everything here goes through
 * {@see Protocol} in both directions rather than through a fixture written to
 * match one side.
 */
class RoundTripTest extends TestCase
{
    private Producer $producer;

    private Feed $consumerFeed;

    private FeedServer $server;

    private CacheCursor $cursor;

    protected function setUp(): void
    {
        $journal = new MemoryJournal('edge');
        $this->producer = new Producer($journal, 'urn:appwrite:cloud:fra');
        $this->server = new FeedServer(new Feed($journal));

        $this->consumerFeed = new Feed(
            new Http($this->server, 'https://cloud.example.com/v1/feeds', 'edge')
        );

        $this->cursor = new CacheCursor(new UtopiaCache(new CacheMemory()));
    }

    private function consumer(string $name = 'invalidator', int $batch = Consumer::BATCH): Consumer
    {
        return new Consumer($this->consumerFeed, $name, $this->cursor, $batch);
    }

    public function testAnEventSurvivesTheWholeTrip(): void
    {
        $this->producer->append(
            'io.appwrite.edge.invalidate-rule',
            ['tags' => ['domain' => 'example.com'], 'isAppwriteNetwork' => true],
            'example.com',
        );

        $received = null;
        $this->consumer()->consume(function (CloudEvent $event) use (&$received): void {
            $received = $event;
        });

        $this->assertInstanceOf(CloudEvent::class, $received);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $received->type);
        $this->assertSame('urn:appwrite:cloud:fra', $received->source);
        $this->assertSame('example.com', $received->subject);
        $this->assertSame([
            'tags' => ['domain' => 'example.com'],
            'isAppwriteNetwork' => true,
        ], $received->data);
    }

    public function testTheConsumerOnlyEverSeesEachEventOnce(): void
    {
        foreach (\range(1, 5) as $i) {
            $this->producer->append('event-' . $i);
        }

        $consumer = $this->consumer();
        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $consumer->consume($handler);
        $consumer->consume($handler);

        $this->producer->append('event-6');
        $consumer->consume($handler);

        $this->assertSame(
            ['event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'],
            $seen,
        );
    }

    /**
     * The rollout property: a consumer shipped after the producer catches up on
     * everything that accumulated in between, rather than starting at the tip.
     */
    public function testAConsumerShippedLateDrainsTheBacklog(): void
    {
        foreach (\range(1, 3) as $i) {
            $this->producer->append('missed-' . $i);
        }

        $seen = [];
        $handled = $this->consumer()->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(3, $handled);
        $this->assertSame(['missed-1', 'missed-2', 'missed-3'], $seen);
    }

    /**
     * The restart property: a new Consumer with no in-memory state picks the
     * stored position up rather than replaying.
     */
    public function testARestartedConsumerResumesWhereItLeftOff(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');

        $this->consumer()->consume(fn (CloudEvent $event) => null);

        $this->producer->append('c');

        $seen = [];
        $this->consumer()->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(['c'], $seen);
    }

    public function testAFailedEventBlocksTheOnesBehindItUntilItSucceeds(): void
    {
        $this->producer->append('a');
        $this->producer->append('poison');
        $this->producer->append('c');

        $consumer = $this->consumer();
        $seen = [];
        $attempts = 0;

        // Fails the first time it sees the poison event and succeeds after,
        // standing in for a dependency that was briefly unavailable.
        $handler = function (CloudEvent $event) use (&$seen, &$attempts): void {
            if ($event->type === 'poison') {
                $attempts++;

                if ($attempts === 1) {
                    throw new \RuntimeException('not yet');
                }
            }

            $seen[] = $event->type;
        };

        try {
            $consumer->consume($handler);
            $this->fail('The handler failure should have been re-raised');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(['a'], $seen, 'Nothing behind the failure is delivered');

        $consumer->consume($handler);

        $this->assertSame(['a', 'poison', 'c'], $seen, 'Order is preserved and nothing is skipped');
        $this->assertSame(2, $attempts, 'The failed event is retried, not dropped');
    }

    public function testTheProducerCachesFullBatchesAndNothingElse(): void
    {
        foreach (\range(1, 5) as $i) {
            $this->producer->append('event-' . $i);
        }

        $consumer = $this->consumer(batch: 2);

        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame([
            'private, max-age=31536000', // 2 of 2 — settled history
            'private, max-age=31536000', // 2 of 2 — settled history
            'no-store',                  // 1 of 2 — the live end, will grow
            'no-store',                  // 0 of 2 — caught up
        ], $this->server->recorder->cacheControl());
    }

    public function testTwoConsumersOfOneProducerAreIndependent(): void
    {
        $this->producer->append('a');

        $one = $this->consumer('one');
        $two = $this->consumer('two');

        $this->assertSame(1, $one->consume(fn (CloudEvent $event) => null));

        $this->producer->append('b');

        $this->assertSame(2, $two->consume(fn (CloudEvent $event) => null), 'The second consumer starts from the beginning');
        $this->assertSame(1, $one->consume(fn (CloudEvent $event) => null), 'The first only sees what is new to it');
    }
}
