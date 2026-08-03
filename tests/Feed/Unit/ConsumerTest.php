<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Store\Memory as MemoryStore;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Tests\Unit\Support\FailingCursor;
use Utopia\Tests\Unit\Support\FakeTransport;
use Utopia\Tests\Unit\Support\MidPollStore;

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

    private function consumer(?Cursor $cursor = null, int $batch = Consumer::BATCH): Consumer
    {
        return new Consumer($this->store, $cursor ?? $this->cursor, 'invalidator', batch: $batch);
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
        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $consumer = $this->consumer();

        $this->assertSame(['a', 'b'], $this->drain($consumer, $count));
        $this->assertSame(2, $count);
        $this->assertSame($last, $this->cursor->load('edge', 'invalidator'));
        $this->assertSame($last, $consumer->position());
    }

    public function testCaughtUpConsumerDoesNothing(): void
    {
        $this->producer->produce('a');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }

    public function testResumesFromTheStoredPosition(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');

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
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertSame(['a', 'b'], $this->drain($this->consumer()));
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
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->producer->produce('c');

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
        $this->producer->produce('a');
        $this->producer->produce('b');

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
        $this->producer->produce('a');

        try {
            $this->consumer()->consume(fn (CloudEvent $event) => throw new \RuntimeException('nope'));
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNull($this->cursor->load('edge', 'invalidator'));
    }

    public function testAHandlerThatAcceptsEverythingCountsEveryEvent(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');
        $this->producer->produce('c');

        $this->assertSame(3, $this->consumer()->consume(fn (CloudEvent $event) => null));
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

    public function testDrainsABacklogInBatches(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->producer->produce('event-' . $i);
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

        $this->assertSame(['a'], $this->drain($consumer), 'The second run reads the store again');
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

    public function testTipStartDoesNotAnnounceTheBacklog(): void
    {
        $this->producer->produce('old-1');
        $this->producer->produce('old-2');

        $consumer = new Consumer($this->store, $this->cursor, 'notifier', start: Consumer::START_TIP);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertNull($this->cursor->load('edge', 'notifier'), 'Skipping the backlog is not progress to commit');
    }

    /**
     * The tip is pinned when the poll starts, so an event landing while the
     * poll waits is delivered — only the backlog is skipped.
     */
    public function testTipStartDeliversWhatLandsMidPoll(): void
    {
        $store = new MidPollStore('edge');
        (new Producer($store, 'urn:test'))->produce('old');

        $cursor = new MemoryCursor();
        $consumer = new Consumer($store, $cursor, 'notifier', timeout: 5_000, start: Consumer::START_TIP);

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
        $first = $this->producer->produce('a');
        $this->producer->produce('b');

        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer($this->store, $this->cursor, 'invalidator', start: Consumer::START_TIP);

        $this->assertSame(['b'], $this->drain($consumer), 'A restart must not skip the gap');
    }

    public function testResetWithTipStartResumesFromNow(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer($this->store, $this->cursor, 'invalidator', start: Consumer::START_TIP);

        $this->assertSame(['b'], $this->drain($consumer), 'The stored position still wins before the reset');

        $consumer->reset();

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null), 'After reset, the backlog is forgotten');
    }

    public function testTipStartOnAnEmptyFeedWaitsOutTheTimeoutEmpty(): void
    {
        $consumer = new Consumer($this->store, $this->cursor, 'notifier', timeout: 600, start: Consumer::START_TIP);

        $started = \microtime(true);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started, 'Must actually wait');
    }

    public function testTipStartOnAnEmptyFeedDeliversWhatLandsMidWait(): void
    {
        $consumer = new Consumer(new MidPollStore('edge'), $this->cursor, 'notifier', timeout: 5_000, start: Consumer::START_TIP);

        $seen = [];
        $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(['landed'], $seen);
    }

    public function testResetReplaysEverythingStillRetained(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertNull($this->cursor->load('edge', 'invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer));
    }

    public function testSeekPositionsTheNextRunStrictlyAfterTheGivenId(): void
    {
        $this->producer->produce('a');
        $second = $this->producer->produce('b');
        $this->producer->produce('c');

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
        $this->producer->produce('a');
        $second = $this->producer->produce('b');
        $this->producer->produce('c');

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
        $this->producer->produce('poison');
        $this->producer->produce('after');

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
     * A hand-made move decided during a run is the newer decision, so the run
     * must not save its own progress over it on the way out.
     */
    public function testASeekMadeInsideAHandlerIsNotOverwritten(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');
        $third = $this->producer->produce('c');

        $consumer = $this->consumer(batch: 1);
        $consumer->consume(function (CloudEvent $event) use ($consumer, $third): void {
            $consumer->seek($third);
        });

        $this->assertSame($third, $consumer->position());
        $this->assertSame($third, $this->cursor->load('edge', 'invalidator'));
        $this->assertSame([], $this->drain($consumer), 'The run resumes after the seeked id, not after the handled one');
    }

    public function testAResetMadeInsideAHandlerIsNotOverwritten(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer();
        $consumer->consume(function (CloudEvent $event) use ($consumer): void {
            if ($event->type === 'b') {
                $consumer->reset();
            }
        });

        $this->assertNull($consumer->position());
        $this->assertNull($this->cursor->load('edge', 'invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer), 'The reset stands, so everything retained replays');
    }

    /**
     * @dataProvider notPositions
     */
    public function testSeekRejectsAnIdThatIsNotAPosition(string $id): void
    {
        $first = $this->producer->produce('a');
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

    public function testConsumersOfTheSameFeedTrackSeparatePositions(): void
    {
        $this->producer->produce('a');

        $one = new Consumer($this->store, $this->cursor, 'one');
        $two = new Consumer($this->store, $this->cursor, 'two');

        $this->assertSame(1, $one->consume(fn (CloudEvent $event) => null));
        $this->assertSame(1, $two->consume(fn (CloudEvent $event) => null), 'The second consumer has its own position');
        $this->assertSame(0, $one->consume(fn (CloudEvent $event) => null));
    }

    /**
     * The other half of that rule, pinned because it is the boundary the
     * design draws rather than an accident: a name is one logical reader, so
     * two processes behind one name split the feed instead of both seeing it.
     */
    public function testTwoConsumersSharingANameSplitTheFeed(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $one = $this->consumer(batch: 1);
        $two = $this->consumer(batch: 1);

        $this->assertSame(['a'], $this->drain($one));
        $this->assertSame(['b'], $this->drain($two), 'The second picks up after the first, it does not see a of its own');
    }

    /**
     * And the cost of sharing a name, which no amount of coordination inside a
     * single process can remove: each save is last-writer-wins, so a replica
     * holding an older position drags the shared one backwards when it saves.
     * At-least-once makes that a replay rather than a loss — the same is true of
     * a reset one replica performs and another then recreates — but it is why
     * every consumer gets its own name.
     */
    public function testAStaleConsumerSharingANameDragsThePositionBackwards(): void
    {
        $first = $this->producer->produce('a');
        $second = $this->producer->produce('b');

        $stale = $this->consumer(batch: 1);
        $this->assertNull($stale->position(), 'Reads the shared position before the other replica moves it');

        $ahead = $this->consumer();
        $this->assertSame(['a', 'b'], $this->drain($ahead));
        $this->assertSame($second, $this->cursor->load('edge', 'invalidator'));

        $this->assertSame(['a'], $this->drain($stale), 'The stale replica polls from where it thought it was');
        $this->assertSame($first, $this->cursor->load('edge', 'invalidator'), 'Its save wins, so the shared position regresses');
    }

    public function testPositionIsNullBeforeTheFirstRun(): void
    {
        $this->assertNull($this->consumer()->position());
    }

    public function testRejectsAnEmptyConsumerName(): void
    {
        $this->expectException(Invalid::class);

        new Consumer($this->store, $this->cursor, '');
    }

    public function testExposesItsName(): void
    {
        $this->assertSame('invalidator', $this->consumer()->getName());
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

    /**
     * The failure a consumer must not turn into a gap: if the read itself
     * fails, nothing is handled and nothing is committed.
     */
    public function testAFailedReadLeavesThePositionAlone(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->cursor->save('edge', 'invalidator', $first);

        $consumer = new Consumer(new \Utopia\Feed\Store\None('edge'), $this->cursor, 'invalidator');

        $this->expectException(\Utopia\Feed\Exception\Unsupported::class);

        try {
            $consumer->consume(fn (CloudEvent $event) => null);
        } finally {
            $this->assertSame($first, $this->cursor->load('edge', 'invalidator'));
        }
    }
}
