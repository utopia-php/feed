# Changelog

## 0.1.0

Initial release.

Extracted from the pull-based feed built across `appwrite-labs/cloud` (producer)
and `appwrite-labs/edge` (consumer), where the event model, the pull loop, the
cursor bookkeeping and the HTTP contract joining the two had each been written
twice. See [docs/migration.md](docs/migration.md) for how those map onto this
library.

- `Feed` — append, read and long-poll an ordered event log
- `Event` — a CloudEvent, with a strict decode that a feed's ids can be paged from
- `Adapter\Redis`, `Adapter\Pool` — Redis streams, directly or over a pool
- `Adapter\Http` — another service's feed, read over the wire with
  [utopia-php/client](https://github.com/utopia-php/client); takes any of its
  adapters, so a pooled or Swoole coroutine transport drops straight in
- `Adapter\Memory`, `Adapter\None` — for tests, and for no backend configured
- `Consumer` — the pull loop, with at-least-once semantics and a durable position
- `Cursor\Cache`, `Cursor\Redis`, `Cursor\Pool`, `Cursor\Memory` — where that position lives
- `Protocol` — the http-feeds wire contract, shared by producer and consumer
- `Id` — feed positions, and the arithmetic for paging past one

Requires PHP 8.5. CI builds one parameterized image per version in the
`php-versions` matrix of `.github/workflows/tests.yml`, which is the only place
versions are listed.
