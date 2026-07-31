<?php

declare(strict_types=1);

namespace Utopia\Feed\Store;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;
use Utopia\Feed\Appendable;
use Utopia\Feed\Store;

class Redis extends Store implements Appendable
{
    public function __construct(
        protected readonly \Redis|\RedisCluster $redis,
        string $name,
        protected readonly int $maxSize = 100_000,
        int $pollInterval = self::POLL_INTERVAL,
    ) {
        parent::__construct($name, $pollInterval);
    }

    public function append(CloudEvent $event): string
    {
        try {
            $id = $this->redis->xAdd('feed:' . $this->name, '*', self::encode($event), $this->maxSize, true);
        } catch (\RedisException $error) {
            throw new Transport("Failed to append to the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        if (!\is_string($id) || $id === '') {
            throw new Transport("Failed to append to the {$this->name} feed");
        }

        return $id;
    }

    public function tip(): ?string
    {
        try {
            $entries = $this->redis->xRevRange('feed:' . $this->name, '+', '-', 1);
        } catch (\RedisException $error) {
            throw new Transport("Failed to read the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        if (!\is_array($entries) || $entries === []) {
            return null;
        }

        return (string) \array_key_first($entries);
    }

    public function read(?string $lastEventId, int $limit): array
    {
        $lastEventId = $this->resolve($lastEventId);

        $start = $lastEventId === null ? '-' : Id::after($lastEventId);

        try {
            $entries = $this->redis->xRange('feed:' . $this->name, $start, '+', $limit);
        } catch (\RedisException $error) {
            throw new Transport("Failed to read the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        if (!\is_array($entries)) {
            return [];
        }

        $events = [];

        /** @var mixed $fields */
        foreach ($entries as $id => $fields) {
            if (!\is_array($fields)) {
                continue;
            }

            $events[] = self::decode((string) $id, $fields);
        }

        return $events;
    }
}
