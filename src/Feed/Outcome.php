<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * What a handler decided about the event — or, under
 * {@see Consumer::consumeChunk()}, the chunk — it was given.
 *
 * Returning nothing decides too: a handler that returns normally without an
 * Outcome has continued, so every handler written before this enum existed
 * keeps its meaning. Throwing is the third word in the vocabulary — the
 * position stays, like Retry, and the error reaches the caller.
 *
 * Deliberately an enum rather than a boolean: PHP APIs return false all the
 * time, so a handler whose last statement happens to return one must not
 * acquire retry semantics by accident — a persistent false would stall the
 * feed silently. Retry can only be said on purpose.
 */
enum Outcome
{
    /** Processed — advance the position past it. */
    case Continue;

    /**
     * Something is wrong beyond this event — stop the run here. The position
     * stays before it, so the next run re-delivers it: the deliberate form of
     * what throwing does, for a failure that is expected rather than raised.
     */
    case Retry;
}
