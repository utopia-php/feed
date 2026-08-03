<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Id;
use Utopia\Feed\Producer;
use Utopia\Feed\Store;

/**
 * Everything a producer promises, run against the store adapter the subclass
 * provides: an event produced into the feed is stamped, positioned, retained
 * within bounds, and reads back as the event that was produced.
 *
 * Reading back goes straight through the store — the simplest reader there
 * is — so a failure here is the adapter's, not another component's.
 */
abstract class Base extends TestCase
{
    protected string $name;

    protected Store&Appendable $store;

    protected Producer $producer;

    /** The store adapter under test. */
    abstract protected function store(string $name, int $maxSize = 100_000, int $pollInterval = 500): Store&Appendable;

    protected function setUp(): void
    {
        // A fresh feed per test: these assert on positions and order, and a
        // shared backend (a real Redis) would leak them between tests.
        $this->name = 'test-' . \bin2hex(\random_bytes(8));
        $this->store = $this->store($this->name);
        $this->producer = new Producer($this->store, 'urn:test');
    }

    /** @return list<CloudEvent> */
    protected function events(): array
    {
        return $this->store->read(null, 1000);
    }

    public function testProduceReturnsAPosition(): void
    {
        $id = $this->producer->produce('io.appwrite.edge.invalidate', ['tags' => ['project' => 'p1']]);

        $this->assertTrue(Id::isValid($id), "The backend returned an id this library cannot page from: {$id}");
    }

    public function testRoundTripsAnEvent(): void
    {
        $id = $this->producer->produce('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = $this->events();

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame('example.com', $events[0]->subject);
        $this->assertSame('urn:test', $events[0]->source);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
        $this->assertNotNull($events[0]->time);
        $this->assertSame('application/json', $events[0]->datacontenttype, 'produce() encodes its payload as JSON and says so');
    }

    public function testStampsTheSourceAndTime(): void
    {
        $this->producer->produce('test');

        $event = $this->events()[0];

        $this->assertSame('urn:test', $event->source);
        $this->assertNotNull($event->time);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event->time);
    }

    /**
     * Recording it when produced rather than when read keeps it correct for a
     * feed read back somewhere other than where it was written.
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
        $this->assertSame('urn:test', $event->source);
        $this->assertSame(['a' => 'b'], $event->data);
    }

    /**
     * `source` records where an event happened, and a producer can only speak
     * for itself — so an event relayed from another feed is republished as
     * this service's event. Documented rather than merely tested, because a
     * caller handing over a "prepared" event would reasonably expect it to be
     * published as prepared.
     */
    public function testPublishReplacesTheCallersSourceWithTheProducersOwn(): void
    {
        $this->producer->publish(new CloudEvent(id: '', type: 'test', source: 'urn:somebody:else'));

        $this->assertSame('urn:test', $this->events()[0]->source);
    }

    /**
     * The stored form can only be decoded as CloudEvents 1.0, so keeping
     * another version would leave an entry in the feed that nothing can read.
     */
    public function testPublishNormalisesTheSpecVersion(): void
    {
        $this->producer->publish(new CloudEvent(id: '', type: 'test', source: '', specversion: '1.1'));

        $this->assertSame('1.0', $this->events()[0]->specversion);
    }

    public function testPublishKeepsATimeTheCallerSet(): void
    {
        $this->producer->publish(new CloudEvent(id: '', type: 'test', source: '', time: '2020-01-01T00:00:00.000Z'));

        $this->assertSame('2020-01-01T00:00:00.000Z', $this->events()[0]->time);
    }

    public function testEventsComeBackOldestFirst(): void
    {
        foreach (['a', 'b', 'c'] as $type) {
            $this->producer->produce($type);
        }

        $this->assertSame(['a', 'b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $this->events()));
    }

    public function testIdsAreStrictlyIncreasingEvenWithinAMillisecond(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->producer->produce('test');
        }

        $this->assertSame($ids, \array_unique($ids), 'Positions must be unique');

        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertGreaterThan(Id::decode($ids[$i - 1]), Id::decode($ids[$i]), 'Positions must increase');
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function payloads(): array
    {
        return [
            'map' => [['tags' => ['domain' => 'example.com']]],
            'list' => [['a', 'b', 'c']],
            'nested list' => [[['x' => 1], ['x' => 2]]],
            'string' => ['a string'],
            'number' => [42],
            'float' => [1.5],
            'boolean' => [true],
            'null' => [null],
            'empty' => [[]],
            'unicode' => [['unicode' => 'ünïcøde ✓']],
        ];
    }

    /**
     * The JSON event format leaves `data` unrestricted, so a list or a scalar
     * has to survive as itself — a list must not come back as a map.
     */
    #[DataProvider('payloads')]
    public function testAnyJsonPayloadSurvivesTheRoundTrip(mixed $data): void
    {
        $this->producer->produce('test', $data);

        $this->assertSame($data, $this->events()[0]->data);
    }

    /**
     * A producer that attaches a `traceparent` means it to reach the consumer.
     * Stamping the event on publish rebuilds it, and storing it flattens it, so
     * either step could quietly drop an attribute this library does not model.
     */
    public function testExtensionAttributesSurviveAppendAndRead(): void
    {
        $this->producer->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            extensions: ['traceparent' => '00-abc-def-01', 'retrycount' => 2],
        ));

        $event = $this->events()[0];

        $this->assertSame('00-abc-def-01', $event->extensions['traceparent']);
        $this->assertSame(2, $event->extensions['retrycount']);
    }

