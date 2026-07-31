<?php

declare(strict_types=1);

namespace Utopia\Feed\Store;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;
use Utopia\Feed\Appendable;
use Utopia\Feed\Store;

/**
 * A feed on a Utopia cache — for a producer whose service already carries a
 * cache and does not want another backend for its feed.
 *
 * The whole feed lives under one key, rewritten on every append, so appends
 * are last-writer-wins rather than atomic: run one producing process, or
 * accept that concurrent appends can drop each other. The store may also be
 * evicted as a unit — a consumer then restarts from an empty feed, which
 * costs a replay of nothing, not a gap it can detect. Retention still trims
 * to maxSize; ttl bounds how long an idle feed outlives its last append.
 */
class Cache extends Store implements Appendable
{
    public const int TTL = 30 * 24 * 60 * 60; // 30 days

    public function __construct(
        protected readonly UtopiaCache $cache,
        string $name,
        protected readonly int $maxSize = 100_000,
        protected readonly int $ttl = self::TTL,
        int $pollInterval = self::POLL_INTERVAL,
    ) {
        parent::__construct($name, $pollInterval);
    }

    public function append(CloudEvent $event): string
    {
        $entries = $this->load();

        $last = $entries === [] ? null : $entries[\count($entries) - 1];
        [$timestamp, $sequence] = $last === null ? [0, -1] : Id::decode($last['id']);

        $now = (int) \floor(\microtime(true) * 1000);
        $id = $now > $timestamp ? Id::encode($now, 0) : Id::encode($timestamp, $sequence + 1);

        $entries[] = ['id' => $id, 'fields' => self::encode($event)];

        if (\count($entries) > $this->maxSize) {
            $entries = \array_slice($entries, -$this->maxSize);
        }

        $saved = $this->cache->save($this->key(), $entries);

        if ($saved === false) {
            throw new Transport("Failed to append to the {$this->name} feed");
        }

        return $id;
    }

    public function tip(): ?string
    {
        $entries = $this->load();

        return $entries === [] ? null : $entries[\count($entries) - 1]['id'];
    }

    public function read(?string $lastEventId, int $limit): array
    {
        $lastEventId = $this->resolve($lastEventId);

        $after = $lastEventId === null ? null : Id::decode($lastEventId);

        $events = [];

        foreach ($this->load() as $entry) {
            if ($after !== null && Id::decode($entry['id']) <= $after) {
                continue;
            }

            $events[] = self::decode($entry['id'], $entry['fields']);

            if (\count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    /**
     * The stored feed, oldest first. Anything that is not the shape append()
     * writes — a missing key, a foreign value under it — reads as an empty
     * feed rather than a fault: a cache is allowed to forget.
     *
     * @return list<array{id: string, fields: array<array-key, mixed>}>
     */
    private function load(): array
    {
        /** @var mixed $stored */
        $stored = $this->cache->load($this->key(), $this->ttl);

        if (!\is_array($stored)) {
            return [];
        }

        $entries = [];

        /** @var mixed $entry */
        foreach ($stored as $entry) {
            if (!\is_array($entry)
                || !isset($entry['id'], $entry['fields'])
                || !\is_string($entry['id'])
                || !\is_array($entry['fields'])) {
                return [];
            }

            $entries[] = ['id' => $entry['id'], 'fields' => $entry['fields']];
        }

        return $entries;
    }

    private function key(): string
    {
        return 'feed:' . $this->name;
    }
}
