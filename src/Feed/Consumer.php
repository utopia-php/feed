<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

/**
 * Reads a feed from where it last got to, hands each new event to a handler,
 * and records how far it got.
 *
 * ```php
 * $consumer = new Consumer($feed, 'cache-invalidator', new Cursor\Cache($cache));
 *
 * // On a timer, or in a loop with a long-poll timeout:
 * $consumer->consume(function (CloudEvent $event) use ($cache) {
 *     $cache->purge($event->data['tag'] ?? '');
 * });
 * ```
 *
 * Delivery is at-least-once, so a handler must be safe to run twice on the same
 * event. A handler rejects an event by throwing, which stops the run there and
 * leaves the position before it, so the next run tries again. See the README
 * for what that means in practice.
 */
class Consumer
{
    /**
     * Events per run. Small enough that a backlog drains in bounded steps
     * instead of one long pass that fails near the end and repeats itself.
     */
    public const int BATCH = 100;

    /**
     * The position, mirrored in memory, so a run reads the store once and a
     * store that becomes unavailable afterwards costs nothing.
     */
    private ?string $position = null;

    private bool $restored = false;

    /** @var (callable(\Throwable, string): void)|null */
    private $onWarning = null;

    /**
     * @param Feed $feed Feed to read.
     * @param string $name This consumer's name, which its position is stored
     *        under. Distinct per logical consumer, and stable across restarts.
     *        Run **one process per name** — two sharing a name share one
     *        position, so the feed is split between them rather than delivered
     *        to both.
     * @param Cursor $cursor Where to keep the position.
     * @param int $batch Events per run.
     * @param int $timeout Milliseconds to wait for an event when the feed is
     *        caught up. Zero returns immediately, which is what a consumer
     *        driven by an external timer wants; a non-zero value suits one
     *        looping on its own.
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

    /**
     * Report failures that were survived rather than raised — currently, a
     * position that could not be loaded or saved.
     *
     * These are not fatal: the consumer carries on with its in-memory position
     * and the only cost is a replay after a restart. They are still worth
     * knowing about, because a store that has been failing quietly for a week
     * is a replay of the entire retained feed waiting to happen.
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
     * @param callable(CloudEvent): void $handler Throws to reject an event,
     *        which stops the run and leaves the position before it.
     * @return int Events handled. Zero means the consumer is caught up.
     * @throws Exception When the feed cannot be read. The position stays where
     *         it was, so the next run retries the same events.
     * @throws \Throwable Whatever the handler threw, after the events before it
     *         have been committed.
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
            $this->position = $this->cursor->load($this->feed->getName(), $this->name);
        } catch (\Throwable $error) {
            // Falls through to null, which restarts from the oldest retained
            // event. Wasteful, but the alternatives are worse: guessing at a
            // position risks skipping, and refusing to run turns an outage in
            // the cursor store into an outage in whatever the feed drives.
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
        $this->cursor->reset($this->feed->getName(), $this->name);

        $this->position = null;
        $this->restored = true;
    }

    private function advance(string $eventId): void
    {
        $this->position = $eventId;

        try {
            $this->cursor->save($this->feed->getName(), $this->name, $eventId);
        } catch (\Throwable $error) {
            // In-memory position already moved, so this process does not repeat
            // itself; only a restart before the store recovers replays.
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
