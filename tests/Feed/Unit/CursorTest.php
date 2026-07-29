<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Cache;
use Utopia\Feed\Cursor\Memory;
use Utopia\Feed\Exception\Invalid;

class CursorTest extends TestCase
{
    /**
     * @return array<string, array{Cursor}>
     */
    public static function stores(): array
    {
        return [
            'memory' => [new Memory('edge')],
            'cache' => [new Cache(new UtopiaCache(new CacheMemory()), 'edge')],
        ];
    }

    /**
     * @dataProvider stores
     */
    public function testAnUnknownConsumerHasNoPosition(Cursor $cursor): void
    {
        $this->assertNull($cursor->load('never-run'));
    }

    /**
     * @dataProvider stores
     */
    public function testRoundTripsAPosition(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1690000000000-0');

        $this->assertSame('1690000000000-0', $cursor->load('invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testOverwritesAPosition(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1-0');
        $cursor->save('invalidator', '2-0');

        $this->assertSame('2-0', $cursor->load('invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testConsumersDoNotShareAPosition(Cursor $cursor): void
    {
        $cursor->save('one', '1-0');
        $cursor->save('two', '2-0');

        $this->assertSame('1-0', $cursor->load('one'));
        $this->assertSame('2-0', $cursor->load('two'));
    }

    /**
     * Two processes running the same consumer overlap during a rolling
     * restart, finish batches of different lengths, and write out of order.
     * Without this the older position lands last and a later restart replays
     * everything between the two.
     *
     * @dataProvider stores
     */
    public function testAPositionNeverMovesBackwards(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1690000000000-5');
        $cursor->save('invalidator', '1690000000000-2');

        $this->assertSame('1690000000000-5', $cursor->load('invalidator'));
    }

    /**
     * Positions are compared by their parts, not as strings — `10-0` is later
     * than `9-0` but sorts before it, so a string comparison would reject a
     * legitimate advance and stall the consumer permanently.
     *
     * @dataProvider stores
     */
    public function testAdvancingAcrossADigitBoundaryIsNotMistakenForGoingBackwards(Cursor $cursor): void
    {
        $cursor->save('invalidator', '9-0');
        $cursor->save('invalidator', '10-0');

        $this->assertSame('10-0', $cursor->load('invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testRewritingTheSamePositionIsAccepted(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1-0');
        $cursor->save('invalidator', '1-0');

        $this->assertSame('1-0', $cursor->load('invalidator'));
    }

    /**
     * The guard must never become a reason a position stops moving forwards.
     *
     * @dataProvider stores
     */
    public function testAStoredValueThatIsNotAPositionIsReplaced(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1-0');
        $cursor->reset('invalidator');

        // Whatever a hand-edited or corrupted store hands back, real progress
        // must still be able to overwrite it.
        $cursor->save('invalidator', '2-0');

        $this->assertSame('2-0', $cursor->load('invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testResetForgetsAPosition(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1-0');
        $cursor->reset('invalidator');

        $this->assertNull($cursor->load('invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testSavingAnEmptyPositionIsIgnored(Cursor $cursor): void
    {
        $cursor->save('invalidator', '1-0');
        $cursor->save('invalidator', '');

        $this->assertSame('1-0', $cursor->load('invalidator'), 'An empty position must not erase a real one');
    }

    /**
     * @dataProvider stores
     */
    public function testRejectsAnEmptyConsumerName(Cursor $cursor): void
    {
        $this->expectException(Invalid::class);

        $cursor->load('');
    }

    public function testFeedsDoNotShareAPosition(): void
    {
        $cache = new UtopiaCache(new CacheMemory());

        (new Cache($cache, 'edge'))->save('invalidator', '1-0');

        $this->assertNull((new Cache($cache, 'other'))->load('invalidator'));
    }

    public function testRejectsAnEmptyFeedName(): void
    {
        $this->expectException(Invalid::class);

        new Memory('');
    }

    public function testExposesTheFeedItTracks(): void
    {
        $this->assertSame('edge', (new Memory('edge'))->getFeed());
    }
}
