<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter as ClientAdapter;
use Utopia\Client\Tls;
use Utopia\Psr7\Header;

/**
 * A {@see ClientAdapter} that answers without a network, and records what it
 * was asked.
 *
 * The `with*()` methods clone the way the real client does, rather than
 * mutating and returning `$this`. That matters: a test asserting that a long
 * poll was given a longer deadline has to be able to fail if the adapter
 * configured a clone and then sent through the original.
 */
abstract class FakeClient implements ClientAdapter
{
    protected ?float $timeout = null;

    public function __construct(public readonly Recorder $recorder = new Recorder())
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->respond($request);

        /** @var array<string, string> $headers */
        $headers = \array_map(
            static fn (array $values): string => \implode(', ', $values),
            $request->getHeaders(),
        );

        $this->recorder->requests[] = [
            'uri' => (string) $request->getUri(),
            'method' => $request->getMethod(),
            'headers' => $headers,
            'timeout' => $this->timeout,
            'status' => $response->getStatusCode(),
            'cacheControl' => $response->getHeaderLine(Header::CACHE_CONTROL),
        ];

        return $response;
    }

    /**
     * @throws \Throwable To simulate a transport failure.
     */
    abstract protected function respond(RequestInterface $request): ResponseInterface;

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        return clone $this;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return clone $this;
    }

    public function withCustomCA(string $path): static
    {
        return clone $this;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return clone $this;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return clone $this;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return clone $this;
    }

    /**
     * Feeds are read buffered — a batch is bounded by `limit`, so there is
     * nothing to stream.
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        throw new \LogicException('A feed is never read as a stream');
    }
}
