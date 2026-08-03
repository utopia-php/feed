<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Feed\Appendable;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Pool as PoolCursor;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Pool as PoolStore;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;

/**
 * The pooled adapter pair: the same Redis the plain adapter talks to, borrowed
 * per operation through a {@see UtopiaPool} — which is exactly the behaviour
 * the adapters exist to provide, so it must survive every scenario the plain
 * connection does.
 */
trait UsesPool
{
    /** The pool's connection count, and so the number of concurrent borrows it allows. */
    protected const int POOL_SIZE = 4;

    /** @var UtopiaPool<\Redis|\RedisCluster>|null */
    private ?UtopiaPool $pool = null;

    /**
     * A pool over the suite's Redis: the default {@see Stack}, or one that
     * records borrows for a test asserting on how the pool is used.
     *
     * @return UtopiaPool<\Redis|\RedisCluster>
     */
    protected static function poolOver(Stack $adapter): UtopiaPool
    {
        /** @var UtopiaPool<\Redis|\RedisCluster> $pool */
        $pool = new UtopiaPool($adapter, 'feed-tests', self::POOL_SIZE, static function (): \Redis {
            $redis = new \Redis();
            $redis->connect((string) (\getenv('REDIS_HOST') ?: 'redis'), (int) (\getenv('REDIS_PORT') ?: 6379));

            return $redis;
        });

        return $pool;
    }

    /** @return UtopiaPool<\Redis|\RedisCluster> */
    protected function pool(): UtopiaPool
    {
        return $this->pool ??= self::poolOver(new Stack());
    }

    protected function store(string $name, int $maxSize = 100_000, int $pollInterval = 500): Store&Appendable
    {
        return new PoolStore($this->pool(), $name, $maxSize, $pollInterval);
    }

    protected function cursor(): Cursor
    {
        return new PoolCursor($this->pool());
    }

    protected function tearDown(): void
    {
        $this->pool()->use(function (\Redis|\RedisCluster $redis): void {
            foreach ((array) $redis->keys('feed:' . $this->name . '*') as $key) {
                if (\is_string($key)) {
                    $redis->del($key);
                }
            }
        });
    }
}
