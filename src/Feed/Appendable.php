<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

// Server interface: a journal that owns its events, so it can be appended to.
// Journal\Http does not implement it — a consumer cannot write to someone else's feed.
interface Appendable
{
    /**
     * Append an event and return the id the backend assigned it.
     *
     * Any id already on $event is ignored: positions are the backend's to
     * allocate, since only it can keep them ordered.
     *
     * @throws Exception When the event cannot be appended.
     */
    public function append(CloudEvent $event): string;
}
