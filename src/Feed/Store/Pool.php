<?php

declare(strict_types=1);

namespace Utopia\Feed\Store;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Store;
use Utopia\Pools\Pool as UtopiaPool;

class Pool extends Store implements Appendable
{
    /**
     * @param UtopiaPool<\Redis|\RedisCluster> $pool
     */
    public function __construct(
        protected readonly UtopiaPool $pool,
        string $name,
        protected readonly int $maxSize = 100_000,
        int $pollInterval = self::POLL_INTERVAL,
    ) {
        parent::__construct($name, $pollInterval);
    }

    private function inner(\Redis|\RedisCluster $redis): Redis
    {
        return new Redis($redis, $this->name, $this->maxSize, $this->pollInterval);
    }

    public function append(CloudEvent $event): string
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): string => $this->inner($redis)->append($event)
        );
    }

    public function tip(): ?string
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): ?string => $this->inner($redis)->tip()
        );
    }

    public function read(?string $lastEventId, int $limit): array
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): array => $this->inner($redis)->read($lastEventId, $limit)
        );
    }
}
