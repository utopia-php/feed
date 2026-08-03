<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Redis as RedisCursor;
use Utopia\Feed\Exception\Transport;
use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

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
     * The cursor's half of the `Transport` contract. `FailingCursor` shows how
     * a consumer reacts to a `Transport`, but it raises one itself — the
     * `\RedisException` wrapping this adapter does was never run under test,
     * so a regression letting the raw exception out would have shipped green.
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

    /**
     * And the consumer's own contract on top of it: a position that cannot be
     * read stops the run, since reading from an unknown position would replay
     * the retained feed rather than report the failure.
     */
    public function testAConsumerOverAnUnreachableCursorStopsWithTransport(): void
    {
        $this->producer->produce('a');

        $consumer = new Consumer($this->store, new RedisCursor(self::unreachableRedis()), 'invalidator', feed: $this->name);

        $this->expectException(Transport::class);

        $consumer->consume(fn (CloudEvent $event) => null);
    }
}
