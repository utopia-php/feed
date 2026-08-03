<?php

declare(strict_types=1);

namespace Utopia\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * What a {@see FakeClient} was asked and what it answered.
 *
 * Held as an object so it survives the `with*()` clones the client interface
 * requires: a clone shares this, but not the timeout that produced it — which
 * is what lets a test tell a request sent through a reconfigured clone from one
 * sent through the original.
 */
class Recorder
{
    /** @var list<array{uri: string, method: string, headers: array<string, string>, timeout: float|null, status: int, cacheControl: string}> */
    public array $requests = [];

    /**
     * Answers, in order. The last one repeats once the script runs out, so a
     * test only scripts the responses it cares about.
     *
     * @var list<ResponseInterface|\Throwable>
     */
    public array $responses = [];

    /**
     * @return array{uri: string, method: string, headers: array<string, string>, timeout: float|null, status: int, cacheControl: string}
     */
    public function last(): array
    {
        $request = \end($this->requests);

        if ($request === false) {
            throw new \RuntimeException('No request was made');
        }

        return $request;
    }

    /**
     * @return list<string>
     */
    public function uris(): array
    {
        return \array_map(static fn (array $request): string => $request['uri'], $this->requests);
    }

    /**
     * @return list<string>
     */
    public function cacheControl(): array
    {
        return \array_map(static fn (array $request): string => $request['cacheControl'], $this->requests);
    }
}
