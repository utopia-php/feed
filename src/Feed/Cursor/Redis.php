<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;

/**
 * Positions kept in Redis, as plain keys alongside the stream.
 *
 * For consumers running inside the producer — a job that turns the feed into
 * something else, a bridge to a system that cannot poll. They have no store of
 * their own, and the feed's Redis is already there.
 *
 * Consumers reached over HTTP should not use this: keeping their positions in
 * the producer's Redis puts per-consumer state back on the producer, which is
 * exactly what the feed is arranged to avoid.
 */
class Redis extends Cursor
{
    public function __construct(
        protected readonly \Redis|\RedisCluster $redis,
        string $feed,
    ) {
        parent::__construct($feed);
    }

    public function load(string $consumer): ?string
    {
        $key = $this->key($consumer);

        try {
            /** @var mixed $cursor */
            $cursor = $this->redis->get($key);
        } catch (\RedisException $error) {
            throw new Transport("Failed to load the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $consumer, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $key = $this->key($consumer);

        try {
            // Deliberately no expiry. Unlike a cache, this is the only copy,
            // and a position that quietly expired would replay the whole
            // retained feed the next time the consumer restarted.
            $this->redis->set($key, $eventId);
        } catch (\RedisException $error) {
            throw new Transport("Failed to save the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }

    public function reset(string $consumer): void
    {
        $key = $this->key($consumer);

        try {
            $this->redis->del($key);
        } catch (\RedisException $error) {
            throw new Transport("Failed to reset the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }
}
