<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Outcome;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Memory as MemoryStore;
use Utopia\Tests\Support\MidPollStore;

/**
 * Everything a consumer promises — delivery, ordering, failure handling,
 * position management — run against the cursor adapter the subclass provides,
 * and through the source the subclass connects: a local store by default, or
 * the HTTP contract when {@see Base::source()} is overridden.
 *
 * The feed itself is the simplest store there is (memory), so a failure here
 * is the consuming side's, not the store's.
 */
abstract class Base extends TestCase
{
    protected string $name;

    protected Store&Appendable $store;

    protected Producer $producer;

    protected Cursor $cursor;

    /** The cursor adapter under test — where this consumer keeps its position. */
    abstract protected function cursor(): Cursor;

    /**
     * How the consumer reaches the feed. A local store by default; the HTTP
     * adapter wraps the same store in a real served endpoint.
     */
    protected function source(Store&Appendable $store): Adapter|Readable
    {
        return $store;
    }

    protected function setUp(): void
    {
        // A fresh feed per test: cursors are keyed by feed and consumer name,
        // and a shared backend (a real Redis) would leak positions between tests.
        $this->name = 'test-' . \bin2hex(\random_bytes(8));
        $this->store = new MemoryStore($this->name);
        $this->producer = new Producer($this->store, 'urn:test');
        $this->cursor = $this->cursor();
    }

    protected function consumer(
        string $name = 'invalidator',
        int $batch = Consumer::BATCH,
        int $timeout = 0,
        string $start = Consumer::START_OLDEST,
        (Store&Appendable)|null $store = null,
    ): Consumer {
        $store ??= $this->store;

        return new Consumer($this->source($store), $this->cursor, $name, feed: $store->getName(), batch: $batch, timeout: $timeout, start: $start);
    }

    /**
     * @param-out int $count
     * @return list<string>
     */
    protected function drain(Consumer $consumer, ?int &$count = null): array
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
        $this->assertSame($last, $this->cursor->load($this->name, 'invalidator'));
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

        $this->cursor->save($this->name, 'invalidator', $first);

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

    public function testTheConsumerOnlyEverSeesEachEventOnce(): void
    {
        foreach (\range(1, 5) as $i) {
            $this->producer->produce('event-' . $i);
        }

        $consumer = $this->consumer();
        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $consumer->consume($handler);
        $consumer->consume($handler);

        $this->producer->produce('event-6');
        $consumer->consume($handler);

        $this->assertSame(
            ['event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'],
            $seen,
        );
    }

    /**
     * The restart property: a fresh Consumer — no in-memory state, same name,
     * same cursor store — picks the persisted position up rather than
     * replaying or skipping.
     */
    public function testARestartedConsumerResumesWhereItLeftOff(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->consumer()->consume(fn (CloudEvent $event) => null);

        $this->producer->produce('c');

        $this->assertSame(['c'], $this->drain($this->consumer()));
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
        $this->assertSame($first, $this->cursor->load($this->name, 'invalidator'), 'Progress before the failure is committed');
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

        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
    }

