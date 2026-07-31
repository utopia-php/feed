<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Batch;
use Utopia\Feed\Protocol;

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

    public function testToArrayIsTheWireEncoding(): void
    {
        $events = self::events(2);

        $this->assertSame(Protocol::encode($events), (new Batch($events, 100))->toArray());
        $this->assertSame([], (new Batch([], 100))->toArray());
    }
}
