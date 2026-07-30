<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

// Server class: appends events to a feed this service owns.
// The journal must be Appendable, so a remote feed cannot reach this at all.
class Producer
{
    /**
     * @param Journal&Appendable $journal Where the events live.
     * @param string $source Who is producing them, as a URI reference
     *        (`urn:appwrite:cloud:fra`). Stamped onto every event, so a consumer
     *        merging feeds from several producers can tell them apart.
     * @throws Exception\Invalid When $source is empty.
     */
    public function __construct(
        protected readonly Journal&Appendable $journal,
        protected readonly string $source,
    ) {
        if ($source === '') {
            throw new Exception\Invalid('Feed producer requires a source');
        }
    }

    public function getName(): string
    {
        return $this->journal->getName();
    }

    /**
     * Append an event and return its position in the feed.
     *
     * @throws Exception\Invalid When $type is empty or $data cannot be encoded.
     * @throws Exception When the backend rejects the append.
     */
    public function append(string $type, mixed $data = [], string $subject = ''): string
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
     * Append a prepared event, stamping it with this producer's source and,
     * unless it already has one, the current time.
     *
     * @throws Exception\Invalid When the event has no type or cannot be encoded.
     * @throws Exception When the backend rejects the append.
     */
    public function publish(CloudEvent $event): string
    {
        if ($event->type === '') {
            throw new Exception\Invalid('Feed event type is required');
        }

        // Rebuilt attribute by attribute — extensions included — so anything
        // this library does not model, a dataschema or a traceparent, survives
        // untouched.
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

        return $this->journal->append($event);
    }

    /** The current time in the RFC 3339 format the spec requires. */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
