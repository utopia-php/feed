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
            'memory' => [new Memory()],
            'cache' => [new Cache(new UtopiaCache(new CacheMemory()))],
        ];
    }

    /**
     * @dataProvider stores
     */
    public function testAnUnknownConsumerHasNoPosition(Cursor $cursor): void
    {
        $this->assertNull($cursor->load('edge', 'never-run'));
    }

    /**
     * @dataProvider stores
     */
    public function testRoundTripsAPosition(Cursor $cursor): void
    {
        $cursor->save('edge', 'invalidator', '1690000000000-0');

        $this->assertSame('1690000000000-0', $cursor->load('edge', 'invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testOverwritesAPosition(Cursor $cursor): void
    {
        $cursor->save('edge', 'invalidator', '1-0');
        $cursor->save('edge', 'invalidator', '2-0');

        $this->assertSame('2-0', $cursor->load('edge', 'invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testConsumersDoNotShareAPosition(Cursor $cursor): void
    {
        $cursor->save('edge', 'one', '1-0');
        $cursor->save('edge', 'two', '2-0');

        $this->assertSame('1-0', $cursor->load('edge', 'one'));
        $this->assertSame('2-0', $cursor->load('edge', 'two'));
    }

    /**
     * One store serves every feed a service consumes, which is why the feed
     * name is part of the key rather than of the cursor.
     *
     * @dataProvider stores
     */
    public function testFeedsDoNotShareAPosition(Cursor $cursor): void
    {
        $cursor->save('edge', 'invalidator', '1-0');

        $this->assertNull($cursor->load('other', 'invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testResetForgetsAPosition(Cursor $cursor): void
    {
        $cursor->save('edge', 'invalidator', '1-0');
        $cursor->reset('edge', 'invalidator');

        $this->assertNull($cursor->load('edge', 'invalidator'));
    }

    /**
     * @dataProvider stores
     */
    public function testRejectsAnEmptyConsumerName(Cursor $cursor): void
    {
        $this->expectException(Invalid::class);

        $cursor->load('edge', '');
    }

    /**
     * @dataProvider stores
     */
    public function testRejectsAnEmptyFeedName(Cursor $cursor): void
    {
        $this->expectException(Invalid::class);

        $cursor->save('', 'invalidator', '1-0');
    }
}
