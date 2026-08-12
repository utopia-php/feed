<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Feed\Store\Memory as MemoryStore;
use Utopia\Feed\Store\None as NoneStore;
use Utopia\Tests\Support\FailingCursor;
use Utopia\Tests\Support\FakeTransport;

/**
 * The consumer behaviours that only a misbehaving collaborator can provoke —
 * a cursor store that is down, a store that records what it was asked, a
 * backend that was never configured. Everything a real adapter can exercise
 * lives in {@see \Utopia\Tests\Consumer\Base} instead.
 */
class ConsumerTest extends TestCase
{
    private MemoryStore $store;

    private Producer $producer;

    private MemoryCursor $cursor;

    protected function setUp(): void
    {
        $this->store = new MemoryStore('edge');
        $this->producer = new Producer($this->store, 'urn:test');
        $this->cursor = new MemoryCursor();
    }

    private function consumer(?Cursor $cursor = null): Consumer
    {
        return new Consumer($this->store, $cursor ?? $this->cursor, 'invalidator');
    }

    public function testReadsTheStoreOnceAndThenTracksThePositionInMemory(): void
    {
        $this->producer->produce('a');

        $cursor = new class () extends MemoryCursor {
            public int $loads = 0;

            public function load(string $feed, string $consumer): ?string
            {
                $this->loads++;

                return parent::load($feed, $consumer);
            }
        };

        $consumer = $this->consumer($cursor);

        // Two reads on the first pass — one restores the position, one is the
        // conditional save's compare. The caught-up polls after it must not
        // touch the store at all, which is what keeps an idle consumer on a
        // timer free.
        $consumer->consume(fn (CloudEvent $event) => null);
        $this->assertSame(2, $cursor->loads);

        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(2, $cursor->loads, 'An idle poll costs no cursor reads');
    }

    /**
     * Two instances sharing a name share one position. A save is conditional
     * on the position the run started from, so the instance that fell behind
     * cannot undo the other's progress — it adopts the newer position and
     * continues from there.
     */
    public function testAStaleInstanceCannotUndoAnotherInstancesProgress(): void
    {
        $one = $this->consumer();
        $two = $this->consumer();

        // An empty first run restores "no position yet" on both instances...
        $this->assertSame(0, $one->consume(fn (CloudEvent $event) => null));
        $this->assertSame(0, $two->consume(fn (CloudEvent $event) => null));

        foreach (['a', 'b', 'c', 'd'] as $type) {
            $this->producer->produce($type);
        }

        // ...then the first instance gets ahead.
        $one->consume(fn (CloudEvent $event) => null);
        $ahead = $one->position();
        $this->assertNotNull($ahead);

        // The stale instance re-handles from the start (at-least-once), but
        // its save is refused rather than moving the shared position back.
        $two->consume(fn (CloudEvent $event) => null);
        $this->assertSame($ahead, $this->cursor->load('edge', 'invalidator'));

        // Having conceded, it reloads the shared position and stays there.
        $this->assertSame($ahead, $two->position());
    }

    /** A seek made through one instance survives another instance's in-flight run. */
    public function testAStaleInstanceCannotUndoAnotherInstancesSeek(): void
    {
        $running = $this->consumer();
        $operator = $this->consumer();

        // An empty first run restores "no position yet" before the seek lands.
        $this->assertSame(0, $running->consume(fn (CloudEvent $event) => null));

        foreach (['a', 'b'] as $type) {
            $this->producer->produce($type);
        }

        $operator->seek('9999999999999-0');

        $running->consume(fn (CloudEvent $event) => null);

        $this->assertSame('9999999999999-0', $this->cursor->load('edge', 'invalidator'));
        $this->assertSame('9999999999999-0', $running->position(), 'The refused instance adopts the seek');
    }

    /**
     * The consumer clamps its own inputs: whatever the constructor was given,
     * a store is never asked for more than the protocol allows.
     */
    public function testClampsBatchAndTimeoutToTheProtocolLimits(): void
    {
        $store = new class ('edge') extends MemoryStore {
            public ?int $limit = null;

            public ?int $timeout = null;

            public function poll(?string $lastEventId, int $limit, int $timeout): array
            {
                $this->limit = $limit;
                $this->timeout = $timeout;

                return parent::poll($lastEventId, $limit, 0);
            }
        };

        $consumer = new Consumer($store, $this->cursor, 'invalidator', batch: 5_000, timeout: 120_000);
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(Readable::MAX_BATCH, $store->limit);
        $this->assertSame(Readable::MAX_TIMEOUT, $store->timeout);
    }

    /**
     * A cursor store that is down surfaces rather than being swallowed: reading
     * from an unknown position would replay the retained feed, so the run stops
     * and the caller decides.
     */
    public function testAPositionThatCannotBeLoadedStopsTheRun(): void
    {
        $this->producer->produce('a');

        $consumer = $this->consumer(new FailingCursor(onLoad: true));
        $seen = [];

        try {
            $consumer->consume(function (CloudEvent $event) use (&$seen): void {
                $seen[] = $event->type;
            });
            $this->fail('The store failure should have been raised');
        } catch (Transport $error) {
            $this->assertSame('Cursor store is unavailable', $error->getMessage());
        }

        $this->assertSame([], $seen, 'Nothing is handled from a position that could not be read');
    }

