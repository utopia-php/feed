<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Cache\Adapter;

/**
 * A cache backend that is down, for testing that the cache-backed store and
 * cursor notice.
 *
 * It fails the two ways {@see \Utopia\Cache\Adapter\Redis} actually fails
 * rather than the way a test double would find convenient:
 *
 * - `save()` catches everything internally and answers `false`, so a caller
 *   that only looks at the absence of an exception sees a successful write.
 * - `load()` and `purge()` let the backend's own error out once the adapter's
 *   internal retries are exhausted — a raw `\RedisException` in production,
 *   stood in for here by a plain exception so the service-free suites stay
 *   service-free. What matters is that it is not a `Utopia\Feed\Exception`.
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
