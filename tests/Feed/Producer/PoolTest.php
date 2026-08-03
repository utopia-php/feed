<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use Utopia\Tests\Support\UsesPool;

class PoolTest extends Base
{
    use UsesPool;

    /** The same Redis underneath, so the same approximate trim. */
    protected function trimsExactly(): bool
    {
        return false;
    }
}
