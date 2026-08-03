<?php

declare(strict_types=1);

namespace Utopia\Tests\Server;

use Utopia\Feed\Server;
use Utopia\Feed\Store\Pool as PoolStore;
use Utopia\Tests\Support\CountingStack;
use Utopia\Tests\Support\UsesPool;

class PoolTest extends Base
{
    use UsesPool;

    /**
     * The property the pooled store exists for, and the only one the shared
     * scenarios cannot show: a long poll is a loop of reads, and the store
     * borrows for each read rather than once around the whole loop. Held for
     * the wait, one poll would tie up a connection for up to 30 seconds, so a
     * handful of idle consumers would exhaust the pool.
     *
     * Asserted as a count of releases, so an "optimization" that hoists the
     * borrow out of the loop fails here instead of silently removing the
     * class's entire reason to exist.
     */
    public function testAHeldPollBorrowsPerReadRatherThanForTheWholeWait(): void
    {
        $adapter = new CountingStack();
        $store = new PoolStore(self::poolOver($adapter), $this->name, pollInterval: 50);

        $events = (new Server($store))->poll(null, 10, 500);

        $this->assertCount(0, $events, 'The feed is empty, so the poll waits out its timeout');
        $this->assertGreaterThan(
            2,
            $adapter->releases,
            'A ~500ms poll on a 50ms interval reads many times; borrowing once for the whole wait would release once',
        );
    }

    /**
     * The other half of the same property: every borrow is given back. A leak
     * would not fail a functional test until the pool ran dry, which in a
     * service is minutes into production rather than here.
     */
    public function testEveryBorrowIsReturnedToThePool(): void
    {
        $this->producer->produce('a');

        $this->server->read();
        $this->server->tip();
        $this->server->serve([]);

        $this->assertSame(self::POOL_SIZE, $this->pool()->count(), 'The pool is whole again');
    }
}
