<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * Where a consumer's position in a feed is kept.
 *
 * http-feeds puts the position on the consumer rather than the producer, which
 * is what makes adding a consumer free — the producer keeps no per-consumer
 * state, so it does not need to know who is reading. The cost is that each
 * consumer needs somewhere to write a string, and that is all this is.
 *
 * The store is allowed to be lossy. A lost position is not a lost event: a
 * consumer with no position resumes from the oldest retained event, so the
 * consequence is redundant work, not a gap. That is why a cache is a
 * reasonable place to put one, and it is also why handlers must tolerate
 * seeing an event twice — which they must anyway, since delivery is
 * at-least-once regardless.
 *
 * One store can hold positions for many consumers of the same feed, keyed by
 * consumer name.
 */
abstract class Cursor
{
    /**
     * @param string $feed Feed the positions belong to. Part of the key, so
     *        one store can serve every feed a service consumes.
     * @throws Invalid When $feed is empty.
     */
    public function __construct(protected readonly string $feed)
    {
        if ($feed === '') {
            throw new Invalid('Cursor requires a feed name');
        }
    }

    public function getFeed(): string
    {
        return $this->feed;
    }

    /**
     * The last position $consumer recorded, or null if it has never recorded
     * one — which a caller should read as "start from the beginning of what is
     * retained", never as "start from now".
     *
     * @throws Exception When the store cannot be read.
     */
    abstract public function load(string $consumer): ?string;

    /**
     * Record a position.
     *
     * Only ever call this once the events up to $eventId have been handled. A
     * position saved ahead of the work it stands for turns a crash into
     * silently skipped events, which is the one failure this design cannot
     * recover from — the events are still in the feed, but nothing will ever
     * read them again.
     *
     * @throws Exception When the store cannot be written.
     */
    abstract public function save(string $consumer, string $eventId): void;

    /**
     * Forget a consumer's position, so its next read starts from the oldest
     * retained event.
     *
     * @throws Exception When the store cannot be written.
     */
    abstract public function reset(string $consumer): void;

    /**
     * @throws Invalid When $consumer is empty.
     */
    protected function key(string $consumer): string
    {
        if ($consumer === '') {
            throw new Invalid('Cursor requires a consumer name');
        }

        return 'feed:' . $this->feed . ':cursor:' . $consumer;
    }
}
