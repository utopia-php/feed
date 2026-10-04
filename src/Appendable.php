<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

// Server interface
interface Appendable
{
    /**
     * @throws Exception When the event cannot be appended.
     */
    public function append(CloudEvent $event): string;
}
