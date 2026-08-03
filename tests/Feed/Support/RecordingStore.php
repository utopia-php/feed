<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Feed\Store\Memory;

/**
 * A memory feed that records what it was asked for.
 *
 * Clamping and coercion are only visible in the arguments the store receives —
 * a caller that stopped forwarding them entirely returns the same events — so
 * asserting on the answer cannot tell the two apart.
 */
class RecordingStore extends Memory
{
    public ?string $lastEventId = null;

    public ?int $limit = null;

    public ?int $timeout = null;

    public function poll(?string $lastEventId, int $limit, int $timeout): array
    {
        $this->lastEventId = $lastEventId;
        $this->limit = $limit;
        $this->timeout = $timeout;

        // Answered without waiting: the point is what was asked for.
        return parent::poll($lastEventId, $limit, 0);
    }
}
