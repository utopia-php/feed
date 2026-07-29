<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Journal\Memory as MemoryJournal;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Feed;
use Utopia\Tests\Unit\Support\FailingCursor;

class ConsumerTest extends TestCase
{
    private MemoryJournal $journal;

    private Feed $feed;

    private MemoryCursor $cursor;

    protected function setUp(): void
    {
        $this->journal = new MemoryJournal('edge');
        $this->feed = new Feed($this->journal, 'urn:test');
        $this->cursor = new MemoryCursor();
    }

    private function consumer(?Cursor $cursor = null, int $batch = Consumer::BATCH): Consumer
    {
        return new Consumer($this->feed, 'invalidator', $cursor ?? $this->cursor, $batch);
    }

    /**
     * @param-out int $count
     * @return list<string>
     */
    private function drain(Consumer $consumer, ?int &$count = null): array
    {
        $seen = [];
        $count = $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        return $seen;
    }

    public function testHandlesEachEventAndAdvancesPastTheLastOne(): void
    {
        $this->feed->append('a');
        $last = $this->feed->append('b');

        $consumer = $this->consumer();

        $this->assertSame(['a', 'b'], $this->drain($consumer, $count));
        $this->assertSame(2, $count);
        $this->assertSame($last, $this->cursor->load('edge', 'invalidator'));
        $this->assertSame($last, $consumer->position());
    }

    public function testCaughtUpConsumerDoesNothing(): void
    {
        $this->feed->append('a');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }

    public function testResumesFromTheStoredPosition(): void
    {
        $first = $this->feed->append('a');
        $this->feed->append('b');

        $this->cursor->save('edge', 'invalidator', $first);

        $this->assertSame(['b'], $this->drain($this->consumer()));
    }

    /**
     * A consumer that has never run starts at the oldest retained event, not
     * at the tip — otherwise the first event a feed ever carries is the one
     * event that is guaranteed to be dropped.
     */
    public function testAConsumerWithNoPositionStartsAtTheOldestEventNotTheTip(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $this->assertSame(['a', 'b'], $this->drain($this->consumer()));
    }

    public function testReadsTheStoreOnceAndThenTracksThePositionInMemory(): void
    {
        $this->feed->append('a');

        $cursor = new class () extends MemoryCursor {
            public int $loads = 0;

            public function load(string $feed, string $consumer): ?string
            {
                $this->loads++;

                return parent::load($feed, $consumer);
            }
        };

        $consumer = $this->consumer($cursor);

        // One read on the first pass to restore the position; the two
        // caught-up polls after it must not touch the store at all, which is
        // what keeps an idle consumer on a timer free.
        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(1, $cursor->loads);
    }

    public function testStopsAtTheFirstFailureAndLeavesThePositionBeforeIt(): void
    {
        $first = $this->feed->append('a');
        $this->feed->append('b');
        $this->feed->append('c');

        $consumer = $this->consumer();
        $seen = [];

        try {
            $consumer->consume(function (CloudEvent $event) use (&$seen): void {
                if ($event->type === 'b') {
                    throw new \RuntimeException('nope');
                }

                $seen[] = $event->type;
            });
            $this->fail('The handler failure should have been re-raised');
        } catch (\RuntimeException $error) {
            $this->assertSame('nope', $error->getMessage());
        }

        $this->assertSame(['a'], $seen);
        $this->assertSame($first, $this->cursor->load('edge', 'invalidator'), 'Progress before the failure is committed');
    }

    public function testRetriesTheFailedEventOnTheNextRun(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $consumer = $this->consumer();
        $attempts = 0;

        try {
            $consumer->consume(function (CloudEvent $event) use (&$attempts): void {
                if ($event->type === 'b') {
                    $attempts++;
                    throw new \RuntimeException('nope');
                }
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(['b'], $this->drain($consumer), 'The failed event comes back');
        $this->assertSame(1, $attempts);
    }

    /**
     * A failure on the very first event of a run commits nothing, so a store
     * that was already empty stays empty rather than being written a position
     * that stands for no completed work.
     */
    public function testAFailureOnTheFirstEventCommitsNothing(): void
    {
        $this->feed->append('a');

        try {
            $this->consumer()->consume(fn (CloudEvent $event) => throw new \RuntimeException('nope'));
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNull($this->cursor->load('edge', 'invalidator'));
    }

    public function testAHandlerThatAcceptsEverythingCountsEveryEvent(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');
        $this->feed->append('c');

        $this->assertSame(3, $this->consumer()->consume(fn (CloudEvent $event) => null));
    }

    public function testDrainsABacklogInBatches(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->feed->append('event-' . $i);
        }

        $consumer = $this->consumer(batch: 4);

        $this->assertSame(4, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertSame(4, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertSame(2, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }

    /**
     * A cursor store that is down surfaces rather than being swallowed: reading
     * from an unknown position would replay the retained feed, so the run stops
     * and the caller decides.
     */
    public function testAPositionThatCannotBeLoadedStopsTheRun(): void
    {
        $this->feed->append('a');

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
        $this->feed->append('a');

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

        $this->assertSame(['a'], $this->drain($consumer), 'The second run reads the store again');
    }

    /**
     * The events were handled, so the failure comes after them: this run keeps
     * its progress in memory and only a restart replays.
     */
    public function testAPositionThatCannotBeSavedIsRaisedAfterTheEventsAreHandled(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

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

    public function testResetReplaysEverythingStillRetained(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertNull($this->cursor->load('edge', 'invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer));
    }

    public function testConsumersOfTheSameFeedTrackSeparatePositions(): void
    {
        $this->feed->append('a');

        $one = new Consumer($this->feed, 'one', $this->cursor);
        $two = new Consumer($this->feed, 'two', $this->cursor);

        $this->assertSame(1, $one->consume(fn (CloudEvent $event) => null));
        $this->assertSame(1, $two->consume(fn (CloudEvent $event) => null), 'The second consumer has its own position');
        $this->assertSame(0, $one->consume(fn (CloudEvent $event) => null));
    }

    public function testPositionIsNullBeforeTheFirstRun(): void
    {
        $this->assertNull($this->consumer()->position());
    }

    public function testRejectsAnEmptyConsumerName(): void
    {
        $this->expectException(Invalid::class);

        new Consumer($this->feed, '', $this->cursor);
    }

    public function testExposesItsName(): void
    {
        $this->assertSame('invalidator', $this->consumer()->getName());
    }

    /**
     * The failure a consumer must not turn into a gap: if the read itself
     * fails, nothing is handled and nothing is committed.
     */
    public function testAFailedReadLeavesThePositionAlone(): void
    {
        $first = $this->feed->append('a');
        $this->feed->append('b');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer(new Feed(new \Utopia\Feed\Journal\None('edge')), 'invalidator', $this->cursor);

        $this->expectException(\Utopia\Feed\Exception\Unsupported::class);

        try {
            $consumer->consume(fn (CloudEvent $event) => null);
        } finally {
            $this->assertSame($first, $this->cursor->load('edge', 'invalidator'));
        }
    }
}
