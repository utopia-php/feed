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
        int $pollInterval = self::POLL_INTERVAL,
    ) {
        parent::__construct($name, $pollInterval);
    }

    // The interval only matters in this class's own inherited poll() loop —
    // the inner journal lives for a single read — but it is passed through so
    // a future change to the inner journal cannot silently drop it.
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
