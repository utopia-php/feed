<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * A client that answers from a script, for driving a remote feed
 * through responses a real producer would be awkward to provoke.
 */
class FakeTransport extends FakeClient
{
    /**
     * @param list<ResponseInterface|\Throwable> $responses Answered in order;
     *        the last one repeats once the script runs out.
     */
    public static function of(array $responses): self
    {
        $transport = new self();
        $transport->recorder->responses = $responses;

        return $transport;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function json(array $body, int $statusCode = 200): ResponseInterface
    {
        return self::raw((string) \json_encode($body), $statusCode);
    }

    public static function raw(string $body, int $statusCode = 200): ResponseInterface
    {
        return (new Response($statusCode, body: new Stream\Factory()->createStream($body)))
            ->withHeader(Header::CONTENT_TYPE, ContentType::JSON);
    }

    /**
     * A transport failure, which PSR-18 requires be thrown rather than returned.
     */
    public static function offline(string $message = 'Connection refused'): \Throwable
    {
        return new class ($message) extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new \LogicException('Not needed for this test');
            }
        };
    }

    protected function respond(RequestInterface $request): ResponseInterface
    {
        $responses = &$this->recorder->responses;

        $response = \count($responses) > 1 ? \array_shift($responses) : ($responses[0] ?? null);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response ?? self::json([]);
    }
}
