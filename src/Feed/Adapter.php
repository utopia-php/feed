<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * Where a feed's events actually live.
 *
 * An adapter is responsible for two things and nothing else: assigning an
 * ordered id on append, and returning the events strictly after a given id.
 * Everything above that — long polling on backends that cannot do it
 * themselves, cursors, the pull loop — is the same regardless of the backend
 * and lives in {@see Feed} and {@see Consumer}.
 *
 * Adapters split into producers (Redis, Pool, Memory), which own the events,
 * and consumers ({@see Adapter\Http}), which read someone else's feed over the
 * wire. The read side is identical either way, which is what lets a service
 * consume a remote feed with the same code it uses on a local one.
 */
abstract class Adapter
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
    abstract public function append(Event $event): string;

    /**
     * Read up to $limit events strictly after $lastEventId, oldest first, or
     * from the oldest retained event when it is null.
     *
     * An empty result means the consumer is caught up, not that the feed is
     * empty.
     *
     * @param int $timeout Milliseconds to wait for an event before giving up,
     *        honoured only when {@see pollable()} is true; {@see Feed::poll()}
     *        handles the wait for every other adapter.
     * @return list<Event>
     * @throws Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    abstract public function read(?string $lastEventId, int $limit, int $timeout = 0): array;

    /**
     * Whether the backend blocks until an event arrives on its own.
     *
     * False here rather than abstract because polling in a loop works against
     * anything; an adapter only overrides it when the backend can do better,
     * and {@see Feed::poll()} then hands the wait over instead of sleeping.
     */
    public function pollable(): bool
    {
        return false;
    }

    /**
     * The backend fields an event is stored as.
     *
     * `data` is JSON so the payload can nest; everything else is a flat string
     * because those are the fields a backend may want to index or filter on.
     * The id is not among them — it is the key the entry is stored under.
     *
     * @return array<string, string>
     * @throws Invalid When the payload cannot be encoded.
     */
    protected static function encode(Event $event): array
    {
        try {
            $data = \json_encode($event->data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Invalid('Feed event data must be JSON encodable: ' . $error->getMessage(), previous: $error);
        }

        return [
            'type' => $event->type,
            'source' => $event->source,
            'subject' => $event->subject,
            'time' => $event->time,
            'data' => $data,
        ];
    }

    /**
     * Rebuild an event from what {@see encode()} stored.
     *
     * Undecodable payloads become an empty array rather than an error: the
     * event still happened, its id is still a valid position, and refusing to
     * return it would wedge every consumer behind it forever.
     *
     * @param array<array-key, mixed> $fields
     */
    protected static function decode(string $id, array $fields): Event
    {
        $data = \json_decode(self::field($fields, 'data'), true);

        return new Event(
            id: $id,
            type: self::field($fields, 'type'),
            data: \is_array($data) ? $data : [],
            source: self::field($fields, 'source'),
            subject: self::field($fields, 'subject'),
            time: self::field($fields, 'time'),
        );
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
