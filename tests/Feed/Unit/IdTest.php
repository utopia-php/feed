<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Id;

class IdTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function ids(): array
    {
        return [
            'well formed' => ['1690000000000-0', true],
            'high sequence' => ['1690000000000-42', true],
            'zero' => ['0-0', true],
            'empty' => ['', false],
            'no sequence' => ['1690000000000', false],
            'not numeric' => ['abc-0', false],
            'negative' => ['-1-0', false],
            'trailing dash' => ['1690000000000-', false],
            'exclusive syntax' => ['(1690000000000-0', false],
            'range token' => ['-', false],
        ];
    }

    /**
     * @dataProvider ids
     */
    public function testValidatesIds(string $id, bool $valid): void
    {
        $this->assertSame($valid, Id::isValid($id));
    }

    public function testAfterIsTheNextSequenceInTheSameMillisecond(): void
    {
        $this->assertSame('1690000000000-1', Id::after('1690000000000-0'));
        $this->assertSame('1690000000000-43', Id::after('1690000000000-42'));
    }

    public function testAfterRejectsAnIdThatIsNotAPosition(): void
    {
        $this->expectException(Invalid::class);

        Id::after('not-an-id');
    }

    public function testDecodeSplitsAnIdIntoItsParts(): void
    {
        $this->assertSame([1690000000000, 7], Id::decode('1690000000000-7'));
    }

    public function testEncodeAndDecodeAreInverses(): void
    {
        $this->assertSame([12, 34], Id::decode(Id::encode(12, 34)));
    }

    /**
     * The reason ids are compared by parts rather than as strings: `10-0`
     * sorts before `9-0` lexically, which would make a consumer treat a newer
     * event as one it had already passed.
     */
    public function testComparesNumericallyNotLexically(): void
    {
        $this->assertSame(1, Id::compare('10-0', '9-0'));
        $this->assertSame(-1, Id::compare('9-0', '10-0'));
        $this->assertSame(0, Id::compare('10-0', '10-0'));
        $this->assertSame(1, Id::compare('10-2', '10-1'));
    }

    public function testCompareRejectsAnIdThatIsNotAPosition(): void
    {
        $this->expectException(Invalid::class);

        Id::compare('1-0', 'nope');
    }
}
