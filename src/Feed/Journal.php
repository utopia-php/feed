<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\CloudEvents\Exception as CloudEventsException;
use Utopia\Feed\Exception\Invalid;

/**
 * Where a feed's events actually live.
 *
 * Named for what event sourcing has long called an append-only, strictly
 * ordered record that is replayed rather than mutated — the same sense in which
 * Akka Persistence calls its pluggable storage backends journals.
 *
 * A journal is responsible for two things and nothing else: assigning an
 * ordered id on append, and returning the events strictly after a given id.
 * Everything above that — long polling on backends that cannot do it
 * themselves, cursors, the pull loop — is the same regardless of the backend
 * and lives in {@see Feed} and {@see Consumer}.
 *
 * Journals split into producers (Redis, Pool, Memory), which own the events,
 * and consumers ({@see Journal\Http}), which read someone else's feed over the
 * wire. The read side is identical either way, which is what lets a service
 * consume a remote feed with the same code it uses on a local one.
 */
abstract class Journal
{
    /**
     * @param string $name Feed identifier. Also the key the backend stores it
     *        under, and the path segment it is served on, so it is part of the
     *        contract with consumers rather than a local label.
     * @throws Invalid When $name is empty.
     */
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

    /**
     * Append an event and return the id the backend assigned it.
     *
     * Any id already on $event is ignored: positions are the backend's to
     * allocate, since only it can guarantee they are ordered.
     *
     * @throws Exception When the event cannot be appended. Never silently, and
     *         never partially — a caller that gets an id back can tell every
     *         consumer will see the event.
     */
    abstract public function append(CloudEvent $event): string;

    /**
     * Read up to $limit events strictly after $lastEventId, oldest first, or
     * from the oldest retained event when it is null.
     *
     * An empty result means the consumer is caught up, not that the feed is
     * empty.
     *
     * @param int $timeout Milliseconds to wait for an event before giving up,
     *        honoured only when {@see pollable()} is true; {@see Feed::poll()}
     *        handles the wait for every other journal.
     * @return list<CloudEvent>
     * @throws Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    abstract public function read(?string $lastEventId, int $limit, int $timeout = 0): array;

    /**
     * Whether the backend blocks until an event arrives on its own.
     *
     * False here rather than abstract because polling in a loop works against
     * anything; a journal only overrides it when the backend can do better,
     * and {@see Feed::poll()} then hands the wait over instead of sleeping.
     */
    public function pollable(): bool
    {
        return false;
    }

    /**
     * Guard a retention cap.
     *
     * Non-positive values do not mean "no retention" — they mean something
     * different on every backend, and nothing useful on any. Redis reads
     * `MAXLEN 0` as "trim everything", so a feed would accept appends and
     * retain none of them; `array_slice($events, -0)` is `array_slice($events,
     * 0)`, so the in-memory journal would do the exact opposite and retain the
     * lot, unbounded. A cap that silently means one thing here and the reverse
     * there is worse than no cap, so it is rejected at construction.
     *
     * @throws Invalid When $maxSize would retain fewer than one event.
     */
    protected static function assertRetention(int $maxSize): void
    {
        if ($maxSize < 1) {
            throw new Invalid("Feed retention must be at least one event, got {$maxSize}");
        }
    }

    /**
     * The backend fields an event is stored as.
     *
     * `data` and `extensions` are JSON so they can hold what CloudEvents lets
     * them hold; every other attribute is a flat string, because those are the
     * ones a backend may want to index or filter on. The id is not among them
     * — it is the key the entry is stored under.
     *
     * Extensions are stored rather than dropped: a producer that attaches one
     * — a `traceparent`, say — means it to reach the consumer, and losing it
     * on the way through the backend would be invisible at both ends.
     *
     * @return array<string, string>
     * @throws Invalid When the payload cannot be encoded.
     */
    protected static function encode(CloudEvent $event): array
    {
        return [
            'type' => $event->type,
            'source' => $event->source,
            // CloudEvents models an absent subject as null. A backend field is
            // a string, so it is normalized here rather than stored as a null
            // that would read back as "" on one backend and break on another.
            'subject' => $event->subject ?? '',
            'time' => $event->time,
            'dataschema' => $event->dataschema ?? '',
            'data' => self::json($event->data, 'data'),
            'extensions' => self::json($event->getExtensions(), 'extensions'),
        ];
    }

    /**
     * Rebuild an event from what {@see encode()} stored.
     *
     * Decoded leniently, for the same reason {@see Protocol::decode()} is: the
     * event happened, its id is a valid position, and refusing to return it
     * over one malformed attribute would wedge every consumer behind it.
     *
     * @param array<array-key, mixed> $fields
     * @throws Invalid When the stored entry cannot be read as an event at all.
     */
    protected static function decode(string $id, array $fields): CloudEvent
    {
        $extensions = \json_decode(self::field($fields, 'extensions'), true);

        $event = [
            'specversion' => CloudEvent::SPECVERSION,
            'id' => $id,
            'type' => self::field($fields, 'type'),
            'source' => self::field($fields, 'source'),
            'time' => self::field($fields, 'time'),
            'data' => \json_decode(self::field($fields, 'data'), true),
        ];

        // The inverse of the normalization in encode(): these two are nullable
        // on a CloudEvent, and a backend field cannot hold a null, so an empty
        // stored field means the attribute was absent. Passing the empty string
        // through instead would turn "no subject" into "a subject that is the
        // empty string" on every round trip through a backend.
        foreach (['subject', 'dataschema'] as $optional) {
            $value = self::field($fields, $optional);

            if ($value !== '') {
                $event[$optional] = $value;
            }
        }

        // The union operator rather than a spread, which renumbers integer keys.
        // An extension name of only digits is legal — the spec allows [a-z0-9]+ —
        // and PHP stores such a name as an int key, so a spread would silently
        // rename "123" to the next free position and lose the attribute.
        // Spec attributes stay on the left, so they win any collision.
        $event += \is_array($extensions) ? $extensions : [];

        try {
            return CloudEvent::fromArray($event, lenient: true);
        } catch (CloudEventsException $error) {
            throw new Invalid("Feed entry {$id} could not be read as an event: {$error->getMessage()}", previous: $error);
        }
    }

    /**
     * @throws Invalid When the value cannot be encoded.
     */
    private static function json(mixed $value, string $attribute): string
    {
        try {
            return \json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Invalid("Feed event {$attribute} must be JSON encodable: {$error->getMessage()}", previous: $error);
        }
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private static function field(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
