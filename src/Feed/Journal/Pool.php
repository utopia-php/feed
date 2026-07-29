<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\Feed\Journal;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Pools\Pool as UtopiaPool;

/**
 * {@see Redis}, over a pooled connection.
 *
 * What most services actually want: a long poll holds its connection for the
 * whole timeout, so a feed read from a shared client would block every other
 * user of it. Taking a connection per operation keeps that contained.
 *
 * @see https://github.com/utopia-php/pools
 */
class Pool extends Journal
{
    /**
     * @param UtopiaPool<\Redis|\RedisCluster> $pool
     * @param string $name Feed name; the stream is stored at `feed:<name>`.
     * @param int $maxSize Approximate cap on retained events.
     */
    public function __construct(
        protected readonly UtopiaPool $pool,
        string $name,
        protected readonly int $maxSize = 100_000,
    ) {
        parent::__construct($name);

        self::assertRetention($maxSize);
    }

    public function append(CloudEvent $event): string
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): string => $this->journal($redis)->append($event));
    }

    public function read(?string $lastEventId, int $limit, int $timeout = 0): array
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): array => $this->journal($redis)->read($lastEventId, $limit, $timeout)
        );
    }

    /**
     * The connection is only borrowed for the length of one call, so the
     * journal wrapping it is built per call too. It holds no state beyond the
     * connection, which makes that free.
     */
    private function journal(\Redis|\RedisCluster $redis): Redis
    {
        return new Redis($redis, $this->name, $this->maxSize);
    }
}
