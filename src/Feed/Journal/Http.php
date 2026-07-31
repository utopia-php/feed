<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Journal;
use Utopia\Feed\Protocol;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Http extends Journal
{
    private readonly RequestFactory $requests;

    public function __construct(
        protected readonly Adapter $client,
        protected readonly string $endpoint,
        string $name,
    ) {
        parent::__construct($name);

        $this->requests = new RequestFactory();
    }

    /**
     * Never called on the consumer path: the tip sentinel is passed through
     * as `lastEventId=$` and the producer resolves it inside the same
     * request, so there is no separate tip round trip to race.
     */
    public function tip(): ?string
    {
        throw new Unsupported("The {$this->name} feed is remote; its producer resolves the tip");
    }

    public function read(?string $lastEventId, int $limit): array
    {
        return $this->fetch($lastEventId, $limit, 0);
    }

    public function poll(?string $lastEventId, int $limit, int $timeout): array
    {
        return $this->fetch($lastEventId, $limit, $timeout);
    }

    /**
     * @return list<CloudEvent>
     */
    private function fetch(?string $lastEventId, int $limit, int $timeout): array
    {
        $url = $this->url();

        // The Content-Type of the response is deliberately not checked: many
        // servers answer application/json, and the body shape is what matters.
        $request = $this->requests->query(
            Method::GET,
            $url,
            Protocol::query($lastEventId, $limit, $timeout),
            [Header::ACCEPT => Protocol::MEDIA_TYPE],
        );

        // A long poll needs a deadline past the one it asked the producer for,
        // or the client cancels a correct wait a hair early and every quiet
        // tick surfaces as a failure. A plain read keeps the caller's own.
        $client = $timeout > 0
            ? $this->client->withTimeout(($timeout + Protocol::TIMEOUT_MARGIN) / 1000)
            : $this->client;

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new Transport("Failed to read the {$this->name} feed at {$url}: {$error->getMessage()}", previous: $error);
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw new Transport(
                "Reading the {$this->name} feed at {$url} failed with status {$status}",
                $status,
            );
        }

        try {
            $body = \json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Transport("The {$this->name} feed at {$url} returned a body that is not JSON: {$error->getMessage()}", previous: $error);
        }

        return Protocol::decode($body);
    }

    private function url(): string
    {
        return \rtrim($this->endpoint, '/') . '/' . \rawurlencode($this->name);
    }
}
