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

        // Stamped with the withers rather than rebuilt, so anything this library
        // does not model — a dataschema, a traceparent — survives untouched.
        $event = $event->withSource($this->source);

        return $this->journal->append($event->time === '' ? $event->withTime() : $event);
    }
}
