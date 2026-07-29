<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

final class Id
{
    private const string PATTERN = '/^(\d+)-(\d+)$/';

    public static function isValid(string $id): bool
    {
        return \preg_match(self::PATTERN, $id) === 1;
    }

    public static function encode(int $timestamp, int $sequence): string
    {
        return $timestamp . '-' . $sequence;
    }

    /** @return array{int, int} */
    public static function decode(string $id): array
    {
        if (\preg_match(self::PATTERN, $id, $matches) !== 1) {
            throw new Invalid('Invalid feed event id: ' . $id);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    public static function after(string $id): string
    {
        [$timestamp, $sequence] = self::decode($id);

        return self::encode($timestamp, $sequence + 1);
    }
}
