<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;

class Redis extends Cursor
{
    /**
     * @param \Redis|\RedisCluster $redis
     */
    public function __construct(protected readonly \Redis|\RedisCluster $redis)
    {
    }

    public function load(string $feed, string $consumer): ?string
    {
        try {
            /** @var mixed $cursor */
            $cursor = $this->redis->get($this->key($feed, $consumer));
        } catch (\RedisException $error) {
            throw new Transport("Failed to load the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        try {
            $this->redis->set($this->key($feed, $consumer), $eventId);
        } catch (\RedisException $error) {
            throw new Transport("Failed to save the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }

    public function reset(string $feed, string $consumer): void
    {
        try {
            $this->redis->del($this->key($feed, $consumer));
        } catch (\RedisException $error) {
            throw new Transport("Failed to reset the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }
}
