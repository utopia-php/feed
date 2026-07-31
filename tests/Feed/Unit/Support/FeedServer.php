<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Feed\Feed;
use Utopia\Feed\Protocol;
use Utopia\Psr7\Header;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * A producer's feed endpoint, as a client.
 *
 * Serves a real {@see Feed} through {@see Protocol} exactly as an HTTP route
 * would, so a consumer reading it exercises the whole contract — parameters,
 * body and caching — rather than a fixture written to match the consumer.
 */
class FeedServer extends FakeClient
{
    public function __construct(private readonly Feed $feed, Recorder $recorder = new Recorder())
    {
        parent::__construct($recorder);
    }

    protected function respond(RequestInterface $request): ResponseInterface
    {
        $query = [];
        \parse_str($request->getUri()->getQuery(), $query);

        $lastEventId = $query[Protocol::PARAM_LAST_EVENT_ID] ?? null;
        $limit = \min((int) ($query[Protocol::PARAM_LIMIT] ?? Feed::MAX_BATCH), Feed::MAX_BATCH);
        $timeout = (int) ($query[Protocol::PARAM_TIMEOUT] ?? 0);

        $events = $this->feed->poll(
            \is_string($lastEventId) && $lastEventId !== '' ? $lastEventId : null,
            $limit,
            $timeout,
        );

        $body = (string) \json_encode(Protocol::encode($events));

        return (new Response(200, body: new Stream\Factory()->createStream($body)))
            ->withHeader(Header::CONTENT_TYPE, Protocol::MEDIA_TYPE)
            ->withHeader(Header::CACHE_CONTROL, Protocol::cacheControl(\count($events), $limit));
    }
}
