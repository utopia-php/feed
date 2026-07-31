<?php

declare(strict_types=1);

namespace Utopia\Feed;

class Server
{
    // The request parameters of https://www.http-feeds.org/, as serve() reads them.
    private const string PARAM_LAST_EVENT_ID = 'lastEventId';
    private const string PARAM_LIMIT = 'limit';
    private const string PARAM_TIMEOUT = 'timeout';

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

    public function read(?string $lastEventId = null, int $limit = Readable::MAX_BATCH): Batch
    {
        $limit = \max(1, \min($limit, Readable::MAX_BATCH));

        return new Batch($this->store->read($lastEventId, $limit), $limit);
    }

    public function poll(?string $lastEventId = null, int $limit = Readable::MAX_BATCH, int $timeout = 0): Batch
    {
        $limit = \max(1, \min($limit, Readable::MAX_BATCH));

        return new Batch(
            $this->store->poll($lastEventId, $limit, \max(0, \min($timeout, Readable::MAX_TIMEOUT))),
            $limit,
        );
    }

    /**
     * @param array<array-key, mixed> $query The request's query parameters, string values included.
     * @throws Exception\Invalid When `lastEventId` is present but is neither a feed position nor the tip sentinel
     */
    public function serve(array $query): Batch
    {
        $lastEventId = $query[self::PARAM_LAST_EVENT_ID] ?? null;
        $lastEventId = \is_string($lastEventId) && $lastEventId !== '' ? $lastEventId : null;

        if ($lastEventId !== null && $lastEventId !== Readable::TIP && !Id::isValid($lastEventId)) {
            throw new Exception\Invalid('Invalid lastEventId: ' . $lastEventId);
        }

        $limit = $query[self::PARAM_LIMIT] ?? null;
        $timeout = $query[self::PARAM_TIMEOUT] ?? null;

        return $this->poll(
            $lastEventId,
            \is_numeric($limit) ? (int) $limit : Readable::MAX_BATCH,
            \is_numeric($timeout) ? (int) $timeout : 0,
        );
    }
}
