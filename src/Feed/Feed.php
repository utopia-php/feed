<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

class Feed
{
    public const int MAX_BATCH = 1000;

    public const int MAX_TIMEOUT = 30_000;

    public function __construct(
        protected readonly Journal $journal,
        protected readonly string $source = '',
    ) {
    }

    public function getName(): string
    {
        return $this->journal->getName();
    }

    public function append(string $type, mixed $data = [], string $subject = ''): string
    {
        return $this->publish(new CloudEvent(
            type: $type,
            subject: $subject === '' ? null : $subject,
            data: $data,
        ));
    }

    public function publish(CloudEvent $event): string
    {
        if ($event->type === '') {
            throw new Exception\Invalid('Feed event type is required');
        }

        if ($this->source === '') {
            throw new Exception\Invalid('Feed source is required to append; construct the feed with one');
        }

        $event = $event->withSource($this->source);

        return $this->journal->append($event->time === '' ? $event->withTime() : $event);
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
