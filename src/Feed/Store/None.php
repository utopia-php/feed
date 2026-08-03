<?php

declare(strict_types=1);

namespace Utopia\Feed\Store;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Appendable;
use Utopia\Feed\Store;

class None extends Store implements Appendable
{
    public function __construct(string $name = 'none')
    {
        parent::__construct($name);
    }

    public function append(CloudEvent $event): string
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }

    public function tip(): ?string
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }

    public function read(?string $lastEventId, int $limit): array
    {
        throw new Unsupported("No feed backend is configured for the {$this->name} feed");
    }
}
