<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

// Server and client class: the read view of a feed — read and long-poll.
// Server serves its own feed with this; client reads a remote one through Journal\Http.
class Feed
{
    public const int MAX_BATCH = 1000;

    public const int MAX_TIMEOUT = 30_000;

    public function __construct(protected readonly Journal $journal)
    {
    }

    public function getName(): string
    {
        return $this->journal->getName();
    }

    /** @return list<CloudEvent> */
    public function read(?string $lastEventId = null, int $limit = self::MAX_BATCH): array
    {
        return $this->journal->read($lastEventId, \max(1, \min($limit, self::MAX_BATCH)));
    }

    /** @return list<CloudEvent> */
    public function poll(?string $lastEventId = null, int $limit = self::MAX_BATCH, int $timeout = 0): array
    {
        return $this->journal->poll(
            $lastEventId,
            \max(1, \min($limit, self::MAX_BATCH)),
            \max(0, \min($timeout, self::MAX_TIMEOUT)),
        );
    }
}
