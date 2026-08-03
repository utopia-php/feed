<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

    /**
     * The stored form is deliberately plain — the key is
     * `feed:<feed>:cursor:<consumer>` and the value the event id as a string —
     * so positions carry across upgrades and an operator can answer "where is
     * this consumer?" from a shell.
     */
    public function testThePositionIsStoredWhereOperatorsExpectIt(): void
    {
        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $this->drain($this->consumer());

        $this->assertSame($last, $this->redis()->get('feed:' . $this->name . ':cursor:invalidator'));
    }
}
