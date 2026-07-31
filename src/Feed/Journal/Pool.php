<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Journal;
use Utopia\Pools\Pool as UtopiaPool;

class Pool extends Journal implements Appendable
{
    /**
     * @param UtopiaPool<\Redis|\RedisCluster> $pool
     */
    public function __construct(
        protected readonly UtopiaPool $pool,
        string $name,
        protected readonly int $maxSize = 100_000,
    ) {
        parent::__construct($name);
    }

    public function append(CloudEvent $event): string
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): string => (new Redis($redis, $this->name, $this->maxSize))->append($event)
        );
    }

    public function tip(): ?string
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): ?string => (new Redis($redis, $this->name, $this->maxSize))->tip()
        );
    }

    public function read(?string $lastEventId, int $limit): array
    {
        return $this->pool->use(
            fn (\Redis|\RedisCluster $redis): array => (new Redis($redis, $this->name, $this->maxSize))->read($lastEventId, $limit)
        );
    }
}
