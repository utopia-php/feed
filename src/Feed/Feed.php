<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

/**
 * An append-only, ordered sequence of events that consumers pull.
 *
 * Follows http-feeds (https://www.http-feeds.org/): each consumer asks for what
 * it has not seen yet, quoting the id of the last event it processed, so one
 * that was down or only just deployed catches up on its next read.
 *
 * Delivery is at-least-once and retention is bounded, so every event must be
 * safe to process twice, and a consumer that falls behind the trim horizon
 * resumes from the oldest retained event.
 *
 * ```php
 * $feed = new Feed(new Journal\Redis($redis, 'edge'), 'urn:appwrite:cloud:fra');
 *
 * $feed->append('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']]);
 *
 * foreach ($feed->read($lastEventId) as $event) {
 *     // ...
 * }
 * ```
 *
 * Subclass to give a feed a typed vocabulary — one method per thing that can
 * happen, rather than callers assembling event types and payloads by hand.
 */
class Feed
{
    /**
     * Most events a single read may return. The cap belongs here because
     * `limit` arrives from a consumer over the network.
     */
    public const int MAX_BATCH = 1000;

    /**
     * Longest a long poll may hold a request open, in milliseconds. Kept under
     * the 60s that proxies commonly cut idle responses off at.
     */
    public const int MAX_TIMEOUT = 30_000;

    /**
     * @param Journal $journal Where the events live.
     * @param string $source Who is producing them, as a URI reference
     *        (`urn:appwrite:cloud:fra`). Stamped onto every event this instance
     *        appends, so a consumer merging feeds from several producers can
     *        tell them apart. Only needed to append, not to read.
     */
    public function __construct(
        protected readonly Journal $journal,
        protected readonly string $source = '',
    ) {
    }

    public function getName(): string
    {
        return $this->journal->getName();
    }

    /**
     * Append an event and return its position in the feed.
     *
     * @param string $type What happened, in reverse-DNS notation.
     * @param mixed $data Payload, JSON encodable. Usually a map, but the JSON
     *        event format leaves it unrestricted.
     * @param string $subject The one business object this is about, if there is
     *        one. Empty means none, which is how CloudEvents models it.
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
     * Append a prepared event, stamping it with this feed's source and, unless
     * it already has one, the current time.
     *
     * @throws Exception\Invalid When the event has no type, the feed has no
     *         source, or the data cannot be encoded.
     * @throws Exception When the backend rejects the append.
     */
    public function publish(CloudEvent $event): string
    {
        if ($event->type === '') {
            throw new Exception\Invalid('Feed event type is required');
        }

        if ($this->source === '') {
            throw new Exception\Invalid('Feed source is required to append; construct the feed with one');
        }

        // Stamped with the withers rather than rebuilt, so anything this
        // library does not model itself — a dataschema, an extension attribute
        // such as a traceparent — survives the append untouched.
        $event = $event->withSource($this->source);

        return $this->journal->append($event->time === '' ? $event->withTime() : $event);
    }

    /**
     * Read the events after $lastEventId, oldest first, or from the oldest
     * retained event when it is null.
     *
     * Returns immediately, with an empty list when the consumer is caught up.
     *
     * @return list<CloudEvent>
     * @throws Exception\Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    public function read(?string $lastEventId = null, int $limit = self::MAX_BATCH): array
    {
        return $this->journal->read($lastEventId, self::limit($limit));
    }

    /**
     * {@see read()}, but when there is nothing new yet, wait up to $timeout
     * milliseconds for something to arrive before answering.
     *
     * This is how a consumer subscribes in near real time without hammering the
     * producer. The batch may still come back empty — the timeout elapsing is a
     * normal outcome, not a failure. A timeout of 0 makes this a plain read.
     *
     * Journals that cannot block wait by re-reading on an interval, which under
     * Swoole yields the worker only if coroutine hooks are enabled. Without them
     * it holds the worker for the duration, so run it with hooks on or keep the
     * timeout at 0.
     *
     * @return list<CloudEvent>
     * @throws Exception\Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    public function poll(?string $lastEventId = null, int $limit = self::MAX_BATCH, int $timeout = 0): array
    {
        return $this->journal->poll(
            $lastEventId,
            self::limit($limit),
            \max(0, \min($timeout, self::MAX_TIMEOUT)),
        );
    }

    /**
     * The limit a read will actually use.
     *
     * Clamped rather than rejected: `limit` is a hint about how much work a
     * consumer wants in one go, and failing a read because it asked for too
     * much would stall a feed over something the producer can simply decide.
     *
     * An endpoint serving this feed should clamp with this before answering, so
     * the number it passes to {@see Protocol::cacheControl()} is the one the
     * batch was actually built with.
     */
    public static function limit(int $limit): int
    {
        return \max(1, \min($limit, self::MAX_BATCH));
    }
}