    /**
     * An extension name of only digits is legal — the spec allows `[a-z0-9]+` —
     * and PHP stores such a name as an integer key. Anything that merges the
     * extensions back in with a spread, or with `array_merge()`, renumbers that
     * key and silently loses the attribute.
     */
    public function testADigitsOnlyExtensionNameSurvivesAppendAndRead(): void
    {
        $this->producer->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            // @phpstan-ignore argument.type ('123' is an integer key in PHP)
            extensions: ['123' => 'digits', 'trace' => 'ok'],
        ));

        $event = $this->events()[0];

        // @phpstan-ignore offsetAccess.notFound
        $this->assertSame('digits', $event->extensions['123']);
        $this->assertSame('ok', $event->extensions['trace']);
    }

    /**
     * A producer that encodes its payload as something other than JSON says so
     * with `datacontenttype`, and losing it leaves a consumer holding data it
     * can no longer interpret. Nothing about the flattening the store does is
     * visible to the caller, so only a round trip can show the attribute made
     * it through.
     */
    public function testDatacontenttypeSurvivesAppendAndRead(): void
    {
        $this->producer->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            datacontenttype: 'application/xml',
            data: '<invalidate/>',
        ));

        $event = $this->events()[0];

        $this->assertSame('application/xml', $event->datacontenttype);
        $this->assertSame('<invalidate/>', $event->data);
    }

    /**
     * CloudEvents treats an absent `datacontenttype` as meaning the data is
     * JSON, so "unset" has to come back unset rather than as the empty string
     * the store flattens it to.
     */
    public function testAnEventWithNoDatacontenttypeReadsBackWithNone(): void
    {
        $this->producer->publish(new CloudEvent(id: '', type: 'test', source: '', datacontenttype: null));

        $this->assertNull($this->events()[0]->datacontenttype);
    }

    public function testDataschemaSurvivesAppendAndRead(): void
    {
        $this->producer->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            dataschema: 'https://example.com/schema.json',
        ));

        $this->assertSame('https://example.com/schema.json', $this->events()[0]->dataschema);
    }

    /**
     * CloudEvents models an absent subject as null rather than an empty string,
     * so a caller checking for one must check for null.
     */
    public function testAnEventWithNoSubjectHasANullSubject(): void
    {
        $this->producer->produce('test');

        $this->assertNull($this->events()[0]->subject);
    }

    public function testASubjectSurvivesAppendAndRead(): void
    {
        $this->producer->produce('test', [], 'example.com');

        $this->assertSame('example.com', $this->events()[0]->subject);
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
     * Trimming may be approximate (Redis trims to node boundaries), so this
     * asserts the property every adapter owes rather than an exact count: the
     * feed stays bounded, the oldest events go first, and the newest survives.
     */
    public function testRetentionIsBoundedAndDropsTheOldestFirst(): void
    {
        $store = $this->store($this->name, maxSize: 10);
        $producer = new Producer($store, 'urn:test');

        $producer->produce('first');

        foreach (\range(1, 299) as $i) {
            $producer->produce('event-' . $i);
        }

        $types = \array_map(fn (CloudEvent $e): string => $e->type, $store->read(null, 1000));

        $this->assertLessThan(300, \count($types), 'The feed must be trimmed');
        $this->assertNotContains('first', $types, 'The oldest event goes first');
        $this->assertSame('event-299', \end($types), 'The newest event is retained');
    }

    /**
     * Whether this adapter trims to exactly the cap. Redis does not — `XADD`
     * with `~` trims to a node boundary, which is the whole reason the
     * scenario above only asserts the loose bound.
     */
    protected function trimsExactly(): bool
    {
        return true;
    }

    /** An adapter that trims exactly owes the tighter contract: the bound is the cap itself. */
    public function testRetentionTrimsToExactlyTheCap(): void
    {
        if (!$this->trimsExactly()) {
            $this->markTestSkipped('This adapter trims approximately');
        }

        $store = $this->store($this->name, maxSize: 3);
        $producer = new Producer($store, 'urn:test');

        foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
            $producer->produce($type);
        }

        $this->assertSame(['c', 'd', 'e'], \array_map(fn (CloudEvent $e): string => $e->type, $store->read(null, 10)));
    }

    public function testAcceptsTheSmallestUsefulRetentionCap(): void
    {
        if (!$this->trimsExactly()) {
            $this->markTestSkipped('This adapter trims approximately');
        }

        $store = $this->store($this->name, maxSize: 1);
        $producer = new Producer($store, 'urn:test');

        $producer->produce('a');
        $producer->produce('b');

        $events = $store->read(null, 10);

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }

    public function testRejectsAnEmptyFeedName(): void
    {
        $this->expectException(Invalid::class);

        $this->store('');
    }

    #[DataProvider('notRetentions')]
    public function testRejectsARetentionThatKeepsNothing(int $maxSize): void
    {
        $this->expectException(Invalid::class);

        $this->store($this->name, maxSize: $maxSize);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function notRetentions(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    #[DataProvider('notIntervals')]
    public function testRejectsAPollIntervalBelowAMillisecond(int $pollInterval): void
    {
        $this->expectException(Invalid::class);

        $this->store($this->name, pollInterval: $pollInterval);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function notIntervals(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }
}
