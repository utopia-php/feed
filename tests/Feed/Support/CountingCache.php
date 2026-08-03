<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Cache\Adapter\Memory;

/** A working cache that records how often each key was read. */
class CountingCache extends Memory
{
    /** @var array<string, int> Reads per key. */
    public array $loads = [];

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads[$key] = ($this->loads[$key] ?? 0) + 1;

        return parent::load($key, $ttl, $hash);
    }

    public function forget(): void
    {
        $this->loads = [];
    }

    public function reads(string $key): int
    {
        return $this->loads[$key] ?? 0;
    }
}
