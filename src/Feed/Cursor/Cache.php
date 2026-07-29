<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Cursor;

/**
 * Positions kept in a Utopia cache.
 *
 * The right store for a consumer reading a remote feed: it already has a cache
 * for its own work, and losing a position there only costs a replay of
 * whatever is still retained.
 *
 * Note that a Utopia cache lowercases keys unless `setCaseSensitivity(true)`
 * was called on it, so consumer names that differ only in case will share a
 * position — and two consumers sharing a position each skip what the other
 * handled.
 *
 * @see https://github.com/utopia-php/cache
 */
class Cache extends Cursor
{
    /**
     * How long a position survives without being written.
     *
     * The clock runs from the last save, not the last load, so this is really
     * "how long a consumer may go without handling anything". A month is long
     * enough that only a feed which has genuinely gone silent reaches it, and
     * a consumer that does lose its position replays the retained feed rather
     * than losing anything.
     */
    public const int TTL = 30 * 24 * 60 * 60;

    public function __construct(
        protected readonly UtopiaCache $cache,
        string $feed,
        protected readonly int $ttl = self::TTL,
    ) {
        parent::__construct($feed);
    }

    public function load(string $consumer): ?string
    {
        /** @var mixed $cursor */
        $cursor = $this->cache->load($this->key($consumer), $this->ttl);

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $consumer, string $eventId): void
    {
        if (!$this->shouldAdvance($consumer, $eventId)) {
            return;
        }

        $this->cache->save($this->key($consumer), $eventId);
    }

    public function reset(string $consumer): void
    {
        $this->cache->purge($this->key($consumer));
    }
}
