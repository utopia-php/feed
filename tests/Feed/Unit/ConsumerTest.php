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
use Utopia\Feed\Producer;
use Utopia\Feed\Protocol;
use Utopia\Feed\Start;
use Utopia\Tests\Unit\Support\FailingCursor;
use Utopia\Tests\Unit\Support\MidPollJournal;

class ConsumerTest extends TestCase
{
    private MemoryJournal $journal;

    private Producer $producer;

    private MemoryCursor $cursor;

    protected function setUp(): void
    {
        $this->journal = new MemoryJournal('edge');
        $this->producer = new Producer($this->journal, 'urn:test');
        $this->cursor = new MemoryCursor();
    }

    private function consumer(?Cursor $cursor = null, int $batch = Consumer::BATCH): Consumer
    {
        return new Consumer($this->journal, 'invalidator', $cursor ?? $this->cursor, $batch);
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
        $this->producer->append('a');
        $last = $this->producer->append('b');

        $consumer = $this->consumer();

        $this->assertSame(['a', 'b'], $this->drain($consumer, $count));
        $this->assertSame(2, $count);
        $this->assertSame($last, $this->cursor->load('edge', 'invalidator'));
        $this->assertSame($last, $consumer->position());
    }

    public function testCaughtUpConsumerDoesNothing(): void
    {
        $this->producer->append('a');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }

    public function testResumesFromTheStoredPosition(): void
    {
        $first = $this->producer->append('a');
        $this->producer->append('b');

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
        $this->producer->append('a');
        $this->producer->append('b');

        $this->assertSame(['a', 'b'], $this->drain($this->consumer()));
    }

    public function testReadsTheStoreOnceAndThenTracksThePositionInMemory(): void
    {
        $this->producer->append('a');

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
        $first = $this->producer->append('a');
        $this->producer->append('b');
        $this->producer->append('c');

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
        $this->producer->append('a');
        $this->producer->append('b');

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
        $this->producer->append('a');

        try {
            $this->consumer()->consume(fn (CloudEvent $event) => throw new \RuntimeException('nope'));
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNull($this->cursor->load('edge', 'invalidator'));
    }

    public function testAHandlerThatAcceptsEverythingCountsEveryEvent(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');
        $this->producer->append('c');

        $this->assertSame(3, $this->consumer()->consume(fn (CloudEvent $event) => null));
    }

    /**
     * The clamping the client-side Feed wrapper used to provide lives in the
     * consumer now: whatever the constructor was given, a journal is never
     * asked for more than the protocol allows.
     */
    public function testClampsBatchAndTimeoutToTheProtocolLimits(): void
    {
        $journal = new class ('edge') extends MemoryJournal {
            public ?int $limit = null;

            public ?int $timeout = null;

            public function poll(?string $lastEventId, int $limit, int $timeout): array
            {
                $this->limit = $limit;
                $this->timeout = $timeout;

                return parent::poll($lastEventId, $limit, 0);
            }
        };

        $consumer = new Consumer($journal, 'invalidator', $this->cursor, batch: 5_000, timeout: 120_000);
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(Protocol::MAX_BATCH, $journal->limit);
        $this->assertSame(Protocol::MAX_TIMEOUT, $journal->timeout);
    }

    public function testDrainsABacklogInBatches(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->producer->append('event-' . $i);
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
        $this->producer->append('a');

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
        $this->producer->append('a');

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
        $this->producer->append('a');
        $this->producer->append('b');

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

    public function testTipStartDoesNotAnnounceTheBacklog(): void
    {
        $this->producer->append('old-1');
        $this->producer->append('old-2');

        $consumer = new Consumer($this->journal, 'notifier', $this->cursor, start: Start::Tip);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertNull($this->cursor->load('edge', 'notifier'), 'Skipping the backlog is not progress to commit');
    }

    /**
     * The tip is pinned when the poll starts, so an event landing while the
     * poll waits is delivered — only the backlog is skipped.
     */
    public function testTipStartDeliversWhatLandsMidPoll(): void
    {
        $journal = new MidPollJournal('edge');
        (new Producer($journal, 'urn:test'))->append('old');

        $cursor = new MemoryCursor();
        $consumer = new Consumer($journal, 'notifier', $cursor, timeout: 5_000, start: Start::Tip);

        $seen = [];
        $count = $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(1, $count);
        $this->assertSame(['landed'], $seen, 'The backlog is skipped; the mid-wait event is not');
        $this->assertNotNull($cursor->load('edge', 'notifier'), 'Handling the event saves the position');
    }

    public function testAStoredCursorBeatsTipStart(): void
    {
        $first = $this->producer->append('a');
        $this->producer->append('b');

        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer($this->journal, 'invalidator', $this->cursor, start: Start::Tip);

        $this->assertSame(['b'], $this->drain($consumer), 'A restart must not skip the gap');
    }

    public function testResetWithTipStartResumesFromNow(): void
    {
        $first = $this->producer->append('a');
        $this->producer->append('b');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer($this->journal, 'invalidator', $this->cursor, start: Start::Tip);

        $this->assertSame(['b'], $this->drain($consumer), 'The stored position still wins before the reset');

        $consumer->reset();

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null), 'After reset, the backlog is forgotten');
    }

