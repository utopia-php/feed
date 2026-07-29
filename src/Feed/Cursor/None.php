<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;

/**
 * No cursor store configured. Nothing is remembered.
 *
 * A no-op, unlike {@see \Utopia\Feed\Journal\None}, because the two losses are
 * not comparable: an append that goes nowhere loses events, while a position
 * that goes nowhere only costs a replay. A consumer using this still advances
 * in memory for the life of the process, and starts again from the oldest
 * retained event whenever it restarts.
 */
class None extends Cursor
{
    public function load(string $feed, string $consumer): ?string
    {
        // Validated even though nothing is stored, so a name that a real store
        // would reject does not start working the moment this stands in for one.
        $this->key($feed, $consumer);

        return null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        $this->key($feed, $consumer);
    }

    public function reset(string $feed, string $consumer): void
    {
        $this->key($feed, $consumer);
    }
}
