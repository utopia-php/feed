<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Producer;
use Utopia\Feed\Store\Cache as CacheStore;
use Utopia\Tests\Support\UsesCache;

class CacheTest extends Base
{
    use UsesCache;

    /** The cache store trims exactly, so the bound is the cap itself. */
    public function testRetentionTrimsToExactlyTheCap(): void
    {
        $store = $this->store($this->name, maxSize: 3);
        $producer = new Producer($store, 'urn:test');

        foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
            $producer->produce($type);
        }

        $this->assertSame(['c', 'd', 'e'], \array_map(fn (CloudEvent $e): string => $e->type, $store->read(null, 10)));
    }

    public function testAcceptsTheSmallestUsefulRetentionCap(): void
    {
        $store = $this->store($this->name, maxSize: 1);
        $producer = new Producer($store, 'urn:test');

        $producer->produce('a');
        $producer->produce('b');

        $events = $store->read(null, 10);

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }

    /**
     * The property that justifies the adapter: the feed lives in the cache, not
     * in the store object, so a second store over the same cache — another
     * request handling the same feed — reads what the first appended.
     */
    public function testTheFeedSurvivesTheStoreThatWroteIt(): void
    {
        $id = $this->producer->produce('a');

        $events = (new CacheStore($this->cache(), $this->name))->read(null, 10);

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
    }

    /**
     * A cache is allowed to forget, and it may also hold a foreign value under
     * the feed's key. Both read as an empty feed — a replay, never a fault.
     */
    public function testAForeignValueUnderTheFeedsKeyReadsAsEmpty(): void
    {
        $this->cache()->save('feed:' . $this->name, ['not' => 'a feed']);

        $this->assertCount(0, $this->store->read(null, 10));
        $this->assertNull($this->store->tip());
    }
}
