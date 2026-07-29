# Utopia Feed

[![Build Status](https://github.com/utopia-php/feed/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/feed/actions)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/feed.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Feed is a simple and lite library for moving events between services with
**pull-based HTTP event feeds** ([http-feeds.org](https://www.http-feeds.org/)),
instead of pushing them to every service that needs them. This library is aiming
to be as simple and easy to learn and use. This library is maintained by the
[Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia
Framework](https://github.com/utopia-php/framework) project, it is dependency
light and can be used as standalone with any other PHP project or framework.

## Why pull instead of push

A service that pushes an event to its consumers has to reach all of them at the
moment it happens. Any consumer that is down, redeploying, rate limited or
simply new misses the event, and there is nothing in the system that will ever
tell it. The producer also grows an outbound call per consumer, has to hold a
retry queue per consumer, and has to be told when a consumer is added.

A feed inverts that. The producer appends to an ordered log and forgets about
it. Each consumer asks *"what has happened since the last thing I saw?"*,
quoting the id of that event. A consumer that was down catches up on its next
poll. A consumer that is added later starts from whatever is still retained. The
producer keeps no per-consumer state at all, so nothing about it changes when
consumers come and go.

```
                append                          GET /feeds/edge?lastEventId=...
   producer ───────────────▶  feed  ◀───────────────────────────── consumer A
                             (log)  ◀───────────────────────────── consumer B
                                                                   consumer C ← added later,
                                                                     catches up on its own
```

The trade is **at-least-once delivery**: consumers retry, replay, and restart
from positions they have already passed, so every event has to be safe to
process twice. Retention is bounded, so a consumer that falls a long way behind
resumes from the oldest retained event rather than failing. That makes a feed a
poor fit for events whose effect depends on seeing every one of them (a balance
built out of deltas) and a good fit for events that describe a state to converge
on — a cache tag to drop, a record to refresh, a config to reload.

## Features

- **Ordered, resumable log** — consumers page by event id, and hold their own position
- **CloudEvents** — events *are* [`utopia-php/cloudevents`](https://github.com/utopia-php/cloudevents) events, as http-feeds requires
- **Journals** — Redis streams, a pooled Redis, in-memory, or another service's feed over HTTP
- **Long polling** — subscribe in near real time without hammering the producer
- **Cursors** — positions in a Utopia cache, in Redis, or in memory
- **Consumer** — the pull loop, the position bookkeeping and the at-least-once semantics, written once

## How the pieces fit

The library splits along the same line the design does: the **producer owns the
events**, each **consumer owns its position**, and `Protocol` is the seam between
them when they live in different services.

```
        PRODUCER                        │              CONSUMER
                                        │
  append()                              │        consume(handler)
     ↓                                  │              ↓
  ┌────────┐        ┌──────────┐        │        ┌──────────┐      ┌────────┐
  │  Feed  │───────▶│ Journal  │        │        │ Consumer │─────▶│ Cursor │
  └────────┘        │  \Redis  │        │        └──────────┘      │ \Cache │
   policy           │  \Pool   │        │         the pull loop    └────────┘
                    │  \Memory │        │              │          "where I got to"
                    └──────────┘        │              ↓
                     the events         │        ┌────────┐     ┌──────────┐
                          │             │        │  Feed  │────▶│ Journal  │
                          │             │        └────────┘     │  \Http   │
                    ┌───────────┐       │                       └──────────┘
                    │ Protocol  │◀──────┼──── HTTP GET ──────────────┘
                    └───────────┘       │
                  the wire contract     │
```

| | |
| --- | --- |
| **`Journal`** | Where the events live. Assigns an ordered id on append, returns the events after a given id — and nothing else. `Journal\Http` reads *another service's* journal, so to everything above it a remote feed and a local one are the same object. |
| **`Feed`** | The policy on one journal: stamps `source` and `time` on append, clamps a consumer-supplied `limit`, and long-polls. Subclass it to give a feed a typed vocabulary. |
| **`Cursor`** | Where one consumer's position is kept. Deliberately independent of `Journal` — a consumer keeps its position in *its own* storage, never the producer's. |
| **`Consumer`** | The pull loop. Reads from the stored position, hands each event to a handler oldest-first, and advances only past events the handler accepted. |
| **`Protocol`** | The HTTP contract — query parameters, response envelope, caching rules — held in one place so the two halves cannot drift apart. |

The structural consequence worth knowing up front: **the producer stores no
per-consumer state at all.** That is what makes adding a consumer free, and it is
why `Cursor` is its own thing rather than a method on `Journal`.

Dependencies only ever point one way, so each piece is testable alone — a
`Journal\Memory` and a `Cursor\Memory` exercise the whole pull loop with no Redis
and no network:

```
Consumer ──▶ Feed ──▶ Journal ──▶ Protocol   (only Journal\Http)
    └──────▶ Cursor
```

## Getting started

Install using composer:

```bash
composer require utopia-php/feed
```

### Producing

```php
use Utopia\Feed\Journal\Redis as RedisJournal;
use Utopia\Feed\Feed;

$redis = new Redis();
$redis->connect('redis', 6379);

$feed = new Feed(
    new RedisJournal($redis, 'edge'),
    source: 'urn:appwrite:cloud:fra',
);

$feed->append(
    type: 'io.appwrite.edge.invalidate-rule',
    data: ['tags' => ['domain' => 'example.com']],
    subject: 'example.com',
);
```

`append()` returns the event's id, which is its position in the feed.

Give a feed a typed vocabulary by subclassing it, so callers cannot invent an
event type or misspell a payload key:

```php
class EdgeFeed extends Feed
{
    public const string NAME = 'edge';

    public function invalidateRule(string $domain): string
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('Rule invalidation requires a domain');
        }

        return $this->append(
            'io.appwrite.edge.invalidate-rule',
            ['tags' => ['domain' => $domain]],
            $domain,
        );
    }
}
```

### Serving a feed over HTTP

`Protocol` holds the wire contract — the query parameters, the response body and
the caching rules — so the endpoint and its consumers cannot drift apart. It
deals in arrays rather than requests and responses, so it fits whichever HTTP
layer the producer is written in:

```php
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;

// GET /v1/feeds/:feedId
$limit = (int) $request->getParam(Protocol::PARAM_LIMIT, Feed::MAX_BATCH);

$events = $feed->poll(
    $request->getParam(Protocol::PARAM_LAST_EVENT_ID) ?: null,
    $limit,
    (int) $request->getParam(Protocol::PARAM_TIMEOUT, 0),
);

$response
    ->addHeader('Cache-Control', Protocol::cacheControl(\count($events), $limit))
    ->json(Protocol::encode($events));
```

`cacheControl()` marks a full batch immutable — the same query returns the same
events forever — and a short batch `no-store`, because it is the live end of the
feed and will grow. It defaults to `private`, since a feed is usually served
behind authorization and `public` would let a shared cache hand one consumer's
events to a requester that never presented a credential.

### Consuming

A `Consumer` reads from where it last got to, hands each new event to a handler,
and records how far it got:

```php
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Cache as CacheCursor;

$consumer = new Consumer(
    feed: $feed,
    name: 'cache-invalidator',
    cursor: new CacheCursor($cache, 'edge'),
);

$handled = $consumer->consume(function (CloudEvent $event) use ($router) {
    $router->invalidate($event->data['tags'] ?? []);
});
```

Consuming **another service's** feed is the same code with a different journal:

```php
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Feed\Journal\Http;

$client = (new Client(new Curl()))
    ->withHeaders(['x-appwrite-jwt' => $token])
    ->withConnectionReuse();

$feed = new Feed(new Http($client, 'https://cloud.example.com/v1/feeds', 'edge'));
```

Nothing above the journal knows the events are arriving over the network,
including the long polling — `Http` hands the wait to the producer, so a poll is
one held request rather than a client-side loop.

`Http` takes any [`utopia-php/client`](https://github.com/utopia-php/client)
adapter, so a `Pool` or a Swoole coroutine transport drops straight in. Leave the
`Retry` decorator off, though: a failed read leaves the cursor where it was, so
the next poll is already the retry, and retrying inside a long poll only
multiplies how long a single tick can take.

Call `consume()` on a timer, or give the consumer a `timeout` and loop:

```php
// Returns as soon as an event arrives, or after 20s with nothing.
$consumer = new Consumer($feed, 'cache-invalidator', $cursor, timeout: 20_000);

while (true) {
    $consumer->consume($handler);
}
```

## Journals

A journal is where a feed's events actually live. The name is the one event
sourcing has long used for an append-only, strictly ordered record that is
replayed rather than mutated — Akka Persistence calls its pluggable storage
backends journals for the same reason. It is responsible for exactly two things:
assigning an ordered id on append, and returning the events strictly after a
given id. Everything else — long polling, cursors, the pull loop — sits above it
and is the same whichever journal is underneath.

| Journal | Use for | Append | Read |
| --- | --- | --- | --- |
| `Journal\Redis` | Producing a feed on a Redis stream | ✅ | ✅ |
| `Journal\Pool` | The same, over a [pooled](https://github.com/utopia-php/pools) connection | ✅ | ✅ |
| `Journal\Http` | Consuming another service's feed, over [utopia-php/client](https://github.com/utopia-php/client) | ❌ | ✅ |
| `Journal\Memory` | Tests, and single-process development | ✅ | ✅ |
| `Journal\None` | No backend configured | ❌ | ❌ |

`Journal\Pool` is what most services producing a feed want: a long poll holds
its connection for the whole timeout, so reading through a shared client would
block every other user of it.

`Journal\None` throws on every operation rather than doing nothing, so a
misconfigured service fails at the point of use instead of silently dropping
events — which only shows up much later, somewhere else. `Journal\Memory`
implements the same id and retention semantics as `Journal\Redis`, including the
awkward parts like resuming from a trimmed position, so code tested against it
behaves the same when it is swapped out.

## Cursors

http-feeds puts the position on the consumer rather than the producer, which is
what makes adding a consumer free. A cursor is just somewhere to write a string:

| Cursor | Use for |
| --- | --- |
| `Cursor\Cache` | A consumer with a [Utopia cache](https://github.com/utopia-php/cache) — the usual choice for one reading a remote feed |
| `Cursor\Redis` | A consumer running inside the producer, with no store of its own |
| `Cursor\Pool` | The same, over a pooled connection |
| `Cursor\Memory` | Tests, or a consumer that should replay from the beginning on every restart |

The store is allowed to be lossy. A lost position is not a lost event — a
consumer with no position resumes from the oldest retained event — so the
consequence is redundant work, not a gap. That is why a cache is a reasonable
place to put one, and it is also why a `Consumer` treats a store that is down as
a warning rather than a failure: it keeps its position in memory and carries on,
and only a restart before the store recovers replays anything. Pass
`onWarning()` to hear about it.

## Events

There is no event type in this library. Events **are**
[`Utopia\CloudEvents\CloudEvent`](https://github.com/utopia-php/cloudevents)
objects, so anything already typed against one takes a feed event directly, and
everything a CloudEvent carries — `dataschema`, extension attributes such as a
`traceparent` — survives an append and a read untouched:

```php
use Utopia\CloudEvents\CloudEvent;

$consumer->consume(function (CloudEvent $event) {
    $tags = $event->data['tags'] ?? [];
    $trace = $event->getExtension('traceparent');
});
```

`data` is unrestricted, as the JSON event format requires — a map, a list, a
string, a number or null are all valid payloads and all round-trip as
themselves. `subject` is nullable, so an event with no subject reads back as
`null` rather than `''`.

The one thing this library decides for itself is how a batch is decoded, and it
is deliberately not `CloudEvent::fromArray()`'s default:

- **Strict about `id`.** For a feed the id *is* the consumer's position, so an
  event without one cannot be recorded as passed. The spec makes `id` required
  too; `Protocol` enforces exactly that one attribute rather than calling
  `validate()`, which would also demand a `source` a feed has no use for.
- **Tolerant about everything else.** Decoding runs with `lenient: true` and
  `allowUnknownSpecversion: true`, so a producer that adds an attribute, omits
  an optional one, or moves the spec forward does not stop a consumer that
  predates it. A feed is read by consumers older than the producer *by design*,
  and that is what makes a staged rollout safe.

An entry that is not a CloudEvent at all — no `specversion`, no `type` — is not
tolerated, because that is a producer sending something other than what the feed
is specified to carry.

## Delivery semantics

**A handler must be safe to run twice on the same event.** There are three
independent reasons, and no arrangement of this library removes any of them:

1. A handler can succeed and the position then fail to save.
2. A run interrupted partway resumes from the last event that succeeded.
3. A consumer whose position was lost restarts from the oldest retained event.

**A handler rejects an event by throwing.** That stops the run at that event and
leaves the position before it, so the next run starts there and tries again.
Everything already handled in that run stays handled — progress is committed
before the failure is re-raised. A handler that keeps failing on one event
therefore blocks everything behind it, which is the intended behaviour: a feed
is ordered, and stepping over a failure would deliver later events on top of
state that was never updated.

**A consumer with no recorded position starts at the oldest retained event,
never at the tip.** Starting at the tip would drop whatever is already in the
feed, and for a consumer being deployed for the first time that is not a
hypothetical backlog — it is exactly the events it was meant to catch up on.
This is what makes a staged rollout safe: ship the producer first, let events
accumulate, then ship the consumer, and nothing in between is lost.

## Rolling out a feed

Replacing push delivery with a feed is a two-release change, and the order
matters:

1. **Release the producer.** It appends events; nothing reads them yet.
2. **Release the consumers.** Each drains the backlog from its first poll,
   because a consumer with no position starts at the oldest retained event.
3. **Only then remove the push path.** Until every consumer is polling, removing
   it means nothing is delivered.

While step 2 is in progress, consumers that have not shipped yet will get a 404
from a producer that does not serve the feed — normal, not a fault. That status
is on the exception, so it can be told apart from a real failure:

```php
use Utopia\Feed\Exception\Transport;

try {
    $consumer->consume($handler);
} catch (Transport $error) {
    if ($error->getCode() !== 404) {
        throw $error;
    }

    // Producer does not serve the feed yet; retry quietly until it does.
}
```

## Tests

Unit tests need nothing but composer:

```bash
composer install
composer test
```

The E2E suite runs against a real Redis, which is where the assumptions about
stream ids and `MAXLEN` trimming are actually confirmed:

```bash
docker compose up -d
docker compose exec tests composer test:e2e
```

Static analysis runs at PHPStan level max. Run it inside the container, where
`ext-redis` is installed:

```bash
docker compose exec tests composer check
```

The image is built from one parameterized `Dockerfile`, so testing against
another PHP version needs no new file:

```bash
PHP_VERSION=8.6 docker compose build
PHP_VERSION=8.6 docker compose up -d
```

To add that version to CI, add it to the `php-versions` matrix in
`.github/workflows/tests.yml` — that is the only place versions are listed.

## System requirements

Utopia Feed requires PHP 8.5 or later. We recommend using the latest PHP version
whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
