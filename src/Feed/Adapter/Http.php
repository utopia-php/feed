<?php

declare(strict_types=1);

namespace Utopia\Feed\Adapter;

use Utopia\Feed\Adapter;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;
use Utopia\Fetch\Client;

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
 * $client = (new Client())
 *     ->addHeader('x-appwrite-jwt', $token)
 *     ->setMaxRetries(0); // The consumer's own retry is the next poll
 *
 * $feed = new Feed(new Http($client, 'https://cloud.example.com/v1/feeds', 'edge'));
 * ```
 */
class Http extends Adapter
{
    /**
     * @param Client $client Configured with whatever credentials the producer
     *        requires. Retries are best left off: a failed read leaves the
     *        cursor where it was, so the next poll is already the retry, and
     *        retrying inside a long poll multiplies the time a tick can take.
     * @param string $endpoint Base URL the producer serves its feeds under.
     *        The feed name is appended to it, so
     *        `https://cloud.example.com/v1/feeds` reads
     *        `https://cloud.example.com/v1/feeds/edge`.
     * @param string $name Feed name, as the producer knows it.
     */
    public function __construct(
        protected readonly Client $client,
        protected readonly string $endpoint,
        string $name,
    ) {
        parent::__construct($name);
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
    public function append(Event $event): string
    {
        throw new Unsupported("The {$this->name} feed is read over HTTP and cannot be appended to");
    }

    public function read(?string $lastEventId, int $limit, int $timeout = 0): array
    {
        $url = $this->getUrl();

        try {
            $response = $this->client->fetch(
                url: $url,
                method: Client::METHOD_GET,
                query: Protocol::query($lastEventId, $limit, $timeout),
                // The producer is expected to answer within its own timeout;
                // the margin only stops the client cutting off a poll that is
                // legitimately waiting one out.
                timeoutMs: $timeout > 0 ? $timeout + Protocol::TIMEOUT_MARGIN : null,
            );
        } catch (\Throwable $error) {
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
            $body = $response->json();
        } catch (\Throwable $error) {
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
}
