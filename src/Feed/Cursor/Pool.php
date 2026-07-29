<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Pools\Pool as UtopiaPool;

class Pool extends Cursor
{
    /**
     * @param UtopiaPool<\Redis|\RedisCluster> $pool
     */
    public function __construct(protected readonly UtopiaPool $pool)
    {
    }

    public function load(string $feed, string $consumer): ?string
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): ?string => (new Redis($redis))->load($feed, $consumer));
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($feed, $consumer, $eventId): void {
            (new Redis($redis))->save($feed, $consumer, $eventId);
        });
    }

    public function reset(string $feed, string $consumer): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($feed, $consumer): void {
            (new Redis($redis))->reset($feed, $consumer);
        });
    }
}
