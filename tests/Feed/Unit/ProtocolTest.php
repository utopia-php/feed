<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Protocol;

class ProtocolTest extends TestCase
{
    public function testQueryOmitsParametersLeftAtTheirDefault(): void
    {
        $this->assertSame([], Protocol::query());
        $this->assertSame([], Protocol::query(null, 0, 0));
        $this->assertSame([], Protocol::query('', 0, 0));
    }

    public function testQueryCarriesTheParametersThatWereSet(): void
    {
        $this->assertSame([
            'lastEventId' => '1-0',
            'limit' => 500,
            'timeout' => 20000,
        ], Protocol::query('1-0', 500, 20000));
    }

    public function testEncodesABatch(): void
    {
        $payload = Protocol::encode([
            new Event(id: '1-0', type: 'a', data: ['x' => 1], source: 'urn:test', subject: 's', time: 't'),
            new Event(id: '1-1', type: 'b'),
        ]);

        $this->assertSame(2, $payload['total']);
        $this->assertCount(2, $payload['events']);
        $this->assertSame('1-0', $payload['events'][0]['id']);
        $this->assertSame(['x' => 1], $payload['events'][0]['data']);
        $this->assertSame('1.0', $payload['events'][0]['specversion']);
    }

    public function testEncodesAnEmptyBatch(): void
    {
        $this->assertSame(['total' => 0, 'events' => []], Protocol::encode([]));
    }

    public function testDecodesWhatItEncoded(): void
    {
        $events = [
            new Event(id: '1-0', type: 'a', data: ['x' => 1], source: 'urn:test', subject: 's', time: 't'),
            new Event(id: '1-1', type: 'b'),
        ];

        $this->assertEquals($events, Protocol::decode(Protocol::encode($events)));
    }

    public function testDecodesAnEmptyBatch(): void
    {
        $this->assertSame([], Protocol::decode(['total' => 0, 'events' => []]));
        $this->assertSame([], Protocol::decode([]));
    }

    public function testRejectsAPayloadThatIsNotABatch(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode('not a batch');
    }

    public function testRejectsAMalformedEventsField(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode(['events' => 'nope']);
    }

    /**
     * An event with no id has no position, so a consumer cannot record having
     * passed it. Returning the usable prefix lets those events be handled and
     * the position advance to the last of them; the broken event is then at
     * the head of the next batch, where it stops the feed loudly.
     */
    public function testKeepsTheEventsBeforeAnUndecodableOne(): void
    {
        $events = Protocol::decode([
            'events' => [
                ['id' => '1-0', 'type' => 'a'],
                ['id' => '1-1', 'type' => 'b'],
                ['type' => 'no id'],
                ['id' => '1-3', 'type' => 'd'],
            ],
        ]);

        $this->assertCount(2, $events);
        $this->assertSame(['a', 'b'], \array_map(fn (Event $e): string => $e->type, $events));
    }

    public function testFailsWhenTheFirstEventIsUndecodable(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode(['events' => [['type' => 'no id'], ['id' => '1-1', 'type' => 'b']]]);
    }

    public function testFailsWhenTheFirstEntryIsNotAnEvent(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode(['events' => ['a string']]);
    }

    public function testKeepsTheEventsBeforeAnEntryThatIsNotAnEvent(): void
    {
        $events = Protocol::decode(['events' => [['id' => '1-0', 'type' => 'a'], 'a string']]);

        $this->assertCount(1, $events);
    }

    /**
     * A full batch is settled history, so it may be cached forever. Anything
     * short is the live end of the feed and will grow.
     */
    public function testAFullBatchIsCacheable(): void
    {
        $this->assertSame('private, max-age=31536000', Protocol::cacheControl(100, 100));
    }

    public function testAPartialBatchIsNotCacheable(): void
    {
        $this->assertSame('no-store', Protocol::cacheControl(99, 100));
        $this->assertSame('no-store', Protocol::cacheControl(0, 100));
    }

    /**
     * A batch of zero out of zero is not history — it is a caught-up consumer,
     * and caching it would pin the consumer at that position forever.
     */
    public function testAnEmptyBatchIsNeverCacheable(): void
    {
        $this->assertSame('no-store', Protocol::cacheControl(0, 0));
    }

    public function testSharedCachingIsOptIn(): void
    {
        $this->assertStringStartsWith('private, ', Protocol::cacheControl(10, 10));
        $this->assertStringStartsWith('public, ', Protocol::cacheControl(10, 10, public: true));
    }
}
