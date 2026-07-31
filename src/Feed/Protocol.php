<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;

// HTTP shape of a feed, as defined by https://www.http-feeds.org/.
final class Protocol
{
    public const string PARAM_LAST_EVENT_ID = 'lastEventId';
    public const string PARAM_LIMIT = 'limit';
    public const string PARAM_TIMEOUT = 'timeout';

    public const string MEDIA_TYPE = 'application/cloudevents-batch+json';

    public const string CACHE_IMMUTABLE = 'max-age=31536000';
    public const string CACHE_NONE = 'no-store';

    public const int TIMEOUT_MARGIN = 10_000;

    /** The context attributes this library models; the rest are extensions. */
    private const array ATTRIBUTES = [
        'specversion',
        'type',
        'source',
        'id',
        'subject',
        'time',
        'datacontenttype',
        'dataschema',
        'data',
    ];

    /**
     * @return array<string, string|int>
     */
    public static function query(?string $lastEventId = null, int $limit = 0, int $timeout = 0): array
    {
        $query = [];

        if ($lastEventId !== null && $lastEventId !== '') {
            $query[self::PARAM_LAST_EVENT_ID] = $lastEventId;
        }

        if ($limit > 0) {
            $query[self::PARAM_LIMIT] = $limit;
        }

        if ($timeout > 0) {
            $query[self::PARAM_TIMEOUT] = $timeout;
        }

        return $query;
    }

    /**
     * A batch on the wire is a plain array of CloudEvents — no envelope. An
     * empty feed serializes to `[]`, which the spec reads as "you are caught up".
     *
     * @param list<CloudEvent> $events
     * @return list<array<array-key, mixed>>
     */
    public static function encode(array $events): array
    {
        return \array_map(static fn (CloudEvent $event): array => $event->toArray(), $events);
    }

    /**
     * Read a batch off the wire.
     *
     * An entry that cannot be read ends the batch there rather than failing the
     * whole response: the events before it are handled and the position
     * advances past them, leaving the broken entry at the head of the next
     * batch, where it stops the feed loudly. With no usable prefix there is
     * nothing to advance to, so that case throws.
     *
     * @return list<CloudEvent>
     *
     * @throws Invalid When the payload is not a batch, or its first entry cannot be read.
     */
    public static function decode(mixed $payload): array
    {
        if (!\is_array($payload)) {
            throw new Invalid('Expected a feed batch, got ' . \get_debug_type($payload));
        }

        if (!\array_is_list($payload)) {
            throw new Invalid('Expected a feed batch as a plain array of events');
        }

        $events = [];

        /** @var mixed $event */
        foreach ($payload as $event) {
            try {
                if (!\is_array($event)) {
                    throw new Invalid('Feed batch contains an entry that is not an event');
                }

                $events[] = self::event($event);
            } catch (Invalid | \InvalidArgumentException $error) {
                if ($events === []) {
                    throw $error instanceof Invalid
                        ? $error
                        : new Invalid('Feed batch contains an event that cannot be read: ' . $error->getMessage(), previous: $error);
                }

                break;
            }
        }

        return $events;
    }

    public static function cacheControl(int $count, int $limit, bool $public = false): string
    {
        if ($count < $limit || $count === 0) {
            return self::CACHE_NONE;
        }

        return ($public ? 'public, ' : 'private, ') . self::CACHE_IMMUTABLE;
    }

    /**
     * Read one event off the wire.
     *
     * Mapped by hand rather than through CloudEvent::fromArray(), which
     * rejects a specversion it does not know. A feed is read by consumers
     * older than its producer by design, so a producer that moved the spec
     * version forward, or attached an attribute this library cannot model,
     * must not stop one that predates it — what cannot be carried is dropped,
     * not fatal.
     *
     * @param array<array-key, mixed> $raw
     */
    private static function event(array $raw): CloudEvent
    {
        foreach (['specversion', 'id', 'type', 'source'] as $required) {
            if (!isset($raw[$required]) || !\is_string($raw[$required]) || $raw[$required] === '') {
                throw new Invalid('Feed event is missing ' . ($required === 'id' ? 'an id' : 'a ' . $required));
            }
        }

        $extensions = [];

        /** @var mixed $value */
        foreach ($raw as $name => $value) {
            if (\in_array($name, self::ATTRIBUTES, true)) {
                continue;
            }

            // Only what the CloudEvent constructor accepts as an extension —
            // anything else would throw and stop the feed.
            if (\preg_match('/^[a-z0-9]+$/', (string) $name) === 1
                && (\is_bool($value) || \is_int($value) || \is_string($value))) {
                $extensions[$name] = $value;
            }
        }

        return new CloudEvent(
            type: $raw['type'],
            source: $raw['source'],
            id: $raw['id'],
            specversion: $raw['specversion'],
            subject: self::optional($raw, 'subject'),
            time: self::optional($raw, 'time'),
            datacontenttype: self::optional($raw, 'datacontenttype'),
            data: $raw['data'] ?? null,
            dataschema: self::optional($raw, 'dataschema'),
            // The docblock wants array<string, mixed>, but a digit-only
            // extension name — legal per the spec — is an integer key in PHP.
            // @phpstan-ignore argument.type
            extensions: $extensions,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function optional(array $raw, string $attribute): ?string
    {
        $value = $raw[$attribute] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
