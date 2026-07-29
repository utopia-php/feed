<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Pools\Pool as UtopiaPool;

/**
 * {@see Redis}, over a pooled connection.
 *
 * Pairs with {@see \Utopia\Feed\Journal\Pool}, and can share its pool: a cursor
 * read is one `GET`, so it borrows a connection only for as long as that takes.
 *
 * @see https://github.com/utopia-php/pools
 */
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
