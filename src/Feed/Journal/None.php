<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Journal;

class None extends Journal
{
    public function __construct(string $name = 'none')
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
