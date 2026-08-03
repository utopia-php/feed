<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Feed\Key;

/**
 * The keys a feed and a cursor occupy in one keyspace. Names come from
 * configuration and from third-party feeds, so the mapping has to stay
 * injective for names nobody vetted.
 */
class KeyTest extends TestCase
{
    /** The layout the README documents, unchanged for any name anyone would pick. */
    public function testAnOrdinaryNameIsLeftAlone(): void
    {
        $this->assertSame('feed:edge', Key::feed('edge'));
        $this->assertSame('feed:edge:cursor:invalidator', Key::cursor('edge', 'invalidator'));
    }

    /** A feed named `edge:cursor:x` used to take consumer `x`'s cursor key on `edge`. */
    public function testAFeedNameCannotCollideWithACursorKey(): void
    {
        $this->assertNotSame(Key::feed('edge:cursor:x'), Key::cursor('edge', 'x'));
    }

    /** And the one that silently shares a position between two unrelated consumers. */
    public function testTwoDistinctPairsCannotShareACursorKey(): void
    {
        $this->assertNotSame(Key::cursor('a:cursor:b', 'c'), Key::cursor('a', 'b:cursor:c'));
    }

    /** Without escaping the escape character, `a:b` and `a%3Ab` would still collide. */
    public function testTheEscapeCharacterIsItselfEscaped(): void
    {
        $this->assertNotSame(Key::feed('a:b'), Key::feed('a%3Ab'));
        $this->assertNotSame(Key::cursor('a:b', 'c'), Key::cursor('a%3Ab', 'c'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return [
            'separator' => ['a:b'],
            'escape character' => ['a%b'],
            'both' => ['a%3A:b'],
            'the cursor infix' => ['edge:cursor:x'],
            'unicode' => ['ünïcøde'],
            'slashes' => ['a/b'],
        ];
    }

    #[DataProvider('names')]
    public function testEveryNameKeepsItsOwnKeys(string $name): void
    {
        $others = \array_column(self::names(), 0);

        foreach ($others as $other) {
            if ($other === $name) {
                continue;
            }

            $this->assertNotSame(Key::feed($other), Key::feed($name));
            $this->assertNotSame(Key::cursor($other, 'c'), Key::cursor($name, 'c'));
            $this->assertNotSame(Key::cursor('f', $other), Key::cursor('f', $name));
        }
    }
}
