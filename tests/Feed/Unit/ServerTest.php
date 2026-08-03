<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Readable;
use Utopia\Feed\Server;
use Utopia\Feed\Store\None;
use Utopia\Tests\Support\RecordingStore;

/**
 * The server behaviours no working adapter can show: a backend that was never
 * configured, and what the server asks its store for — clamping is invisible
 * in the events that come back. Everything else lives in
 * {@see \Utopia\Tests\Server\Base}.
 */
class ServerTest extends TestCase
{
    public function testAFeedWithNoBackendCannotBeRead(): void
    {
        $server = new Server(new None('edge'));

        $this->expectException(Unsupported::class);

        $server->read();
    }

    /**
     * `timeout` arrives from an untrusted client, so this clamp keeps
     * `?timeout=86400000` from holding a worker for as long as it asks.
     *
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('timeouts')]
    public function testServeClampsTheTimeoutItForwards(array $query, int $expected): void
    {
        $store = new RecordingStore('edge');

        (new Server($store))->serve($query);

        $this->assertSame($expected, $store->timeout);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, int}>
     */
    public static function timeouts(): array
    {
        return [
            'forwarded as given' => [['timeout' => '2500'], 2_500],
            'capped at the protocol maximum' => [['timeout' => '120000'], Readable::MAX_TIMEOUT],
            'a negative timeout floors at zero' => [['timeout' => '-5000'], 0],
            'garbage falls back to no wait' => [['timeout' => 'soon'], 0],
            'absent means no wait' => [[], 0],
        ];
    }

    /**
     * `limit` is clamped for the same reason and against the same caller.
     *
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('limits')]
    public function testServeClampsTheLimitItForwards(array $query, int $expected): void
    {
        $store = new RecordingStore('edge');

        (new Server($store))->serve($query);

        $this->assertSame($expected, $store->limit);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, int}>
     */
    public static function limits(): array
    {
        return [
            'forwarded as given' => [['limit' => '25'], 25],
            'capped at the protocol maximum' => [['limit' => '5000'], Readable::MAX_BATCH],
            'zero floors at one' => [['limit' => '0'], 1],
            'a negative limit floors at one' => [['limit' => '-5'], 1],
            'garbage falls back to the maximum' => [['limit' => 'lots'], Readable::MAX_BATCH],
        ];
    }

    /** The same clamps apply when a route reaches poll() rather than serve(). */
    public function testPollClampsTheTimeoutAndLimitToo(): void
    {
        $store = new RecordingStore('edge');

        (new Server($store))->poll(null, 5_000, 120_000);

        $this->assertSame(Readable::MAX_TIMEOUT, $store->timeout);
        $this->assertSame(Readable::MAX_BATCH, $store->limit);
    }

    /** A position reaches the store as given, so clamping is all serve() does. */
    public function testServeForwardsThePositionUntouched(): void
    {
        $store = new RecordingStore('edge');

        (new Server($store))->serve(['lastEventId' => '1-7']);

        $this->assertSame('1-7', $store->lastEventId);
    }
}
