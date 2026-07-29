<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * Feed positions: `<milliseconds>-<sequence>`, Redis' stream id format, where
 * the sequence disambiguates events appended within the same millisecond.
 *
 * The format is part of the wire contract, not a Redis implementation detail —
 * an id produced by one journal has to be a valid position for another, so a
 * feed can move between backends without invalidating the positions consumers
 * already hold.
 */
final class Id
{
    private const string PATTERN = '/^(\d+)-(\d+)$/';

    /**
     * Whether $id is a feed position.
     */
    public static function isValid(string $id): bool
    {
        return \preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * Build an id from its parts.
     */
    public static function encode(int $timestamp, int $sequence): string
    {
        return $timestamp . '-' . $sequence;
    }

    /**
     * Split an id into its millisecond timestamp and sequence number, which
     * compare in feed order (`10-0` is after `9-0`, not before it).
     *
     * @return array{int, int}
     * @throws Invalid When $id is not a feed position.
     */
    public static function decode(string $id): array
    {
        if (\preg_match(self::PATTERN, $id, $matches) !== 1) {
            throw new Invalid('Invalid feed event id: ' . $id);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * The exclusive successor of an id: the smallest position strictly after it.
     *
     * Computed rather than relying on Redis' `(`-prefixed exclusive ranges, so
     * reads work against anything speaking the Redis 5 stream API — including
     * the proxies and compatible servers that never implemented the newer
     * syntax.
     *
     * @throws Invalid When $id is not a feed position.
     */
    public static function after(string $id): string
    {
        [$timestamp, $sequence] = self::decode($id);

        return self::encode($timestamp, $sequence + 1);
    }
}
