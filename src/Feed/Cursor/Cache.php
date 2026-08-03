<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;

class Cache extends Cursor
{
    public const int TTL = 30 * 24 * 60 * 60; // 30 days

    public function __construct(
        protected readonly UtopiaCache $cache,
        protected readonly int $ttl = self::TTL,
    ) {
    }

    public function load(string $feed, string $consumer): ?string
    {
        /** @var mixed $cursor */
        $cursor = $this->cache->load($this->key($feed, $consumer), $this->ttl);

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        $saved = $this->cache->save($this->key($feed, $consumer), $eventId);

        // A cache adapter reports a failed write by returning false rather
        // than raising — swallowing that would let a position silently not
        // persist, so the consumer replays its backlog on the next restart
        // and a seek past a poison event quietly does nothing.
        if ($saved === false) {
            throw new Transport("Failed to save the {$consumer} cursor on the {$feed} feed");
        }
    }

    public function reset(string $feed, string $consumer): void
    {
        // Unlike save(), purge()'s false is not a failure signal: it is also
        // what an adapter answers for a key that was never there, which is
        // the ordinary case for a consumer that has not saved a position yet.
        $this->cache->purge($this->key($feed, $consumer));
    }
}
