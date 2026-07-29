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
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Someone else's feed, read over HTTP.
 *
 * The counterpart to serving a feed with {@see Protocol}: point this at another
 * service's feed endpoint and consume it with the same `Feed` and `Consumer` a
 * local one uses. Read-only — a feed is owned by whoever appends to it.
 *
 * ```php
 * $client = (new Client(new Curl()))->withHeaders(['x-appwrite-jwt' => $token]);
 *
 * $feed = new Feed(new Http($client, 'https://cloud.example.com/v1/feeds', 'edge'));
 * ```
 *
 * @see https://github.com/utopia-php/client
 */
class Http extends Journal
{
    private readonly RequestFactory $requests;

    /**
     * @param Adapter $client Configured with whatever credentials the producer
     *        requires. Typed as the client's own adapter interface rather than
     *        plain PSR-18, because a poll needs to set its own deadline.
     *
     *        Retries are best left off: a failed read leaves the cursor where it
     *        was, so the next poll is already the retry.
     * @param string $endpoint Base URL the producer serves its feeds under. The
     *        feed name is appended, so `https://cloud.example.com/v1/feeds`
     *        reads `https://cloud.example.com/v1/feeds/edge`.
     * @param string $name Feed name, as the producer knows it.
     */
    public function __construct(
        protected readonly Adapter $client,
        protected readonly string $endpoint,
        string $name,
    ) {
        parent::__construct($name);

        $this->requests = new RequestFactory();
    }

    /**
     * @throws Unsupported Always. A consumer cannot append to a feed it does
     *         not own; call the producer's own API instead.
     */
    public function append(CloudEvent $event): string
    {
        throw new Unsupported("The {$this->name} feed is read over HTTP and cannot be appended to");
    }

    public function read(?string $lastEventId, int $limit): array
    {
        return $this->fetch($lastEventId, $limit, 0);
    }

    /**
     * The producer does the waiting, so a poll is one held request rather than
     * a client-side loop.
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array
    {
        return $this->fetch($lastEventId, $limit, $timeout);
    }

    /**
     * @return list<CloudEvent>
     * @throws Transport When the producer cannot be reached, or answers with an
     *         error status or a body that is not JSON.
     */
    private function fetch(?string $lastEventId, int $limit, int $timeout): array
    {
        $url = $this->url();

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
            // usefully to treat a 404 as "this producer does not serve the feed
            // yet", which is normal while a feed is being rolled out.
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

    /**
     * A long poll needs a deadline past the one it asked for. Without the margin
     * the client's deadline races the producer's, and a poll that correctly
     * waits out its full timeout surfaces as a transport failure on every quiet
     * tick. A plain read keeps whatever the caller configured.
     */
    private function client(int $timeout): Adapter
    {
        if ($timeout <= 0) {
            return $this->client;
        }

        return $this->client->withTimeout(($timeout + Protocol::TIMEOUT_MARGIN) / 1000);
    }
}
