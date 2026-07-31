# Utopia Feed

[![Build Status](https://github.com/utopia-php/feed/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/feed/actions)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/feed.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Feed moves events between services with **pull-based HTTP event feeds**
([http-feeds.org](https://www.http-feeds.org/)) instead of pushing them to every
service that needs them.

A producer appends events to an ordered log. Each consumer asks *"what has
happened since the last event I saw?"*, quoting that event's id, and keeps track
of its own position. A consumer that was down catches up on its next poll; a
consumer added later starts from whatever is still retained. The producer stores
nothing per consumer, so nothing about it changes when consumers come and go.

The trade is **at-least-once delivery**: every event must be safe to handle
twice. Retention is bounded, so a feed suits events that describe a state to
converge on — a cache tag to drop, a record to refresh — rather than ones whose
effect depends on seeing every single one.

This library is maintained by the [Appwrite team](https://appwrite.io). Although
it is part of the [Utopia
Framework](https://github.com/utopia-php/framework), it is dependency light and
works standalone with any PHP project.

## Getting started

```bash
composer require utopia-php/feed
```

### Produce

```php
use Utopia\Feed\Journal;
use Utopia\Feed\Producer;

$producer = new Producer(
    new Journal\Redis($redis, 'edge'),
    source: 'urn:appwrite:cloud:fra',
);

$id = $producer->append(
    type: 'io.appwrite.edge.invalidate-rule',
    data: ['tags' => ['domain' => 'example.com']],
    subject: 'example.com',
);
```

`append()` returns the event's id, which is its position in the feed.

Subclass `Producer` to give it a typed vocabulary, so callers cannot invent an
event type or misspell a payload key:

```php
class EdgeProducer extends Producer
{
    public function invalidateRule(string $domain): string
    {
        return $this->append(
            'io.appwrite.edge.invalidate-rule',
            ['tags' => ['domain' => $domain]],
            $domain,
        );
    }
}
```

`Producer` only accepts a journal that owns its events (one implementing
`Appendable`), so pointing it at a remote feed is a type error rather than a
runtime surprise.

### Consume

A `Consumer` reads from where it last got to, hands each new event to your
handler, and records how far it got:

```php
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;

$consumer = new Consumer($feed, 'cache-invalidator', new Cursor\Cache($cache));

$handled = $consumer->consume(function (CloudEvent $event) use ($router) {
    $router->invalidate($event->data['tags'] ?? []);
});
```

Call `consume()` on a timer, or give the consumer a `timeout` and loop — each
call then returns the moment an event arrives, or empty after the timeout:

```php
$consumer = new Consumer($feed, 'cache-invalidator', $cursor, timeout: 20_000);

while (true) {
    $consumer->consume($handler);
}
```

A consumer with no stored position starts at the oldest retained event. A
consumer that must not act on the backlog — a notifier announcing events as
they happen — opts into starting at the tip instead:

```php
use Utopia\Feed\Start;

$consumer = new Consumer($feed, 'notifier', $cursor, timeout: 20_000, start: Start::Tip);
```

A stored position always wins; `Start::Tip` only applies on the first run, or
after `reset()` — which with `Start::Tip` means "forget everything, resume
from now". Give a tip consumer a `timeout`: the producer anchors "now" as each
poll arrives, so new events land inside the held request rather than in the
gap between polls. Against a producer that predates the tip extension, the
first poll fails with a 4xx `Transport` error rather than silently replaying
the backlog.

### Consume another service's feed

Same code, different journal — nothing above it knows the events arrive over the
network:

```php
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as Curl;

$client = (new Client(new Curl()))
    ->withHeaders(['x-appwrite-jwt' => $token])
    ->withConnectionReuse();

$feed = new Feed(new Journal\Http($client, 'https://cloud.example.com/v1/feeds', 'edge'));
```

Long polling is handled by the producer, so a poll is one held request rather
than a client-side loop. `Journal\Http` takes any
[utopia-php/client](https://github.com/utopia-php/client) adapter, so a pooled or
Swoole coroutine transport drops straight in. Leave the `Retry` decorator off: a
failed read leaves the position where it was, so the next poll is already the
retry.

### Serve a feed over HTTP

The feed a service serves is the read half of the same journal it appends to.
`Feed::serve()` takes the route's raw query parameters and answers with a
`Batch`, which knows its own body and caching header — the whole route body is:

```php
use Utopia\Feed\Feed;

$feed = new Feed($journal);   // the same journal the Producer was built on

// GET /v1/feeds/:feedId
$batch = $feed->serve($request->getParams());

$response
    ->addHeader('Content-Type', 'application/cloudevents-batch+json')
    ->addHeader('Cache-Control', $batch->cacheControl(public: true))
    ->json($batch->toArray());
```

`serve()` extracts `lastEventId`, `limit` and `timeout` from the query,
coerces their string values, applies the defaults, and clamps the batch to
`Feed::MAX_BATCH` (1000 events) and the long-poll wait to `Feed::MAX_TIMEOUT`
(30s), so a client cannot ask for more than the producer is willing to build
or hold. A malformed `lastEventId` throws `Exception\Invalid` — catch it to
answer 400.

The response body is a bare JSON array of CloudEvents, as
[http-feeds.org](https://www.http-feeds.org/) defines it — no envelope. An
empty array means the consumer is caught up. The media type is
`application/cloudevents-batch+json`; on receipt this library only checks the
body shape, so a feed answering `application/json` still reads fine.

The spec defines two query parameters: `lastEventId` and `timeout`. This
library extends it with two more pieces of vocabulary: the `limit` parameter,
and the `lastEventId` value `$`, which the producer resolves to the tip of the
feed — the anchor behind `Start::Tip`. A spec-compliant consumer simply never
sends either, and a `$` can never collide with a real id.

A full batch is settled history and `cacheControl()` marks it cacheable; a
short one is the live end of the feed and is marked `no-store`. Caching is
`private` unless you pass `public: true`. The batch carries the limit it was
actually built with, so the header is always honest — there is no number for
the route to keep in sync.

Callers that already hold typed values can use `Feed::read()` and
`Feed::poll()` directly; both return a `Batch`, which counts and iterates as
the list of events it carries, and `Batch::lastId()` is the position a
stateless relay would otherwise track by hand.

## Events

Events **are**
[`Utopia\CloudEvents\CloudEvent`](https://github.com/utopia-php/cloudevents)
objects — this library defines no event type of its own:

```php
$consumer->consume(function (CloudEvent $event) {
    $tags = $event->data['tags'] ?? [];
    $trace = $event->extensions['traceparent'] ?? null;
});
```

`data` is unrestricted — a map, list, string, number or null all round-trip as
themselves. `subject` is nullable, so an event with no subject reads back as
`null`. `dataschema` and extension attributes survive an append and a read.

A batch is decoded strictly about `id`, because for a feed the id *is* the
consumer's position, and leniently about everything else — a producer that adds
an attribute or moves the spec forward must not stop a consumer that predates it.

An entry that cannot be read at all ends the batch where it sits: the events
before it are returned and handled, and the broken one heads the next batch,
where it stops the feed loudly. Only when it is the first entry — leaving no
usable prefix — does the read throw `Exception\Invalid`.

## Journals

A journal is where a feed's events live. It returns the events after a given id;
the ones that own their events also implement `Appendable` and assign the ids.

| Journal | Use for | `Appendable` |
| --- | --- | --- |
| `Journal\Redis` | Producing a feed on a Redis stream | ✅ |
| `Journal\Pool` | The same, over a [pooled](https://github.com/utopia-php/pools) connection | ✅ |
| `Journal\Http` | Consuming another service's feed | ❌ — it belongs to whoever appends to it |
| `Journal\Memory` | Tests and single-process development | ✅ |
| `Journal\None` | No backend configured — throws on use | ✅, and throws |

`Journal\Pool` is what most services producing a feed want: a long poll spans its
whole timeout, and this one borrows a connection per read and gives it back while
it waits, so polling never ties up the client the rest of the service is using.

`Journal\Redis` and `Journal\Pool` trim the stream to about `maxSize` entries
(100,000 by default, and the same for `Journal\Memory`; Redis trims
approximately, so the stream may run a little longer). That cap is the feed's
retention: the oldest entry still in it is where a consumer with no position
starts.

`Journal\None` throws on every operation rather than doing nothing, so a
misconfigured service fails at the point of use instead of silently dropping
events. `Cursor\None` is the opposite — it is a no-op, because a position that
goes nowhere only costs a replay, while an append that goes nowhere loses
events.

## Cursors

A cursor is where one consumer keeps its position. It is keyed by feed and
consumer name, so a single store serves every feed a service consumes:

| Cursor | Use for |
| --- | --- |
| `Cursor\Cache` | A consumer with a [Utopia cache](https://github.com/utopia-php/cache) — the usual choice when reading a remote feed |
| `Cursor\Redis` | A consumer running inside the producer, with no store of its own |
| `Cursor\Pool` | The same, over a pooled connection |
| `Cursor\Memory` | Tests, or a consumer that should replay from the beginning on every restart |
| `Cursor\None` | No store configured — remembers nothing, so a restart replays |

`Cursor\Cache` holds a position for `Cursor\Cache::TTL` (30 days) unless it is
saved again, so a consumer idle for longer than that reads back as one that has
never run.

The store is allowed to be lossy: a lost position costs a replay, not a gap. A
store that is *down* is a different matter — the failure surfaces from
`consume()` as a `Transport` exception rather than being swallowed, so catch it
in your loop if the consumer should keep trying:

```php
try {
    $consumer->consume($handler);
} catch (Transport $error) {
    Console::warning("[feed] {$error->getMessage()}");
}
```

Run **one process per consumer name.** Two sharing a name share one position, so
the feed is split between them rather than delivered to both.

## What a handler must tolerate

**A handler must be safe to run twice on the same event.** There are three
reasons, and none of them can be arranged away:

1. A handler can succeed and the position then fail to save.
2. A run interrupted partway resumes from the last event that succeeded.
3. A consumer whose position was lost restarts from the oldest retained event.

Every one of them re-delivers; none of them skips. An event handled twice is
absorbed by an idempotent handler, whereas an event stepped over is gone.

**A handler rejects an event by throwing.** The run stops there, the position
stays before it, and the next run tries again. Everything handled earlier in that
run stays handled. A handler that keeps failing blocks everything behind it —
intentionally, because a feed is ordered and stepping over a failure would apply
later events on top of state that was never updated.

**A consumer with no position starts at the oldest retained event, never at the
tip**, so a consumer deployed after the producer catches up rather than dropping
the backlog. Starting at the tip is strictly opt-in, per consumer, with
`Start::Tip`.

## Rolling out a feed

Replacing push delivery with a feed is a staged change, one release per step:

1. **Release the producer.** It appends events; nothing reads them yet.
2. **Release the consumers.** Each drains the backlog from its first poll.
3. **Only then remove the push path.**

While step 2 is in progress, consumers get a 404 from a producer that does not
serve the feed yet — normal, not a fault. The status is on the exception:

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

Unit tests need no services, but dependencies declare extensions the suite never
touches (`ext-redis`, `ext-memcached`, `ext-protobuf`), so install past them:

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

Utopia Feed requires PHP 8.5 or later. We recommend using the latest PHP version
whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
