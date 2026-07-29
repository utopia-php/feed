<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Id;
use Utopia\Feed\Journal\Http;
use Utopia\Feed\Journal\Memory;
use Utopia\Feed\Journal\None;
use Utopia\Feed\Producer;
use Utopia\Tests\Unit\Support\FakeTransport;

class ProducerTest extends TestCase
{
    private Memory $journal;

    private Producer $producer;

    private Feed $feed;

    protected function setUp(): void
    {
        $this->journal = new Memory('edge');
        $this->producer = new Producer($this->journal, 'urn:appwrite:cloud:fra');
        $this->feed = new Feed($this->journal);
    }

    public function testAppendReturnsAPosition(): void
    {
        $id = $this->producer->append('io.appwrite.edge.invalidate', ['tags' => ['project' => 'p1']]);

        $this->assertTrue(Id::isValid($id));
    }

    public function testStampsTheSourceAndTimeOnAppend(): void
    {
        $this->producer->append('test');

        $event = $this->feed->read()[0];

        $this->assertSame('urn:appwrite:cloud:fra', $event->source);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event->time);
    }

    /**
     * Recording it at append rather than at read keeps it correct for a feed
     * read back somewhere other than where it was written.
     */
    public function testKeepsTheSourceOfTheProducerThatAppended(): void
    {
        (new Producer($this->journal, 'urn:appwrite:cloud:fra'))->append('test');
        (new Producer($this->journal, 'urn:appwrite:cloud:nyc'))->append('test');

        $events = $this->feed->read();

        $this->assertSame('urn:appwrite:cloud:fra', $events[0]->source);
        $this->assertSame('urn:appwrite:cloud:nyc', $events[1]->source);
    }

    public function testPublishStampsAPreparedEvent(): void
    {
        $id = $this->producer->publish(new CloudEvent(id: 'ignored', type: 'test', data: ['a' => 'b'], subject: 's'));

        $event = $this->feed->read()[0];

        $this->assertSame($id, $event->id);
        $this->assertNotSame('ignored', $event->id, 'The backend assigns the position, not the caller');
        $this->assertSame('urn:appwrite:cloud:fra', $event->source);
        $this->assertSame(['a' => 'b'], $event->data);
    }

    public function testPublishKeepsATimeTheCallerSet(): void
    {
        $this->producer->publish(new CloudEvent(id: '', type: 'test', time: '2020-01-01T00:00:00.000Z'));

        $this->assertSame('2020-01-01T00:00:00.000Z', $this->feed->read()[0]->time);
    }

    public function testRejectsAnEmptyEventType(): void
    {
        $this->expectException(Invalid::class);

        $this->producer->append('');
    }

    public function testRejectsAPayloadThatCannotBeEncoded(): void
    {
        $this->expectException(Invalid::class);

        $this->producer->append('test', ['resource' => \fopen('php://memory', 'r')]);
    }

    /**
     * CloudEvents requires a source, and an event stamped with an empty one is
     * an event no consumer can attribute.
     */
    public function testRejectsAnEmptySource(): void
    {
        $this->expectException(Invalid::class);

        new Producer($this->journal, '');
    }

    /**
     * A feed read over HTTP belongs to whoever appends to it, so it is not
     * Appendable — the mistake is a type error at construction rather than an
     * exception once an event is already in hand.
     */
    public function testAJournalThatCannotBeAppendedToIsRejectedOnConstruction(): void
    {
        $journal = new Http(FakeTransport::of([]), 'https://cloud.example.com/v1/feeds', 'edge');

        $this->expectException(\TypeError::class);

        // @phpstan-ignore argument.type
        new Producer($journal, 'urn:appwrite:edge:fra');
    }

    public function testAFeedWithNoBackendFailsLoudlyRatherThanDroppingEvents(): void
    {
        $producer = new Producer(new None('edge'), 'urn:appwrite:cloud:fra');

        $this->expectException(Unsupported::class);

        $producer->append('test');
    }
}
