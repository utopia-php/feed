<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Pools\Adapter\Stack;

/**
 * A pool adapter that records how often a connection was handed back.
 *
 * Counting releases rather than acquisitions is deliberate: the pool creates
 * its first connection instead of popping one, so acquisitions undercount the
 * first borrow, while every completed `use()` pushes exactly once.
 */
class CountingStack extends Stack
{
    /** Completed borrows — one per `Pool::use()` that ran to the end. */
    public int $releases = 0;

    public function push(mixed $connection): static
    {
        $this->releases++;

        return parent::push($connection);
    }
}
