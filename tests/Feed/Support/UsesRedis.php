<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Feed\Appendable;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Redis as RedisCursor;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Redis as RedisStore;

/**
 * The Redis adapter pair, against the real Redis the suite's container links
 * to. Feed names are unique per test (the base classes see to that), so tests
 * cannot leak positions into each other; whatever a test wrote is dropped on
 * the way out.
 */
trait UsesRedis
{
    private ?\Redis $redis = null;

    protected function redis(): \Redis
    {
        if ($this->redis === null) {
            $this->redis = new \Redis();
            $this->redis->connect((string) (\getenv('REDIS_HOST') ?: 'redis'), (int) (\getenv('REDIS_PORT') ?: 6379));
        }

        return $this->redis;
    }

    protected function store(string $name, int $maxSize = 100_000, int $pollInterval = 500): Store&Appendable
    {
        return new RedisStore($this->redis(), $name, $maxSize, $pollInterval);
    }

    protected function cursor(): Cursor
    {
        return new RedisCursor($this->redis());
    }

    protected function tearDown(): void
    {
        foreach ((array) $this->redis()->keys('feed:' . $this->name . '*') as $key) {
            if (\is_string($key)) {
                $this->redis()->del($key);
            }
        }

        $this->redis()->close();
        $this->redis = null;
    }
}
