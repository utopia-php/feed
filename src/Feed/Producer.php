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
     * Three attributes are the producer's, not the caller's, and are replaced
     * whatever the event arrived with:
     *
     * - `source` becomes this producer's. It records where the event happened,
     *   and a producer can only speak for itself — an event relayed from
     *   another feed is published as this service's event, not as the original
     *   producer's. Keep the origin in an extension attribute if it matters.
     * - `id` is assigned by the store, since it is also the event's position
     *   in the feed and only the store can order it.
     * - `time` is stamped as now when the event carries none.
     *
     * Everything else — `subject`, `datacontenttype`, `dataschema`, `data`
     * and extensions — is published as prepared. `specversion` reads back as
     * `1.0`: it is the only version the stored form can be decoded as, so a
     * store that kept another one would hold an entry nothing could read.
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
