<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Key;

/**
 * The keys a feed and a cursor occupy in one backend keyspace. Names come
 * from configuration and from third-party feeds, so the mapping has to stay
 * injective for names nobody vetted.
 */
class KeyTest extends TestCase
{
    /**
     * The layout the README documents and an operator reads from a shell —
     * unchanged for every name anyone would actually pick, which is what makes
     * escaping cheap enough to always do.
     */
    public function testAnOrdinaryNameIsLeftAlone(): void
    {
        $this->assertSame('feed:edge', Key::feed('edge'));
        $this->assertSame('feed:edge:cursor:invalidator', Key::cursor('edge', 'invalidator'));
    }

    /**
     * The collision that corrupts data rather than losing it: the stream key
     * of a feed named `edge:cursor:x` used to be the cursor key of consumer
     * `x` on feed `edge`, so a cursor `SET` landed on an `XADD` stream.
     */
    public function testAFeedNameCannotCollideWithACursorKey(): void
    {
        $this->assertNotSame(Key::feed('edge:cursor:x'), Key::cursor('edge', 'x'));
    }

    /**
     * And the collision that silently shares one position between two
     * unrelated consumers — the "two processes sharing a name" hazard the
     * README warns about, arrived at without anyone sharing a name.
     */
    public function testTwoDistinctPairsCannotShareACursorKey(): void
    {
        $this->assertNotSame(Key::cursor('a:cursor:b', 'c'), Key::cursor('a', 'b:cursor:c'));
    }

    /**
     * Escaping is only injective if the escape character is escaped too:
     * without that, `a:b` and `a%3Ab` would map to the same key and the fix
     * would just move the collision somewhere less obvious.
     */
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

    /**
     * @dataProvider names
     */
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
