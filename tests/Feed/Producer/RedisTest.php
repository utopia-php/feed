<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use Utopia\Feed\Producer;
use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

    /**
     * The wire format other tools rely on: `GET`-able keys named after the
     * feed, holding a plain Redis stream — which is what lets an operator
     * answer "what is in this feed?" from a shell.
     */
    public function testEventsLiveInAStreamUnderTheFeedsKey(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertSame(2, $this->redis()->xLen('feed:' . $this->name));
    }

    /** Trimming must happen on the server, not only in what read() returns. */
    public function testTheStreamItselfIsTrimmed(): void
    {
        $store = $this->store($this->name, maxSize: 10);
        $producer = new Producer($store, 'urn:test');

        foreach (\range(1, 300) as $i) {
            $producer->produce('event-' . $i);
        }

        $this->assertLessThan(300, $this->redis()->xLen('feed:' . $this->name), 'The stream must be trimmed');
    }
}
