<?php

declare(strict_types=1);

namespace Utopia\Feed\Adapter;

use Utopia\Feed\Adapter;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;

/**
 * A feed backed by a Redis stream.
 *
 * Streams give the two properties http-feeds needs and are awkward to build on
 * anything else: `XADD` allocates ids that are ordered and unique across
 * concurrent producers, and `XRANGE` pages from any of them without the
 * producer tracking who has read what. Consumer groups are deliberately not
 * used — they move the position onto the producer, which is the arrangement
 * this library exists to avoid.
 *
 * Retention is a `MAXLEN` cap. Redis trims approximately, to whole nodes,
 * so a feed holds at least $maxSize events and usually somewhat more; it is a
 * bound on memory, not a promise about how far back a consumer can resume
 * from.
 */
class Redis extends Adapter
{
    /**
     * @param \Redis|\RedisCluster $redis
     * @param string $name Feed name; the stream is stored at `feed:<name>`.
     * @param int $maxSize Approximate cap on retained events. The default
     *        holds a long weekend of a busy feed, which is the window that
     *        matters: a consumer down for longer than its feed's retention
     *        resumes from the oldest event it can, rather than from where it
     *        left off.
     */
    public function __construct(
        protected readonly \Redis|\RedisCluster $redis,
        string $name,
        protected readonly int $maxSize = 100_000,
    ) {
        parent::__construct($name);
    }

    /**
     * The key the stream lives at. Namespaced so a feed can share a Redis with
     * whatever else the service keeps there.
     */
    public function getKey(): string
    {
        return 'feed:' . $this->name;
    }

    public function append(Event $event): string
    {
        try {
            $id = $this->redis->xAdd($this->getKey(), '*', self::encode($event), $this->maxSize, true);
        } catch (\RedisException $error) {
            throw new Transport("Failed to append to the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        if (!\is_string($id) || $id === '') {
            throw new Transport("Failed to append to the {$this->name} feed");
        }

        return $id;
    }

    public function read(?string $lastEventId, int $limit, int $timeout = 0): array
    {
        $start = $lastEventId === null ? '-' : Id::after($lastEventId);

        try {
            $entries = $this->redis->xRange($this->getKey(), $start, '+', $limit);
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
