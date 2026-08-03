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
     * The property the pooled store exists for: a long poll borrows per read,
     * not once around the loop, so an idle consumer does not tie up a
     * connection for 30 seconds. Counted, so hoisting the borrow fails here.
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

    /** Every borrow is given back — a leak fails nothing until the pool runs dry. */
    public function testEveryBorrowIsReturnedToThePool(): void
    {
        $this->producer->produce('a');

        $this->server->read();
        $this->server->tip();
        $this->server->serve([]);

        $this->assertSame(self::POOL_SIZE, $this->pool()->count(), 'The pool is whole again');
    }
}
