<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * Feed positions.
 *
 * http-feeds requires event ids to be strictly ordered so a consumer can ask
 * for "everything after this one" with nothing but the last id it processed.
 * This library uses Redis' stream id format for them — `<milliseconds>-<seq>`,
 * where `seq` disambiguates events appended within the same millisecond.
 *
 * The format is part of the wire contract, not a Redis implementation detail:
 * an id produced by one journal has to be a valid position for another, so
 * that a feed can move between backends without invalidating the positions
 * consumers already hold.
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
     * Split an id into its millisecond timestamp and sequence number.
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
     * The exclusive successor of an id: the smallest position strictly after
     * it.
     *
     * Computed rather than relying on Redis' `(`-prefixed exclusive ranges, so
     * reads work against anything speaking the Redis 5 stream API — including
     * the several proxies and compatible servers that never implemented the
     * newer syntax.
     *
     * @throws Invalid When $id is not a feed position.
     */
    public static function after(string $id): string
    {
        [$timestamp, $sequence] = self::decode($id);

        return self::encode($timestamp, $sequence + 1);
    }

    /**
     * Compare two positions the way `<=>` would, so ids sort by age rather
     * than by string order (`10-0` is after `9-0`, not before it).
     *
     * @throws Invalid When either id is not a feed position.
     */
    public static function compare(string $a, string $b): int
    {
        return self::decode($a) <=> self::decode($b);
    }
}