    /**
     * The load is retried on the next run rather than being remembered as a
     * failure, so a store that blips does not leave the consumer stuck.
     */
    public function testAFailedLoadIsRetriedOnTheNextRun(): void
    {
        $this->producer->produce('a');

        $cursor = new class () extends MemoryCursor {
            public bool $fail = true;

            public function load(string $feed, string $consumer): ?string
            {
                if ($this->fail) {
                    $this->fail = false;

                    throw new Transport('Cursor store is unavailable');
                }

                return parent::load($feed, $consumer);
            }
        };

        $consumer = $this->consumer($cursor);

        try {
            $consumer->consume(fn (CloudEvent $event) => null);
        } catch (Transport) {
            // Expected on the first run.
        }

        $this->assertSame(1, $consumer->consume(fn (CloudEvent $event) => null), 'The second run reads the store again');
    }

    /**
     * The events were handled, so the failure comes after them: this run keeps
     * its progress in memory and only a restart replays.
     */
    public function testAPositionThatCannotBeSavedIsRaisedAfterTheEventsAreHandled(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer(new FailingCursor(onSave: true));
        $seen = [];

        try {
            $consumer->consume(function (CloudEvent $event) use (&$seen): void {
                $seen[] = $event->type;
            });
            $this->fail('The store failure should have been raised');
        } catch (Transport $error) {
            $this->assertSame('Cursor store is unavailable', $error->getMessage());
        }

        $this->assertSame(['a', 'b'], $seen, 'The handler still saw the batch');
        $this->assertNotNull($consumer->position(), 'The in-memory position still moved');
    }

    /**
     * The chunk was handled, so the save failure comes after it — the same
     * promise consume() makes, on the chunk path: this run keeps its progress
     * in memory and only a restart replays.
     */
    public function testAChunkPositionThatCannotBeSavedIsRaisedAfterTheChunkWasHandled(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer(new FailingCursor(onSave: true));
        $seen = [];

        try {
            $consumer->consumeChunk(function (array $events) use (&$seen): void {
                $seen = \array_map(static fn (CloudEvent $event): string => $event->type, $events);
            });
            $this->fail('The store failure should have been raised');
        } catch (Transport $error) {
            $this->assertSame('Cursor store is unavailable', $error->getMessage());
        }

        $this->assertSame(['a', 'b'], $seen, 'The handler still saw the chunk');
        $this->assertNotNull($consumer->position(), 'The in-memory position still moved');
    }

    /** The conditional save guards the chunk path too: a stale chunk run concedes. */
    public function testAStaleChunkRunCannotUndoAnotherInstancesProgress(): void
    {
        $one = $this->consumer();
        $two = $this->consumer();

        // An empty first run restores "no position yet" on both instances...
        $this->assertSame(0, $one->consumeChunk(fn (array $events) => null));
        $this->assertSame(0, $two->consumeChunk(fn (array $events) => null));

        foreach (['a', 'b', 'c', 'd'] as $type) {
            $this->producer->produce($type);
        }

        // ...then the first instance gets ahead.
        $one->consumeChunk(fn (array $events) => null);
        $ahead = $one->position();
        $this->assertNotNull($ahead);

        // The stale instance re-handles the chunk (at-least-once), but its
        // save is refused rather than moving the shared position back.
        $two->consumeChunk(fn (array $events) => null);
        $this->assertSame($ahead, $this->cursor->load('edge', 'invalidator'));
        $this->assertSame($ahead, $two->position(), 'Having conceded, it adopts the shared position');
    }

    /**
     * A seek that did not persist must not look like one that did: the store
     * failure surfaces, and the in-memory position stays where it was.
     */
    public function testASeekThatCannotPersistFailsLoudlyAndMovesNothing(): void
    {
        $this->producer->produce('a');

        $consumer = $this->consumer(new FailingCursor(onSave: true));

        $this->assertNull($consumer->position());

        try {
            $consumer->seek('1-0');
            $this->fail('The store failure should have been raised');
        } catch (Transport) {
            // Expected.
        }

        $this->assertNull($consumer->position(), 'The in-memory position must not move on a failed seek');
    }

    /**
     * The failure a consumer must not turn into a gap: if the read itself
     * fails, nothing is handled and nothing is committed.
     */
    public function testAFailedReadLeavesThePositionAlone(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer(new NoneStore('edge'), $this->cursor, 'invalidator');

        $this->expectException(Unsupported::class);

        try {
            $consumer->consume(fn (CloudEvent $event) => null);
        } finally {
            $this->assertSame($first, $this->cursor->load('edge', 'invalidator'));
        }
    }

    /**
     * A client carries the endpoint but not the feed's name, so a consumer
     * built over one has to be told which feed it is reading.
     */
    public function testConsumingThroughAClientRequiresAFeedName(): void
    {
        $this->expectException(Invalid::class);

        new Consumer(FakeTransport::of([]), $this->cursor, 'invalidator');
    }

    /**
     * A local store already names its feed. Repeating the name is harmless;
     * contradicting it means the caller is confused about what they are
     * reading, which must not resolve silently in either direction.
     */
    public function testAFeedNameThatContradictsTheStoreIsRejected(): void
    {
        $this->expectException(Invalid::class);

        new Consumer($this->store, $this->cursor, 'invalidator', feed: 'other');
    }

    public function testAFeedNameThatMatchesTheStoreIsAccepted(): void
    {
        $this->producer->produce('a');

        $consumer = new Consumer($this->store, $this->cursor, 'invalidator', feed: 'edge');

        $this->assertSame(1, $consumer->consume(fn (CloudEvent $event) => null));
    }
}
