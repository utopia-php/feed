<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

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
 *
 * It routes on the request path too: a fixture that ignored it would answer
 * any feed name with the one feed it holds, so a consumer pointed at the
 * wrong feed would read the right events and no test could tell.
 */
class FeedServer extends FakeClient
{
    public function __construct(private readonly Server $server, Recorder $recorder = new Recorder())
    {
        parent::__construct($recorder);
    }

    protected function respond(RequestInterface $request): ResponseInterface
    {
        if (self::feed($request) !== $this->server->getName()) {
            return new Response(404, body: new Stream\Factory()->createStream('{"message":"No such feed"}'));
        }

        $query = [];
        \parse_str($request->getUri()->getQuery(), $query);

        $batch = $this->server->serve($query);

        $body = (string) \json_encode($batch->toArray());

        return (new Response(200, body: new Stream\Factory()->createStream($body)))
            ->withHeader(Header::CONTENT_TYPE, Batch::MEDIA_TYPE)
            ->withHeader(Header::CACHE_CONTROL, $batch->cacheControl());
    }

    /**
     * The feed name the request asks for: the last path segment, decoded.
     *
     * Split before decoding, never after — a feed called `a/b` arrives as
     * `a%2Fb`. The path may also hold no slash at all, since a client with no
     * base URI sends the bare name.
     */
    private static function feed(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $slash = \strrpos($path, '/');

        return \rawurldecode($slash === false ? $path : \substr($path, $slash + 1));
    }
}
