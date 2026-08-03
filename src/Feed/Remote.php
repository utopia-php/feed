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
    // MEDIA_TYPE, sent as `Accept` with every read, is inherited from Readable.

    // The request parameters of https://www.http-feeds.org/.
    private const string PARAM_LAST_EVENT_ID = 'lastEventId';
    private const string PARAM_LIMIT = 'limit';
    private const string PARAM_TIMEOUT = 'timeout';

    /**
     * How much longer than the long poll the HTTP client is allowed to wait,
     * in milliseconds. Without the margin the client's deadline races the
     * producer's, and a poll that correctly waits out its timeout surfaces
     * as a failure on every quiet tick.
     */
    private const int TIMEOUT_MARGIN = 10_000;

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

    public function read(?string $lastEventId = null, int $limit = self::MAX_BATCH): array
    {
        return $this->fetch($lastEventId, $limit, 0);
    }

    public function poll(?string $lastEventId = null, int $limit = self::MAX_BATCH, int $timeout = 0): array
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
            self::query($lastEventId, $limit, $timeout),
            [Header::ACCEPT => self::MEDIA_TYPE],
        );

        $client = $timeout > 0
            ? $this->client->withTimeout(($timeout + self::TIMEOUT_MARGIN) / 1000)
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

        return self::decode($body);
    }

    /**
     * @return array<string, string|int>
     */
    private static function query(?string $lastEventId, int $limit, int $timeout): array
    {
        $query = [];

        if ($lastEventId !== null && $lastEventId !== '') {
            $query[self::PARAM_LAST_EVENT_ID] = $lastEventId;
        }

        if ($limit > 0) {
            $query[self::PARAM_LIMIT] = $limit;
        }

        if ($timeout > 0) {
            $query[self::PARAM_TIMEOUT] = $timeout;
        }

        return $query;
    }

    /**
     * @return list<CloudEvent>
     * @throws Invalid When the payload is not a batch, or its first entry cannot be read.
     */
    private static function decode(mixed $payload): array
    {
        if (!\is_array($payload)) {
            throw new Invalid('Expected a feed batch, got ' . \get_debug_type($payload));
        }

        if (!\array_is_list($payload)) {
            throw new Invalid('Expected a feed batch as a plain array of events');
        }

        $events = [];

        /** @var mixed $event */
        foreach ($payload as $event) {
            try {
                if (!\is_array($event)) {
                    throw new Invalid('Feed batch contains an entry that is not an event');
                }

                $events[] = self::event($event);
            } catch (Invalid | \InvalidArgumentException $error) {
                if ($events === []) {
                    throw $error instanceof Invalid
                        ? $error
                        : new Invalid('Feed batch contains an event that cannot be read: ' . $error->getMessage(), previous: $error);
                }

                break;
            }
        }

        return $events;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function event(array $raw): CloudEvent
    {
        foreach (['specversion', 'id', 'type', 'source'] as $required) {
            if (!isset($raw[$required]) || !\is_string($raw[$required]) || $raw[$required] === '') {
                throw new Invalid('Feed event is missing ' . ($required === 'id' ? 'an id' : 'a ' . $required));
            }
        }

        $extensions = Extensions::filter($raw);

        return new CloudEvent(
            type: $raw['type'],
            source: $raw['source'],
            id: $raw['id'],
            specversion: $raw['specversion'],
            subject: self::optional($raw, 'subject'),
            time: self::optional($raw, 'time'),
            datacontenttype: self::optional($raw, 'datacontenttype'),
            data: $raw['data'] ?? null,
            dataschema: self::optional($raw, 'dataschema'),
            // The docblock wants array<string, mixed>, but a digit-only
            // extension name — legal per the spec — is an integer key in PHP.
            // @phpstan-ignore argument.type
            extensions: $extensions,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function optional(array $raw, string $attribute): ?string
    {
        $value = $raw[$attribute] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
