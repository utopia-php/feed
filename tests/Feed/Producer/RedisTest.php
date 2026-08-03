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

    /**
     * Feeds and cursors share one Redis keyspace, and both keys are built by
     * joining names with `:`. Joined raw, a feed named `<feed>:cursor:x` takes
     * the key consumer `x`'s position on `<feed>` occupies, so a cursor `SET`
     * lands on a stream — a WRONGTYPE at best, and at worst one silently
     * destroying the other.
     */
    public function testAFeedNamedLikeACursorKeyDoesNotCollideWithOne(): void
    {
        $store = $this->store($this->name . ':cursor:x');
        (new Producer($store, 'urn:test'))->produce('a');

        $cursor = $this->cursor();
        $cursor->save($this->name, 'x', '1-0');

        $this->assertSame('1-0', $cursor->load($this->name, 'x'), 'The position is readable back');
        $this->assertCount(1, $store->read(null, 10), 'And the feed still holds its event');
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
