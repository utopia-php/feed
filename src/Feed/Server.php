<?php

declare(strict_types=1);

namespace Utopia\Feed;

class Server
{
    public function __construct(protected readonly Readable $store)
    {
    }

    public function getName(): string
    {
        return $this->store->getName();
    }

    /**
     * @throws Exception
     */
    public function tip(): ?string
    {
        return $this->store->tip();
    }

    public function read(?string $lastEventId = null, int $limit = Protocol::MAX_BATCH): Batch
    {
        $limit = \max(1, \min($limit, Protocol::MAX_BATCH));

        return new Batch($this->store->read($lastEventId, $limit), $limit);
    }

    public function poll(?string $lastEventId = null, int $limit = Protocol::MAX_BATCH, int $timeout = 0): Batch
    {
        $limit = \max(1, \min($limit, Protocol::MAX_BATCH));

        return new Batch(
            $this->store->poll($lastEventId, $limit, \max(0, \min($timeout, Protocol::MAX_TIMEOUT))),
            $limit,
        );
    }

    /**
     * @param array<array-key, mixed> $query The request's query parameters, string values included.
     * @throws Exception\Invalid When `lastEventId` is present but is neither a feed position nor the tip sentinel
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
            \is_numeric($limit) ? (int) $limit : Protocol::MAX_BATCH,
            \is_numeric($timeout) ? (int) $timeout : 0,
        );
    }
}
