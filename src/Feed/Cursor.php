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
 *
 * Positions move forwards only. {@see save()} compares before writing and drops
 * anything that is not an advance, which matters because a rolling restart runs
 * two processes under one name for a moment. That comparison is not atomic, and
 * the reasoning for leaving it that way — including what the residual race
 * costs, which is a replay rather than a lost event — is on `save()`.
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
     * Record a position, never moving one backwards.
     *
     * Only ever call this once the events up to $eventId have been handled. A
     * position saved ahead of the work it stands for turns a crash into
     * silently skipped events, which is the one failure this design cannot
     * recover from — the events are still in the feed, but nothing will ever
     * read them again.
     *
     * A position that would move *backwards* is dropped instead. Two processes
     * running the same consumer overlap during a rolling restart — a normal
     * operation, not a misconfiguration — and they finish batches of different
     * lengths: without this, the slower one's older position lands last and a
     * later restart replays everything between the two, which on a busy feed is
     * potentially thousands of events. Positions are totally ordered
     * ({@see Id::compare()}), so "never backwards" is decidable here in a way it
     * is not for a cursor store in general.
     *
     * ## The check is not atomic
     *
     * It reads, compares, then writes. Two processes can interleave inside that
     * window, both decide they are ahead, and leave the older position stored.
     *
     * **The consequence is a replay, which is the delivery guarantee rather
     * than a departure from it.** A regressed position re-delivers events that
     * were already handled; it never skips one, never loses one, and never
     * advances past work that did not happen. Handlers are required to tolerate
     * a repeat for three other reasons already — see {@see Consumer} — so this
     * adds a fourth cause of something they must survive regardless, not a new
     * kind of failure.
     *
     * Closing the window needs a compare-and-set the store performs in one
     * operation, and it is not reachable across this abstraction:
     *
     * - `ZADD ... GT` is the native primitive, but scores are doubles and
     *   cannot hold `<ms>-<seq>` exactly once the sequence is packed in.
     * - `WATCH`/`MULTI` needs no scripting, but leaves watch state on a
     *   connection that is about to go back into a pool.
     * - A one-entry stream via `XADD` would be exact, since these ids *are*
     *   Redis stream ids and Redis refuses to move one backwards natively — but
     *   it changes the stored type, so cursors written by an earlier version
     *   stop being readable.
     * - {@see Cursor\Cache} has no portable compare-and-set at all: a Utopia
     *   cache exposes leases, but `getGeneration()` returns `'0'` on adapters
     *   that do not implement them. Any fix here would hold on some cache
     *   backends and silently not on others, which is worse than one uniform,
     *   stated guarantee.
     *
     * So the guarantee is deliberately the weaker, uniform one: a position
     * never moves backwards **except** under a sub-millisecond interleave
     * between two processes sharing a consumer name, whose cost is bounded
     * replay. Run one process per name ({@see Consumer::__construct()}) and it
     * cannot arise at all.
     *
     * @throws Exception When the store cannot be written.
     */
    abstract public function save(string $consumer, string $eventId): void;

    /**
     * Whether $eventId is worth storing for $consumer — that is, whether it is
     * a real position and a later one than what is already there.
     *
     * The check every implementation applies before writing.
     *
     * Deliberately fails open. A position that cannot be compared — because the
     * store could not be read, or because what came back is not a position —
     * is treated as behind, so real progress replaces it. The guard exists to
     * stop a position going backwards, and it must never become a reason for
     * one to stop going forwards.
     */
    protected function shouldAdvance(string $consumer, string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        try {
            $current = $this->load($consumer);
        } catch (\Throwable) {
            return true;
        }

        if ($current === null || $current === '') {
            return true;
        }

        try {
            return Id::compare($eventId, $current) > 0;
        } catch (Invalid) {
            return true;
        }
    }

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