    public function testAFailedEventBlocksTheOnesBehindItUntilItSucceeds(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('poison');
        $this->producer->produce('c');

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

    /** Retry is throwing without the exception: same position, calm return. */
    public function testRetryStopsTheRunAndKeepsTheProgressBeforeIt(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->producer->produce('c');

        $consumer = $this->consumer();

        $count = $consumer->consume(fn (CloudEvent $event): ?Outcome => $event->type === 'b' ? Outcome::Retry : null);

        $this->assertSame(1, $count, 'Only what came before the retry is committed');
        $this->assertSame($first, $this->cursor->load($this->name, 'invalidator'), 'Progress before the retry is committed');
        $this->assertSame(['b', 'c'], $this->drain($consumer), 'The retried event comes back first, nothing behind it is lost');
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
     * The chunk's event types, for asserting delivery.
     *
     * @param list<CloudEvent> $events
     * @return list<string>
     */
    private static function types(array $events): array
    {
        return \array_map(static fn (CloudEvent $event): string => $event->type, $events);
    }

    public function testAChunkHandlerReceivesTheWholePollAndAdvancesPastIt(): void
    {
        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $consumer = $this->consumer();
        $chunks = [];

        $count = $consumer->consumeChunk(function (array $events) use (&$chunks): void {
            $chunks[] = self::types($events);
        });

        $this->assertSame(2, $count);
        $this->assertSame([['a', 'b']], $chunks, 'One call, the whole poll');
        $this->assertSame($last, $this->cursor->load($this->name, 'invalidator'));
        $this->assertSame($last, $consumer->position());
    }

    public function testChunksFollowTheBatchSetting(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->producer->produce('event-' . $i);
        }

        $consumer = $this->consumer(batch: 4);
        $sizes = [];
        $handler = function (array $events) use (&$sizes): void {
            $sizes[] = \count($events);
        };

        $this->assertSame(4, $consumer->consumeChunk($handler));
        $this->assertSame(4, $consumer->consumeChunk($handler));
        $this->assertSame(2, $consumer->consumeChunk($handler));
        $this->assertSame(0, $consumer->consumeChunk($handler));

        $this->assertSame([4, 4, 2], $sizes, 'A caught-up poll never reaches the handler');
    }

    public function testAChunkHandlerThatThrowsLeavesThePositionAlone(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer();

        try {
            $consumer->consumeChunk(fn (array $events) => throw new \RuntimeException('nope'));
            $this->fail('The handler failure should have been re-raised');
        } catch (\RuntimeException $error) {
            $this->assertSame('nope', $error->getMessage());
        }

        $this->assertNull($this->cursor->load($this->name, 'invalidator'), 'A failed chunk commits nothing');

        $seen = [];
        $consumer->consumeChunk(function (array $events) use (&$seen): void {
            $seen = self::types($events);
        });

        $this->assertSame(['a', 'b'], $seen, 'The whole chunk comes back');
    }

    public function testRetryRedeliversTheWholeChunkOnTheNextRun(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer();

        $this->assertSame(0, $consumer->consumeChunk(fn (array $events): Outcome => Outcome::Retry));
        $this->assertNull($this->cursor->load($this->name, 'invalidator'), 'Retry commits nothing');

        $seen = [];
        $count = $consumer->consumeChunk(function (array $events) use (&$seen): void {
            $seen = self::types($events);
        });

        $this->assertSame(2, $count);
        $this->assertSame(['a', 'b'], $seen);
    }

    /**
     * The partial-progress pattern: a chunk that failed midway seeks to the
     * last event it completed and asks for a retry, so only the remainder
     * comes back. The seek is the newer decision — the run must not save the
     * chunk's end over it.
     */
    public function testASeekMadeInsideAChunkHandlerIsNotOverwritten(): void
    {
        $this->producer->produce('a');
        $second = $this->producer->produce('b');
        $this->producer->produce('c');

        $consumer = $this->consumer();

        $consumer->consumeChunk(function (array $events) use ($consumer, $second): Outcome {
            $consumer->seek($second);

            return Outcome::Retry;
        });

        $this->assertSame($second, $consumer->position());
        $this->assertSame($second, $this->cursor->load($this->name, 'invalidator'));

        $seen = [];
        $consumer->consumeChunk(function (array $events) use (&$seen): void {
            $seen = self::types($events);
        });

        $this->assertSame(['c'], $seen, 'The run resumes after the seeked id, not before the chunk');
    }

    /** The moved guard, on the path where the chunk does try to save. */
    public function testAChunkRunDoesNotSaveOverASeekEvenWhenItContinues(): void
    {
        $this->producer->produce('a');
        $second = $this->producer->produce('b');
        $this->producer->produce('c');

        $consumer = $this->consumer();

        $consumer->consumeChunk(function (array $events) use ($consumer, $second): void {
            $consumer->seek($second);
        });

        $this->assertSame($second, $consumer->position(), 'The seek stands over the chunk\'s own end');
        $this->assertSame($second, $this->cursor->load($this->name, 'invalidator'));

        $seen = [];
        $consumer->consumeChunk(function (array $events) use (&$seen): void {
            $seen = self::types($events);
        });

        $this->assertSame(['c'], $seen);
    }

    public function testTipStartDoesNotAnnounceTheBacklog(): void
    {
        $this->producer->produce('old-1');
        $this->producer->produce('old-2');

        $consumer = $this->consumer('notifier', start: Consumer::START_TIP);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertNull($this->cursor->load($this->name, 'notifier'), 'Skipping the backlog is not progress to commit');
    }

    /**
     * The tip is pinned when the poll starts, so an event landing while the
     * poll waits is delivered — only the backlog is skipped.
     */
    public function testTipStartDeliversWhatLandsMidPoll(): void
    {
        $store = new MidPollStore($this->name);
        (new Producer($store, 'urn:test'))->produce('old');

        $consumer = $this->consumer('notifier', timeout: 5_000, start: Consumer::START_TIP, store: $store);

        $seen = [];
        $count = $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(1, $count);
        $this->assertSame(['landed'], $seen, 'The backlog is skipped; the mid-wait event is not');
        $this->assertNotNull($this->cursor->load($this->name, 'notifier'), 'Handling the event saves the position');
    }

    public function testTipStartOnAnEmptyFeedDeliversWhatLandsMidWait(): void
    {
        $consumer = $this->consumer('notifier', timeout: 5_000, start: Consumer::START_TIP, store: new MidPollStore($this->name));

        $seen = [];
        $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });

