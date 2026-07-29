<?php

declare(strict_types=1);

namespace Utopia\Feed\Adapter;

use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter as ClientAdapter;
use Utopia\Feed\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Someone else's feed, read over HTTP.
 *
 * The counterpart to serving a feed with {@see Protocol}: a service points
 * this at another service's feed endpoint and consumes it with the same
 * {@see Feed} and {@see \Utopia\Feed\Consumer} it would use on a local one.
 * Nothing above the adapter knows the events are arriving over the network.
 *
 * Read-only, because a feed is owned by whoever appends to it. Long polling is
 * delegated to the producer, which is the point of doing it this way: the
 * consumer holds one request open instead of asking repeatedly, and the
 * producer answers the moment an event exists.
 *
 * ```php
 * use Utopia\Client;
 * use Utopia\Client\Adapter\Curl\Client as Curl;
 *
 * $client = (new Client(new Curl()))
 *     ->withHeaders(['x-appwrite-jwt' => $token])
 *     ->withConnectionReuse();
 *
 * $feed = new Feed(new Http($client, 'https://cloud.example.com/v1/feeds', 'edge'));
 * ```
 *
 * @see https://github.com/utopia-php/client
 */
class Http extends Adapter
{
    private readonly RequestFactory $requests;

    /**
     * @param ClientAdapter $client Configured with whatever credentials the
     *        producer requires. Typed as the client's own adapter interface
     *        rather than plain PSR-18, because a read needs to set its own
     *        deadline — which also means a `Retry` or `Pool` decorator can be
     *        passed here, since those implement it too.
     *
     *        Retries are best left off. A failed read leaves the cursor where
     *        it was, so the next poll is already the retry; retrying inside a
     *        long poll only multiplies how long a single tick can take.
     * @param string $endpoint Base URL the producer serves its feeds under.
     *        The feed name is appended to it, so
     *        `https://cloud.example.com/v1/feeds` reads
     *        `https://cloud.example.com/v1/feeds/edge`.
     * @param string $name Feed name, as the producer knows it.
     */
    public function __construct(
        protected readonly ClientAdapter $client,
        protected readonly string $endpoint,
        string $name,
        ?RequestFactory $requests = null,
    ) {
        parent::__construct($name);

        $this->requests = $requests ?? new RequestFactory();
    }

    /**
     * The URL this adapter reads.
     */
    public function getUrl(): string
    {
        return \rtrim($this->endpoint, '/') . '/' . \rawurlencode($this->name);
    }

    /**
     * @throws Unsupported Always. A consumer cannot append to a feed it does
     *         not own; call the producer's own API instead.
     */
    public function append(CloudEvent $event): string
    {
        throw new Unsupported("The {$this->name} feed is read over HTTP and cannot be appended to");
    }

    public function read(?string $lastEventId, int $limit, int $timeout = 0): array
    {
        $url = $this->getUrl();

        $request = $this->requests->query(
            Method::GET,
            $url,
            Protocol::query($lastEventId, $limit, $timeout),
            [Header::ACCEPT => ContentType::JSON],
        );

        try {
            $response = $this->client($timeout)->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            // PSR-18 reserves exceptions for failures that produced no usable
            // response, so anything landing here is a transport problem rather
            // than something the producer said.
            throw new Transport("Failed to read the {$this->name} feed at {$url}: {$error->getMessage()}", previous: $error);
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            // Carried as the exception code so a caller can act on it — most
            // usefully to treat a 404 as "this producer does not serve the
            // feed yet", which is the normal state while a feed is being
            // rolled out across services and not something to alert on.
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

    /**
     * The producer does the waiting.
     */
    public function pollable(): bool
    {
        return true;
    }

    /**
     * The client to read with, given how long the producer has been asked to
     * hold the request.
     *
     * A long poll needs a deadline past the one it asked for. Without the
     * margin the client's deadline races the producer's, and a poll that
     * correctly waits out its full timeout gets cancelled a hair early and
     * surfaces as a transport failure on every quiet tick — burying the
     * failures that matter. A plain read keeps whatever the caller configured.
     */
    private function client(int $timeout): ClientAdapter
    {
        if ($timeout <= 0) {
            return $this->client;
        }

        return $this->client->withTimeout(($timeout + Protocol::TIMEOUT_MARGIN) / 1000);
    }
}
