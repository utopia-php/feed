<?php

declare(strict_types=1);

namespace Utopia\Feed\Adapter;

use Utopia\Feed\Adapter;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Unsupported;

/**
 * No backend configured. Every operation throws.
 *
 * Lets a service construct its feeds unconditionally and fail at the point of
 * use, instead of threading a nullable feed through every caller.
 *
 * Deliberately not a no-op, unlike the null adapters elsewhere in Utopia. A
 * feed that silently swallowed appends would leave the producer believing its
 * consumers had been told, and the consequence of that only shows up much
 * later somewhere else — a cache that never invalidates, a replica that never
 * catches up. If dropping events is genuinely acceptable, use {@see Memory}.
 */
class None extends Adapter
{
    public function __construct(string $name = 'none')
    {
        parent::__construct($name);
    }

    public function append(Event $event): string
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }

    public function read(?string $lastEventId, int $limit, int $timeout = 0): array
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }
}
