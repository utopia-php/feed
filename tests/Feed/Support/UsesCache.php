<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Feed\Appendable;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Cache as CacheCursor;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Cache as CacheStore;

/**
 * The cache adapter pair, over one {@see UtopiaCache} per test — the feed and
 * the cursors live in the cache, so everything in a test shares it the way a
 * service sharing one cache backend would.
 */
trait UsesCache
{
    private ?UtopiaCache $cache = null;

    protected function cache(): UtopiaCache
    {
        return $this->cache ??= new UtopiaCache(new CacheMemory());
    }

    protected function store(string $name, int $maxSize = 100_000, int $pollInterval = 500): Store&Appendable
    {
        return new CacheStore($this->cache(), $name, $maxSize, pollInterval: $pollInterval);
    }

    protected function cursor(): Cursor
    {
        return new CacheCursor($this->cache());
    }
}
