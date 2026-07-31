# Changelog

## 0.1.0

- **Breaking (the three classes):** the library is now three main classes.
  `Producer` writes events to a feed with `produce()` (formerly `append()`;
  `publish()` still takes a prepared `CloudEvent`). `Server` (formerly
  `Feed`) is the HTTP feed endpoint, with `serve()` as its main method.
  `Consumer` reads a feed as a client with `consume()`.
- **Breaking:** `Journal` is now `Store` — `Store\Redis`, `Store\Pool`,
  `Store\Memory`, `Store\None`, in the `Utopia\Feed\Store` namespace. The
  `Producer` and `Server` are built over a store; `Appendable` and `Readable`
  are unchanged as its contracts.
- **Breaking:** `Consumer` no longer takes an endpoint. It is built straight
  over a [utopia-php/client](https://github.com/utopia-php/client) whose
  base URI points at the feed endpoint (`withBaseUri()`), plus the feed's
  name: `new Consumer($client, $cursor, name: 'invalidator', feed: 'edge')`.
  A local store still drops in for the client, for consuming a feed the same
  service produces; the cursor moved forward to the second parameter.
  `Remote` accordingly lost its `endpoint` parameter — the feed name is sent
  as a relative path and the client resolves it.
- Added `Store\Cache` — a feed on a [utopia-php/cache](https://github.com/utopia-php/cache)
  `Cache`, for a producer whose service already carries a cache and does not
  want another backend. The whole feed lives under one key (last-writer-wins
  appends — run one producing process), trims to `maxSize`, and expires `ttl`
  seconds after the last append (default 30 days). Both `Store` and `Cursor`
  now have `Redis` and `Cache` adapters.
- `Server::read()` and `Server::poll()` return a `Batch` instead of a plain
  event array. A batch counts and iterates as its events and carries the
  limit it was actually built with, so `Batch::cacheControl()` can never be
  fed a number the read did not use. `Batch::toArray()` is the wire encoding,
  `Batch::lastId()` the position a stateless relay tracks.
- Added `Server::serve(array $query): Batch` — the whole HTTP request in one
  call: extracts `lastEventId`, `limit` and `timeout` from the route's raw
  query parameters, coerces and clamps them, and rejects a malformed
  `lastEventId` with `Exception\Invalid`. A route never needs to name
  `Protocol`, which is now documented as internal plumbing.
- Added `Start::Tip` — a consumer with no stored position can opt into
  starting at the tip of the feed (only what happens from now on) instead of
  draining the backlog. Rides a protocol extension: the `lastEventId` value
  `$`, resolved by the producer to the newest event as the request arrives.
  Also added `Server::tip()`, the id of the newest event in a local store.
- Added `Consumer::seek(string $eventId)` — set the position explicitly: the
  id is treated as the last event handled, persisted immediately, and the
  next `consume()` starts strictly after it. The operational escape hatch for
  a poison event: seek to the failing event's own id to step past it
  deliberately.
- The long-poll read interval is now a constructor option on the stores:
  `pollInterval`, in milliseconds, default 500. An interval below 1 ms throws
  `Exception\Invalid`. The poll loop also no longer oversleeps: it sleeps the
  remaining time when less than an interval is left, so a timeout is honoured
  to within scheduler precision instead of running up to one interval late.
- **Breaking (renames):** every user-facing name now belongs to exactly one
  side of the wire. `Journal\Http` is gone; its replacement is
  `Utopia\Feed\Remote` — another service's feed, over HTTP — a standalone
  class implementing the new `Readable` interface (`read`, `poll`, `tip`,
  `getName`) rather than posing as a store. `Consumer` clamps its own
  `batch`/`timeout`. The protocol limits moved with the responsibility:
  `Feed::MAX_BATCH`/`Feed::MAX_TIMEOUT` are now `Protocol::MAX_BATCH` and
  `Protocol::MAX_TIMEOUT`.
- **Breaking (wire format):** a feed batch on the wire is now the plain JSON
  array of CloudEvents that [http-feeds.org](https://www.http-feeds.org/)
  defines — the `{total, events}` envelope is gone, and an empty feed
  serializes to `[]`. `Protocol::encode()` returns the bare array,
  `Protocol::decode()` expects one, and `Remote` asks for the spec's
  `application/cloudevents-batch+json` media type (`Protocol::MEDIA_TYPE`).
  Both sides of a feed must move together.

## 0.1.0

Initial release.

- `Producer` — appends events to a feed this service owns
- `Feed` — reads and long-polls a feed, local or remote
- Events are [utopia-php/cloudevents](https://github.com/utopia-php/cloudevents)
  `CloudEvent` objects — this library defines no event type of its own, so a feed
  event is accepted anywhere a `CloudEvent` is, and `dataschema` and extension
  attributes survive an append and a read
- `Appendable` — the journals that own their events and can be appended to;
  `Journal\Http` deliberately does not implement it
- `Journal\Redis`, `Journal\Pool` — Redis streams, directly or over a pool
- `Journal\Http` — another service's feed, read over the wire with
  [utopia-php/client](https://github.com/utopia-php/client); takes any of its
  adapters, so a pooled or Swoole coroutine transport drops straight in
- `Journal\Memory`, `Journal\None` — for tests, and for no backend configured
- `Consumer` — the pull loop, with at-least-once semantics and a durable position
- `Cursor\Cache`, `Cursor\Redis`, `Cursor\Pool`, `Cursor\Memory`, `Cursor\None` — where that
  position lives, keyed by feed and consumer name
- `Protocol` — the http-feeds wire contract, shared by producer and consumer, and
  the one place the feed's decode policy lives: strict about `id` because it is
  the consumer's position, tolerant of everything else so a consumer older than
  the producer keeps working
- `Id` — feed positions, and the arithmetic for paging past one

Requires PHP 8.5. CI builds one parameterized image per version in the
`php-versions` matrix of `.github/workflows/tests.yml`, which is the only place
versions are listed.
