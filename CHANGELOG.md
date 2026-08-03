# Changelog

## 0.1.0 — Initial release

Pull-based HTTP event feeds ([http-feeds.org](https://www.http-feeds.org/)) for
PHP. A producer writes events to an ordered log; each consumer polls for what
happened after the last event it handled, and keeps that position itself.

Requires PHP 8.5. `ext-redis` is a suggestion, needed only by the Redis and
pooled adapters.

### Producing

- `Producer` — `produce(type, data, subject)` for the common case, and
  `publish(CloudEvent)` for a prepared event. Both return the event's id, which
  is also its position in the feed. The producer stamps its own `source`, the
  store assigns the `id`, and a missing `time` becomes now; everything else is
  published as prepared.
- Events are [utopia-php/cloudevents](https://github.com/utopia-php/cloudevents)
  `CloudEvent` objects. This library defines no event type of its own, so a feed
  event is accepted anywhere a `CloudEvent` is, and `datacontenttype`,
  `dataschema` and extension attributes survive an append and a read.

### Serving

- `Server` — the feed endpoint. `serve(array $query)` is the whole HTTP request
  in one call: it reads `lastEventId`, `limit` and `timeout` from the raw query
  parameters, coerces and clamps them, and rejects a `lastEventId` that is
  neither a position nor the tip sentinel with `Exception\Invalid`. `read()`,
  `poll()` and `tip()` are there for a route that wants the pieces.
- `Batch` — one read of a feed. Counts and iterates as its events, and carries
  the limit it was actually built with, so `cacheControl()` cannot be handed a
  number the read did not use: a full batch is settled history and cacheable
  forever, anything shorter is `no-store`. `toArray()` is the wire encoding and
  `lastId()` the position a stateless relay tracks.

### Consuming

- `Consumer` — the pull loop, at-least-once, with a durable position.
  `consume(callable)` handles a batch and commits after the last event that
  succeeded; a failing handler blocks the events behind it by design.
- Built over a local `Readable` for a feed the same service produces, or
  straight over a [utopia-php/client](https://github.com/utopia-php/client)
  whose base URI points at the feed endpoint, plus the feed's name.
- `position()`, `reset()` and `seek(eventId)` move the position by hand.
  `seek()` is the escape hatch for a poison event: seek to the failing event's
  own id to step past it. Both are safe to call from inside a handler.
- `Consumer::START_TIP` starts a consumer with no stored position at the tip of
  the feed instead of at the oldest retained event. It rides a protocol
  extension — the `lastEventId` value `$`, which the producer resolves to its
  newest event as the request arrives — so skipping the backlog costs no extra
  round trip and still delivers what lands mid-poll.

### Stores and cursors

- `Store\Redis`, `Store\Pool` — a capped Redis stream, directly or over a
  [pooled](https://github.com/utopia-php/pools) connection. The pooled store
  borrows per read, so a held long poll never ties up a connection.
- `Store\Cache` — the feed on a [Utopia cache](https://github.com/utopia-php/cache),
  for a service that already carries one. The whole feed lives under one key,
  so an append rewrites it (last-writer-wins — run one producing process) and
  retention is also the cost of producing an event, which is why its default
  `maxSize` is 1 000 rather than the 100 000 the Redis store keeps. The newest
  id is kept under a second, tiny key so a caught-up long poll does not load
  the feed to learn nothing.
- `Store\Memory` — tests and single-process development. `Store\None` — no
  backend configured; throws on use, so a misconfigured service fails loudly
  instead of dropping events.
- `Cursor\Cache`, `Cursor\Redis`, `Cursor\Pool`, `Cursor\Memory`, `Cursor\None`
  — where a consumer's position lives, keyed by feed and consumer name. The
  stored form is deliberately plain (`feed:<feed>:cursor:<consumer>` holding
  the id as a string), so positions carry across upgrades and an operator can
  answer "where is this consumer?" from a shell.
- Retention (`maxSize`) and the long-poll read interval (`pollInterval`, in
  milliseconds, default 500) are constructor options on every store.

### The wire

- A batch is the plain JSON array of CloudEvents the spec defines — no
  envelope. An empty array means the consumer is caught up. The media type is
  `Readable::MEDIA_TYPE`, `application/cloudevents-batch+json`, aliased as
  `Batch::MEDIA_TYPE` for the serving side and `Remote::MEDIA_TYPE` for the
  consuming one.
- `Readable` is the contract both sides share — `read`, `poll`, `tip`,
  `getName`, plus `TIP`, `MAX_BATCH` (1000) and `MAX_TIMEOUT` (30s).
  `Appendable` is the contract of a store that owns its events;
  `Remote` deliberately does not implement it.
- `Remote` — another service's feed over HTTP, a `Readable` in its own right
  rather than a store. Decoding is strict about `id`, because that is the
  consumer's position, and tolerant of everything else, so a consumer older
  than the producer keeps working: unknown attributes ride along as
  extensions, and an entry that cannot be read ends the batch early rather
  than discarding the usable events before it — unless it is the first, where
  there is no progress to keep and the read fails loudly instead.
- `Id` — feed positions (`{ms}-{seq}`) and the arithmetic for paging past one.
  `Key` shapes the backend keys, escaping names so a feed and a cursor cannot
  collide in one keyspace.

### Errors

Everything this library raises extends `Utopia\Feed\Exception`:
`Exception\Invalid` for something the caller handed over, `Exception\Transport`
for a backend or network failure, `Exception\Unsupported` for something a
backend cannot do.
