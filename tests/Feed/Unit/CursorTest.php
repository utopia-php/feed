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
