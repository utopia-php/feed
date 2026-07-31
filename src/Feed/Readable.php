<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

// The contract of "something a feed's events can be read from" — the
// counterpart of Appendable. Journal implements it on the server; Remote is
// the client's read-only view of another service's feed.
interface Readable
{
    public function getName(): string;

    /**
     * Events strictly after $lastEventId, oldest first, at most $limit of
     * them. A null position reads from the oldest retained event; the tip
     * sentinel (Protocol::TIP) reads from the tip.
     *
     * @return list<CloudEvent>
     *
     * @throws Exception When the events cannot be read.
     */
    public function read(?string $lastEventId, int $limit): array;

    /**
     * Like read(), but waits up to $timeout milliseconds for events to land
     * before answering empty.
     *
     * @return list<CloudEvent>
     *
     * @throws Exception When the events cannot be read.
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array;

    /**
     * The id of the newest event, or null when the feed is empty.
     *
     * @throws Exception When the tip cannot be read, or only its owner can resolve it.
     */
    public function tip(): ?string;
}
