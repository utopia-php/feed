<?php

declare(strict_types=1);

namespace Utopia\Tests\Cursor;

use Utopia\Feed\Key;
use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

    /** A key left in another format is no position, not a permanent failure. */
    public function testAKeyOfAnotherTypeLoadsAsNoPosition(): void
    {
        $this->redis()->xAdd(Key::cursor($this->name, 'invalidator'), '1-0', ['p' => '1']);

        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
    }

    /** The first save overwrites a key of any type, converting it in place. */
    public function testSavingOverAKeyOfAnotherTypeConvertsIt(): void
    {
        $key = Key::cursor($this->name, 'invalidator');
        $this->redis()->xAdd($key, '1-0', ['p' => '1']);

        $this->cursor->save($this->name, 'invalidator', '2-0');

        $this->assertSame(\Redis::REDIS_STRING, $this->redis()->type($key));
        $this->assertSame('2-0', $this->cursor->load($this->name, 'invalidator'));
    }
}
