<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

/**
 * A producer's feed endpoint, as a fetch adapter.
 *
 * Serves a real {@see Feed} through {@see Protocol} exactly as an HTTP route
 * would, so a consumer reading it exercises the whole contract — parameters,
 * body and caching — rather than a fixture written to match the consumer.
 */
class FeedServer implements Adapter
{
    /** @var list<string> */
    public array $cacheControl = [];

    public function __construct(private readonly Feed $feed)
    {
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $query = [];
        \parse_str((string) \parse_url($url, PHP_URL_QUERY), $query);

        $lastEventId = $query[Protocol::PARAM_LAST_EVENT_ID] ?? null;
        $limit = (int) ($query[Protocol::PARAM_LIMIT] ?? Feed::MAX_BATCH);
        $timeout = (int) ($query[Protocol::PARAM_TIMEOUT] ?? 0);

        $events = $this->feed->poll(
            \is_string($lastEventId) && $lastEventId !== '' ? $lastEventId : null,
            $limit,
            $timeout,
        );

        $this->cacheControl[] = $cacheControl = Protocol::cacheControl(\count($events), $limit);

        return new Response(
            200,
            (string) \json_encode(Protocol::encode($events)),
            ['cache-control' => $cacheControl],
        );
    }
}
