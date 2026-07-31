<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;

// Server class: durable storage for the events — Journal\Redis, Pool, Memory.
// The one client-side journal is Journal\Http, which reads another service's
// feed over the wire. Journals that own their events also implement Appendable.
abstract class Journal
{
    protected const int POLL_INTERVAL = 500_000; // 0.5s

    public function __construct(protected readonly string $name)
    {
        if ($name === '') {
            throw new Invalid('Feed name is required');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<CloudEvent> */
    abstract public function read(?string $lastEventId, int $limit): array;

    /**
     * The id of the newest event, or null when the feed is empty.
     *
     * @throws Exception
     */
    abstract public function tip(): ?string;

    /**
     * Resolve the tip sentinel into a concrete position: the newest id at the
     * moment of the call, or null on an empty feed — which reads as "the
     * beginning of future events". The sentinel is resolved here, before any
     * id arithmetic; Id itself keeps rejecting it.
     */
    protected function resolve(?string $lastEventId): ?string
    {
        return $lastEventId === Protocol::TIP ? $this->tip() : $lastEventId;
    }

    /**
     * Wait for events, re-reading on an interval until some land or the
     * deadline passes. Journal\Http overrides this: there the producer does the
     * waiting, so a poll is one held request.
     *
     * @return list<CloudEvent>
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array
    {
        // The sentinel is pinned once, before the wait: re-resolving on every
        // read would move the tip past events landing mid-poll, and they
        // would never be delivered.
        $lastEventId = $this->resolve($lastEventId);

        $deadline = \microtime(true) + $timeout / 1000;

        while (true) {
            $events = $this->read($lastEventId, $limit);

            if ($events !== [] || \microtime(true) >= $deadline) {
                return $events;
            }

            \usleep(self::POLL_INTERVAL);
        }
    }

    /** @return array<string, string> */
    protected static function encode(CloudEvent $event): array
    {
        return [
            'type' => $event->type,
            'source' => $event->source,
            // CloudEvents models an absent subject, dataschema and time as
            // null, and a backend field cannot hold one, so they are
            // normalized here and read back as absent in decode().
            'subject' => $event->subject ?? '',
            'dataschema' => $event->dataschema ?? '',
            'time' => $event->time ?? '',
            'data' => self::json($event->data, 'data'),
            'extensions' => self::json($event->extensions, 'extensions'),
        ];
    }

    /** @param array<array-key, mixed> $fields */
    protected static function decode(string $id, array $fields): CloudEvent
    {
        $extensions = \json_decode(self::field($fields, 'extensions'), true);

        $event = [
            'specversion' => '1.0',
            'id' => $id,
            'type' => self::field($fields, 'type'),
            'source' => self::field($fields, 'source'),
            'data' => \json_decode(self::field($fields, 'data'), true),
        ];

        foreach (['subject', 'dataschema', 'time'] as $optional) {
            $value = self::field($fields, $optional);

            if ($value !== '') {
                $event[$optional] = $value;
            }
        }

        $event += \is_array($extensions) ? $extensions : [];

        try {
            // The docblock wants array<string, mixed>, but a digit-only
            // extension name — legal per the spec — is an integer key in PHP.
            // @phpstan-ignore argument.type
            return CloudEvent::fromArray($event);
        } catch (\InvalidArgumentException $error) {
            throw new Invalid("Feed entry {$id} could not be read as an event: {$error->getMessage()}", previous: $error);
        }
    }

    private static function json(mixed $value, string $attribute): string
    {
        try {
            return \json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Invalid("Feed event {$attribute} must be JSON encodable: {$error->getMessage()}", previous: $error);
        }
    }

    /** @param array<array-key, mixed> $fields */
    private static function field(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
