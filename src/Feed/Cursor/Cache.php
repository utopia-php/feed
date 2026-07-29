<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Cursor;

class Cache extends Cursor
{
    public const int TTL = 30 * 24 * 60 * 60; // 30 days

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
        $this->cache->save($this->key($feed, $consumer), $eventId);
    }

    public function reset(string $feed, string $consumer): void
    {
        $this->cache->purge($this->key($feed, $consumer));
    }
}
