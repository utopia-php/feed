<?php

declare(strict_types=1);

namespace Utopia\Feed;

// Client vocabulary: where a consumer with no stored position begins.
// A stored cursor always wins — this only applies on a first run, or after reset().
enum Start
{
    /** The oldest retained event — drain the backlog. The default. */
    case Oldest;

    /**
     * Only what happens from now on — for a consumer that must not act on
     * the backlog, like a notifier that would otherwise announce history.
     *
     * "Now" is anchored by the producer, per poll, until the first event is
     * handled and the cursor saves. Run a Tip consumer with a poll timeout,
     * so events land inside the held request rather than in the unanchored
     * gap between polls.
     */
    case Tip;
}
