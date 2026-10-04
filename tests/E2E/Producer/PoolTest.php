<?php

declare(strict_types=1);

namespace Utopia\Feed\Tests\E2E\Producer;

use Utopia\Feed\Tests\E2E\Support\UsesPool;
use Utopia\Feed\Tests\Producer\Base;

class PoolTest extends Base
{
    use UsesPool;

    /** The same Redis underneath, so the same approximate trim. */
    protected function trimsExactly(): bool
    {
        return false;
    }
}