    public function testTipStartOnAnEmptyFeedWaitsOutTheTimeoutEmpty(): void
    {
        $consumer = new Consumer($this->journal, 'notifier', $this->cursor, timeout: 600, start: Start::Tip);

        $started = \microtime(true);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started, 'Must actually wait');
    }

    public function testTipStartOnAnEmptyFeedDeliversWhatLandsMidWait(): void
    {
        $consumer = new Consumer(new MidPollJournal('edge'), 'notifier', $this->cursor, timeout: 5_000, start: Start::Tip);

        $seen = [];
        $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(['landed'], $seen);
    }

    public function testResetReplaysEverythingStillRetained(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertNull($this->cursor->load('edge', 'invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer));
    }

    public function testSeekPositionsTheNextRunStrictlyAfterTheGivenId(): void
    {
        $this->producer->append('a');
        $second = $this->producer->append('b');
        $this->producer->append('c');

        $consumer = $this->consumer();
        $consumer->seek($second);

        $this->assertSame($second, $consumer->position(), 'The seeked id is the position until something is handled');
        $this->assertSame(['c'], $this->drain($consumer));
    }

    /**
     * A seek is persisted, not just remembered: a fresh Consumer sharing the
     * store and the name — a restart — resumes from it.
     */
    public function testASeekSurvivesARestart(): void
    {
        $this->producer->append('a');
        $second = $this->producer->append('b');
        $this->producer->append('c');

        $this->consumer()->seek($second);

        $this->assertSame(['c'], $this->drain($this->consumer()));
    }

    /**
     * The operational escape hatch seek() exists for: a handler that keeps
     * failing blocks the feed by design, and stepping past it is a deliberate
     * seek to the failing event's own id.
     */
    public function testSeekingToAPoisonEventsIdUnblocksTheConsumer(): void
    {
        $this->producer->append('poison');
        $this->producer->append('after');

        $consumer = $this->consumer();
        $poison = null;

        $handler = function (CloudEvent $event) use (&$poison): void {
            if ($event->type === 'poison') {
                $poison = $event->id;

                throw new \RuntimeException('cannot handle this one');
            }
        };

        try {
            $consumer->consume($handler);
            $this->fail('The poison event should have blocked the run');
        } catch (\RuntimeException) {
            // Expected: the feed is now blocked at the poison event.
        }

        $this->assertNotNull($poison);
        $consumer->seek($poison);

        $this->assertSame(['after'], $this->drain($consumer), 'The poison event is stepped over, nothing behind it is lost');
    }

    /**
     * @dataProvider notPositions
     */
    public function testSeekRejectsAnIdThatIsNotAPosition(string $id): void
    {
        $first = $this->producer->append('a');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = $this->consumer();

        try {
            $consumer->seek($id);
            $this->fail('The id should have been rejected');
        } catch (Invalid) {
            // Expected.
        }

        $this->assertSame($first, $this->cursor->load('edge', 'invalidator'), 'A rejected seek leaves the stored position untouched');
        $this->assertSame($first, $consumer->position());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPositions(): array
    {
        return [
            'empty' => [''],
            'not an id' => ['abc'],
            'too many parts' => ['1-2-3'],
            'the tip sentinel' => ['$'],
        ];
    }

    /**
     * A seek that did not persist must not look like one that did: the store
     * failure surfaces, and the in-memory position stays where it was.
     */
    public function testASeekThatCannotPersistFailsLoudlyAndMovesNothing(): void
    {
        $this->producer->append('a');

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

    public function testConsumersOfTheSameFeedTrackSeparatePositions(): void
    {
        $this->producer->append('a');

        $one = new Consumer($this->journal, 'one', $this->cursor);
        $two = new Consumer($this->journal, 'two', $this->cursor);

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

        new Consumer($this->journal, '', $this->cursor);
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
        $first = $this->producer->append('a');
        $this->producer->append('b');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer(new \Utopia\Feed\Journal\None('edge'), 'invalidator', $this->cursor);

        $this->expectException(\Utopia\Feed\Exception\Unsupported::class);

        try {
            $consumer->consume(fn (CloudEvent $event) => null);
        } finally {
            $this->assertSame($first, $this->cursor->load('edge', 'invalidator'));
        }
    }
}
