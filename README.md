# Utopia Feed

[![Build Status](https://github.com/utopia-php/feed/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/feed/actions)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Feed moves events between services with **pull-based HTTP event feeds**
([http-feeds.org](https://www.http-feeds.org/)). A producer appends events to
an ordered log; each consumer polls *"what happened since the last event I
saw?"* and keeps its own position, so the producer stores nothing per consumer
and delivery is **at-least-once**. A consumer that was down catches up on its
next poll.

The whole library in one sentence: *a `Producer` appends to a `Journal`, a
`Feed` serves that journal over HTTP; a `Consumer` reads a `Remote` feed and
keeps its place in a `Cursor`.*

## Which classes are mine?

| Server (owns the feed) | Client (consumes it) |
| --- | --- |
| `Journal` — where events live | `Remote` — another service's feed, over HTTP |
| `Producer` — appends events | `Consumer` — the pull loop |
| `Feed` — serves the journal | `Cursor` — where the position is kept |

## Install

```bash
composer require utopia-php/feed
```

## Serve a feed

The server side is three objects over one journal — where the events live,
here a capped Redis stream:

```php
use Utopia\Feed\Feed;
use Utopia\Feed\Journal;
use Utopia\Feed\Producer;

$journal = new Journal\Redis($redis, 'edge');

// Wherever things happen:
$producer = new Producer($journal, source: 'urn:appwrite:cloud:fra');

$producer->append(
    type: 'io.appwrite.edge.invalidate-rule',
    data: ['tags' => ['domain' => 'example.com']],
    subject: 'example.com',
);

// The whole feed route:
$feed = new Feed($journal);

// GET /v1/feeds/:feedId
$batch = $feed->serve($request->getParams());

$response
    ->addHeader('Content-Type', 'application/cloudevents-batch+json')
    ->addHeader('Cache-Control', $batch->cacheControl(public: true))
    ->json($batch->toArray());
```

On the wire, the response is a plain JSON array of
[CloudEvents](https://github.com/utopia-php/cloudevents) — no envelope. An
empty array means the consumer is caught up:

```json
[
  {
    "specversion": "1.0",
    "type": "io.appwrite.edge.invalidate-rule",
    "source": "urn:appwrite:cloud:fra",
    "id": "1717689471234-0",
    "subject": "example.com",
    "time": "2026-07-31T09:15:02.123Z",
    "data": { "tags": { "domain": "example.com" } }
  },
  {
    "specversion": "1.0",
    "type": "io.appwrite.edge.invalidate",
    "source": "urn:appwrite:cloud:fra",
    "id": "1717689471234-1",
    "time": "2026-07-31T09:15:02.348Z",
    "data": { "tags": { "project": "p1" } }
  }
]
```

`serve()` reads `lastEventId`, `limit` and `timeout` from the raw query
parameters, coerces and clamps them (at most 1000 events per batch, long polls
held at most 30s), and throws `Exception\Invalid` on a malformed `lastEventId`
— catch it to answer 400. `append()` returns the event's id, which is its
position in the feed. Subclass `Producer` to give callers a typed vocabulary
instead of raw type strings.

The `Batch` that `serve()` (and `Feed::read()`/`poll()`) returns counts and
iterates as its events; `cacheControl()` marks a full batch as immutable
history and everything shorter `no-store`, using the limit the batch was
actually built with, so the header is always honest.

## Consume a feed

The client side is a `Consumer` pulling a `Remote` feed, with its position in
a `Cursor`. With a `timeout`, each poll is one held request that returns the
moment an event lands (long polling — the producer does the waiting):

```php
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Remote;

$client = (new Client(new Curl()))
    ->withHeaders(['x-appwrite-jwt' => $token])
    ->withConnectionReuse();

$remote = new Remote($client, 'https://cloud.example.com/v1/feeds', 'edge');

$consumer = new Consumer($remote, 'cache-invalidator', new Cursor\Cache($cache), timeout: 20_000);

while (true) {
    try {
        $consumer->consume(function (CloudEvent $event) use ($router) {
            $router->invalidate($event->data['tags'] ?? []);
        });
    } catch (Transport $error) {
        // Feed or cursor store unreachable. The position did not move, so
        // the next poll is already the retry — just don't spin.
        \usleep(1_000_000);
    }
}
```

Events **are** `Utopia\CloudEvents\CloudEvent` objects — `data` round-trips
any JSON value, and extension attributes like `traceparent` survive the trip.
Leave the client's `Retry` decorator off: a failed read leaves the position
where it was.

A consumer inside the producing service reads its own feed the same way —
`Consumer` accepts anything `Readable`, so hand it the local journal directly:

```php
$consumer = new Consumer($journal, 'audit-log', new Cursor\Redis($redis));
```

### Starting at the tip

A consumer with no stored position starts at the oldest retained event. A
consumer that must not act on the backlog — a notifier announcing events as
they happen — opts into starting at the tip:

```php
use Utopia\Feed\Start;

$consumer = new Consumer($remote, 'notifier', $cursor, timeout: 20_000, start: Start::Tip);
```

A stored position always wins; `Start::Tip` applies only on the first run or
after `reset()` (which then means "forget everything, resume from now"). Give
a tip consumer a `timeout`: the producer anchors "now" as each poll arrives,
so new events land inside the held request rather than in the gap between
polls. Against a producer that predates the tip extension, the first poll
fails with a 4xx `Transport` error rather than silently replaying the backlog.

### Moving the position by hand

- `reset()` — forget the position; the next run starts from the oldest
  retained event (or the tip, for a `Start::Tip` consumer).
- `seek($eventId)` — treat `$eventId` as the last event handled; the next run
  starts strictly *after* it. Persisted immediately; a store failure surfaces
  as `Transport`. The id must be well formed but need not still exist in the
  feed.

`seek()` is the escape hatch for a poison event. A handler that keeps failing
blocks the feed by design, so stepping past it is a decision made in code:
catch the failure, log the event's id, and once you have decided the event
must be skipped, seek to *its own id*:

```php
try {
    $consumer->consume($handler);
} catch (\Throwable $error) {
    Console::error("[feed] blocked: {$error->getMessage()}");

    // After investigating — this event cannot and should not be handled:
    $consumer->seek($poisonEventId);
}
```

## The rules a handler lives by

**Safe to run twice on the same event.** Three things re-deliver, and none can
be arranged away:

1. A handler succeeds and the position then fails to save.
2. A run interrupted partway resumes from the last event that succeeded.
3. A consumer whose position was lost restarts from the oldest retained event.

Every one re-delivers; none skips. An idempotent handler absorbs a duplicate,
whereas an event stepped over is gone.

**Reject by throwing.** The run stops there, the position stays before the
failed event, and the next run retries it. Everything handled earlier in the
run stays handled. A handler that keeps failing blocks everything behind it —
intentionally: a feed is ordered, and stepping over a failure would apply
later events on top of state that was never updated.

**No position means the oldest retained event, never the tip** (unless the
consumer opted into `Start::Tip`), so a consumer deployed after the producer
drains the backlog instead of dropping it.

**One process per consumer name.** Two processes sharing a name share one
position, so the feed is split between them rather than delivered to both.

## Reference

### Journals

Every journal is `Readable` and `Appendable` — it owns its events and assigns
their ids. (`Remote` is `Readable` only; you cannot produce into someone
else's feed.) All take `maxSize` (retention, ~100,000 entries by default) and
`pollInterval` (how often a held poll re-reads, 500 ms by default — shorter
lowers long-poll latency, raises backend reads).

| Journal | Use for |
| --- | --- |
| `Journal\Redis` | Producing a feed on a Redis stream |
| `Journal\Pool` | The same, over a [pooled](https://github.com/utopia-php/pools) connection — borrows per read, so a held poll never ties up a connection |
| `Journal\Memory` | Tests and single-process development |
| `Journal\None` | No backend configured — throws on use, so a misconfigured service fails loudly instead of dropping events |

### Cursors

A cursor is keyed by feed and consumer name, so one store serves every feed a
service consumes. The store may be lossy — a lost position costs a replay, not
a gap.

| Cursor | Use for |
| --- | --- |
| `Cursor\Cache` | A consumer with a [Utopia cache](https://github.com/utopia-php/cache) — the usual choice for a remote feed. Holds a position for 30 days (`Cursor\Cache::TTL`) unless saved again |
| `Cursor\Redis` | A consumer inside the producer, with no store of its own |
| `Cursor\Pool` | The same, over a pooled connection |
| `Cursor\Memory` | Tests, or deliberate replay-on-restart |
| `Cursor\None` | Remembers nothing — every restart replays |

### Protocol parameters

The endpoint speaks [http-feeds.org](https://www.http-feeds.org/): a GET
returning a JSON array of CloudEvents, media type
`application/cloudevents-batch+json` (a feed answering `application/json` is
read fine — the body shape is what matters).

| Parameter | Origin | Meaning |
| --- | --- | --- |
| `lastEventId` | spec | The consumer's position: return events strictly after it. Omitted = oldest retained |
| `timeout` | spec | Long poll: hold the request up to this many ms before answering `[]`. Clamped to 30s |
| `limit` | extension | Cap the batch size. Clamped to 1000, which is also the default |
| `lastEventId=$` | extension | The tip: the producer resolves `$` to its newest event as the request arrives. Never collides with a real id |

A spec-compliant third-party client simply never sends the extensions.

### Exceptions

All extend `Utopia\Feed\Exception`.

| Exception | Thrown when |
| --- | --- |
| `Exception\Invalid` | Input is wrong: a malformed event id or `lastEventId`, an empty feed/consumer name, a payload that cannot be JSON-encoded, a response that is not a feed batch. Answer 400 when it surfaces from `serve()` |
| `Exception\Transport` | The backend or network failed: Redis errors, HTTP failures (the status code is on the exception), a cursor store that is down |
| `Exception\Unsupported` | The operation cannot happen here: any use of `Journal\None`, or `tip()` on a `Remote` (the producer resolves the tip) |

## The fine print

**Rolling out a feed** is a staged change: release the producer (events
accumulate, nothing reads them), then the consumers (each drains the backlog),
and only then remove the old push path. In between, a consumer polling a
producer that does not serve the feed yet gets a 404 — normal, not a fault:

```php
try {
    $consumer->consume($handler);
} catch (Transport $error) {
    if ($error->getCode() !== 404) {
        throw $error;
    }

    // Producer does not serve the feed yet; retry quietly until it does.
}
```

**Retention is bounded.** Journals trim to about `maxSize` entries (Redis
trims approximately). The oldest retained entry is where a consumer with no
position starts; a consumer that fell behind the trim horizon gets what is
left — no error, no detectable gap. Feeds therefore suit events that describe
a state to converge on (a cache tag to drop, a record to refresh) rather than
ones whose effect depends on seeing every single one.

**Decoding is strict about `id`, lenient about the rest.** The id is the
consumer's position, so an entry without one ends the batch there: everything
before it is handled, and the broken entry heads the next batch, where it
stops the feed loudly (only a broken *first* entry throws `Invalid`). A
producer that adds attributes or moves the spec version forward does not stop
a consumer that predates it — the spec's optional `method` attribute included.

**Why a poll loop instead of `XREAD BLOCK`?** A blocking read holds the
connection for the whole wait, which is exactly what `Journal\Pool`'s
borrow-per-read strategy exists to avoid. Tune the trade-off with
`pollInterval`.

**For integrators** building a transport of their own: the wire contract —
query parameters, batch encoding, caching rule — lives in `Utopia\Feed\Protocol`.
Services never need it.

## Tests

Unit tests need no services, but dependencies declare extensions the suite
never touches (`ext-redis`, `ext-memcached`, `ext-protobuf`), so install past
them:

```bash
composer install --ignore-platform-reqs
composer test
```

The E2E suite runs against a real Redis, and static analysis needs `ext-redis`,
so both run in the container:

```bash
docker compose up -d
docker compose exec tests composer test:e2e
docker compose exec tests composer check
```

To test another PHP version, build with `PHP_VERSION=8.6 docker compose build`,
and add it to the `php-versions` matrix in `.github/workflows/tests.yml`.

## System requirements

Utopia Feed requires PHP 8.5 or later. This library is maintained by the
[Appwrite team](https://appwrite.io) and, although part of the
[Utopia Framework](https://github.com/utopia-php/framework), it is dependency
light and works standalone with any PHP project.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
