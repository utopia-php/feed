<?php

declare(strict_types=1);

namespace Utopia\Feed\Tests\E2E\Server;

use Utopia\Feed\Tests\E2E\Support\UsesRedis;
use Utopia\Feed\Tests\Server\Base;

class RedisTest extends Base
{
    use UsesRedis;
}
