<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Pools\Pool as UtopiaPool;

/**
 * {@see Redis}, over a pooled connection.
 *
 * Pairs with {@see \Utopia\Feed\Journal\Pool}, and can share its pool: a
 * cursor read is one `GET`, so it borrows a connection only for as long as
 * that takes.
 *
 * @see https://github.com/utopia-php/pools
 */
class Pool extends Cursor
{
    /**
     * @param UtopiaPool<\Redis|\RedisCluster> $pool
     */
    public function __construct(
        protected readonly UtopiaPool $pool,
        string $feed,
    ) {
        parent::__construct($feed);
    }

    public function load(string $consumer): ?string
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): ?string => $this->cursor($redis)->load($consumer));
    }

    public function save(string $consumer, string $eventId): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($consumer, $eventId): void {
            $this->cursor($redis)->save($consumer, $eventId);
        });
    }

    public function reset(string $consumer): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($consumer): void {
            $this->cursor($redis)->reset($consumer);
        });
    }

    private function cursor(\Redis|\RedisCluster $redis): Redis
    {
        return new Redis($redis, $this->feed);
    }
}
