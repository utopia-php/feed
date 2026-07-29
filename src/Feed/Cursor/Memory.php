<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;

/**
 * Positions held in process memory.
 *
 * For tests, and for a consumer that genuinely wants to start from the
 * beginning of the retained feed on every restart.
 */
class Memory extends Cursor
{
    /** @var array<string, string> */
    private array $cursors = [];

    public function load(string $feed, string $consumer): ?string
    {
        return $this->cursors[$this->key($feed, $consumer)] ?? null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $this->cursors[$this->key($feed, $consumer)] = $eventId;
    }

    public function reset(string $feed, string $consumer): void
    {
        unset($this->cursors[$this->key($feed, $consumer)]);
    }
}
