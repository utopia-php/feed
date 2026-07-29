<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

/**
 * Reads a feed from where it last got to, hands each new event to a handler,
 * and records how far it got.
 *
 * ```php
 * $consumer = new Consumer($feed, 'cache-invalidator', new Cursor\Cache($cache, 'edge'));
 *
 * // On a timer, or in a loop with a long-poll timeout:
 * $consumer->consume(function (CloudEvent $event) use ($cache) {
 *     $cache->purge($event->data['tag'] ?? '');
 * });
 * ```
 *
 * ## What the handler must tolerate
 *
 * Delivery is at-least-once, so a handler will see the same event more than
 * once and must be safe to repeat. There are four separate reasons, and no
 * arrangement of this class removes any of them:
 *
 * 1. A handler can succeed and the position then fail to save.
 * 2. A batch interrupted partway replays from the last event that succeeded.
 * 3. A consumer whose position is lost restarts from the oldest retained event.
 * 4. Two processes sharing a consumer name can interleave inside the
 *    read-compare-write in {@see Cursor::save()} and leave the older position
 *    stored, re-delivering what the newer one had already handled.
 *
 * Every one of them re-delivers; none of them skips. That asymmetry is the
 * whole design — an event handled twice is absorbed by an idempotent handler,
 * whereas an event stepped over is gone, still sitting in the feed with nothing
 * that will ever read it again.
 *
 * A handler rejects an event by throwing. That stops the run at that event and
 * leaves the position before it, so the next run starts there and tries again.
 * Everything already handled in that run stays handled — progress is committed
 * before the failure is re-raised — which means a handler that fails on one
 * event does not undo the batch, but does block everything behind it until it
 * stops failing. That is the intended behaviour: a feed is ordered, and
 * stepping over a failure would deliver later events on top of state that was
 * never updated.
 *
 * ## Starting position
 *
 * A consumer with no recorded position starts at the oldest retained event,
 * never at the tip. Starting at the tip would drop whatever is already in the
 * feed, and for a consumer being deployed for the first time that is not a
 * hypothetical backlog — it is everything that happened between the producer
 * shipping and the consumer shipping, which during a staged rollout is exactly
 * the events that were meant to be caught up on.
 */
class Consumer
{
    /**
     * Events per run. Small enough that a backlog drains in bounded steps
     * instead of one long pass that fails near the end and repeats most of
     * itself.
     */
    public const int BATCH = 100;

    /**
     * The position, mirrored in memory.
     *
     * A run therefore reads the store once, on its first pass, and a store
     * that becomes unavailable afterwards costs nothing — the consumer keeps
     * making progress and only replays if it restarts before the store
     * recovers.
     */
    private ?string $position = null;

    private bool $restored = false;

    /** @var (callable(\Throwable, string): void)|null */
    private $onWarning = null;

    /**
     * @param Feed $feed Feed to read.
     * @param string $name This consumer's name, which its position is stored
     *        under. Distinct per logical consumer, and stable across restarts.
     *
     *        **One process per name.** Two processes sharing a name each skip
     *        what the other handled, because neither sees the other's work
     *        before reading its own position. That is wasted effort, not lost
     *        events — delivery is at-least-once and handlers must tolerate a
     *        repeat regardless.
     *
     *        The overlap a rolling restart creates is therefore safe, and
     *        {@see Cursor::save()} additionally refuses to move a stored
     *        position backwards, so the departing process cannot undo the
     *        arriving one's progress.
     * @param Cursor $cursor Where to keep the position.
     * @param int $batch Events per run.
     * @param int $timeout Milliseconds to wait for an event when the feed is
     *        caught up. Zero returns immediately, which is what a consumer
     *        driven by an external timer wants; a non-zero value suits a
     *        consumer looping on its own, where it replaces a sleep with a
     *        wait that ends the moment an event arrives.
     * @throws Exception\Invalid When $name is empty.
     */
    public function __construct(
        protected readonly Feed $feed,
        protected readonly string $name,
        protected readonly Cursor $cursor,
        protected readonly int $batch = self::BATCH,
        protected readonly int $timeout = 0,
    ) {
        if ($name === '') {
            throw new Exception\Invalid('Feed consumer requires a name');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    /**
     * Report failures that were survived rather than raised — currently, a
     * position that could not be loaded or saved.
     *
     * These are not fatal: the consumer carries on with its in-memory
     * position and the only cost is a replay after a restart. They are still
     * worth knowing about, because a store that has been failing quietly for a
     * week is a replay of the entire retained feed waiting to happen.
     *
     * @param (callable(\Throwable, string): void)|null $callback Receives the
     *        error and a short context string.
     */
    public function onWarning(?callable $callback): self
    {
        $this->onWarning = $callback;

        return $this;
    }

    /**
     * Hand every event not yet seen to $handler, oldest first, and return how
     * many it accepted.
     *
     * @param callable(CloudEvent): void $handler Throws to reject an event, which
     *        stops the run and leaves the position before it.
     * @return int Events handled. Zero means the consumer is caught up.
     * @throws Exception When the feed cannot be read. The position stays where
     *         it was, so the next run retries the same events.
     * @throws \Throwable Whatever the handler threw, after the events before
     *         it have been committed.
     */
    public function consume(callable $handler): int
    {
        $events = $this->feed->poll($this->position(), $this->batch, $this->timeout);

        if ($events === []) {
            return 0;
        }

        $handled = 0;
        $processed = null;
        $failure = null;

        foreach ($events as $event) {
            try {
                $handler($event);
            } catch (\Throwable $error) {
                $failure = $error;
                break;
            }

            $processed = $event->id;
            $handled++;
        }

        if ($processed !== null) {
            $this->advance($processed);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $handled;
    }

    /**
     * Where this consumer has got to, or null if it has not started.
     */
    public function position(): ?string
    {
        if ($this->restored) {
            return $this->position;
        }

        $this->restored = true;

        try {
            $this->position = $this->cursor->load($this->name);
        } catch (\Throwable $error) {
            // Falls through to null, which restarts from the oldest retained
            // event. Wasteful — it replays events already handled — but the
            // alternatives are worse: guessing at a position risks skipping,
            // and refusing to run means an outage in the cursor store becomes
            // an outage in whatever the feed drives.
            $this->warn($error, 'load');
        }

        return $this->position;
    }

    /**
     * Drop the position and start again from the oldest retained event on the
     * next run. Every event still in the feed will be handled again.
     *
     * @throws Exception When the store cannot be written.
     */
    public function reset(): void
    {
        $this->cursor->reset($this->name);

        $this->position = null;
        $this->restored = true;
    }

    private function advance(string $eventId): void
    {
        $this->position = $eventId;

        try {
            $this->cursor->save($this->name, $eventId);
        } catch (\Throwable $error) {
            // In-memory position already moved, so this process does not
            // repeat itself; only a restart before the store recovers replays.
            $this->warn($error, 'save');
        }
    }

    private function warn(\Throwable $error, string $context): void
    {
        if ($this->onWarning !== null) {
            ($this->onWarning)($error, $context);
        }
    }
}
