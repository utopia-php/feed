<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Adapter\Memory as MemoryAdapter;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Feed;
use Utopia\Tests\Unit\Support\FailingCursor;

class ConsumerTest extends TestCase
{
    private MemoryAdapter $adapter;

    private Feed $feed;

    private MemoryCursor $cursor;

    protected function setUp(): void
    {
        $this->adapter = new MemoryAdapter('edge');
        $this->feed = new Feed($this->adapter, 'urn:test');
        $this->cursor = new MemoryCursor('edge');
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
        $count = $consumer->consume(function (Event $event) use (&$seen): void {
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
        $this->assertSame($last, $this->cursor->load('invalidator'));
        $this->assertSame($last, $consumer->position());
    }

    public function testCaughtUpConsumerDoesNothing(): void
    {
        $this->feed->append('a');

        $consumer = $this->consumer();
        $consumer->consume(fn (Event $event) => null);

        $this->assertSame(0, $consumer->consume(fn (Event $event) => null));
    }

    public function testResumesFromTheStoredPosition(): void
    {
        $first = $this->feed->append('a');
        $this->feed->append('b');

        $this->cursor->save('invalidator', $first);

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

        $cursor = new class ('edge') extends MemoryCursor {
            public int $loads = 0;

            public function load(string $consumer): ?string
            {
                $this->loads++;

                return parent::load($consumer);
            }
        };

        $consumer = $this->consumer($cursor);

        $consumer->consume(fn (Event $event) => null);
        $consumer->consume(fn (Event $event) => null);
        $consumer->consume(fn (Event $event) => null);

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
            $consumer->consume(function (Event $event) use (&$seen): void {
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
        $this->assertSame($first, $this->cursor->load('invalidator'), 'Progress before the failure is committed');
    }

    public function testRetriesTheFailedEventOnTheNextRun(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $consumer = $this->consumer();
        $attempts = 0;

        try {
            $consumer->consume(function (Event $event) use (&$attempts): void {
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
            $this->consumer()->consume(fn (Event $event) => throw new \RuntimeException('nope'));
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNull($this->cursor->load('invalidator'));
    }

    public function testAHandlerThatAcceptsEverythingCountsEveryEvent(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');
        $this->feed->append('c');

        $this->assertSame(3, $this->consumer()->consume(fn (Event $event) => null));
    }

    public function testDrainsABacklogInBatches(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->feed->append('event-' . $i);
        }

        $consumer = $this->consumer(batch: 4);

        $this->assertSame(4, $consumer->consume(fn (Event $event) => null));
        $this->assertSame(4, $consumer->consume(fn (Event $event) => null));
        $this->assertSame(2, $consumer->consume(fn (Event $event) => null));
        $this->assertSame(0, $consumer->consume(fn (Event $event) => null));
    }

    /**
     * The store failing must not stop the work: the position is mirrored in
     * memory, so the run carries on and only a restart before the store
     * recovers replays anything.
     */
    public function testKeepsWorkingWhenThePositionCannotBeLoaded(): void
    {
        $this->feed->append('a');

        $consumer = $this->consumer(new FailingCursor('edge', onLoad: true));
        $warnings = [];
        $consumer->onWarning(function (\Throwable $error, string $context) use (&$warnings): void {
            $warnings[] = $context;
        });

        $this->assertSame(['a'], $this->drain($consumer));
        $this->assertSame(['load'], $warnings);
    }

    public function testKeepsWorkingWhenThePositionCannotBeSaved(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $consumer = $this->consumer(new FailingCursor('edge', onSave: true));
        $warnings = [];
        $consumer->onWarning(function (\Throwable $error, string $context) use (&$warnings): void {
            $warnings[] = $context;
        });

        $this->assertSame(['a', 'b'], $this->drain($consumer));
        $this->assertSame(['save'], $warnings);

        $this->feed->append('c');

        $this->assertSame(['c'], $this->drain($consumer), 'The in-memory position still moved');
    }

    public function testSurvivesAFailingStoreWithNoWarningHandler(): void
    {
        $this->feed->append('a');

        $this->assertSame(['a'], $this->drain($this->consumer(new FailingCursor('edge', onLoad: true, onSave: true))));
    }

    public function testResetReplaysEverythingStillRetained(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $consumer = $this->consumer();
        $consumer->consume(fn (Event $event) => null);

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertNull($this->cursor->load('invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer));
    }

    public function testConsumersOfTheSameFeedTrackSeparatePositions(): void
    {
        $this->feed->append('a');

        $one = new Consumer($this->feed, 'one', $this->cursor);
        $two = new Consumer($this->feed, 'two', $this->cursor);

        $this->assertSame(1, $one->consume(fn (Event $event) => null));
        $this->assertSame(1, $two->consume(fn (Event $event) => null), 'The second consumer has its own position');
        $this->assertSame(0, $one->consume(fn (Event $event) => null));
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

    public function testExposesWhatItIsConsuming(): void
    {
        $consumer = $this->consumer();

        $this->assertSame('invalidator', $consumer->getName());
        $this->assertSame($this->feed, $consumer->getFeed());
    }

    /**
     * The failure a consumer must not turn into a gap: if the read itself
     * fails, nothing is handled and nothing is committed.
     */
    public function testAFailedReadLeavesThePositionAlone(): void
    {
        $first = $this->feed->append('a');
        $this->feed->append('b');
        $this->cursor->save('invalidator', $first);

        $consumer = new Consumer(new Feed(new \Utopia\Feed\Adapter\None('edge')), 'invalidator', $this->cursor);

        $this->expectException(\Utopia\Feed\Exception\Unsupported::class);

        try {
            $consumer->consume(fn (Event $event) => null);
        } finally {
            $this->assertSame($first, $this->cursor->load('invalidator'));
        }
    }
}
