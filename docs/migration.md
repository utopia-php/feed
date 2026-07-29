# Migrating an existing feed onto this library

This library was extracted from two implementations of the same idea that had
grown up on either side of one feed: a producer in `appwrite-labs/cloud` and a
consumer in `appwrite-labs/edge`. Between them they had two event models, two
pull loops, two cursor stores and two copies of the HTTP contract that joined
them — the duplication that matters most, because the two halves drifting apart
is a wire incompatibility rather than a local bug.

This is what maps onto what.

## Producer (cloud)

| Was | Now |
| --- | --- |
| `Feed::append()` / `read()` / `poll()` | `Feed`, on `Adapter\Pool` |
| `Feed::after()` — stream id arithmetic | `Id::after()` |
| `Feed::getCursor()` / `saveCursor()` | `Cursor\Pool` |
| `Consumer::consume()` | `Consumer::consume()` |
| Query params and response shape in `Http\Feeds\Get` | `Protocol` |
| `Cache-Control` rules in `Http\Feeds\Get` | `Protocol::cacheControl()` |
| `EdgeFeed` | Stays — subclass `Feed` |
| `FastlyConsumer` | Stays — becomes a handler |
| `Response\Model\FeedEvent` | Stays — it is an SDK response model |

`EdgeFeed` stays in cloud because the tag names in its payloads are a contract
with the edge, not a general-purpose feed concern. It keeps its typed method per
invalidatable resource for the same reason as before — a caller-supplied tag
array with a typo in it produces an event that silently invalidates nothing —
and now only has to define the vocabulary:

```php
class EdgeFeed extends Feed
{
    public const string NAME = 'edge';

    public const string EVENT_INVALIDATE_RULE = 'io.appwrite.edge.invalidate-rule';

    public function __construct(?Pool $pool, string $source, int $maxSize = 100_000)
    {
        parent::__construct(
            $pool === null ? new None(self::NAME) : new Adapter\Pool($pool, self::NAME, $maxSize),
            $source,
        );
    }

    public function invalidateRule(string $domain, bool $isAppwriteNetwork = false): string
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('Rule invalidation requires a domain');
        }

        return $this->append(
            self::EVENT_INVALIDATE_RULE,
            ['tags' => ['domain' => $domain], 'isAppwriteNetwork' => $isAppwriteNetwork],
            $domain,
        );
    }

    // ...one method per invalidatable resource, as before
}
```

The nullable pool becomes `Adapter\None`, which throws on use with the same
intent as the old `pool()` guard: a feed with no backend must fail loudly rather
than drop events.

`Http\Feeds\Get` keeps its route, auth and SDK metadata, and hands the wire
details to `Protocol`:

```php
$events = $feed->poll($lastEventId === '' ? null : $lastEventId, $limit, $timeout);

$response->addHeader('Cache-Control', Protocol::cacheControl(\count($events), $limit));
$response->dynamic(new Document(Protocol::encode($events)), Response::MODEL_FEED_EVENT_LIST);
```

`FastlyConsumer` stops extending `Consumer` and becomes a handler passed to one.
Its Fastly-specific parts — the purge URL, the surrogate key format, the
credential — stay in cloud; the pull loop does not:

```php
$consumer = new Consumer($edgeFeed, FastlyConsumer::NAME, new Cursor\Pool($pool, EdgeFeed::NAME));

$purged = 0;
$seen = [];

$consumer->consume(function (Event $event) use (&$purged, &$seen): void {
    $url = $this->purgeUrl($event);
    if ($url === null || isset($seen[$url])) {
        return;
    }

    $this->send($url);
    $seen[$url] = true;
    $purged++;
});
```

`purgeUrl()` reads a typed `Event` instead of an array:

```php
if ($event->type !== EdgeFeed::EVENT_INVALIDATE_RULE) {
    return null;
}

$tags = $event->getData('tags', []);
$domain = \is_array($tags) ? ($tags['domain'] ?? '') : '';
```

## Consumer (edge)

| Was | Now |
| --- | --- |
| `Feed\Consumer` | `Consumer`, plus a handler |
| `Feed\Cursor` | `Cursor\Cache` |
| `Feed\Event` | `Event` |
| `Feed\Event::FEED` and the type constants | Stay — they name cloud's feed and its events |
| `Manager::fetchFeed()` | `Adapter\Http`, over `utopia-php/client` |
| `Consumer::TIMEOUT_MARGIN` | `Protocol::TIMEOUT_MARGIN` |
| `Feed\Poller` | Stays — Swoole interval scheduling |
| `Router\Invalidator` | Stays — it purges edge caches |

The whole of `Feed\Consumer`, `Feed\Cursor` and `Feed\Event` is replaced by
construction:

```php
$client = (new Client(new Curl()))->withHeaders(['x-appwrite-jwt' => $token]);

$consumer = new Consumer(
    feed: new Feed(new Http($client, $endpoint . '/manager/feeds', 'edge')),
    name: $region,
    cursor: new Cursor\Cache($cache, 'edge'),
    batch: 500,
    timeout: $timeout,
);

$consumer->onWarning(fn (\Throwable $error, string $context) =>
    Console::warning("[feed] Could not {$context} the {$region} cursor: {$error->getMessage()}"));
```

The edge's old `Event::from()` normalized tags on the way in so that
`tags === []` was a reliable answer to "is there anything to do?". That belongs
with the invalidator that defines what a usable tag is, so it moves into the
handler:

```php
$consumer->consume(function (Event $event) use ($invalidator): void {
    $tags = $event->getData('tags', []);
    $tags = \is_array($tags) ? Invalidator::normalize($tags) : [];

    if ($tags === []) {
        Span::add('feed.consume.skipped_type', $event->type);
        return;
    }

    $invalidator->invalidate($tags);
});
```

`Poller` keeps its Swoole `WaitGroup` fan-out and its once-per-region 404
reporting. Only the exception it catches changes, from `PlatformException` to
`Utopia\Feed\Exception\Transport` — the status is still on `getCode()`, so the
`!== 404` check is unchanged.

## Behaviour that is deliberately identical

These were load-bearing in the original implementations and are preserved:

- **A consumer with no position starts at the oldest retained event**, not at
  the tip. This is what makes the staged rollout in the README safe.
- **A run commits the events handled before a failure**, then re-raises it. The
  failed event is retried on the next run, and everything behind it waits.
- **A cursor store that is down is a warning, not a failure.** The position is
  mirrored in memory, so the consumer keeps working and only a restart replays.
- **The `feed:<name>:cursor:<consumer>` key format**, so consumers keep their
  positions across the migration instead of replaying the retained feed.
- **`<ms>-<seq>` event ids**, so positions already handed out stay valid.
- **The margin a consumer allows its HTTP client over the long-poll timeout**,
  without which every quiet tick surfaces as a transport failure.

## Behaviour that changed

- **`source` is stamped at append rather than at read.** Previously every event
  read from a feed was labelled with the reading region's source, whoever
  actually produced it. Storing it at append costs nothing and keeps it correct
  for a feed that is replicated or read back somewhere else.
- **A batch containing an event with no id no longer throws away the valid
  events in front of it.** `Protocol::decode()` returns the usable prefix, and
  throws once the broken event reaches the head of a batch — where the feed
  stops visibly rather than quietly losing events.
- **`Cache-Control` is computed from the batch**, and `public` is opt-in rather
  than a decision baked into one endpoint.
