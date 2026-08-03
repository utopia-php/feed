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
        // The key is shaped outside the try: an unusable name is the caller's
        // bug (Invalid), not the backend's failure (Transport).
        $key = $this->key($feed, $consumer);

        try {
            /** @var mixed $cursor */
            $cursor = $this->cache->load($key, $this->ttl);
        } catch (\Throwable $error) {
            // A cache adapter over a backend that is down raises whatever that
            // backend raises — a raw \RedisException, say. Every error this
            // library reports extends Utopia\Feed\Exception, and a consumer
            // retrying on Transport must not crash on a backend blip instead.
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

        // A cache adapter reports a failed write by returning false rather
        // than raising — swallowing that would let a position silently not
        // persist, so the consumer replays its backlog on the next restart
        // and a seek past a poison event quietly does nothing.
        if ($saved === false) {
            throw new Transport("Failed to save the {$consumer} cursor on the {$feed} feed");
        }
    }

    public function reset(string $feed, string $consumer): void
    {
        $key = $this->key($feed, $consumer);

        try {
            // Unlike save(), purge()'s false is not a failure signal: it is
            // also what an adapter answers for a key that was never there,
            // which is the ordinary case for a consumer that has not saved a
            // position yet. Only a raising backend is a failure to report.
            $this->cache->purge($key);
        } catch (\Throwable $error) {
            throw new Transport("Failed to reset the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }
}
