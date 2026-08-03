<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Invalid;
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

    /**
     * Whether the feed under test mints the positions it pages by, and so is
     * the authority on their shape. A local store is; another producer's feed,
     * read over HTTP, is not.
     */
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

    /**
     * @dataProvider noPositions
     */
    public function testSeekRejectsAnIdNoFeedCouldUse(string $id): void
    {
        $this->assertSeekRejected($id);
    }

    /**
     * Rejected whatever the feed is: an empty string names nothing, and the
     * tip sentinel stands for wherever the feed ends when the request arrives
     * — a start rather than a position.
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

    /**
     * @dataProvider notPositions
     */
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
     * And the cost of sharing a name, which no amount of coordination inside a
     * single process can remove: each save is last-writer-wins, so a replica
     * holding an older position drags the shared one backwards when it saves.
     * At-least-once makes that a replay rather than a loss — but it is why
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
        $this->assertSame($second, $this->cursor->load($this->name, 'invalidator'));

        $this->assertSame(['a'], $this->drain($stale), 'The stale replica polls from where it thought it was');
        $this->assertSame($first, $this->cursor->load($this->name, 'invalidator'), 'Its save wins, so the shared position regresses');
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
