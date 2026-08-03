<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;

class None extends Cursor
{
    public function load(string $feed, string $consumer): ?string
    {
        // Validated even though nothing is stored
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

    /** Nothing is stored, so nothing can conflict: the caller's own memory is the only record. */
    public function advance(string $feed, string $consumer, string $eventId, ?string $expected): bool
    {
        $this->key($feed, $consumer);

        return true;
    }
}
