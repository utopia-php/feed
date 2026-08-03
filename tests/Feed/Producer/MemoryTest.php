<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Producer;
use Utopia\Tests\Support\UsesMemory;

class MemoryTest extends Base
{
    use UsesMemory;

    /** Memory trims exactly, so the bound is the cap itself. */
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
}
