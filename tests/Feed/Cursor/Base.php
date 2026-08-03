<?php

declare(strict_types=1);

namespace Utopia\Tests\Cursor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Invalid;

/**
 * The cursor contract at its own API, run against the adapter the subclass
 * provides: a position is remembered, keyed by feed *and* consumer, forgotten
 * on reset, and every operation refuses a name it cannot key.
 *
 * Most of this is also visible through {@see \Utopia\Tests\Consumer\Base},
 * which runs every cursor adapter — but not all of it. `Consumer` validates
 * its own name before the cursor is ever touched, so the adapters' `Invalid`
 * path is unreachable from there, and nothing else checks that each adapter
 * routes all three operations through the shared {@see Cursor::key()} rather
 * than building a key of its own. An adapter that did would skip the
 * validation and diverge from the documented key layout at the same time.
 */
abstract class Base extends TestCase
{
    protected string $name;

    protected Cursor $cursor;

    /** The cursor adapter under test. */
    abstract protected function cursor(): Cursor;

    protected function setUp(): void
    {
        // A fresh feed per test: cursors are keyed by feed and consumer name,
        // and a shared backend (a real Redis) would leak positions between tests.
        $this->name = 'test-' . \bin2hex(\random_bytes(8));
        $this->cursor = $this->cursor();
    }

    public function testAConsumerThatHasNeverRunHasNoPosition(): void
    {
        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
    }

    public function testAPositionComesBackAsItWasSaved(): void
    {
        $this->cursor->save($this->name, 'invalidator', '1690000000000-7');

        $this->assertSame('1690000000000-7', $this->cursor->load($this->name, 'invalidator'));
    }

    /**
     * Saving is how a consumer advances, so it happens on every run — the last
     * one has to win rather than the first being kept.
     */
    public function testSavingAgainMovesThePosition(): void
    {
        $this->cursor->save($this->name, 'invalidator', '1-0');
        $this->cursor->save($this->name, 'invalidator', '2-0');

        $this->assertSame('2-0', $this->cursor->load($this->name, 'invalidator'));
    }

    public function testConsumersOfOneFeedDoNotShareAPosition(): void
    {
        $this->cursor->save($this->name, 'one', '1-0');
        $this->cursor->save($this->name, 'two', '2-0');

        $this->assertSame('1-0', $this->cursor->load($this->name, 'one'));
        $this->assertSame('2-0', $this->cursor->load($this->name, 'two'));
    }

    /**
     * One cursor store serves every feed a service consumes, so the feed name
     * is part of the key — the same consumer name on another feed is another
     * position entirely.
     */
    public function testTheSameConsumerNameOnAnotherFeedIsAnotherPosition(): void
    {
        $this->cursor->save($this->name, 'invalidator', '1-0');
        $this->cursor->save($this->name . '-other', 'invalidator', '2-0');

        $this->assertSame('1-0', $this->cursor->load($this->name, 'invalidator'));
        $this->assertSame('2-0', $this->cursor->load($this->name . '-other', 'invalidator'));
    }

    public function testResetForgetsThePosition(): void
    {
        $this->cursor->save($this->name, 'invalidator', '1-0');
        $this->cursor->reset($this->name, 'invalidator');

        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
    }

    /** Resetting a consumer that never saved anything is the ordinary case. */
    public function testResettingAPositionThatWasNeverSavedIsHarmless(): void
    {
        $this->cursor->reset($this->name, 'invalidator');

        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
    }

    public function testResetOnlyForgetsTheConsumerItNames(): void
    {
        $this->cursor->save($this->name, 'one', '1-0');
        $this->cursor->save($this->name, 'two', '2-0');

        $this->cursor->reset($this->name, 'one');

        $this->assertNull($this->cursor->load($this->name, 'one'));
        $this->assertSame('2-0', $this->cursor->load($this->name, 'two'), 'The other consumer is untouched');
    }

    /**
     * A name that cannot be keyed is refused by every operation, not only by
     * whichever one a caller happens to reach first. This is the assertion
     * that says the adapter goes through the shared key builder at all —
     * `Consumer` validates its own name long before the cursor sees it, so
     * nothing else here can.
     *
     * @param callable(Cursor, string, string): void $operation
     */
    #[DataProvider('operationsAndNames')]
    public function testEveryOperationRefusesAnUnusableName(callable $operation, string $feed, string $consumer): void
    {
        $this->expectException(Invalid::class);

        $operation($this->cursor, $feed, $consumer);
    }

    /**
     * @return array<string, array{callable(Cursor, string, string): void, string, string}>
     */
    public static function operationsAndNames(): array
    {
        $operations = [
            'load' => static function (Cursor $cursor, string $feed, string $consumer): void {
                $cursor->load($feed, $consumer);
            },
            'save' => static function (Cursor $cursor, string $feed, string $consumer): void {
                $cursor->save($feed, $consumer, '1-0');
            },
            'reset' => static function (Cursor $cursor, string $feed, string $consumer): void {
                $cursor->reset($feed, $consumer);
            },
        ];

        $names = [
            'no feed' => ['', 'invalidator'],
            'no consumer' => ['edge', ''],
            'neither' => ['', ''],
        ];

        $cases = [];

        foreach ($operations as $operation => $callable) {
            foreach ($names as $name => [$feed, $consumer]) {
                $cases["{$operation} with {$name}"] = [$callable, $feed, $consumer];
            }
        }

        return $cases;
    }
}
