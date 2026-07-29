<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\CloudEvents\Exception as CloudEventsException;
use Utopia\Feed\Exception\Invalid;

// HTTP shape of a feed, as defined by https://www.http-feeds.org/.
final class Protocol
{
    public const string PARAM_LAST_EVENT_ID = 'lastEventId';
    public const string PARAM_LIMIT = 'limit';
    public const string PARAM_TIMEOUT = 'timeout';

    public const string KEY_EVENTS = 'events';
    public const string KEY_TOTAL = 'total';

    public const string CACHE_IMMUTABLE = 'max-age=31536000';
    public const string CACHE_NONE = 'no-store';

    public const int TIMEOUT_MARGIN = 10_000;

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
     * @param list<CloudEvent> $events
     * @return array{total: int, events: list<array<array-key, mixed>>}
     */
    public static function encode(array $events): array
    {
        return [
            self::KEY_TOTAL => \count($events),
            self::KEY_EVENTS => \array_map(static fn (CloudEvent $event): array => $event->toArray(), $events),
        ];
    }

    /**
     * @return list<CloudEvent>
     */
    public static function decode(mixed $payload): array
    {
        if (!\is_array($payload)) {
            throw new Invalid('Expected a feed batch, got ' . \get_debug_type($payload));
        }

        if (!\array_key_exists(self::KEY_EVENTS, $payload)) {
            throw new Invalid('Feed batch is missing the "' . self::KEY_EVENTS . '" field');
        }

        $raw = $payload[self::KEY_EVENTS];
        if (!\is_array($raw)) {
            throw new Invalid('Feed batch has a malformed "' . self::KEY_EVENTS . '" field');
        }

        $events = [];

        /** @var mixed $event */
        foreach ($raw as $event) {
            try {
                if (!\is_array($event)) {
                    throw new Invalid('Feed batch contains an entry that is not an event');
                }

                $events[] = self::event($event);
            } catch (Invalid | CloudEventsException $error) {
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
     * @param array<array-key, mixed> $raw
     */
    private static function event(array $raw): CloudEvent
    {
        $event = CloudEvent::fromArray($raw, lenient: true, allowUnknownSpecversion: true);

        if ($event->id === '') {
            throw new Invalid('Feed event is missing an id');
        }

        return $event;
    }
}
