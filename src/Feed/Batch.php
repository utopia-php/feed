<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

/**
 * One read of a feed: the events, paired with the limit the batch was
 * actually built with.
 *
 * The pairing is the point. Whether a batch may be cached forever depends on
 * whether it came back full, so the caching rule needs the limit the read was
 * clamped to — not the one the request asked for. Holding both in one value
 * makes a mismatched pair impossible to express.
 *
 * @implements \IteratorAggregate<int, CloudEvent>
 */
final class Batch implements \Countable, \IteratorAggregate
{
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

    /**
     * The id of the last event, or null on an empty batch — the position a
     * caller relaying the feed by hand tracks.
     */
    public function lastId(): ?string
    {
        $count = \count($this->events);

        return $count === 0 ? null : $this->events[$count - 1]->id;
    }

    /**
     * The Cache-Control header for the response carrying this batch: a full
     * batch is settled history and immutable, anything short is the live end
     * of the feed and must not be cached. Caching is private unless $public.
     */
    public function cacheControl(bool $public = false): string
    {
        return Protocol::cacheControl(\count($this->events), $this->limit, $public);
    }

    /**
     * The batch as it goes on the wire: a plain array of CloudEvents.
     *
     * @return list<array<array-key, mixed>>
     */
    public function toArray(): array
    {
        return Protocol::encode($this->events);
    }
}
