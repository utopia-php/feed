<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;

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
        // Keyed outside the try: an unusable name is Invalid, not Transport.
        $key = $this->key($feed, $consumer);

        try {
            /** @var mixed $cursor */
            $cursor = $this->cache->load($key, $this->ttl);
        } catch (\Throwable $error) {
            throw new Transport("Failed to load the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        $key = $this->key($feed, $consumer);

        try {
            $saved = $this->cache->save($key, $eventId);
        } catch (\Throwable $error) {
            throw new Transport("Failed to save the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }

        // A cache reports a failed write by returning false rather than raising.
        if ($saved === false) {
            throw new Transport("Failed to save the {$consumer} cursor on the {$feed} feed");
        }
    }

    public function reset(string $feed, string $consumer): void
    {
        $key = $this->key($feed, $consumer);

        try {
            // purge()'s false is not checked: it also means "was never there",
            // which is the ordinary case for a consumer with no position yet.
            $this->cache->purge($key);
        } catch (\Throwable $error) {
            throw new Transport("Failed to reset the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }
}
