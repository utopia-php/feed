<?php

declare(strict_types=1);

namespace Utopia\Feed\Store;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;
use Utopia\Feed\Key;
use Utopia\Feed\Appendable;
use Utopia\Feed\Store;

class Cache extends Store implements Appendable
{
    public const int TTL = 30 * 24 * 60 * 60; // 30 days

    /**
     * Deliberately far below the {@see Store::MAX_SIZE} the Redis store
     * inherits, where trimming happens server-side and reads are ranged.
     * Here the whole feed lives under one key, so retention is also the size
     * of every append's read-modify-write: at 100 000 entries a single
     * `produce()` moves megabytes through the cache both ways. A larger cap
     * is a fine choice for a low-rate feed, but it should be one somebody
     * made rather than one inherited from a backend with other costs.
     */
    protected const int MAX_SIZE = 1_000; // entries

    public function __construct(
        protected readonly UtopiaCache $cache,
        string $name,
        int $maxSize = self::MAX_SIZE,
        protected readonly int $ttl = self::TTL,
        int $pollInterval = self::POLL_INTERVAL,
    ) {
        parent::__construct($name, $maxSize, $pollInterval);
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

        // The tip marker goes first, so it is never behind the feed. A crash
        // between the two writes leaves it ahead, which only costs a read that
        // finds nothing; behind, it would report a caught-up consumer and the
        // event would never be delivered.
        $this->write(Key::tip($this->name), $id);
        $this->write($this->key(), $entries);

        return $id;
    }

    /**
     * @param string|array<int|string, mixed> $value
     * @throws Transport When the write fails, either way a cache adapter can.
     */
    private function write(string $key, string|array $value): void
    {
        try {
            $saved = $this->cache->save($key, $value);
        } catch (\Throwable $error) {
            throw new Transport("Failed to append to the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        if ($saved === false) {
            throw new Transport("Failed to append to the {$this->name} feed");
        }
    }

    public function tip(): ?string
    {
        $entries = $this->load();

        return $entries === [] ? null : $entries[\count($entries) - 1]['id'];
    }

    public function read(?string $lastEventId, int $limit): array
    {
        $lastEventId = $this->resolve($lastEventId);

        if ($lastEventId !== null && $this->caughtUp($lastEventId)) {
            return [];
        }

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
     * Whether the feed provably holds nothing after $lastEventId, decided from
     * the tip marker alone.
     *
     * The whole feed lives under one key, so answering this by reading it
     * costs the entire retained feed — every poll tick, per waiting consumer,
     * for up to 30 seconds a request. The marker turns the common case, a
     * caught-up consumer waiting on a quiet feed, into one small read.
     *
     * Only ever used to skip work, never to invent an answer: the marker is
     * written before the feed, so it is never behind, and a missing or
     * unreadable one falls through to the real read.
     *
     * @throws Transport When the cache backend cannot be reached.
     */
    private function caughtUp(string $lastEventId): bool
    {
        try {
            /** @var mixed $tip */
            $tip = $this->cache->load(Key::tip($this->name), $this->ttl);
        } catch (\Throwable $error) {
            throw new Transport("Failed to read the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        if (!\is_string($tip) || !Id::isValid($tip)) {
            return false;
        }

        return Id::decode($tip) <= Id::decode($lastEventId);
    }

    /**
     * @return list<array{id: string, fields: array<array-key, mixed>}>
     * @throws Transport When the cache backend cannot be reached.
     */
    private function load(): array
    {
        try {
            /** @var mixed $stored */
            $stored = $this->cache->load($this->key(), $this->ttl);
        } catch (\Throwable $error) {
            // A cache adapter over a backend that is down raises whatever that
            // backend raises — a raw \RedisException, say. Every error this
            // library reports extends Utopia\Feed\Exception, and a consumer
            // retrying on Transport must not crash on a backend blip instead.
            throw new Transport("Failed to read the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

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
}
