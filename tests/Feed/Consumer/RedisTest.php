<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Redis as RedisCursor;
use Utopia\Feed\Appendable;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Producer;
use Utopia\Feed\Store;
use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

    /**
     * A feed on a real Redis stream, and a producer over it. The shared
     * scenarios use a memory feed, so the consumer's paging arithmetic is
     * otherwise never run against the ids `XADD` assigns.
     *
     * @return array{Store&Appendable, Producer}
     */
    private function stream(): array
    {
        $store = $this->store($this->name);

        return [$store, new Producer($store, 'urn:test')];
    }

    public function testConsumesAStreamThroughAPersistedCursor(): void
    {
        [$store, $producer] = $this->stream();

        $producer->produce('a');
        $last = $producer->produce('b');

        $this->assertSame(['a', 'b'], $this->drain($this->consumer(store: $store)));
        $this->assertSame($last, $this->cursor->load($this->name, 'invalidator'));

        $producer->produce('c');

        // A fresh Consumer over the same cursor store: a restart.
        $this->assertSame(['c'], $this->drain($this->consumer(store: $store)), 'Resumes without replaying');
    }

    /**
     * `XADD` bumps the sequence within a millisecond, so a batch boundary
     * regularly falls between two ids sharing a timestamp.
     */
    public function testPagesAStreamInBatchesWithoutSkippingOrRepeating(): void
    {
        [$store, $producer] = $this->stream();

        foreach (\range(1, 10) as $i) {
            $producer->produce('event-' . $i);
        }

        $consumer = $this->consumer(batch: 3, store: $store);

        $seen = [];
        foreach (\range(1, 4) as $ignored) {
            $seen = [...$seen, ...$this->drain($consumer)];
        }

        $this->assertSame(\array_map(static fn (int $i): string => 'event-' . $i, \range(1, 10)), $seen);
        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null), 'And then it is caught up');
    }

    public function testASecondConsumerOfTheSameStreamGetsItsOwnPosition(): void
    {
        [$store, $producer] = $this->stream();

        $producer->produce('a');

        $this->assertSame(['a'], $this->drain($this->consumer('one', store: $store)));
        $this->assertSame(['a'], $this->drain($this->consumer('two', store: $store)), 'The second reads it too');
        $this->assertSame([], $this->drain($this->consumer('one', store: $store)), 'The first stays caught up');
    }

    public function testResetReplaysWhatTheStreamStillRetains(): void
    {
        [$store, $producer] = $this->stream();

        $producer->produce('a');
        $producer->produce('b');

        $consumer = $this->consumer(store: $store);
        $this->drain($consumer);

        $consumer->reset();

        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
        $this->assertSame(['a', 'b'], $this->drain($consumer));
    }

    /**
     * The stored form is deliberately plain — the key is
     * `feed:<feed>:cursor:<consumer>` and the value the event id as a string —
     * so positions carry across upgrades and an operator can answer "where is
     * this consumer?" from a shell.
     */
    public function testThePositionIsStoredWhereOperatorsExpectIt(): void
    {
        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $this->drain($this->consumer());

        $this->assertSame($last, $this->redis()->get('feed:' . $this->name . ':cursor:invalidator'));
    }

    /**
     * The cursor's half of the `Transport` contract: `FailingCursor` raises
     * one itself, so the wrapping here is otherwise never run.
     *
     * @param callable(Cursor): void $operation
     */
    #[DataProvider('operations')]
    public function testABackendThatCannotBeReachedRaisesTransport(callable $operation): void
    {
        $cursor = new RedisCursor(self::unreachableRedis());

        $this->expectException(Transport::class);

        $operation($cursor);
    }

    /**
     * @return array<string, array{callable(Cursor): void}>
     */
    public static function operations(): array
    {
        return [
            'load' => [static function (Cursor $cursor): void {
                $cursor->load('edge', 'invalidator');
            }],
            'save' => [static function (Cursor $cursor): void {
                $cursor->save('edge', 'invalidator', '1-0');
            }],
            'reset' => [static function (Cursor $cursor): void {
                $cursor->reset('edge', 'invalidator');
            }],
        ];
    }

    /** A position that cannot be read stops the run rather than replaying the feed. */
    public function testAConsumerOverAnUnreachableCursorStopsWithTransport(): void
    {
        $this->producer->produce('a');

        $consumer = new Consumer($this->store, new RedisCursor(self::unreachableRedis()), 'invalidator', feed: $this->name);

        $this->expectException(Transport::class);

        $consumer->consume(fn (CloudEvent $event) => null);
    }
}
