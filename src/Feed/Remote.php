<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Remote implements Readable
{
    private readonly RequestFactory $requests;

    public function __construct(
        protected readonly Adapter $client,
        protected readonly string $name,
    ) {
        if ($name === '') {
            throw new Invalid('Feed name is required');
        }

        $this->requests = new RequestFactory();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function tip(): ?string
    {
        throw new Unsupported("The {$this->name} feed is remote; its producer resolves the tip");
    }

    public function read(?string $lastEventId = null, int $limit = Protocol::MAX_BATCH): array
    {
        return $this->fetch($lastEventId, $limit, 0);
    }

    public function poll(?string $lastEventId = null, int $limit = Protocol::MAX_BATCH, int $timeout = 0): array
    {
        return $this->fetch($lastEventId, $limit, $timeout);
    }

    /**
     * @return list<CloudEvent>
     */
    private function fetch(?string $lastEventId, int $limit, int $timeout): array
    {
        $request = $this->requests->query(
            Method::GET,
            \rawurlencode($this->name),
            Protocol::query($lastEventId, $limit, $timeout),
            [Header::ACCEPT => Protocol::MEDIA_TYPE],
        );

        $client = $timeout > 0
            ? $this->client->withTimeout(($timeout + Protocol::TIMEOUT_MARGIN) / 1000)
            : $this->client;

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new Transport("Failed to read the {$this->name} feed: {$error->getMessage()}", previous: $error);
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw new Transport(
                "Reading the {$this->name} feed failed with status {$status}",
                $status,
            );
        }

        try {
            $body = \json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Transport("The {$this->name} feed returned a body that is not JSON: {$error->getMessage()}", previous: $error);
        }

        return Protocol::decode($body);
    }
}
