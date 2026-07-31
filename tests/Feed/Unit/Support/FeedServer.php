<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Feed\Batch;
use Utopia\Feed\Server;
use Utopia\Psr7\Header;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * A producer's feed endpoint, as a client.
 *
 * Serves a real {@see Server} through {@see Server::serve()} exactly as an
 * HTTP route would, so a consumer reading it exercises the whole contract —
 * parameters, body and caching — rather than a fixture written to match the
 * consumer.
 */
class FeedServer extends FakeClient
{
    public function __construct(private readonly Server $server, Recorder $recorder = new Recorder())
    {
        parent::__construct($recorder);
    }

    protected function respond(RequestInterface $request): ResponseInterface
    {
        $query = [];
        \parse_str($request->getUri()->getQuery(), $query);

        $batch = $this->server->serve($query);

        $body = (string) \json_encode($batch->toArray());

        return (new Response(200, body: new Stream\Factory()->createStream($body)))
            ->withHeader(Header::CONTENT_TYPE, Batch::MEDIA_TYPE)
            ->withHeader(Header::CACHE_CONTROL, $batch->cacheControl());
    }
}
