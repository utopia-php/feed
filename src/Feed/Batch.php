<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

/**
 * One read of a feed
 * @implements \IteratorAggregate<int, CloudEvent>
 */
final class Batch implements \Countable, \IteratorAggregate
{
    /**
     * What a feed response's `Content-Type` carries — the serving side's name
     * for {@see Readable::MEDIA_TYPE}, which is where the value lives so the
     * two sides of the wire cannot drift apart.
     */
    public const string MEDIA_TYPE = Readable::MEDIA_TYPE;

    private const string CACHE_IMMUTABLE = 'max-age=31536000';
    private const string CACHE_NONE = 'no-store';

    /**
     * @param list<CloudEvent> $events
     * @param int $limit The effective limit — the clamped value the feed used to build this batch.
     */
    public function __construct(
        private readonly array $events,
        private readonly int $limit,
    ) {
    }

    /** @return \ArrayIterator<int, CloudEvent> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->events);
    }

    public function count(): int
    {
        return \count($this->events);
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function lastId(): ?string
    {
        $count = \count($this->events);

        return $count === 0 ? null : $this->events[$count - 1]->id;
    }

    /**
     * A full batch is settled history, so it may be cached forever. Anything
     * short is the live end of the feed and will grow — an empty batch
     * included: zero events is a caught-up consumer, and caching that would
     * pin the consumer at its position forever.
     */
    public function cacheControl(bool $public = false): string
    {
        $count = \count($this->events);

        if ($count < $this->limit || $count === 0) {
            return self::CACHE_NONE;
        }

        return ($public ? 'public, ' : 'private, ') . self::CACHE_IMMUTABLE;
    }

    /**
     * A batch on the wire is a plain array of CloudEvents — no envelope. An
     * empty feed serializes to `[]`, which the spec reads as "you are caught up".
     *
     * @return list<array<array-key, mixed>>
     */
    public function toArray(): array
    {
        return \array_map(static fn (CloudEvent $event): array => $event->toArray(), $this->events);
    }
}
