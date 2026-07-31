<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\Feed\Id;
use Utopia\Feed\Producer;
use Utopia\Feed\Server;
use Utopia\Feed\Store\Cache;

/**
 * The store behaviours {@see Cache} has to provide itself — id allocation,
 * ordering, trimming — rather than inherit from {@see \Utopia\Feed\Store},
 * plus the property that justifies the adapter: the feed lives in the cache,
 * so a second store over the same cache reads what the first wrote.
 */
class StoreCacheTest extends TestCase
{
    private UtopiaCache $cache;

    private Cache $store;

    private Producer $producer;

    protected function setUp(): void
    {
        $this->cache = new UtopiaCache(new CacheMemory());
        $this->store = new Cache($this->cache, 'edge');
        $this->producer = new Producer($this->store, 'urn:test');
    }

    public function testRoundTripsAnEventThroughTheServer(): void
    {
        $id = $this->producer->produce('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = \array_values(\iterator_to_array((new Server($this->store))->read()));

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame('example.com', $events[0]->subject);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    public function testEventsComeBackOldestFirst(): void
    {
        foreach (['a', 'b', 'c'] as $type) {
            $this->producer->produce($type);
        }

        $this->assertSame(['a', 'b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $this->store->read(null, 10)));
    }

    public function testIdsAreStrictlyIncreasingEvenWithinAMillisecond(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->producer->produce('test');
        }

        $this->assertSame($ids, \array_unique($ids), 'Positions must be unique');

        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertGreaterThan(Id::decode($ids[$i - 1]), Id::decode($ids[$i]), 'Positions must increase');
        }
    }

    public function testReadsStrictlyAfterTheGivenPosition(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');

        $events = $this->store->read($first, 10);

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
        $this->assertCount(0, $this->store->read($events[0]->id, 10));
    }

    public function testHonoursTheLimit(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->producer->produce('test');
        }

        $this->assertCount(3, $this->store->read(null, 3));
    }

    public function testRetentionIsBoundedAndTrimsTheOldest(): void
    {
        $store = new Cache($this->cache, 'small', maxSize: 3);
        $producer = new Producer($store, 'urn:test');

        foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
            $producer->produce($type);
        }

        $this->assertSame(['c', 'd', 'e'], \array_map(fn (CloudEvent $e): string => $e->type, $store->read(null, 10)));
    }

    public function testTipIsTheNewestEventsId(): void
    {
        $this->assertNull($this->store->tip(), 'An empty feed has no tip');

        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $this->assertSame($last, $this->store->tip());
    }

    /**
     * The feed lives in the cache, not in the store object: a second store
     * over the same cache — another request handling the same feed — reads
     * what the first appended.
     */
    public function testTheFeedSurvivesTheStoreThatWroteIt(): void
    {
        $id = $this->producer->produce('a');

        $events = (new Cache($this->cache, 'edge'))->read(null, 10);

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
    }

    /**
     * A cache is allowed to forget, and it may also hold a foreign value under
     * the feed's key. Both read as an empty feed — a replay, never a fault.
     */
    public function testAForeignValueUnderTheFeedsKeyReadsAsEmpty(): void
    {
        $this->cache->save('feed:edge', ['not' => 'a feed']);

        $this->assertCount(0, $this->store->read(null, 10));
        $this->assertNull($this->store->tip());
    }

    public function testExtensionAttributesSurviveTheTrip(): void
    {
        $this->producer->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            extensions: ['traceparent' => '00-abc-def-01'],
        ));

        $this->assertSame('00-abc-def-01', $this->store->read(null, 10)[0]->extensions['traceparent']);
    }

    public function testAConsumerDrainsACacheFedFeed(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $seen = [];
        $consumer = new Consumer($this->store, new MemoryCursor(), 'invalidator');

        $this->assertSame(2, $consumer->consume(function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        }));
        $this->assertSame(['a', 'b'], $seen);
    }
}
