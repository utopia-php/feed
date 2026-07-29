<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

/**
 * The HTTP shape of a feed, as defined by https://www.http-feeds.org/.
 *
 * A producer serving a feed and a consumer reading one have to agree on the
 * query parameters, the response body and the caching rules. Both halves live
 * here so they cannot drift: {@see Adapter\Http} reads through it, and a
 * producer builds its endpoint's response with it — whichever HTTP framework
 * that endpoint happens to be written in, which is why this deals in arrays
 * rather than in requests and responses.
 *
 * ```php
 * // Producer, in whatever routing layer it uses:
 * $events = $feed->poll(
 *     $request->getParam(Protocol::PARAM_LAST_EVENT_ID) ?: null,
 *     (int) $request->getParam(Protocol::PARAM_LIMIT, Feed::MAX_BATCH),
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
     * Position to read from. Omitted or empty means the oldest retained
     * event — not the newest. Starting at the tip would silently drop whatever
     * is already in the feed, and on a fresh consumer that is not a
     * hypothetical backlog: nothing is recorded until the first event arrives,
     * so the first event is precisely the one that would be skipped.
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
     * poll timeout it asked for.
     *
     * Without it the client's own deadline races the server's: a poll that
     * correctly waits out its full timeout gets cancelled a hair early and
     * surfaces as a transport failure on every quiet tick, burying the
     * failures that matter.
     */
    public const int TIMEOUT_MARGIN = 10_000;

    /**
     * The query string for a read.
     *
     * Parameters at their default are left out rather than sent explicitly, so
     * a consumer and a producer that disagree on a default resolve it the
     * producer's way — and so the URL of a first read is stable enough to be
     * cached and logged as one thing.
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
     * @param list<Event> $events
     * @return array{total: int, events: list<array<string, mixed>>}
     */
    public static function encode(array $events): array
    {
        return [
            self::KEY_TOTAL => \count($events),
            self::KEY_EVENTS => \array_map(static fn (Event $event): array => $event->toArray(), $events),
        ];
    }

    /**
     * Read a batch out of a response body.
     *
     * Stops at the first event that cannot be decoded and returns the ones
     * before it, rather than dropping it and carrying on. An event with no id
     * has no position, so a consumer cannot record having passed it; skipping
     * it would mean every event after it is acknowledged under a cursor that
     * never advanced past the gap, and the next restart would replay them all.
     *
     * Returning the prefix keeps the events that *are* usable moving: the
     * consumer applies them, advances to the last one, and meets the broken
     * event at the head of the next batch — where, with no prefix left to
     * salvage, this throws and the feed visibly stops instead of quietly
     * losing events.
     *
     * @return list<Event>
     * @throws Invalid When the payload is not a batch, or when the very first
     *         event in it cannot be decoded.
     */
    public static function decode(mixed $payload): array
    {
        if (!\is_array($payload)) {
            throw new Invalid('Expected a feed batch, got ' . \get_debug_type($payload));
        }

        $raw = $payload[self::KEY_EVENTS] ?? [];
        if (!\is_array($raw)) {
            throw new Invalid('Feed batch has a malformed "' . self::KEY_EVENTS . '" field');
        }

        $events = [];

        /** @var mixed $event */
        foreach ($raw as $event) {
            if (!\is_array($event)) {
                if ($events === []) {
                    throw new Invalid('Feed batch contains an entry that is not an event');
                }

                break;
            }

            try {
                $events[] = Event::fromArray($event);
            } catch (Invalid $error) {
                if ($events === []) {
                    throw $error;
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
     * @param int $limit Events that were asked for.
     * @param bool $public Whether a shared cache may store the batch. Off by
     *        default: feeds are usually served behind authorization, and
     *        `public` there would let a CDN hand one consumer's events to a
     *        requester that never presented a credential. Only turn it on for
     *        a feed whose events are safe for anyone who can reach the URL.
     */
    public static function cacheControl(int $count, int $limit, bool $public = false): string
    {
        if ($count < $limit || $count === 0) {
            return self::CACHE_NONE;
        }

        return ($public ? 'public, ' : 'private, ') . self::CACHE_IMMUTABLE;
    }
}
