<?php

declare(strict_types=1);

namespace Utopia\Feed;

// Server and client class: the read view of a feed — read and long-poll.
// Server serves its own feed with this; client reads a remote one through Journal\Http.
class Feed
{
    public const int MAX_BATCH = 1000;

    public const int MAX_TIMEOUT = 30_000;

    public function __construct(protected readonly Journal $journal)
    {
    }

    public function getName(): string
    {
        return $this->journal->getName();
    }

    /**
     * The id of the newest event, or null on an empty feed. Local journals
     * only — a remote feed's producer resolves the tip sentinel instead.
     *
     * @throws Exception
     */
    public function tip(): ?string
    {
        return $this->journal->tip();
    }

    public function read(?string $lastEventId = null, int $limit = self::MAX_BATCH): Batch
    {
        $limit = \max(1, \min($limit, self::MAX_BATCH));

        return new Batch($this->journal->read($lastEventId, $limit), $limit);
    }

    public function poll(?string $lastEventId = null, int $limit = self::MAX_BATCH, int $timeout = 0): Batch
    {
        $limit = \max(1, \min($limit, self::MAX_BATCH));

        return new Batch(
            $this->journal->poll($lastEventId, $limit, \max(0, \min($timeout, self::MAX_TIMEOUT))),
            $limit,
        );
    }

    /**
     * Serve one HTTP feed request: the route's raw query-parameter array in,
     * the batch out. Extracts `lastEventId`, `limit` and `timeout`, coerces
     * their string values, applies the defaults and clamps to the protocol
     * limits, so the route never touches the wire vocabulary itself.
     *
     * @param array<array-key, mixed> $query The request's query parameters, string values included.
     *
     * @throws Exception\Invalid When `lastEventId` is present but is neither a feed position nor the tip sentinel — a 400-worthy input.
     */
    public function serve(array $query): Batch
    {
        $lastEventId = $query[Protocol::PARAM_LAST_EVENT_ID] ?? null;
        $lastEventId = \is_string($lastEventId) && $lastEventId !== '' ? $lastEventId : null;

        if ($lastEventId !== null && $lastEventId !== Protocol::TIP && !Id::isValid($lastEventId)) {
            throw new Exception\Invalid('Invalid lastEventId: ' . $lastEventId);
        }

        $limit = $query[Protocol::PARAM_LIMIT] ?? null;
        $timeout = $query[Protocol::PARAM_TIMEOUT] ?? null;

        return $this->poll(
            $lastEventId,
            \is_numeric($limit) ? (int) $limit : self::MAX_BATCH,
            \is_numeric($timeout) ? (int) $timeout : 0,
        );
    }
}
