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
        string $feed,
        private readonly bool $onLoad = false,
        private readonly bool $onSave = false,
    ) {
        parent::__construct($feed);
    }

    public function load(string $consumer): ?string
    {
        if ($this->onLoad) {
            throw new Transport('Cursor store is unavailable');
        }

        return parent::load($consumer);
    }

    public function save(string $consumer, string $eventId): void
    {
        if ($this->onSave) {
            throw new Transport('Cursor store is unavailable');
        }

        parent::save($consumer, $eventId);
    }
}
