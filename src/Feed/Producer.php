<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

class Producer
{
    /**
     * @throws Exception\Invalid When $source is empty.
     */
    public function __construct(
        protected readonly Store&Appendable $store,
        protected readonly string $source,
    ) {
        if ($source === '') {
            throw new Exception\Invalid('Feed producer requires a source');
        }
    }

    public function getName(): string
    {
        return $this->store->getName();
    }

    /**
     * @throws Exception\Invalid When $type is empty or $data cannot be encoded.
     * @throws Exception When the backend rejects the event.
     */
    public function produce(string $type, mixed $data = [], string $subject = ''): string
    {
        return $this->publish(new CloudEvent(
            type: $type,
            source: $this->source,
            id: '',
            subject: $subject === '' ? null : $subject,
            data: $data,
        ));
    }

    /**
     * Publish a prepared event.
     *
     * Three attributes are replaced whatever the event arrived with: `source`
     * (a producer only speaks for itself, so a relayed event is republished as
     * this service's), `id` (the store assigns it — it is also the position)
     * and a missing `time`. `specversion` reads back as `1.0`; the rest is
     * published as prepared.
     *
     * @throws Exception\Invalid When the event has no type or cannot be encoded.
     * @throws Exception When the backend rejects the event.
     */
    public function publish(CloudEvent $event): string
    {
        if ($event->type === '') {
            throw new Exception\Invalid('Feed event type is required');
        }

        // Rebuilt attribute by attribute to have untouched copy
        $event = new CloudEvent(
            type: $event->type,
            source: $this->source,
            id: $event->id,
            specversion: $event->specversion,
            subject: $event->subject,
            time: $event->time === null || $event->time === '' ? self::now() : $event->time,
            datacontenttype: $event->datacontenttype,
            data: $event->data,
            dataschema: $event->dataschema,
            extensions: $event->extensions,
        );

        return $this->store->append($event);
    }

    /** The current time in the RFC 3339 format the spec requires. */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
