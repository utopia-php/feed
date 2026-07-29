<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * Where a consumer's position in a feed is kept.
 *
 * http-feeds puts the position on the consumer rather than the producer, which
 * is what makes adding a consumer free. A cursor is somewhere to write a
 * string, keyed by feed and consumer name, so one store serves every feed a
 * service consumes.
 *
 * The store is allowed to be lossy. A lost position is not a lost event — a
 * consumer with no position resumes from the oldest retained event — so the
 * cost is redundant work, not a gap.
 */
abstract class Cursor
{
    /**
     * The last position $consumer recorded on $feed, or null if it has never
     * recorded one — which means "start from the oldest retained event", never
     * "start from now".
     *
     * @throws Exception When the store cannot be read.
     */
    abstract public function load(string $feed, string $consumer): ?string;

    /**
     * Record a position.
     *
     * Only ever call this once the events up to $eventId have been handled. A
     * position saved ahead of the work it stands for turns a crash into
     * silently skipped events, which is the one failure this design cannot
     * recover from.
     *
     * An empty $eventId is ignored rather than rejected: it means "nothing
     * handled yet", and must not erase a real position.
     *
     * @throws Exception When the store cannot be written.
     */
    abstract public function save(string $feed, string $consumer, string $eventId): void;

    /**
     * Forget a position, so the consumer's next read starts from the oldest
     * retained event.
     *
     * @throws Exception When the store cannot be written.
     */
    abstract public function reset(string $feed, string $consumer): void;

    /**
     * @throws Invalid When either name is empty.
     */
    protected function key(string $feed, string $consumer): string
    {
        if ($feed === '' || $consumer === '') {
            throw new Invalid('Cursor requires a feed and a consumer name');
        }

        return 'feed:' . $feed . ':cursor:' . $consumer;
    }
}
