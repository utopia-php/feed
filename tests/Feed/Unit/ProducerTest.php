<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Server;
use Utopia\Feed\Id;
use Utopia\Feed\Store\Memory;
use Utopia\Feed\Store\None;
use Utopia\Feed\Producer;
use Utopia\Feed\Remote;
use Utopia\Tests\Unit\Support\FakeTransport;

class ProducerTest extends TestCase
{
    private Memory $store;

    private Producer $producer;

    private Server $server;

    protected function setUp(): void
    {
        $this->store = new Memory('edge');
        $this->producer = new Producer($this->store, 'urn:appwrite:cloud:fra');
        $this->server = new Server($this->store);
    }

    public function testProduceReturnsAPosition(): void
    {
        $id = $this->producer->produce('io.appwrite.edge.invalidate', ['tags' => ['project' => 'p1']]);

        $this->assertTrue(Id::isValid($id));
    }

    /** @return list<CloudEvent> */
    private function events(): array
    {
        return \array_values(\iterator_to_array($this->server->read()));
    }

    public function testStampsTheSourceAndTimeOnProduce(): void
    {
        $this->producer->produce('test');

        $event = $this->events()[0];

        $this->assertSame('urn:appwrite:cloud:fra', $event->source);
        $this->assertNotNull($event->time);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event->time);
    }

    /**
     * Recording it when produced rather than when read keeps it correct for a feed
     * read back somewhere other than where it was written.
     */
    public function testKeepsTheSourceOfTheProducerThatProduced(): void
    {
        (new Producer($this->store, 'urn:appwrite:cloud:fra'))->produce('test');
        (new Producer($this->store, 'urn:appwrite:cloud:nyc'))->produce('test');

        $events = $this->events();

        $this->assertSame('urn:appwrite:cloud:fra', $events[0]->source);
        $this->assertSame('urn:appwrite:cloud:nyc', $events[1]->source);
    }

    public function testPublishStampsAPreparedEvent(): void
    {
        $id = $this->producer->publish(new CloudEvent(id: 'ignored', type: 'test', source: 'ignored', data: ['a' => 'b'], subject: 's'));

        $event = $this->events()[0];

        $this->assertSame($id, $event->id);
        $this->assertNotSame('ignored', $event->id, 'The backend assigns the position, not the caller');
        $this->assertSame('urn:appwrite:cloud:fra', $event->source);
        $this->assertSame(['a' => 'b'], $event->data);
    }

    public function testPublishKeepsATimeTheCallerSet(): void
    {
        $this->producer->publish(new CloudEvent(id: '', type: 'test', source: '', time: '2020-01-01T00:00:00.000Z'));

        $this->assertSame('2020-01-01T00:00:00.000Z', $this->events()[0]->time);
    }

    public function testRejectsAnEmptyEventType(): void
    {
        $this->expectException(Invalid::class);

        $this->producer->produce('');
    }

    public function testRejectsAPayloadThatCannotBeEncoded(): void
    {
        $this->expectException(Invalid::class);

        $this->producer->produce('test', ['resource' => \fopen('php://memory', 'r')]);
    }

    /**
     * CloudEvents requires a source, and an event stamped with an empty one is
     * an event no consumer can attribute.
     */
    public function testRejectsAnEmptySource(): void
    {
        $this->expectException(Invalid::class);

        new Producer($this->store, '');
    }

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
