<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Batch;

class BatchTest extends TestCase
{
    /** @return list<CloudEvent> */
    private static function events(int $count): array
    {
        return \array_map(
            static fn (int $i): CloudEvent => new CloudEvent(id: '1-' . $i, type: 'event-' . $i, source: 'urn:test'),
            \range(0, $count - 1),
        );
    }

    public function testCountsItsEvents(): void
    {
        $this->assertCount(3, new Batch(self::events(3), 100));
        $this->assertCount(0, new Batch([], 100));
    }

    public function testIteratesItsEventsInOrder(): void
    {
        $types = [];

        foreach (new Batch(self::events(2), 100) as $event) {
            $types[] = $event->type;
        }

        $this->assertSame(['event-0', 'event-1'], $types);
    }

    public function testKnowsWhetherItIsEmpty(): void
    {
        $this->assertTrue((new Batch([], 100))->isEmpty());
        $this->assertFalse((new Batch(self::events(1), 100))->isEmpty());
    }

    public function testLastIdIsTheLastEventsPosition(): void
    {
        $this->assertSame('1-2', (new Batch(self::events(3), 100))->lastId());
        $this->assertNull((new Batch([], 100))->lastId());
    }

    /**
     * The caching rule stays in one place — the batch answers with its own
     * count and its own limit, so a mismatched pair cannot be expressed.
     */
    public function testAFullBatchIsCacheable(): void
    {
        $this->assertSame('private, max-age=31536000', (new Batch(self::events(2), 2))->cacheControl());
        $this->assertSame('public, max-age=31536000', (new Batch(self::events(2), 2))->cacheControl(public: true));
    }

    public function testAShortOrEmptyBatchIsNot(): void
    {
        $this->assertSame('no-store', (new Batch(self::events(1), 2))->cacheControl());
        $this->assertSame('no-store', (new Batch([], 2))->cacheControl());
    }

    /**
     * A batch of zero out of zero is not history — it is a caught-up consumer,
     * and caching it would pin the consumer at that position forever.
     */
    public function testAnEmptyBatchIsNeverCacheable(): void
    {
        $this->assertSame('no-store', (new Batch([], 0))->cacheControl());
    }

    public function testToArrayIsTheWireEncoding(): void
    {
        $payload = (new Batch(self::events(2), 100))->toArray();

        $this->assertTrue(\array_is_list($payload), 'A batch is a plain array — the spec defines no envelope');
        $this->assertSame(['1-0', '1-1'], \array_column($payload, 'id'));
        $this->assertSame('1.0', $payload[0]['specversion']);
        $this->assertSame([], (new Batch([], 100))->toArray());
    }

    /**
     * Asserted as a whole array: `id` and `specversion` alone would pass an
     * encoder that dropped `subject` or an extension.
     */
    public function testEveryAttributeReachesTheWire(): void
    {
        $batch = new Batch([new CloudEvent(
            id: '1-0',
            type: 'io.appwrite.edge.invalidate-rule',
            source: 'urn:appwrite:cloud:fra',
            subject: 'example.com',
            time: '2026-07-31T09:15:02.123Z',
            datacontenttype: 'application/json',
            data: ['tags' => ['domain' => 'example.com']],
            dataschema: 'https://example.com/schema.json',
            extensions: ['traceparent' => '00-abc-def-01'],
        )], 100);

        $this->assertSame([
            'specversion' => '1.0',
            'type' => 'io.appwrite.edge.invalidate-rule',
            'source' => 'urn:appwrite:cloud:fra',
            'id' => '1-0',
            'subject' => 'example.com',
            'time' => '2026-07-31T09:15:02.123Z',
            'datacontenttype' => 'application/json',
            'dataschema' => 'https://example.com/schema.json',
            'data' => ['tags' => ['domain' => 'example.com']],
            'traceparent' => '00-abc-def-01',
        ], $batch->toArray()[0]);
    }

    /** The spec has no null attribute values, so an absent attribute is omitted. */
    public function testAnAbsentOptionalAttributeIsOmittedRatherThanNulled(): void
    {
        $encoded = (new Batch([new CloudEvent(
            id: '1-0',
            type: 'a',
            source: 'urn:test',
            datacontenttype: null,
        )], 100))->toArray()[0];

        $this->assertSame(['specversion', 'type', 'source', 'id'], \array_keys($encoded));
    }
}
