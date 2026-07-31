# Changelog

## Unreleased

- **Breaking:** `Feed::read()` and `Feed::poll()` return a `Batch` instead of
  a plain event array. A batch counts and iterates as its events and carries
  the limit it was actually built with, so `Batch::cacheControl()` can never
  be fed a number the read did not use. `Batch::toArray()` is the wire
  encoding, `Batch::lastId()` the position a stateless relay tracks.
- Added `Feed::serve(array $query): Batch` — the whole HTTP request in one
  call: extracts `lastEventId`, `limit` and `timeout` from the route's raw
  query parameters, coerces and clamps them, and rejects a malformed
  `lastEventId` with `Exception\Invalid`. A route never needs to name
  `Protocol`, which is now documented as internal plumbing.

- **Breaking (wire format):** a feed batch on the wire is now the plain JSON
  array of CloudEvents that [http-feeds.org](https://www.http-feeds.org/)
  defines — the `{total, events}` envelope is gone, and an empty feed
  serializes to `[]`. `Protocol::encode()` returns the bare array,
  `Protocol::decode()` expects one, and the HTTP journal asks for the spec's
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
