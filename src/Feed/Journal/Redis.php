<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;
use Utopia\Feed\Journal;

class Redis extends Journal
{
    public function __construct(
        protected readonly \Redis|\RedisCluster $redis,
        string $name,
        protected readonly int $maxSize = 100_000,
    ) {
        parent::__construct($name);
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

    public function read(?string $lastEventId, int $limit): array
    {
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
