<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Cache\Adapter;

/**
 * A cache backend that is down, failing the two ways
 * {@see \Utopia\Cache\Adapter\Redis} does: `save()` answers `false` without
 * raising, and the rest let the backend's own error out. That error stands in
 * for a `\RedisException` so the service-free suites stay service-free — all
 * that matters is that it is not a `Utopia\Feed\Exception`.
 */
class BrokenCache implements Adapter
{
    public function __construct(private readonly bool $raises = false)
    {
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->fail();

        return false;
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        $this->fail();

        return false;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        return false;
    }

    /** @return string[] */
    public function list(string $key): array
    {
        return [];
    }

    public function purge(string $key, string $hash = ''): bool
    {
        $this->fail();

        return false;
    }

    public function flush(): bool
    {
        return false;
    }

    public function ping(): bool
    {
        return false;
    }

    public function getSize(): int
    {
        return 0;
    }

    public function getName(?string $key = null): string
    {
        return 'broken';
    }

    private function fail(): void
    {
        if ($this->raises) {
            throw new \RuntimeException('read error on connection to redis:6379');
        }
    }
}
