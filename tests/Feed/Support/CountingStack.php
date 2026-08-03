<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Pools\Adapter\Stack;

/**
 * A pool adapter that counts completed borrows. Releases rather than
 * acquisitions: the pool creates its first connection instead of popping one.
 */
class CountingStack extends Stack
{
    public int $releases = 0;

    public function push(mixed $connection): static
    {
        $this->releases++;

        return parent::push($connection);
    }
}
