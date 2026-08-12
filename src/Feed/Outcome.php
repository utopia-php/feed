<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * What a handler decided about the event — or, under
 * {@see Consumer::consumeChunk()}, the chunk — it was given.
 *
 * Returning nothing decides too: a handler that returns normally without an
 * Outcome has continued, so every handler written before this enum existed
 * keeps its meaning. Throwing is the fourth word in the vocabulary — the
 * position stays, like Retry, and the error reaches the caller.
 */
enum Outcome
{
    /** Processed — advance the position past it. */
    case Continue;

    /**
     * Could not be processed, and retrying will not change that — advance
     * anyway. The decision to lose an event is the handler's to make, so it
     * is never implied: only this explicit word steps over a failure.
     */
    case Skip;

    /**
     * Something is wrong beyond this event — stop the run here. The position
     * stays before it, so the next run re-delivers it: the deliberate form of
     * what throwing does, for a failure that is expected rather than raised.
     */
    case Retry;
}
