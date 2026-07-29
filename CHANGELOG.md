# Changelog

## 0.1.0

Initial release.

Extracted from the pull-based feed built across `appwrite-labs/cloud` (producer)
and `appwrite-labs/edge` (consumer), where the event model, the pull loop, the
cursor bookkeeping and the HTTP contract joining the two had each been written
twice. See [docs/migration.md](docs/migration.md) for how those map onto this
library.

- `Feed` — append, read and long-poll an ordered event log
- Events are [utopia-php/cloudevents](https://github.com/utopia-php/cloudevents)
  `CloudEvent` objects — this library defines no event type of its own, so a feed
  event is accepted anywhere a `CloudEvent` is, and `dataschema` and extension
  attributes survive an append and a read
- `Journal\Redis`, `Journal\Pool` — Redis streams, directly or over a pool
- `Journal\Http` — another service's feed, read over the wire with
  [utopia-php/client](https://github.com/utopia-php/client); takes any of its
  adapters, so a pooled or Swoole coroutine transport drops straight in
- `Journal\Memory`, `Journal\None` — for tests, and for no backend configured
- `Consumer` — the pull loop, with at-least-once semantics and a durable position
- `Cursor\Cache`, `Cursor\Redis`, `Cursor\Pool`, `Cursor\Memory` — where that position lives
- `Protocol` — the http-feeds wire contract, shared by producer and consumer, and
  the one place the feed's decode policy lives: strict about `id` because it is
  the consumer's position, tolerant of everything else so a consumer older than
  the producer keeps working
- `Id` — feed positions, and the arithmetic for paging past one

Requires PHP 8.5. CI builds one parameterized image per version in the
`php-versions` matrix of `.github/workflows/tests.yml`, which is the only place
versions are listed.
