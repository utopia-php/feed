<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Utopia\Cache\Adapter;

/**
 * A cache backend that cannot be written, for testing that the cache-backed
 * store and cursor notice.
 *
 * It fails the way {@see \Utopia\Cache\Adapter\Redis} does rather than the way
 * a test double would find convenient: `save()` catches everything internally
 * and answers `false`, so a caller that only looks at the absence of an
 * exception sees a successful write.
 */
class BrokenCache implements Adapter
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return false;
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
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
}
