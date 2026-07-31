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
}
