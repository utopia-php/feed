<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Journal;

/**
 * No backend configured. Every operation throws.
 *
 * Lets a service construct its feeds unconditionally and fail at the point of
 * use, instead of threading a nullable feed through every caller.
 *
 * Deliberately not a no-op: a feed that silently swallowed appends would leave
 * the producer believing its consumers had been told, and the consequence only
 * shows up much later somewhere else. If dropping events is genuinely
 * acceptable, use {@see Memory}.
 */
class Unconfigured extends Journal
{
    public function __construct(string $name = 'unconfigured')
    {
        parent::__construct($name);
    }

    public function append(CloudEvent $event): string
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }

    public function read(?string $lastEventId, int $limit): array
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }
}
