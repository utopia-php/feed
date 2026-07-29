<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Utopia\Fetch\Adapter;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

/**
 * A fetch adapter that answers from a script instead of a network, and records
 * what it was asked, so the HTTP feed adapter can be tested without a server.
 */
class FakeTransport implements Adapter
{
    /** @var list<array{url: string, method: string, headers: array<string, string>, timeout: int}> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $responses;

    /**
     * @param list<Response|\Throwable> $responses Answered in order; the last
     *        one repeats once the script runs out.
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function ok(array $body, int $statusCode = 200): Response
    {
        return new Response($statusCode, (string) \json_encode($body), []);
    }

    public static function status(int $statusCode, string $body = '{}'): Response
    {
        return new Response($statusCode, $body, []);
    }

    public static function raw(string $body): Response
    {
        return new Response(200, $body, []);
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $this->requests[] = [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'timeout' => $options->getTimeout(),
        ];

        $response = \count($this->responses) > 1 ? \array_shift($this->responses) : ($this->responses[0] ?? null);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response ?? self::ok(['total' => 0, 'events' => []]);
    }

    /**
     * @return array{url: string, method: string, headers: array<string, string>, timeout: int}
     */
    public function lastRequest(): array
    {
        $request = \end($this->requests);

        if ($request === false) {
            throw new \RuntimeException('No request was made');
        }

        return $request;
    }
}
