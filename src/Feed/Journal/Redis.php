<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;
use Utopia\Feed\Journal;

/**
 * A feed backed by a Redis stream.
 *
 * `XADD` allocates ids that are ordered and unique across concurrent producers,
 * and `XRANGE` pages from any of them without the producer tracking who has
 * read what. Consumer groups are deliberately not used — they move the position
 * onto the producer, which is what this library exists to avoid.
 */
class Redis extends Journal
{
    /**
     * @param \Redis|\RedisCluster $redis
     * @param string $name Feed name; the stream is stored at `feed:<name>`.
     * @param int $maxSize Cap on retained events. Redis trims approximately, to
     *        whole nodes, so a feed holds at least this many and usually more —
     *        a bound on memory, not a promise about how far back a consumer can
     *        resume from.
     * @throws Invalid When $name is empty, or $maxSize is below one event.
     */
    public function __construct(
        protected readonly \Redis|\RedisCluster $redis,
        string $name,
        protected readonly int $maxSize = 100_000,
    ) {
        parent::__construct($name);

        self::assertRetention($maxSize);
    }

    public function append(CloudEvent $event): string
    {
        try {
            $id = $this->redis->xAdd($this->key(), '*', self::encode($event), $this->maxSize, true);
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
            $entries = $this->redis->xRange($this->key(), $start, '+', $limit);
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

    /**
     * The key the stream lives at. Namespaced so a feed can share a Redis with
     * whatever else the service keeps there.
     */
    private function key(): string
    {
        return 'feed:' . $this->name;
    }
}
