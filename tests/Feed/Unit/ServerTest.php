<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Server;
use Utopia\Feed\Store\None;

/**
 * The server behaviour no working adapter can show: serving a feed whose
 * backend was never configured. Everything a real adapter can exercise lives
 * in {@see \Utopia\Tests\Server\Base} instead.
 */
class ServerTest extends TestCase
{
    public function testAFeedWithNoBackendCannotBeRead(): void
    {
        $server = new Server(new None('edge'));

        $this->expectException(Unsupported::class);

        $server->read();
    }
}
