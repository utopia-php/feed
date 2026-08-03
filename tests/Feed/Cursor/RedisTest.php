<?php

declare(strict_types=1);

namespace Utopia\Tests\Cursor;

use Utopia\Feed\Key;
use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

    /** An earlier version kept cursors as one-entry streams, with the position as the entry's id. */
    public function testAPositionRetainedAsAStreamStillLoads(): void
    {
        $this->redis()->xAdd(Key::cursor($this->name, 'invalidator'), '1690000000000-7', ['p' => '1']);

        $this->assertSame('1690000000000-7', $this->cursor->load($this->name, 'invalidator'));
    }

    /** The first save after the upgrade converts the key to a string in place. */
    public function testSavingOverAStreamPositionConvertsTheKey(): void
    {
        $key = Key::cursor($this->name, 'invalidator');
        $this->redis()->xAdd($key, '1-0', ['p' => '1']);

        $this->cursor->save($this->name, 'invalidator', '2-0');

        $this->assertSame(\Redis::REDIS_STRING, $this->redis()->type($key));
        $this->assertSame('2-0', $this->cursor->load($this->name, 'invalidator'));
    }

    public function testResetForgetsAPositionRetainedAsAStream(): void
    {
        $this->redis()->xAdd(Key::cursor($this->name, 'invalidator'), '1-0', ['p' => '1']);

        $this->cursor->reset($this->name, 'invalidator');

        $this->assertNull($this->cursor->load($this->name, 'invalidator'));
    }
}
