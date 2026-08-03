<?php

declare(strict_types=1);

namespace Utopia\Tests\Server;

use Utopia\Feed\Server;
use Utopia\Tests\Support\MidPollStore;
use Utopia\Tests\Support\UsesMemory;

class MemoryTest extends Base
{
    use UsesMemory;

    /**
     * A poll is a loop of reads, so an event another process appends while the
     * poll waits is delivered by the next read — only memory can stage that
     * append mid-wait deterministically, which is why this lives here.
     */
    public function testPollDeliversAnEventThatLandsMidWait(): void
    {
        $server = new Server(new MidPollStore($this->name));

        $started = \microtime(true);
        $events = $server->poll(null, 10, 5_000);

        $this->assertCount(1, $events);
        $this->assertLessThan(3, \microtime(true) - $started, 'Must return on the event, not the timeout');
    }

    public function testAShorterPollIntervalDeliversAMidPollEventSooner(): void
    {
        $server = new Server(new MidPollStore($this->name, pollInterval: 20));

        $started = \microtime(true);
        $events = $server->poll(null, 10, 5_000);
        $elapsed = \microtime(true) - $started;

        $this->assertCount(1, $events);
        // Ignoring the configured interval falls back to the 500ms default.
        $this->assertLessThan(0.45, $elapsed, 'A 20ms interval must beat the default 500ms floor');
    }
}
