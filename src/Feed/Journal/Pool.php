<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Journal;
use Utopia\Pools\Pool as UtopiaPool;

/**
 * {@see Redis}, over a pooled connection.
 *
 * What most services producing a feed want: a long poll holds its connection
 * for the whole timeout, so reading through a shared client would block every
 * other user of it.
 *
 * @see https://github.com/utopia-php/pools
 */
class Pool extends Journal
{
    /**
     * @param UtopiaPool<\Redis|\RedisCluster> $pool
     * @param string $name Feed name; the stream is stored at `feed:<name>`.
     * @param int $maxSize Approximate cap on retained events.
     * @throws Invalid When $name is empty, or $maxSize is below one event.
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

    public function read(?string $lastEventId, int $limit): array
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): array => $this->journal($redis)->read($lastEventId, $limit)
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
