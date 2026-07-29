<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Cursor;

/**
 * Positions kept in a Utopia cache — the usual choice for a consumer reading a
 * remote feed, since it already has a cache for its own work.
 *
 * Note that a Utopia cache lowercases keys unless `setCaseSensitivity(true)`
 * was called on it, so consumer names that differ only in case share a position.
 *
 * @see https://github.com/utopia-php/cache
 */
class Cache extends Cursor
{
    /**
     * How long a position survives without being written. The clock runs from
     * the last save, so this is really "how long a consumer may go without
     * handling anything".
     */
    public const int TTL = 30 * 24 * 60 * 60;

    public function __construct(
        protected readonly UtopiaCache $cache,
        protected readonly int $ttl = self::TTL,
    ) {
    }

    public function load(string $feed, string $consumer): ?string
    {
        /** @var mixed $cursor */
        $cursor = $this->cache->load($this->key($feed, $consumer), $this->ttl);

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $this->cache->save($this->key($feed, $consumer), $eventId);
    }

    public function reset(string $feed, string $consumer): void
    {
        $this->cache->purge($this->key($feed, $consumer));
    }
}
