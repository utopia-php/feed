<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\CloudEvents\Exception as CloudEventsException;
use Utopia\Feed\Exception\Invalid;

/**
 * The HTTP shape of a feed, as defined by https://www.http-feeds.org/.
 *
 * Both halves live here so a producer's endpoint and its consumers cannot
 * drift: {@see Journal\Http} reads through it, and a producer builds its
 * response with it — in whichever HTTP framework it is written in, which is why
 * this deals in arrays rather than in requests and responses.
 *
 * ```php
 * // Producer, in whatever routing layer it uses:
 * $limit = Feed::limit((int) $request->getParam(Protocol::PARAM_LIMIT, Feed::MAX_BATCH));
 *
 * $events = $feed->poll(
 *     $request->getParam(Protocol::PARAM_LAST_EVENT_ID) ?: null,
 *     $limit,
 *     (int) $request->getParam(Protocol::PARAM_TIMEOUT, 0),
 * );
 *
 * $response
 *     ->addHeader('Cache-Control', Protocol::cacheControl(\count($events), $limit))
 *     ->json(Protocol::encode($events));
 * ```
 */
final class Protocol
{
    /**
     * Position to read from. Omitted or empty means the oldest retained event —
     * not the newest, which would silently drop whatever is already in the feed.
     */
    public const string PARAM_LAST_EVENT_ID = 'lastEventId';

    /**
     * Most events to return.
     */
    public const string PARAM_LIMIT = 'limit';

    /**
     * Milliseconds to hold the request open waiting for an event before
     * answering with an empty batch. Zero returns immediately.
     */
    public const string PARAM_TIMEOUT = 'timeout';

    public const string KEY_EVENTS = 'events';

    public const string KEY_TOTAL = 'total';

    /**
     * A full batch is settled history — the same query returns the same events
     * forever — so it may be cached indefinitely.
     */
    public const string CACHE_IMMUTABLE = 'max-age=31536000';

    /**
     * A short batch is the live end of the feed. Re-asking the same question a
     * second later legitimately returns more, so it must not be cached at all.
     */
    public const string CACHE_NONE = 'no-store';

    /**
     * Extra milliseconds a consumer allows its HTTP client on top of the long
     * poll timeout it asked for, so the client's deadline does not race the
     * producer's.
     */
    public const int TIMEOUT_MARGIN = 10_000;

    /**
     * The query string for a read.
     *
     * Parameters at their default are left out, so a consumer and a producer
     * that disagree on a default resolve it the producer's way — and so the URL
     * of a first read is stable enough to be cached and logged as one thing.
     *
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
     * The response body for a batch.
     *
     * @param list<CloudEvent> $events
     * @return array{total: int, events: list<array<array-key, mixed>>} The keys
     *         are not narrowed to strings because an extension attribute named
     *         only of digits is legal, and PHP holds such a name as an int key.
     */
    public static function encode(array $events): array
    {
        return [
            self::KEY_TOTAL => \count($events),
            self::KEY_EVENTS => \array_map(static fn (CloudEvent $event): array => $event->toArray(), $events),
        ];
    }

    /**
     * Read a batch out of a response body.
     *
     * Decoded leniently, and tolerating a `specversion` this consumer has never
     * seen: a feed is read by consumers older than the producer *by design*, so
     * a producer that adds an attribute or moves the spec forward must not stop
     * one that predates it. Only `id` is enforced — for a feed the id *is* the
     * consumer's position, so an event without one cannot be recorded as passed.
     *
     * Stops at the first event that cannot be decoded and returns the ones
     * before it. The consumer applies those, advances, and meets the broken
     * event at the head of the next batch — where, with no prefix left to
     * salvage, this throws and the feed visibly stops instead of quietly
     * skipping past a gap.
     *
     * @return list<CloudEvent>
     * @throws Invalid When the payload is not a batch, or when the very first
     *         event in it cannot be decoded.
     */
    public static function decode(mixed $payload): array
    {
        if (!\is_array($payload)) {
            throw new Invalid('Expected a feed batch, got ' . \get_debug_type($payload));
        }

        // Required, not defaulted to empty: an empty batch and a response that
        // is not a batch at all mean opposite things — "you are caught up"
        // versus "you did not reach the feed" — and a misrouted request or a
        // proxy's error page must not read as a quiet, permanent caught-up
        // state.
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

    /**
     * What a producer should send as `Cache-Control` for a batch.
     *
     * @param int $count Events being returned.
     * @param int $limit Events the batch was built with, after {@see
     *        Feed::limit()} has clamped what the consumer asked for.
     * @param bool $public Whether a shared cache may store the batch. Off by
     *        default: feeds are usually served behind authorization, and
     *        `public` there would let a CDN hand one consumer's events to a
     *        requester that never presented a credential.
     */
    public static function cacheControl(int $count, int $limit, bool $public = false): string
    {
        if ($count < $limit || $count === 0) {
            return self::CACHE_NONE;
        }

        return ($public ? 'public, ' : 'private, ') . self::CACHE_IMMUTABLE;
    }

    /**
     * Decode one event, enforcing the only attribute a feed cannot do without.
     *
     * @param array<array-key, mixed> $raw
     * @throws Invalid When the event carries no usable id.
     * @throws CloudEventsException When it is not a CloudEvent at all.
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
