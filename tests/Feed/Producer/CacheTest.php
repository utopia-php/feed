<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Key;
use Utopia\Feed\Producer;
use Utopia\Feed\Server;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Cache as CacheStore;
use Utopia\Tests\Support\BrokenCache;
use Utopia\Tests\Support\CountingCache;
use Utopia\Tests\Support\UsesCache;

class CacheTest extends Base
{
    use UsesCache;

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

    /**
     * The whole feed lives under one key, so answering "anything new?" by
     * reading it costs the entire feed — per tick, per waiting consumer.
     */
    public function testACaughtUpPollReadsTheTipMarkerRatherThanTheFeed(): void
    {
        $adapter = new CountingCache();
        $store = new CacheStore(new UtopiaCache($adapter), $this->name, pollInterval: 50);

        $last = (new Producer($store, 'urn:test'))->produce('a');
        $adapter->forget();

        $events = (new Server($store))->poll($last, 10, 400);

        $this->assertCount(0, $events, 'The consumer is caught up, so the poll waits out its timeout');
        $this->assertGreaterThan(2, $adapter->reads(Key::tip($this->name)), 'Each tick checks the marker');
        $this->assertSame(0, $adapter->reads(Key::feed($this->name)), 'And never loads the feed to learn nothing');
    }

    /**
     * A cache may drop one key and keep another, so a missing marker must fall
     * through to the real read rather than strand the consumer.
     */
    public function testAMissingTipMarkerFallsBackToReadingTheFeed(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');

        $this->cache()->purge(Key::tip($this->name));

        $this->assertCount(1, $this->store->read($first, 10));
    }

    /** And the gate must not be sticky, or a quiet feed stays quiet forever. */
    public function testAnEventAppendedAfterACaughtUpReadIsStillDelivered(): void
    {
        $first = $this->producer->produce('a');

        $this->assertCount(0, $this->store->read($first, 10), 'Caught up');

        $this->producer->produce('b');

        $this->assertCount(1, $this->store->read($first, 10), 'And no longer');
    }

    /** Retention is also the size of every append here, so the default is far smaller. */
    public function testTheDefaultRetentionIsScaledToWhatAnAppendCosts(): void
    {
        $store = new CacheStore($this->cache(), $this->name);
        $producer = new Producer($store, 'urn:test');

        foreach (\range(1, 1_001) as $i) {
            $producer->produce('event-' . $i);
        }

        $events = $store->read(null, 2_000);

        $this->assertCount(1_000, $events);
        $this->assertSame('event-2', $events[0]->type, 'The oldest went first');
    }

    /** A store over a cache backend that is down, as an operator would meet it. */
    private function unreachable(): Store&Appendable
    {
        return new CacheStore(new UtopiaCache(new BrokenCache(raises: true)), $this->name);
    }

    /**
     * A cache lets the backend's own error out once its retries are exhausted.
     * Unwrapped, the canonical consume loop crashes on a backend blip instead
     * of retrying — what the Transport contract exists to prevent.
     *
     * @param callable(Store&Appendable): void $operation
     */
    #[DataProvider('operations')]
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
     * A write answered with false must be caught too, or the position reported
     * names an event no consumer can ever read.
     */
    public function testACacheThatRejectsTheWriteRaisesTransport(): void
    {
        $store = new CacheStore(new UtopiaCache(new BrokenCache()), $this->name);

        $this->expectException(Transport::class);

        $store->append(new CloudEvent(id: '', type: 'test', source: 'urn:test'));
    }
}
