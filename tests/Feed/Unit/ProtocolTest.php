<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
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

    public function testEncodesABatchAsAPlainArrayOfEvents(): void
    {
        $payload = Protocol::encode([
            new CloudEvent(id: '1-0', type: 'a', data: ['x' => 1], source: 'urn:test', subject: 's', time: 't'),
            new CloudEvent(id: '1-1', type: 'b', source: 'urn:test'),
        ]);

        $this->assertTrue(\array_is_list($payload), 'A batch is a plain array — the spec defines no envelope');
        $this->assertCount(2, $payload);
        $this->assertSame('1-0', $payload[0]['id']);
        $this->assertSame(['x' => 1], $payload[0]['data']);
        $this->assertSame('1.0', $payload[0]['specversion']);
    }

    public function testEncodesAnEmptyBatchAsAnEmptyArray(): void
    {
        $this->assertSame([], Protocol::encode([]));
    }

    public function testDecodesWhatItEncoded(): void
    {
        $events = [
            new CloudEvent(id: '1-0', type: 'a', data: ['x' => 1], source: 'urn:test', subject: 's', time: 't'),
            new CloudEvent(id: '1-1', type: 'b', source: 'urn:test'),
        ];

        $this->assertEquals($events, Protocol::decode(Protocol::encode($events)));
    }

    public function testDecodesAnEmptyBatch(): void
    {
        $this->assertSame([], Protocol::decode([]));
    }

    /**
     * An empty batch means "you are caught up". A JSON object means "you did
     * not reach the feed" — a misrouted request, a proxy's JSON error page, an
     * endpoint that moved. Reading one as an empty batch would leave a
     * consumer sitting quietly at a position that never advances again.
     *
     * @dataProvider notBatches
     */
    public function testARespondingEndpointThatIsNotAFeedIsNotMistakenForBeingCaughtUp(mixed $payload): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode($payload);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notBatches(): array
    {
        return [
            'the old envelope' => [['total' => 0, 'events' => []]],
            'some other API' => [['data' => [], 'status' => 'ok']],
            'an error body' => [['message' => 'Not found', 'code' => 404]],
        ];
    }

    public function testRejectsAPayloadThatIsNotABatch(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode('not a batch');
    }

    /**
     * One event as a producer would put it on the wire.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function raw(string $id, string $type, array $overrides = []): array
    {
        return \array_merge([
            'specversion' => '1.0',
            'id' => $id,
            'type' => $type,
            'source' => 'urn:test',
        ], $overrides);
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
            self::raw('1-0', 'a'),
            self::raw('1-1', 'b'),
            self::raw('', 'no id'),
            self::raw('1-3', 'd'),
        ]);

        $this->assertCount(2, $events);
        $this->assertSame(['a', 'b'], \array_map(fn (CloudEvent $e): string => $e->type, $events));
    }

    public function testFailsWhenTheFirstEventIsUndecodable(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode([self::raw('', 'no id'), self::raw('1-1', 'b')]);
    }

    public function testFailsWhenTheFirstEntryIsNotAnEvent(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode(['a string']);
    }

    public function testKeepsTheEventsBeforeAnEntryThatIsNotAnEvent(): void
    {
        $events = Protocol::decode([self::raw('1-0', 'a'), 'a string']);

        $this->assertCount(1, $events);
    }

    /**
     * `specversion` is REQUIRED by the spec and a feed's own producer always
     * sends it, so an entry without one is not a CloudEvent at all — the batch
     * stops there rather than the attribute being invented.
     */
    public function testFailsWhenAnEventIsNotACloudEventAtAll(): void
    {
        $this->expectException(Invalid::class);

        Protocol::decode([['id' => '1-0', 'type' => 'a']]);
    }

    /**
     * The forward-compatibility property a feed depends on: it is read by
     * consumers older than the producer by design, so a producer that adds an
     * attribute or moves the spec version forward must not stop one that
     * predates it.
     */
    public function testSurvivesAProducerThatMovedAhead(): void
    {
        $events = Protocol::decode([
            self::raw('1-0', 'a', [
                'specversion' => '1.1',
                'somethingnew' => 'ignored',
                'traceparent' => '00-abc-def-01',
            ]),
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('1.1', $events[0]->specversion);
        $this->assertSame('00-abc-def-01', $events[0]->extensions['traceparent']);
    }

    /**
     * The spec's optional compaction/deletion feature marks an event with a
     * `method` attribute. This library does not implement the feature, but a
     * feed that uses it must still be readable — the attribute rides along as
     * an extension rather than breaking the batch.
     */
    public function testAnEventCarryingTheSpecsMethodAttributeDecodes(): void
    {
        $events = Protocol::decode([self::raw('1-0', 'a', ['method' => 'DELETE'])]);

        $this->assertCount(1, $events);
        $this->assertSame('DELETE', $events[0]->extensions['method']);
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