        $this->assertSame(['landed'], $seen);
    }

    public function testTipStartOnAnEmptyFeedWaitsOutTheTimeoutEmpty(): void
    {
        $consumer = $this->consumer('notifier', timeout: 600, start: Consumer::START_TIP);

        $started = \microtime(true);

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started, 'Must actually wait');
    }

    public function testAStoredCursorBeatsTipStart(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');

        $this->cursor->save($this->name, 'invalidator', $first);

        $consumer = $this->consumer(start: Consumer::START_TIP);

        $this->assertSame(['b'], $this->drain($consumer), 'A restart must not skip the gap');
    }

    public function testResetWithTipStartResumesFromNow(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->cursor->save($this->name, 'invalidator', $first);

        $consumer = $this->consumer(start: Consumer::START_TIP);

        $this->assertSame(['b'], $this->drain($consumer), 'The stored position still wins before the reset');

        $consumer->reset();

        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null), 'After reset, the backlog is forgotten');
    }

    public function testResetReplaysEverythingStillRetained(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $consumer = $this->consumer();
        $consumer->consume(fn (CloudEvent $event) => null);

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
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
     * cursor store and the name — a restart — resumes from it.
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
        $this->assertSame($third, $this->cursor->load($this->name, 'invalidator'));
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
        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer), 'The reset stands, so everything retained replays');
    }

    /** Whether the feed under test mints its own positions, and so judges their shape. */
    protected function ownsItsIdFormat(): bool
    {
        return true;
    }

    /** A rejected seek must leave both positions exactly where they were. */
    private function assertSeekRejected(string $id): void
    {
        $first = $this->producer->produce('a');
        $this->cursor->save($this->name, 'invalidator', $first);

        $consumer = $this->consumer();

        try {
            $consumer->seek($id);
            $this->fail('The id should have been rejected');
        } catch (Invalid) {
            // Expected.
        }

        $this->assertSame($first, $this->cursor->load($this->name, 'invalidator'), 'A rejected seek leaves the stored position untouched');
        $this->assertSame($first, $consumer->position());
    }

    #[DataProvider('noPositions')]
    public function testSeekRejectsAnIdNoFeedCouldUse(string $id): void
    {
        $this->assertSeekRejected($id);
    }

    /**
     * Rejected whatever the feed is: an empty string names nothing, and the
     * tip sentinel is a start rather than a position.
     *
     * @return array<string, array{string}>
     */
    public static function noPositions(): array
    {
        return [
            'empty' => [''],
            'the tip sentinel' => ['$'],
        ];
    }

    #[DataProvider('notPositions')]
    public function testSeekRejectsAnIdThatIsNotAPosition(string $id): void
    {
        if (!$this->ownsItsIdFormat()) {
            $this->markTestSkipped('Only the feed that mints its positions can judge their shape');
        }

        $this->assertSeekRejected($id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPositions(): array
    {
        return [
            'not an id' => ['abc'],
            'too many parts' => ['1-2-3'],
        ];
    }

    public function testConsumersOfTheSameFeedTrackSeparatePositions(): void
    {
        $this->producer->produce('a');

        $one = $this->consumer('one');
        $two = $this->consumer('two');

        $this->assertSame(1, $one->consume(fn (CloudEvent $event) => null));
        $this->assertSame(1, $two->consume(fn (CloudEvent $event) => null), 'The second consumer has its own position');
        $this->assertSame(0, $one->consume(fn (CloudEvent $event) => null));
    }

    /**
     * A cursor store serves every feed a service consumes, so the feed's name
     * is part of the key: the same consumer name on another feed is another
     * position entirely.
     */
    public function testTheSameConsumerNameOnAnotherFeedTracksItsOwnPosition(): void
    {
        $other = new MemoryStore($this->name . '-other');
        (new Producer($other, 'urn:test'))->produce('other-a');

        $this->producer->produce('a');

        $this->assertSame(['a'], $this->drain($this->consumer()));
        $this->assertSame(['other-a'], $this->drain($this->consumer(store: $other)), 'Draining one feed must not advance the other');
    }

    /**
     * The boundary the design draws rather than an accident: a name is one
     * logical reader, so two processes behind one name split the feed instead
     * of both seeing it.
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
     * And what sharing a name costs: a replica holding an older position
     * re-handles events it polls (at-least-once), but its save is conditional
     * on the position its run started from, so it cannot drag the shared
     * position backwards — it concedes to the replica that got ahead. Every
     * consumer still gets its own name; the fence bounds the damage, it does
     * not split the feed cleanly.
     */
    public function testAStaleConsumerSharingANameCannotDragThePositionBackwards(): void
    {
        $this->producer->produce('a');
        $second = $this->producer->produce('b');

        $stale = $this->consumer(batch: 1);
        $this->assertNull($stale->position(), 'Reads the shared position before the other replica moves it');

        $ahead = $this->consumer();
        $this->assertSame(['a', 'b'], $this->drain($ahead));
        $this->assertSame($second, $this->cursor->load($this->name, 'invalidator'));

        $this->assertSame(['a'], $this->drain($stale), 'The stale replica re-handles from where it thought it was');
        $this->assertSame($second, $this->cursor->load($this->name, 'invalidator'), 'Its save is refused, so the shared position holds');
    }

    public function testPositionIsNullBeforeTheFirstRun(): void
    {
        $this->assertNull($this->consumer()->position());
    }

    public function testRejectsAnEmptyConsumerName(): void
    {
        $this->expectException(Invalid::class);

        $this->consumer('');
    }

    public function testExposesItsName(): void
    {
        $this->assertSame('invalidator', $this->consumer()->getName());
    }
}
