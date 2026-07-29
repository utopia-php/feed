<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\CloudEvents\Exception as CloudEventsException;
use Utopia\Feed\Exception\Invalid;

/**
 * Where a feed's events live.
 *
 * A journal does two things: assign an ordered id on append, and return the
 * events strictly after a given id. Everything above it — long polling,
 * cursors, the pull loop — is the same whichever journal is underneath.
 */
abstract class Journal
{
    /**
     * Microseconds between reads while waiting in {@see poll()}.
     */
    protected const int POLL_INTERVAL = 500_000;

    /**
     * @param string $name Feed identifier. Also the key the backend stores it
     *        under, and the path segment it is served on.
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
     * allocate, since only it can keep them ordered.
     *
     * @throws Exception When the event cannot be appended.
     */
    abstract public function append(CloudEvent $event): string;

    /**
     * Read up to $limit events strictly after $lastEventId, oldest first, or
     * from the oldest retained event when it is null.
     *
     * An empty result means the consumer is caught up, not that the feed is
     * empty.
     *
     * @return list<CloudEvent>
     * @throws Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    abstract public function read(?string $lastEventId, int $limit): array;

    /**
     * {@see read()}, but wait up to $timeout milliseconds for an event before
     * answering with an empty batch.
     *
     * Waits by re-reading on an interval, which works against any backend. A
     * journal that can do better — {@see Journal\Http} hands the wait to the
     * producer — overrides this.
     *
     * @return list<CloudEvent>
     * @throws Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array
    {
        $deadline = \microtime(true) + $timeout / 1000;

        while (true) {
            $events = $this->read($lastEventId, $limit);

            if ($events !== [] || \microtime(true) >= $deadline) {
                return $events;
            }

            \usleep(self::POLL_INTERVAL);
        }
    }

    /**
     * A feed must retain at least one event. Backends disagree about what a
     * non-positive cap means — some keep nothing, some keep everything — so it
     * is refused here rather than resolved differently on each one.
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
     * them hold; every other attribute is a flat string a backend can index.
     * The id is not among them — it is the key the entry is stored under.
     *
     * @return array<string, string>
     * @throws Invalid When the payload cannot be encoded.
     */
    protected static function encode(CloudEvent $event): array
    {
        return [
            'type' => $event->type,
            'source' => $event->source,
            // CloudEvents models an absent subject and dataschema as null, and
            // a backend field cannot hold one, so both are normalized here and
            // read back as absent in decode().
            'subject' => $event->subject ?? '',
            'dataschema' => $event->dataschema ?? '',
            'time' => $event->time,
            'data' => self::json($event->data, 'data'),
            'extensions' => self::json($event->getExtensions(), 'extensions'),
        ];
    }

    /**
     * Rebuild an event from what {@see encode()} stored.
     *
     * @param array<array-key, mixed> $fields
     * @throws Invalid When the stored entry cannot be read as an event.
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

        foreach (['subject', 'dataschema'] as $optional) {
            $value = self::field($fields, $optional);

            if ($value !== '') {
                $event[$optional] = $value;
            }
        }

        // The union operator rather than a spread, which renumbers integer
        // keys: an extension named only of digits is legal, and PHP holds such
        // a name as an int key. Spec attributes stay on the left, so they win
        // any collision.
        $event += \is_array($extensions) ? $extensions : [];

        try {
            // Lenient for the same reason Protocol::decode() is: the event
            // happened and its id is a valid position, so refusing to return it
            // over one malformed attribute would wedge every consumer behind it.
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
