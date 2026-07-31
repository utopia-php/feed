<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Journal\Memory;
use Utopia\Feed\Journal\None;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Batch;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Producer;
use Utopia\Feed\Protocol;
use Utopia\Feed\Id;
use Utopia\Tests\Unit\Support\MidPollJournal;

class FeedTest extends TestCase
{
    private Memory $journal;

    private Feed $feed;

    private Producer $producer;

    protected function setUp(): void
    {
        $this->journal = new Memory('edge');
        $this->producer = new Producer($this->journal, 'urn:appwrite:cloud:fra');
        $this->feed = new Feed($this->journal);
    }

    /** @return list<CloudEvent> */
    private static function events(Batch $batch): array
    {
        return \array_values(\iterator_to_array($batch));
    }

    public function testReadsBackWhatWasAppended(): void
    {
        $this->producer->append('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = self::events($this->feed->read());

        $this->assertCount(1, $events);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame('example.com', $events[0]->subject);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    public function testEventsComeBackOldestFirst(): void
    {
        foreach (['a', 'b', 'c'] as $type) {
            $this->producer->append($type);
        }

        $this->assertSame(['a', 'b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, self::events($this->feed->read())));
    }

    public function testIdsAreStrictlyIncreasingEvenWithinAMillisecond(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->producer->append('test');
        }

        $this->assertSame($ids, \array_unique($ids), 'Positions must be unique');

        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertGreaterThan(Id::decode($ids[$i - 1]), Id::decode($ids[$i]), 'Positions must increase');
        }
    }

    public function testReadsStrictlyAfterTheGivenPosition(): void
    {
        $first = $this->producer->append('a');
        $this->producer->append('b');

        $events = self::events($this->feed->read($first));

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }

    public function testReadFromTheLastEventIsEmpty(): void
    {
        $this->producer->append('a');
        $last = $this->producer->append('b');

        $this->assertCount(0, $this->feed->read($last));
    }

    public function testNullPositionReadsFromTheOldestRetainedEvent(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');

        $this->assertCount(2, $this->feed->read(null));
    }

    public function testHonoursTheLimit(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->producer->append('test');
        }

        $this->assertCount(3, $this->feed->read(null, 3));
    }

    /**
     * `limit` arrives from a consumer, so it is clamped rather than rejected —
     * failing the read would stall a feed over something the producer can just
     * decide.
     */
    public function testClampsTheLimitToTheMaximum(): void
    {
        $this->producer->append('test');

        $this->assertCount(1, $this->feed->read(null, Protocol::MAX_BATCH * 10));
        $this->assertCount(1, $this->feed->read(null, 0));
        $this->assertCount(1, $this->feed->read(null, -5));
    }

    public function testRejectsAPositionThatIsNotAFeedId(): void
    {
        $this->expectException(Invalid::class);

        $this->feed->read('not-a-position');
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

        $event = self::events($this->feed->read())[0];

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

        $event = self::events($this->feed->read())[0];

        // @phpstan-ignore offsetAccess.notFound
        $this->assertSame('digits', $event->extensions['123']);
        $this->assertSame('ok', $event->extensions['trace']);
    }

    public function testDataschemaSurvivesAppendAndRead(): void
    {
        $this->producer->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            dataschema: 'https://example.com/schema.json',
        ));

