# Utopia Feed

[![Build Status](https://github.com/utopia-php/feed/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/feed/actions)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Feed moves events between services with **pull-based HTTP event feeds**
([http-feeds.org](https://www.http-feeds.org/)). A producer writes events to
an ordered log; each consumer polls *"what happened since the last event I
saw?"* and keeps its own position, so the producer stores nothing per consumer
and delivery is **at-least-once**. A consumer that was down catches up on its
next poll.

The whole library in three classes: a `Producer` produces events into a
`Store`, a `Server` serves that store over HTTP, and a `Consumer` consumes the
feed through an HTTP client, keeping its place in a `Cursor`.

## Which classes are mine?

| Server (owns the feed) | Client (consumes it) |
| --- | --- |
| `Store` — where events live | `Consumer` — the pull loop |
| `Producer` — writes events | `Cursor` — where the position is kept |
| `Server` — serves the store | |

## Install

```bash
composer require utopia-php/feed
```

## Serve a feed

The server side is three objects over one store — where the events live, here
a capped Redis stream:

```php
use Utopia\Feed\Producer;
use Utopia\Feed\Server;
use Utopia\Feed\Store;

$store = new Store\Redis($redis, 'edge');

// Wherever things happen:
$producer = new Producer($store, source: 'urn:appwrite:cloud:fra');

$producer->produce(
    type: 'io.appwrite.edge.invalidate-rule',
    data: ['tags' => ['domain' => 'example.com']],
    subject: 'example.com',
);

// The whole feed route:
$server = new Server($store);

// GET /v1/feeds/:feedId
$batch = $server->serve($request->getParams());

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
— catch it to answer 400. `produce()` returns the event's id, which is its
position in the feed. Subclass `Producer` to give callers a typed vocabulary
instead of raw type strings.

The `Batch` that `serve()` (and `Server::read()`/`poll()`) returns counts and
iterates as its events; `cacheControl()` marks a full batch as immutable
history and everything shorter `no-store`, using the limit the batch was
actually built with, so the header is always honest.

## Consume a feed

The client side is a `Consumer` pulling over an HTTP client, with its position
in a `Cursor`. The feed's endpoint is set on the client — `withBaseUri()` —
and the consumer names the feed it reads. With a `timeout`, each poll is one
held request that returns the moment an event lands (long polling — the
producer does the waiting):

```php
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;

$client = (new Client(new Curl()))
    ->withBaseUri('https://cloud.example.com/v1/feeds')
    ->withHeaders(['x-appwrite-jwt' => $token])
    ->withConnectionReuse();

$consumer = new Consumer($client, new Cursor\Cache($cache), name: 'cache-invalidator', feed: 'edge', timeout: 20_000);

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
`Consumer` also accepts a local store in place of the client, which already
names its feed:

```php
$consumer = new Consumer($store, new Cursor\Redis($redis), name: 'audit-log');
```

### Starting at the tip

A consumer with no stored position starts at the oldest retained event. A
consumer that must not act on the backlog — a notifier announcing events as
they happen — opts into starting at the tip:

```php
$consumer = new Consumer($client, $cursor, name: 'notifier', feed: 'edge', timeout: 20_000, start: Consumer::START_TIP);
```

A stored position always wins; `Consumer::START_TIP` applies only on the first run or
after `reset()` (which then means "forget everything, resume from now"). Give
a tip consumer a `timeout`: the producer anchors "now" as each poll arrives,
so new events land inside the held request rather than in the gap between
polls. Against a producer that predates the tip extension, the first poll
fails with a 4xx `Transport` error rather than silently replaying the backlog.

### Moving the position by hand

- `reset()` — forget the position; the next run starts from the oldest
  retained event (or the tip, for a `Consumer::START_TIP` consumer).
- `seek($eventId)` — treat `$eventId` as the last event handled; the next run
  starts strictly *after* it. Persisted immediately; a store failure surfaces
  as `Transport`. The id need not still exist in the feed.

What counts as a usable id is the feed's to say. On a local store — which mints
`{ms}-{seq}` positions and pages by decoding them — anything else is rejected
as `Invalid`. On a feed read over HTTP the producer is the authority, so any
non-empty id is accepted: http-feeds endpoints commonly use UUIDs, and refusing
one would take the escape hatch below away from precisely the consumers that
have no way around it. Both refuse the tip sentinel `$`, which stands for a
start rather than a position.

Either is safe to call from inside a handler: a run that finishes after the
move keeps its own progress to itself rather than saving over the newer
decision.

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
consumer opted into `Consumer::START_TIP`), so a consumer deployed after the producer
drains the backlog instead of dropping it.

**One process per consumer name.** Two processes sharing a name share one
position, so the feed is split between them rather than delivered to both —
and because each save is last-writer-wins, the shared position can also move
backwards and replay. Give every consumer its own name.

## Reference

### Stores

Every store is `Readable` and `Appendable` — it owns its events and assigns
their ids. All take `maxSize` (retention, ~100,000 entries by default) and
`pollInterval` (how often a held poll re-reads, 500 ms by default — shorter
lowers long-poll latency, raises backend reads). Neither may be below 1 — a
store that retains nothing is a misconfiguration, so the constructor throws
`Exception\Invalid`.

| Store | Use for |
| --- | --- |
| `Store\Redis` | Producing a feed on a Redis stream |
| `Store\Pool` | The same, over a [pooled](https://github.com/utopia-php/pools) connection — borrows per read, so a held poll never ties up a connection |
| `Store\Cache` | A feed on a [Utopia cache](https://github.com/utopia-php/cache) — for a service that already carries a cache and does not want another backend. One key per feed, rewritten per append (last-writer-wins — run one producing process); expires `ttl` after the last append, 30 days by default |
| `Store\Memory` | Tests and single-process development |
| `Store\None` | No backend configured — throws on use, so a misconfigured service fails loudly instead of dropping events |

`Store\Cache` keeps the whole feed under one key, which is what makes it cheap
to adopt and what bounds how far it scales: an append is a read-modify-write of
the entire retained feed, so retention is also the cost of producing one event.
Its default `maxSize` is therefore 1 000 rather than the 100 000 the Redis store
keeps, where trimming happens server-side and reads are ranged. Raising it is a
fine choice for a low-rate feed — just a deliberate one.

Long polls do not pay that cost: the newest id is kept under a second, tiny key
(`feed:<name>:tip`), so a caught-up consumer waiting out a 30-second poll checks
that marker each tick instead of loading the feed. The marker is written before
the feed and is only ever used to skip a read, never to answer one, so a missing
or stale marker costs a wasted read rather than a missed event.

### Cursors

A cursor is keyed by feed and consumer name, so one store serves every feed a
service consumes. The store may be lossy — a lost position costs a replay, not
a gap.

The stored form is deliberately plain: the key is `feed:<feed>:cursor:<consumer>`
and the value is the event id as a string. That is the format consumers have
always written, so positions carry across an upgrade instead of replaying the
retained feed, and `GET feed:edge:cursor:notifier` answers "where is this
consumer?" from a shell.

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
| `Exception\Unsupported` | The operation cannot happen here: any use of `Store\None`, or `tip()` on a remote feed (the producer resolves the tip) |

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

**Retention is bounded.** Stores trim to about `maxSize` entries (Redis trims
approximately). The oldest retained entry is where a consumer with no position
starts; a consumer that fell behind the trim horizon gets what is left — no
error, no detectable gap. Feeds therefore suit events that describe a state to
converge on (a cache tag to drop, a record to refresh) rather than ones whose
effect depends on seeing every single one.

**Decoding is strict about `id`, lenient about the rest.** The id is the
consumer's position, so an entry without one ends the batch there: everything
before it is handled, and the broken entry heads the next batch, where it
stops the feed loudly (only a broken *first* entry throws `Invalid`). A
producer that adds attributes or moves the spec version forward does not stop
a consumer that predates it — the spec's optional `method` attribute included.

**Why a poll loop instead of `XREAD BLOCK`?** A blocking read holds the
connection for the whole wait, which is exactly what `Store\Pool`'s
borrow-per-read strategy exists to avoid. Tune the trade-off with
`pollInterval`.

**For integrators** building a transport of their own: the shared contract —
the tip sentinel and the batch/timeout limits — lives on `Utopia\Feed\Readable`;
`Utopia\Feed\Batch` carries the serving side (encoding, media type, caching
rule) and `Utopia\Feed\Remote` is the client-side `Readable` the consumer
builds over its client. Services never need any of them directly.

## Tests

The suite is organized around behaviour, not classes: each component has one
abstract scenario suite — `tests/Feed/Producer/Base.php`,
`tests/Feed/Server/Base.php`, `tests/Feed/Consumer/Base.php` — and every
adapter extends it, so a passing adapter suite means that adapter honours the
whole contract. Producer and Server run per store (`memory`, `cache`, `redis`,
`pool`); Consumer runs per cursor plus once through the real HTTP wire code
(`http`). What remains in `tests/Feed/Unit` are the cases only a fake can
provoke: a cursor store that is down, a body that is not a batch, a backend
that was never configured.

The service-free suites need no Redis, but `utopia-php/cache` declares
`ext-redis` and `ext-memcached` and `utopia-php/telemetry` declares
`ext-protobuf`, none of which those suites touch. Install past exactly those
rather than with a blanket `--ignore-platform-reqs`, which would also skip the
PHP version check this library actually depends on:

```bash
composer install \
    --ignore-platform-req=ext-redis \
    --ignore-platform-req=ext-memcached \
    --ignore-platform-req=ext-protobuf
composer test          # unit + memory + cache + http
```

The `redis` and `pool` suites run the same scenarios against a real Redis, and
static analysis needs `ext-redis`, so both run in the container:

```bash
docker compose up -d
docker compose exec tests composer test:redis
docker compose exec tests composer test:pool
docker compose exec tests composer check
```

CI runs every suite as its own job, so a failing adapter is visible by name.

To test another PHP version, build with `PHP_VERSION=8.6 docker compose build`,
and add it to the `php-versions` matrix in `.github/workflows/tests.yml`.

## System requirements

Utopia Feed requires PHP 8.5 or later. This library is maintained by the
[Appwrite team](https://appwrite.io) and, although part of the
[Utopia Framework](https://github.com/utopia-php/framework), it is dependency
light and works standalone with any PHP project.

`ext-redis` is a suggestion, not a requirement: it is needed by
`Store\Redis`, `Store\Pool`, `Cursor\Redis` and `Cursor\Pool`, and a service
using the memory or cache adapters, or consuming a feed over HTTP, never loads
those classes. Note that `utopia-php/cache` requires `ext-redis` and
`ext-memcached` itself, so a machine without them still needs
`--ignore-platform-req` to install — that constraint comes from the cache
package rather than from this one.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
