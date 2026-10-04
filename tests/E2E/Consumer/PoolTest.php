<?php

declare(strict_types=1);

namespace Utopia\Feed\Tests\E2E\Consumer;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Tests\Consumer\Base;
use Utopia\Feed\Tests\E2E\Support\UsesPool;

class PoolTest extends Base
{
    use UsesPool;

    /**
     * Every cursor operation borrows a connection and must give it back. A
     * leak drains the pool over a consumer's lifetime rather than failing.
     */
    public function testEveryCursorOperationReturnsItsConnection(): void
    {
        $this->producer->produce('a');
        $second = $this->producer->produce('b');

        $consumer = $this->consumer();

        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->position();
        $consumer->seek($second);
        $consumer->reset();

        $this->assertSame(self::POOL_SIZE, $this->pool()->count(), 'The pool is whole again');
    }
}
