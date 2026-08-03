<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Producer;
use Utopia\Feed\Remote;
use Utopia\Feed\Store\None;
use Utopia\Tests\Support\FakeTransport;

/**
 * The producer behaviours no working adapter can show: producing into a feed
 * that is not yours, and producing into a backend that was never configured.
 * Everything a real adapter can exercise lives in
 * {@see \Utopia\Tests\Producer\Base} instead.
 */
class ProducerTest extends TestCase
{
    /**
     * A remote feed belongs to whoever produces into it, so Remote is neither
     * a Store nor Appendable — the mistake is a type error at construction
     * rather than an exception once an event is already in hand.
     */
    public function testARemoteFeedIsRejectedOnConstruction(): void
    {
        $remote = new Remote(FakeTransport::of([]), 'edge');

        $this->expectException(\TypeError::class);

        // @phpstan-ignore argument.type
        new Producer($remote, 'urn:appwrite:edge:fra');
    }

    public function testAFeedWithNoBackendFailsLoudlyRatherThanDroppingEvents(): void
    {
        $producer = new Producer(new None('edge'), 'urn:appwrite:cloud:fra');

        $this->expectException(Unsupported::class);

        $producer->produce('test');
    }
}
