<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Feed\Appendable;
use Utopia\Feed\Cursor;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Memory as MemoryStore;

/**
 * The memory adapter pair: a store and a cursor that live and die with the
 * process, for tests and single-process development.
 */
trait UsesMemory
{
    protected function store(string $name, int $maxSize = 100_000, int $pollInterval = 500): Store&Appendable
    {
        return new MemoryStore($name, $maxSize, $pollInterval);
    }

    protected function cursor(): Cursor
    {
        return new MemoryCursor();
    }
}
