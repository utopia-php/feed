<?php

declare(strict_types=1);

namespace Utopia\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Batch;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Feed\Server;
use Utopia\Feed\Store;

/**
 * Everything a server promises, run against the store adapter the subclass
 * provides: paging strictly after a position, clamping what a consumer asks
 * for, holding a long poll, resolving the tip, and answering `serve()` the way
 * an HTTP route needs.
 *
 * Events get into the feed through a plain {@see Producer} over the same
 * store — the simplest producing setup there is — so a failure here is the
 * serving side's, not the producing side's.
 */
abstract class Base extends TestCase
{
    protected string $name;

    protected Store&Appendable $store;

    protected Producer $producer;

    protected Server $server;

    /** The store adapter under test. */
    abstract protected function store(string $name, int $maxSize = 100_000, int $pollInterval = 500): Store&Appendable;

    protected function setUp(): void
    {
        // A fresh feed per test: these assert on positions and order, and a
        // shared backend (a real Redis) would leak them between tests.
        $this->name = 'test-' . \bin2hex(\random_bytes(8));
        $this->store = $this->store($this->name);
        $this->producer = new Producer($this->store, 'urn:test');
        $this->server = new Server($this->store);
    }

    /** @return list<CloudEvent> */
    protected static function events(Batch $batch): array
    {
        return \array_values(\iterator_to_array($batch));
    }

    /** @return list<string> */
    protected static function types(Batch $batch): array
    {
        return \array_map(fn (CloudEvent $e): string => $e->type, self::events($batch));
    }

