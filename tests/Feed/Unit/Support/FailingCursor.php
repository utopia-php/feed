<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Utopia\Feed\Cursor\Memory;
use Utopia\Feed\Exception\Transport;

/**
 * A cursor store that is down, for testing that a consumer survives one.
 */
class FailingCursor extends Memory
{
    public function __construct(
        private readonly bool $onLoad = false,
        private readonly bool $onSave = false,
    ) {
    }

    public function load(string $feed, string $consumer): ?string
    {
        if ($this->onLoad) {
            throw new Transport('Cursor store is unavailable');
        }

        return parent::load($feed, $consumer);
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        if ($this->onSave) {
            throw new Transport('Cursor store is unavailable');
        }

        parent::save($feed, $consumer, $eventId);
    }
}
