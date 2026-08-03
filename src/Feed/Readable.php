<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;

interface Readable
{
    /**
     * Extension beyond the spec, like `limit`: a `lastEventId` of `$` means
     * "the tip of the feed". The producer resolves it to the newest event at
     * the moment the request arrives, so a consumer can ask for only what
     * happens from now on without a separate round trip to learn the tip.
     */
    public const string TIP = '$';

    /** The batch media type on the wire — one side's `Content-Type`, the other's `Accept`. */
    public const string MEDIA_TYPE = 'application/cloudevents-batch+json';

    /** The most events one batch may carry — producers clamp `limit` to this. */
    public const int MAX_BATCH = 1000;

    /** The longest a long poll may hold a connection, in milliseconds. */
    public const int MAX_TIMEOUT = 30_000;

    public function getName(): string;

    /**
     * @return list<CloudEvent>
     * @throws Exception When the events cannot be read.
     */
    public function read(?string $lastEventId, int $limit): array;

    /**
     * @return list<CloudEvent>
     * @throws Exception When the events cannot be read.
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array;

    /**
     * @throws Exception When the tip cannot be read, or only its owner can resolve it.
     */
    public function tip(): ?string;
}
