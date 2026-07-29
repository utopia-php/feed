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
use Utopia\Feed\Feed;
use Utopia\Feed\Journal;

$feed = new Feed(
    new Journal\Redis($redis, 'edge'),
    source: 'urn:appwrite:cloud:fra',
);

$id = $feed->append(
    type: 'io.appwrite.edge.invalidate-rule',
    data: ['tags' => ['domain' => 'example.com']],
    subject: 'example.com',
);
```

`append()` returns the event's id, which is its position in the feed.

Subclass `Feed` to give it a typed vocabulary, so callers cannot invent an event
type or misspell a payload key:

```php
class EdgeFeed extends Feed
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

`Protocol` holds the wire contract — query parameters, response body, caching
rules — and deals in arrays, so it fits whichever HTTP layer you use:

```php
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;

// GET /v1/feeds/:feedId
$limit = \min((int) $request->getParam(Protocol::PARAM_LIMIT, Feed::MAX_BATCH), Feed::MAX_BATCH);

$events = $feed->poll(
    $request->getParam(Protocol::PARAM_LAST_EVENT_ID) ?: null,
    $limit,
    (int) $request->getParam(Protocol::PARAM_TIMEOUT, 0),
);

$response
    ->addHeader('Cache-Control', Protocol::cacheControl(\count($events), $limit))
    ->json(Protocol::encode($events));
```

Cap the limit yourself with `Feed::MAX_BATCH` before the call, so the number
that reaches `cacheControl()` is the one the batch was actually built with — a
read never returns more than that cap anyway. A full batch is settled
history and is marked cacheable; a short one is the live end of the feed and is
marked `no-store`. Caching is `private` unless you pass `public: true`.

## Events

Events **are**
[`Utopia\CloudEvents\CloudEvent`](https://github.com/utopia-php/cloudevents)
objects — this library defines no event type of its own:

```php
$consumer->consume(function (CloudEvent $event) {
    $tags = $event->data['tags'] ?? [];
    $trace = $event->getExtension('traceparent');
});
```

`data` is unrestricted — a map, list, string, number or null all round-trip as
themselves. `subject` is nullable, so an event with no subject reads back as
`null`. `dataschema` and extension attributes survive an append and a read.

A batch is decoded strictly about `id`, because for a feed the id *is* the
consumer's position, and leniently about everything else — a producer that adds
an attribute or moves the spec forward must not stop a consumer that predates it.

## Journals

A journal is where a feed's events live. It assigns an ordered id on append and
returns the events after a given id; everything else sits above it.

| Journal | Use for | Append | Read |
| --- | --- | --- | --- |
| `Journal\Redis` | Producing a feed on a Redis stream | ✅ | ✅ |
| `Journal\Pool` | The same, over a [pooled](https://github.com/utopia-php/pools) connection | ✅ | ✅ |
| `Journal\Http` | Consuming another service's feed | ❌ | ✅ |
| `Journal\Memory` | Tests and single-process development | ✅ | ✅ |
| `Journal\None` | No backend configured — throws on use | ❌ | ❌ |

`Journal\Pool` is what most services producing a feed want: a long poll holds its
connection for the whole timeout, so reading through a shared client would block
every other user of it.

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
the backlog.

## Rolling out a feed

Replacing push delivery with a feed is a two-release change:

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

Unit tests need nothing but composer:

```bash
composer install
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
