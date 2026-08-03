<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Producer;
use Utopia\Feed\Store;
use Utopia\Tests\Support\UsesCache;

class CacheTest extends Base
{
    use UsesCache;

    /**
     * A feed in the cache, and a producer over it.
     *
     * The shared scenarios run against a memory feed on purpose, and swap only
     * the cursor — so `Store\Cache` was exercised by the producer and server
     * suites but never by a `Consumer`, which is the integration the adapter
     * exists for: a service that already carries a cache keeping both the feed
     * and the position there.
     *
     * @return array{Store&Appendable, Producer}
     */
    private function fed(): array
    {
        $store = $this->store($this->name);

        return [$store, new Producer($store, 'urn:test')];
    }

    public function testAConsumerDrainsACacheFedFeed(): void
    {
        [$store, $producer] = $this->fed();

        $producer->produce('a');
        $last = $producer->produce('b');

        $this->assertSame(['a', 'b'], $this->drain($this->consumer(store: $store), $count));
        $this->assertSame(2, $count);
        $this->assertSame($last, $this->cursor->load($this->name, 'invalidator'), 'The position lives in the same cache as the feed');

        $producer->produce('c');

        // A fresh Consumer over the same cache: a restart.
        $this->assertSame(['c'], $this->drain($this->consumer(store: $store)), 'Resumes without replaying');
    }

    /**
     * The consumer's poll loop over this adapter, which reads the feed
     * differently from the others: a caught-up tick answers from the tip
     * marker rather than by loading the feed, so "caught up" has to keep
     * meaning caught up and not "nothing more, ever".
     */
    public function testACaughtUpConsumerStillSeesTheNextEvent(): void
    {
        [$store, $producer] = $this->fed();

        $producer->produce('a');

        $consumer = $this->consumer(store: $store);

        $this->assertSame(['a'], $this->drain($consumer));
        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null), 'Caught up');

        $producer->produce('b');

        $this->assertSame(['b'], $this->drain($consumer), 'And no longer');
    }

    public function testAConsumerPagesACacheFedBacklogInBatches(): void
    {
        [$store, $producer] = $this->fed();

        foreach (\range(1, 10) as $i) {
            $producer->produce('event-' . $i);
        }

        $consumer = $this->consumer(batch: 4, store: $store);

        $this->assertSame(4, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertSame(4, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertSame(2, $consumer->consume(fn (CloudEvent $event) => null));
        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }
}