    public function testReadsBackWhatWasAppended(): void
    {
        $id = $this->producer->produce('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = self::events($this->server->read());

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    /**
     * The reason positions advance arithmetically instead of with Redis'
     * `(`-exclusive range syntax: "strictly after" has to hold on every
     * backend, not only the ones whose range API can express it.
     */
    public function testReadsStrictlyAfterTheGivenPosition(): void
    {
        $first = $this->producer->produce('a');
        $second = $this->producer->produce('b');

        $events = self::events($this->server->read($first));

        $this->assertCount(1, $events);
        $this->assertSame($second, $events[0]->id);
    }

    public function testReadFromTheLastEventIsEmpty(): void
    {
        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $this->assertCount(0, $this->server->read($last));
    }

    public function testNullPositionReadsFromTheOldestRetainedEvent(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertSame(['a', 'b'], self::types($this->server->read(null)));
    }

    public function testHonoursTheLimit(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->producer->produce('test');
        }

        $this->assertCount(3, $this->server->read(null, 3));
    }

    /**
     * `limit` arrives from a consumer, so it is clamped rather than rejected —
     * failing the read would stall a feed over something the producer can just
     * decide.
     */
    public function testClampsTheLimitToTheMaximum(): void
    {
        $this->producer->produce('test');

        $this->assertCount(1, $this->server->read(null, Readable::MAX_BATCH * 10));
        $this->assertCount(1, $this->server->read(null, 0));
        $this->assertCount(1, $this->server->read(null, -5));
    }

    public function testRejectsAPositionThatIsNotAFeedId(): void
    {
        $this->expectException(Invalid::class);

        $this->server->read('not-a-position');
    }

    public function testPollReturnsImmediatelyWhenEventsAreWaiting(): void
    {
        $this->producer->produce('test');

        $started = \microtime(true);
        $events = $this->server->poll(null, 10, 2000);

        $this->assertCount(1, $events);
        $this->assertLessThan(1, \microtime(true) - $started);
    }

    public function testPollGivesUpAtTheTimeoutWithAnEmptyBatch(): void
    {
        $started = \microtime(true);
        $events = $this->server->poll(null, 10, 600);
        $elapsed = \microtime(true) - $started;

        $this->assertCount(0, $events);
        $this->assertGreaterThanOrEqual(0.4, $elapsed, 'Must actually wait');
        $this->assertLessThan(3.0, $elapsed, 'Must not wait far past the timeout');
    }

    public function testPollWithoutATimeoutIsAPlainRead(): void
    {
        $started = \microtime(true);

        $this->assertCount(0, $this->server->poll());
        $this->assertLessThan(0.4, \microtime(true) - $started);
    }

    /**
     * The overshoot fix: the poll loop must sleep the remaining time when that
     * is less than the interval, not a full interval past the deadline.
     */
    public function testPollHonoursATimeoutShorterThanThePollInterval(): void
    {
        $server = new Server($this->store($this->name, pollInterval: 500));

        $started = \microtime(true);
        $events = $server->poll(null, 10, 100);
        $elapsed = \microtime(true) - $started;

        $this->assertCount(0, $events);
        $this->assertGreaterThanOrEqual(0.08, $elapsed, 'Must actually wait out the timeout');
        $this->assertLessThan(0.3, $elapsed, 'Must not sleep a full interval past the deadline');
    }

    public function testTipIsTheNewestEventsId(): void
    {
        $this->assertNull($this->server->tip(), 'An empty feed has no tip');

        $this->producer->produce('a');
        $last = $this->producer->produce('b');

        $this->assertSame($last, $this->server->tip());
    }

    public function testReadingFromTheTipSentinelSkipsTheBacklog(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertCount(0, $this->server->read(Readable::TIP));
    }

    /**
     * The one case a caller has to design for: a consumer that fell behind the
     * trim horizon gets what is left, not an error and not a gap it can detect.
     */
    public function testAPositionBelowTheTrimHorizonReadsWhatIsLeft(): void
    {
        $store = $this->store($this->name, maxSize: 10);
        $producer = new Producer($store, 'urn:test');
        $server = new Server($store);

        $first = $producer->produce('first');

        foreach (\range(1, 300) as $i) {
            $producer->produce('event-' . $i);
        }

        $events = $server->read($first);

        $this->assertFalse($events->isEmpty(), 'A consumer that fell behind must still get what is retained');
        $this->assertLessThan(300, \count($events), 'The feed must be trimmed');
    }

    public function testExposesTheFeedItReads(): void
    {
        $this->assertSame($this->name, $this->server->getName());
    }

    public function testServeAppliesTheDefaultsWhenNoParametersArrive(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertCount(2, $this->server->serve([]));
    }

    public function testServeCoercesTheStringValuesARouteHands(): void
    {
        $first = $this->producer->produce('a');
        $this->producer->produce('b');
        $this->producer->produce('c');

        $batch = $this->server->serve([
            'lastEventId' => $first,
            'limit' => '1',
            'timeout' => '0',
        ]);

        $this->assertSame(['b'], self::types($batch));
    }

    public function testServeTreatsAnEmptyLastEventIdAsAbsent(): void
    {
        $this->producer->produce('a');

        $this->assertCount(1, $this->server->serve(['lastEventId' => '']));
    }

    public function testServeRejectsALastEventIdThatIsNotAPosition(): void
    {
        $this->expectException(Invalid::class);

        $this->server->serve(['lastEventId' => 'not-a-position']);
    }

    /**
     * PHP parses `?lastEventId[]=1-0` into an array, so a `lastEventId` that
     * is present need not be a string. Coercing one to "absent" would answer
     * a malformed parameter with a full replay of the retained feed — the
     * most expensive response the endpoint has, and one a caught-up consumer
     * would read as a sudden flood of new events rather than as the 400 it is.
     */
    #[DataProvider('notStrings')]
    public function testServeRejectsALastEventIdThatIsNotEvenAString(mixed $lastEventId): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->expectException(Invalid::class);

        $this->server->serve(['lastEventId' => $lastEventId]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notStrings(): array
    {
        return [
            'a repeated parameter' => [['1-0']],
            'an empty array' => [[]],
            'a nested map' => [['a' => '1-0']],
            'a boolean' => [true],
        ];
    }

    /**
     * `limit` and `timeout` stay forgiving where `lastEventId` does not: both
     * are the producer's to decide, so garbage falls back to the default
     * rather than stalling a feed over something that cannot cause a wrong
     * answer. An array is garbage like any other.
     */
    public function testServeFallsBackToTheDefaultsOnArrayLimitsAndTimeouts(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertCount(2, $this->server->serve(['limit' => ['5'], 'timeout' => ['0']]));
    }

    public function testServeLetsTheTipSentinelThrough(): void
    {
        $this->producer->produce('a');

        $this->assertCount(0, $this->server->serve(['lastEventId' => Readable::TIP]));
    }

    /**
     * The long-poll contract has to hold through the HTTP entry point, not
     * only through `poll()`. A refactor that stopped forwarding the timeout
     * would turn every long poll into a plain read — consumers would spin
     * instead of waiting, and nothing that asserts on returned events could
     * tell, because a caught-up read returns the same empty batch either way.
     */
    public function testServeHoldsALongPollOnAnEmptyFeed(): void
    {
        $started = \microtime(true);

        $batch = $this->server->serve(['timeout' => '600']);

        $this->assertCount(0, $batch);
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started, 'Must actually wait');
    }

    public function testServeFallsBackToTheDefaultOnAGarbageLimit(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertCount(2, $this->server->serve(['limit' => 'lots', 'timeout' => 'soon']));
    }

    /**
     * The trap the old API set: a route that passed the raw request limit to
     * the caching rule while the read was clamped to less would mark a full
     * batch `no-store` — or worse. The batch carries the limit it was actually
     * built with, so a full batch under an oversized request is still
     * recognized as settled history.
     */
    public function testAnOversizedLimitStillYieldsAnHonestCacheControl(): void
    {
        foreach (\range(1, Readable::MAX_BATCH) as $i) {
            $this->producer->produce('event-' . $i);
        }

        $batch = $this->server->serve(['limit' => '5000']);

        $this->assertCount(Readable::MAX_BATCH, $batch);
        $this->assertSame('public, max-age=31536000', $batch->cacheControl(public: true));
    }

    public function testAShortBatchIsNotCacheable(): void
    {
        $this->producer->produce('a');

        $this->assertSame('no-store', $this->server->serve([])->cacheControl());
    }

    public function testAnEmptyBatchIsNotCacheable(): void
    {
        $this->assertSame('no-store', $this->server->serve([])->cacheControl());
    }
}