        $this->assertSame('https://example.com/schema.json', self::events($this->feed->read())[0]->dataschema);
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
        ];
    }

    /**
     * The JSON event format leaves `data` unrestricted, so a list or a scalar
     * has to survive as itself — a list must not come back as a map.
     *
     * @dataProvider payloads
     */
    public function testAnyJsonPayloadSurvivesTheRoundTrip(mixed $data): void
    {
        $this->producer->append('test', $data);

        $this->assertSame($data, self::events($this->feed->read())[0]->data);
    }

    /**
     * CloudEvents models an absent subject as null rather than an empty string,
     * so a caller checking for one must check for null.
     */
    public function testAnEventWithNoSubjectHasANullSubject(): void
    {
        $this->producer->append('test');

        $this->assertNull(self::events($this->feed->read())[0]->subject);
    }

    public function testASubjectSurvivesAppendAndRead(): void
    {
        $this->producer->append('test', [], 'example.com');

        $this->assertSame('example.com', self::events($this->feed->read())[0]->subject);
    }

    public function testPollReturnsImmediatelyWhenEventsAreWaiting(): void
    {
        $this->producer->append('test');

        $started = \microtime(true);
        $events = $this->feed->poll(null, 10, 2000);

        $this->assertCount(1, $events);
        $this->assertLessThan(1, \microtime(true) - $started);
    }

    public function testPollGivesUpAtTheTimeoutWithAnEmptyBatch(): void
    {
        $started = \microtime(true);
        $events = $this->feed->poll(null, 10, 600);
        $elapsed = \microtime(true) - $started;

        $this->assertCount(0, $events);
        $this->assertGreaterThanOrEqual(0.4, $elapsed, 'Must actually wait');
        $this->assertLessThan(3.0, $elapsed, 'Must not wait far past the timeout');
    }

    public function testPollWithoutATimeoutIsAPlainRead(): void
    {
        $started = \microtime(true);

        $this->assertCount(0, $this->feed->poll());
        $this->assertLessThan(0.4, \microtime(true) - $started);
    }

    public function testAShorterPollIntervalDeliversAMidPollEventSooner(): void
    {
        $journal = new MidPollJournal('edge', pollInterval: 20);

        $started = \microtime(true);
        $events = $journal->poll(null, 10, 5_000);
        $elapsed = \microtime(true) - $started;

        $this->assertCount(1, $events);
        $this->assertLessThan(0.4, $elapsed, 'A 20ms interval must beat the default 500ms floor');
    }

    /**
     * The overshoot fix: the loop must sleep the remaining time when that is
     * less than the interval, not a full interval past the deadline.
     */
    public function testPollHonoursATimeoutShorterThanTheInterval(): void
    {
        $journal = new Memory('edge', pollInterval: 500);

        $started = \microtime(true);
        $events = $journal->poll(null, 10, 100);
        $elapsed = \microtime(true) - $started;

        $this->assertSame([], $events);
        $this->assertGreaterThanOrEqual(0.08, $elapsed, 'Must actually wait out the timeout');
        $this->assertLessThan(0.3, $elapsed, 'Must not sleep a full interval past the deadline');
    }

    public function testRejectsAZeroPollInterval(): void
    {
        $this->expectException(Invalid::class);

        new Memory('edge', pollInterval: 0);
    }

    public function testRejectsANegativePollInterval(): void
    {
        $this->expectException(Invalid::class);

        new Memory('edge', pollInterval: -5);
    }

    public function testRetentionIsBoundedAndTrimsTheOldest(): void
    {
        $journal = new Memory('small', maxSize: 3);
        $producer = new Producer($journal, 'urn:appwrite:cloud:fra');
        $feed = new Feed($journal);

        foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
            $producer->append($type);
        }

        $this->assertSame(['c', 'd', 'e'], \array_map(fn (CloudEvent $e): string => $e->type, self::events($feed->read())));
    }

    /**
     * The one case a caller has to design for: a consumer that fell behind the
     * trim horizon gets what is left, not an error and not a gap it can detect.
     */
    public function testAPositionBelowTheTrimHorizonReadsWhatIsLeft(): void
    {
        $journal = new Memory('small', maxSize: 2);
        $producer = new Producer($journal, 'urn:appwrite:cloud:fra');
        $feed = new Feed($journal);

        $first = $producer->append('a');
        $producer->append('b');
        $producer->append('c');

        $this->assertSame(['b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, self::events($feed->read($first))));
    }

    public function testExposesTheFeedItReads(): void
    {
        $this->assertSame('edge', $this->feed->getName());
    }

    public function testAFeedWithNoBackendCannotBeRead(): void
    {
        $feed = new Feed(new None('edge'));

        $this->expectException(Unsupported::class);

        $feed->read();
    }

    public function testTipIsTheNewestEventsId(): void
    {
        $this->assertNull($this->feed->tip());

        $this->producer->append('a');
        $last = $this->producer->append('b');

        $this->assertSame($last, $this->feed->tip());
    }

    public function testReadingFromTheTipSentinelSkipsTheBacklog(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');

        $this->assertCount(0, $this->feed->read(Protocol::TIP));
    }

    public function testServeAppliesTheDefaultsWhenNoParametersArrive(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');

        $this->assertCount(2, $this->feed->serve([]));
    }

    public function testServeCoercesTheStringValuesARouteHands(): void
    {
        $first = $this->producer->append('a');
        $this->producer->append('b');
        $this->producer->append('c');

        $batch = $this->feed->serve([
            'lastEventId' => $first,
            'limit' => '1',
            'timeout' => '0',
        ]);

        $this->assertSame(['b'], \array_map(fn (CloudEvent $e): string => $e->type, self::events($batch)));
    }

    public function testServeTreatsAnEmptyLastEventIdAsAbsent(): void
    {
        $this->producer->append('a');

        $this->assertCount(1, $this->feed->serve(['lastEventId' => '']));
    }

    public function testServeRejectsALastEventIdThatIsNotAPosition(): void
    {
        $this->expectException(Invalid::class);

        $this->feed->serve(['lastEventId' => 'not-a-position']);
    }

    public function testServeLetsTheTipSentinelThrough(): void
    {
        $this->producer->append('a');

        $this->assertCount(0, $this->feed->serve(['lastEventId' => Protocol::TIP]));
    }

    public function testServeFallsBackToTheDefaultOnAGarbageLimit(): void
    {
        $this->producer->append('a');
        $this->producer->append('b');

        $this->assertCount(2, $this->feed->serve(['limit' => 'lots', 'timeout' => 'soon']));
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
        foreach (\range(1, Protocol::MAX_BATCH) as $i) {
            $this->producer->append('event-' . $i);
        }

        $batch = $this->feed->serve(['limit' => '5000']);

        $this->assertCount(Protocol::MAX_BATCH, $batch);
        $this->assertSame('public, max-age=31536000', $batch->cacheControl(public: true));
    }

    public function testAShortBatchIsNotCacheable(): void
    {
        $this->producer->append('a');

        $this->assertSame('no-store', $this->feed->serve([])->cacheControl());
    }

    public function testAnEmptyBatchIsNotCacheable(): void
    {
        $this->assertSame('no-store', $this->feed->serve([])->cacheControl());
    }

    public function testRejectsAnEmptyFeedName(): void
    {
        $this->expectException(Invalid::class);

        new Memory('');
    }

    public function testAcceptsTheSmallestUsefulRetentionCap(): void
    {
        $journal = new Memory('edge', maxSize: 1);
        $producer = new Producer($journal, 'urn:appwrite:cloud:fra');
        $feed = new Feed($journal);

        $producer->append('a');
        $producer->append('b');

        $events = self::events($feed->read());

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }
}
