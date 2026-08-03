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
     * Save $eventId only if the stored position is still $expected — what the
     * caller's run started from, or null for none. A refusal means another
     * instance moved the position, and that newer decision stands.
     *
     * Equality is all it takes, so it works on ids with no order (a remote
     * feed's UUIDs). The check is read-compare-write, not atomic: it narrows
     * the window for a lost update from a whole run to one round trip, and
     * what slips through costs a bounded replay, which at-least-once delivery
     * absorbs anyway.
     *
     * @throws Exception When the store cannot be read or written.
     */
    public function advance(string $feed, string $consumer, string $eventId, ?string $expected): bool
    {
        if ($this->load($feed, $consumer) !== $expected) {
            return false;
        }

        $this->save($feed, $consumer, $eventId);

        return true;
    }

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
