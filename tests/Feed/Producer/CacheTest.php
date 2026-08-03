<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Producer;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Cache as CacheStore;
use Utopia\Tests\Support\BrokenCache;
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

    /** A store over a cache backend that is down, as an operator would meet it. */
    private function unreachable(): Store&Appendable
    {
        return new CacheStore(new UtopiaCache(new BrokenCache(raises: true)), $this->name);
    }

    /**
     * A cache adapter lets the backend's own error out once its internal
     * retries are exhausted, so without wrapping a raw \RedisException escapes
     * this library entirely. The canonical consume loop retries on Transport
     * and would crash on a backend blip instead — the exact failure mode the
     * Transport contract exists to prevent.
     *
     * @dataProvider operations
     * @param callable(Store&Appendable): void $operation
     */
    public function testABackendThatIsDownRaisesTransport(callable $operation): void
    {
        $store = $this->unreachable();

        $this->expectException(Transport::class);

        $operation($store);
    }

    /**
     * @return array<string, array{callable(Store&Appendable): void}>
     */
    public static function operations(): array
    {
        return [
            'read' => [static function (Store&Appendable $store): void {
                $store->read(null, 10);
            }],
            'tip' => [static function (Store&Appendable $store): void {
                $store->tip();
            }],
            'append' => [static function (Store&Appendable $store): void {
                $store->append(new CloudEvent(id: '', type: 'test', source: 'urn:test'));
            }],
        ];
    }

    /**
     * A cache that answers a write with false rather than raising must be
     * caught too — the entry is not in the feed, so reporting the position it
     * would have had would invent an event no consumer can ever read.
     */
    public function testACacheThatRejectsTheWriteRaisesTransport(): void
    {
        $store = new CacheStore(new UtopiaCache(new BrokenCache()), $this->name);

        $this->expectException(Transport::class);

        $store->append(new CloudEvent(id: '', type: 'test', source: 'urn:test'));
    }
}
