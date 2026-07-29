<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;

/**
 * Positions held in process memory.
 *
 * For tests, and for a consumer that genuinely wants to start from the
 * beginning of the retained feed on every restart. Anything else will replay
 * the whole feed each time it is deployed.
 */
class Memory extends Cursor
{
    /** @var array<string, string> */
    private array $cursors = [];

    public function load(string $consumer): ?string
    {
        return $this->cursors[$this->key($consumer)] ?? null;
    }

    public function save(string $consumer, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $this->cursors[$this->key($consumer)] = $eventId;
    }

    public function reset(string $consumer): void
    {
        unset($this->cursors[$this->key($consumer)]);
    }
}
