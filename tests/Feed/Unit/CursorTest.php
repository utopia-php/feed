<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Cursor\None;
use Utopia\Feed\Exception\Invalid;

/**
 * The cursor that deliberately remembers nothing. The real cursor adapters
 * are exercised through {@see \Utopia\Tests\Consumer\Base}; None cannot be —
 * a consumer over it replays forever, which is its point.
 */
class CursorTest extends TestCase
{
    public function testTheNoneStoreRemembersNothing(): void
    {
        $cursor = new None();

        $cursor->save('edge', 'invalidator', '1-0');

        $this->assertNull($cursor->load('edge', 'invalidator'), 'Nothing is stored, so nothing comes back');
    }

    public function testResettingTheNoneStoreIsHarmless(): void
    {
        $cursor = new None();

        $cursor->reset('edge', 'invalidator');

        $this->assertNull($cursor->load('edge', 'invalidator'));
    }

    /**
     * A stand-in that accepted names a real store rejects would let a bug
     * through in development and surface it in production instead.
     *
     * @return array<string, array{string, string}>
     */
    public static function unusableNames(): array
    {
        return [
            'no feed' => ['', 'invalidator'],
            'no consumer' => ['edge', ''],
        ];
    }

    /**
     * @dataProvider unusableNames
     */
    public function testTheNoneStoreStillRejectsEmptyNames(string $feed, string $consumer): void
    {
        $this->expectException(Invalid::class);

        (new None())->load($feed, $consumer);
    }
}
