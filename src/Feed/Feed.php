<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

/**
 * An append-only, strongly ordered sequence of events that consumers pull.
 *
 * Follows http-feeds (https://www.http-feeds.org/): rather than the producer
 * pushing every event to every consumer, each consumer asks for what it has
 * not seen yet, quoting the id of the last event it processed. Delivery then
 * stops depending on every consumer being reachable at the moment something
 * happens — one that was down, redeploying, or only just added catches up on
 * its next read instead of missing the event entirely.
 *
 * The trade is at-least-once delivery. Consumers fall behind, retry, and
 * restart from positions they have already passed, so **every event must be
 * safe to process twice**. Retention is bounded, so a consumer that falls
 * behind the trim horizon resumes from the oldest retained event rather than
 * failing — which makes the feed unsuitable for events whose effect depends on
 * seeing all of them (a balance built from deltas), and a good fit for events
 * that describe a state to converge on (a cache tag to drop, a record to
 * refresh).
 *
 * ```php
 * $feed = new Feed(new Redis($redis, 'edge'), 'urn:appwrite:cloud:fra');
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
     * Most events a single read may return. A cap belongs here rather than on
     * the caller: `limit` arrives from a consumer over the network, and an
     * unbounded read is a way to hold a producer's worker open.
     */
    public const int MAX_BATCH = 1000;

    /**
     * Longest a long poll may hold a request open, in milliseconds. Kept
     * under the 60s that proxies and load balancers commonly cut idle
     * responses off at, so a poll ends by returning empty rather than by
     * having the connection dropped underneath it.
     */
    public const int MAX_TIMEOUT = 30_000;

    /**
     * Microseconds between reads while long polling on a backend that cannot
     * block on its own. Half a second bounds delivery latency at roughly that,
     * while keeping a quiet feed at two reads a second per consumer.
     */
    protected const int POLL_INTERVAL = 500_000;

    /**
     * @param Adapter $adapter Where the events live.
     * @param string $source Who is producing them, as a URI reference
     *        (`urn:appwrite:cloud:fra`). Stamped onto every event this
     *        instance appends, so a consumer merging feeds from several
     *        producers can tell which one an event came from. Irrelevant when
     *        the feed is only being read.
     */
    public function __construct(
        protected readonly Adapter $adapter,
        protected readonly string $source = '',
    ) {
    }

    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    public function getName(): string
    {
        return $this->adapter->getName();
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Append an event and return its position in the feed.
     *
     * @param string $type What happened, in reverse-DNS notation.
     * @param mixed $data Payload, JSON encodable. Usually a map, but the JSON
     *        event format leaves it unrestricted, so a list or a scalar is
     *        equally valid.
     * @param string $subject The one business object this is about, if there
     *        is one. Empty means none, which is how CloudEvents models it.
     * @throws Exception\Invalid When $type is empty or $data cannot be
     *         encoded.
     * @throws Exception When the backend rejects the append.
     */
    public function append(string $type, mixed $data = [], string $subject = ''): string
    {
        if ($type === '') {
            throw new Exception\Invalid('Feed event type is required');
        }

        return $this->publish(new CloudEvent(
            type: $type,
            subject: $subject === '' ? null : $subject,
            data: $data,
        ));
    }

    /**
     * Append a prepared event, stamping it with this feed's source and the
     * current time.
     *
     * Both are stamped here rather than accepted from the caller because they
     * describe the append itself. Recording the source at append rather than
     * at read also keeps it correct for a feed that is replicated or read back
     * from somewhere other than where it was written.
     *
     * @throws Exception\Invalid When the event has no type or its data cannot
     *         be encoded.
     * @throws Exception When the backend rejects the append.
     */
    public function publish(CloudEvent $event): string
    {
        if ($event->type === '') {
            throw new Exception\Invalid('Feed event type is required');
        }

        // Stamped with the withers rather than rebuilt, so anything this
        // library does not model itself — a dataschema, an extension attribute
        // such as a traceparent — survives the append untouched.
        return $this->adapter->append(
            $event
                ->withSource($this->source)
                ->withTime($event->time !== '' ? $event->time : null)
        );
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
        return $this->adapter->read($lastEventId, self::limit($limit));
    }

    /**
     * {@see read()}, but when there is nothing new yet, wait up to $timeout
     * milliseconds for something to arrive before answering.
     *
     * This is how a consumer subscribes in near real time without hammering
     * the producer: poll in a loop with a timeout, and each call either
     * returns as soon as an event is appended or costs one request per
     * timeout while the feed is quiet. A timeout of 0 makes this a plain read.
     *
     * The batch may still come back empty — the timeout elapsing is a normal
     * outcome, not a failure.
     *
     * Where the backend cannot block on its own this waits by re-reading on an
     * interval, which under Swoole yields the worker only if coroutine hooks
     * are enabled. Without them it holds the worker for the duration, so run
     * it with hooks on or keep the timeout at 0.
     *
     * @return list<CloudEvent>
     * @throws Exception\Invalid When $lastEventId is not a feed position.
     * @throws Exception When the backend cannot be read.
     */
    public function poll(?string $lastEventId = null, int $limit = self::MAX_BATCH, int $timeout = 0): array
    {
        $limit = self::limit($limit);
        $timeout = \max(0, \min($timeout, self::MAX_TIMEOUT));

        if ($this->adapter->pollable()) {
            return $this->adapter->read($lastEventId, $limit, $timeout);
        }

        $deadline = \microtime(true) + $timeout / 1000;

        while (true) {
            $events = $this->adapter->read($lastEventId, $limit);

            if ($events !== [] || \microtime(true) >= $deadline) {
                return $events;
            }

            \usleep(self::POLL_INTERVAL);
        }
    }

    /**
     * Clamp rather than reject: `limit` is a hint about how much work a
     * consumer wants in one go, and failing a read because it asked for too
     * much would stall a feed over something the producer can simply decide.
     */
    private static function limit(int $limit): int
    {
        return \max(1, \min($limit, self::MAX_BATCH));
    }
}
