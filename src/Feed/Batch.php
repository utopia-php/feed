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

    public function cacheControl(bool $public = false): string
    {
        return Protocol::cacheControl(\count($this->events), $this->limit, $public);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function toArray(): array
    {
        return Protocol::encode($this->events);
    }
}
