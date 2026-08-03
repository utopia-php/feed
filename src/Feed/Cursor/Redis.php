<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Transport;

class Redis extends Cursor
{
    // advance() is inherited: its load() already reads a legacy stream key,
    // and its save() converts one. Redis' own compare-and-set (WATCH/MULTI)
    // is not used on purpose — this client is shared with the producer's
    // store, and WATCH state does not survive a shared connection.

    public function __construct(protected readonly \Redis|\RedisCluster $redis)
    {
    }

    public function load(string $feed, string $consumer): ?string
    {
        $key = $this->key($feed, $consumer);

        try {
            $this->redis->clearLastError();

            /** @var mixed $cursor */
            $cursor = $this->redis->get($key);
        } catch (\RedisException $error) {
            if (self::wrongType($error->getMessage())) {
                return $this->loadStream($key, $consumer);
            }

            throw new Transport("Failed to load the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }

        // phpredis reports a refusal either by throwing or by returning
        // `false` with the text in getLastError(); check both paths.
        if ($cursor === false && self::wrongType($this->lastError())) {
            return $this->loadStream($key, $consumer);
        }

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function save(string $feed, string $consumer, string $eventId): void
    {
        try {
            $this->redis->set($this->key($feed, $consumer), $eventId);
        } catch (\RedisException $error) {
            throw new Transport("Failed to save the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }

    public function reset(string $feed, string $consumer): void
    {
        try {
            $this->redis->del($this->key($feed, $consumer));
        } catch (\RedisException $error) {
            throw new Transport("Failed to reset the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }
    }

    /**
     * A position kept as a one-entry stream by an earlier version, with the
     * position as the entry's id. The next {@see save()} converts the key.
     *
     * @throws Transport When the key holds neither a string nor a stream.
     */
    private function loadStream(string $key, string $consumer): ?string
    {
        try {
            $this->redis->clearLastError();

            $entries = $this->redis->xRevRange($key, '+', '-', 1);
        } catch (\RedisException $error) {
            throw new Transport("Failed to load the {$consumer} cursor: {$error->getMessage()}", previous: $error);
        }

        if ($entries === false) {
            throw new Transport("Failed to load the {$consumer} cursor: " . ($this->lastError() ?: 'Redis command failed'));
        }

        if (!\is_array($entries) || $entries === []) {
            return null;
        }

        // The position is the entry's id, not its payload.
        $id = \array_key_first($entries);

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /** The last command's error text, cleared on the way out. */
    private function lastError(): string
    {
        $error = $this->redis->getLastError();
        $this->redis->clearLastError();

        return \is_string($error) ? $error : '';
    }

    /** Whether Redis refused because the key holds another type. */
    private static function wrongType(string $error): bool
    {
        return \str_contains($error, 'WRONGTYPE');
    }
}
