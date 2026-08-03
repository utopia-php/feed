<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Feed\Exception\Invalid;

abstract class Cursor
{
    /**
     * @throws Exception When the store cannot be read.
     */
    abstract public function load(string $feed, string $consumer): ?string;

    /**
     * @throws Exception When the store cannot be written.
     */
    abstract public function save(string $feed, string $consumer, string $eventId): void;

    /**
     * @throws Exception When the store cannot be written.
     */
    abstract public function reset(string $feed, string $consumer): void;

    /**
     * The gate every cursor operation goes through, so no adapter builds a key
     * of its own and skips the check.
     *
     * @throws Invalid When either name is empty.
     */
    protected function key(string $feed, string $consumer): string
    {
        if ($feed === '' || $consumer === '') {
            throw new Invalid('Cursor requires a feed and a consumer name');
        }

        return Key::cursor($feed, $consumer);
    }
}
