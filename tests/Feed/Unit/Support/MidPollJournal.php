<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Journal\Memory;

/**
 * A journal where another process appends while a poll is waiting: the event
 * lands just before the poll loop's second read, mid-wait.
 */
class MidPollJournal extends Memory
{
    private int $reads = 0;

    public function __construct(string $name, private readonly string $lands = 'landed', int $pollInterval = self::POLL_INTERVAL)
    {
        parent::__construct($name, pollInterval: $pollInterval);
    }

    public function read(?string $lastEventId, int $limit): array
    {
        if ($this->reads++ === 1) {
            $this->append(new CloudEvent(id: '', type: $this->lands, source: 'urn:test'));
        }

        return parent::read($lastEventId, $limit);
    }
}
