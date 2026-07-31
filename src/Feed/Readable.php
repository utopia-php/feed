<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

interface Readable
{
    public function getName(): string;

    /**
     * @return list<CloudEvent>
     * @throws Exception When the events cannot be read.
     */
    public function read(?string $lastEventId, int $limit): array;

    /**
     * @return list<CloudEvent>
     * @throws Exception When the events cannot be read.
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array;

    /**
     * @throws Exception When the tip cannot be read, or only its owner can resolve it.
     */
    public function tip(): ?string;
}
