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
    /** @var UtopiaPool<\Redis|\RedisCluster>|null */
    private ?UtopiaPool $pool = null;

    /** @return UtopiaPool<\Redis|\RedisCluster> */
    protected function pool(): UtopiaPool
    {
        if ($this->pool === null) {
            /** @var UtopiaPool<\Redis|\RedisCluster> $pool */
            $pool = new UtopiaPool(new Stack(), 'feed-tests', 4, static function (): \Redis {
                $redis = new \Redis();
                $redis->connect((string) (\getenv('REDIS_HOST') ?: 'redis'), (int) (\getenv('REDIS_PORT') ?: 6379));

                return $redis;
            });

            $this->pool = $pool;
        }

        return $this->pool;
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
