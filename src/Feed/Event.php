<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * One event in a feed, shaped as a CloudEvent (https://cloudevents.io/) the
 * way http-feeds requires.
 *
 * Immutable, because an event that has been appended is history: a consumer
 * three regions away may already have acted on it.
 *
 * Unknown fields are dropped on the way in rather than rejected. A producer
 * that starts sending a new attribute must not break the consumers that
 * predate it — which is the property that lets a feed be rolled out to its
 * consumers gradually.
 */
final readonly class Event
{
    public const string SPEC_VERSION = '1.0';

    public const string CONTENT_TYPE = 'application/json';

    /**
     * @param string $id Position in the feed. Empty on an event that has not
     *        been appended yet — the backend assigns it.
     * @param string $type What happened, in reverse-DNS notation
     *        (`io.appwrite.edge.invalidate-rule`).
     * @param array<array-key, mixed> $data Payload. Must survive a JSON
     *        round-trip, which is also why the keys are not narrowed to
     *        strings: a payload is allowed to be a JSON array, and that decodes
     *        with integer keys. Producers normally send a map.
     * @param string $source Who produced the event, as a URI reference
     *        (`urn:appwrite:cloud:fra`). Stamped by {@see Feed} on append.
     * @param string $subject The single business object the event is about,
     *        when it has one, so a consumer can filter without decoding
     *        `$data`.
     * @param string $time RFC 3339 timestamp. Stamped on append.
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data = [],
        public string $source = '',
        public string $subject = '',
        public string $time = '',
    ) {
    }

    /**
     * Decode an event received from a producer.
     *
     * @param array<array-key, mixed> $event
     * @throws Invalid When the event carries no id.
     *         The id is the one field a consumer cannot proceed without: it is
     *         the cursor position, so accepting an event without one would mean
     *         losing the place in the feed. Every other field is defaulted,
     *         because a consumer that only reads `data` should not be stopped
     *         by a producer that omits `subject`.
     */
    public static function fromArray(array $event): self
    {
        $id = $event['id'] ?? '';
        if (!\is_string($id) || $id === '') {
            throw new Invalid('Feed event is missing an id');
        }

        $data = $event['data'] ?? [];

        return new self(
            id: $id,
            type: self::string($event, 'type'),
            data: \is_array($data) ? $data : [],
            source: self::string($event, 'source'),
            subject: self::string($event, 'subject'),
            time: self::string($event, 'time'),
        );
    }

    /**
     * The event as a CloudEvent, ready to be serialized to JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'specversion' => self::SPEC_VERSION,
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'time' => $this->time,
            'subject' => $this->subject,
            'datacontenttype' => self::CONTENT_TYPE,
            'data' => $this->data,
        ];
    }

    /**
     * Read one key out of the payload.
     *
     * Handlers are looking at data some other service wrote, so this exists to
     * keep them from having to re-check `isset` and the type on every access.
     */
    public function getData(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * The same event at a new position, used by adapters to attach the id the
     * backend assigned on append.
     */
    public function withId(string $id): self
    {
        return new self(
            id: $id,
            type: $this->type,
            data: $this->data,
            source: $this->source,
            subject: $this->subject,
            time: $this->time,
        );
    }

    /**
     * Now, in the RFC 3339 form http-feeds asks for: UTC, milliseconds, `Z`.
     */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private static function string(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
