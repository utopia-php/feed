<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Journal\Memory;
use Utopia\Feed\Journal\None;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Feed;
use Utopia\Feed\Id;

class FeedTest extends TestCase
{
    private Memory $journal;

    private Feed $feed;

    protected function setUp(): void
    {
        $this->journal = new Memory('edge');
        $this->feed = new Feed($this->journal, 'urn:appwrite:cloud:fra');
    }

    public function testAppendReturnsAPosition(): void
    {
        $id = $this->feed->append('io.appwrite.edge.invalidate', ['tags' => ['project' => 'p1']]);

        $this->assertTrue(Id::isValid($id));
    }

    public function testReadsBackWhatWasAppended(): void
    {
        $this->feed->append('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = $this->feed->read();

        $this->assertCount(1, $events);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame('example.com', $events[0]->subject);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    public function testStampsTheSourceAndTimeOnAppend(): void
    {
        $this->feed->append('test');

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
        (new Feed($this->journal, 'urn:appwrite:cloud:fra'))->append('test');
        (new Feed($this->journal, 'urn:appwrite:cloud:nyc'))->append('test');

        $events = (new Feed($this->journal, 'urn:appwrite:cloud:syd'))->read();

        $this->assertSame('urn:appwrite:cloud:fra', $events[0]->source);
        $this->assertSame('urn:appwrite:cloud:nyc', $events[1]->source);
    }

    public function testEventsComeBackOldestFirst(): void
    {
        foreach (['a', 'b', 'c'] as $type) {
            $this->feed->append($type);
        }

        $this->assertSame(['a', 'b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $this->feed->read()));
    }

    public function testIdsAreStrictlyIncreasingEvenWithinAMillisecond(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->feed->append('test');
        }

        $this->assertSame($ids, \array_unique($ids), 'Positions must be unique');

        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertSame(1, Id::compare($ids[$i], $ids[$i - 1]), 'Positions must increase');
        }
    }

    public function testReadsStrictlyAfterTheGivenPosition(): void
    {
        $first = $this->feed->append('a');
        $this->feed->append('b');

        $events = $this->feed->read($first);

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }

    public function testReadFromTheLastEventIsEmpty(): void
    {
        $this->feed->append('a');
        $last = $this->feed->append('b');

        $this->assertSame([], $this->feed->read($last));
    }

    public function testNullPositionReadsFromTheOldestRetainedEvent(): void
    {
        $this->feed->append('a');
        $this->feed->append('b');

        $this->assertCount(2, $this->feed->read(null));
    }

    public function testHonoursTheLimit(): void
    {
        foreach (\range(1, 10) as $i) {
            $this->feed->append('test');
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
        $this->feed->append('test');

        $this->assertCount(1, $this->feed->read(null, Feed::MAX_BATCH * 10));
        $this->assertCount(1, $this->feed->read(null, 0));
        $this->assertCount(1, $this->feed->read(null, -5));
    }

    public function testRejectsAPositionThatIsNotAFeedId(): void
    {
        $this->expectException(Invalid::class);

        $this->feed->read('not-a-position');
    }

    public function testRejectsAnEmptyEventType(): void
    {
        $this->expectException(Invalid::class);

        $this->feed->append('');
    }

    public function testRejectsAPayloadThatCannotBeEncoded(): void
    {
        $this->expectException(Invalid::class);

        $this->feed->append('test', ['resource' => \fopen('php://memory', 'r')]);
    }

    public function testPublishStampsAPreparedEvent(): void
    {
        $id = $this->feed->publish(new CloudEvent(id: 'ignored', type: 'test', data: ['a' => 'b'], subject: 's'));

        $event = $this->feed->read()[0];

        $this->assertSame($id, $event->id);
        $this->assertNotSame('ignored', $event->id, 'The backend assigns the position, not the caller');
        $this->assertSame('urn:appwrite:cloud:fra', $event->source);
        $this->assertSame(['a' => 'b'], $event->data);
    }

    public function testPublishKeepsATimeTheCallerSet(): void
    {
        $this->feed->publish(new CloudEvent(id: '', type: 'test', time: '2020-01-01T00:00:00.000Z'));

        $this->assertSame('2020-01-01T00:00:00.000Z', $this->feed->read()[0]->time);
    }

    /**
     * A producer that attaches a `traceparent` means it to reach the consumer.
     * Stamping the event on publish rebuilds it, and storing it flattens it, so
     * either step could quietly drop an attribute this library does not model.
     */
    public function testExtensionAttributesSurviveAppendAndRead(): void
    {
        $this->feed->publish(new CloudEvent(
            id: '',
            type: 'test',
            extensions: ['traceparent' => '00-abc-def-01', 'retrycount' => 2],
        ));

        $event = $this->feed->read()[0];

        $this->assertSame('00-abc-def-01', $event->getExtension('traceparent'));
        $this->assertSame(2, $event->getExtension('retrycount'));
        $this->assertSame('urn:appwrite:cloud:fra', $event->source, 'Stamping still happened');
    }

    /**
     * An extension name of only digits is legal — the spec allows `[a-z0-9]+` —
     * and PHP stores such a name as an integer key. Anything that merges the
     * extensions back in with a spread, or with `array_merge()`, renumbers that
     * key and silently loses the attribute.
     */
    public function testADigitsOnlyExtensionNameSurvivesAppendAndRead(): void
    {
        $this->feed->publish(new CloudEvent(
            id: '',
            type: 'test',
            extensions: ['123' => 'digits', 'trace' => 'ok'],
        ));

        $event = $this->feed->read()[0];

        $this->assertSame('digits', $event->getExtension('123'));
        $this->assertSame('ok', $event->getExtension('trace'));
    }

    public function testDataschemaSurvivesAppendAndRead(): void
    {
        $this->feed->publish(new CloudEvent(
            id: '',
            type: 'test',
            dataschema: 'https://example.com/schema.json',
        ));

        $this->assertSame('https://example.com/schema.json', $this->feed->read()[0]->dataschema);
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
        $this->feed->append('test', $data);

        $this->assertSame($data, $this->feed->read()[0]->data);
    }

    /**
     * CloudEvents models an absent subject as null rather than an empty string,
     * so a caller checking for one must check for null.
     */
    public function testAnEventWithNoSubjectHasANullSubject(): void
    {
        $this->feed->append('test');

        $this->assertNull($this->feed->read()[0]->subject);
    }

    public function testASubjectSurvivesAppendAndRead(): void
    {
        $this->feed->append('test', [], 'example.com');

        $this->assertSame('example.com', $this->feed->read()[0]->subject);
    }

    public function testPollReturnsImmediatelyWhenEventsAreWaiting(): void
    {
        $this->feed->append('test');

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

        $this->assertSame([], $events);
        $this->assertGreaterThanOrEqual(0.4, $elapsed, 'Must actually wait');
        $this->assertLessThan(3.0, $elapsed, 'Must not wait far past the timeout');
    }

    public function testPollWithoutATimeoutIsAPlainRead(): void
    {
        $started = \microtime(true);

        $this->assertSame([], $this->feed->poll());
        $this->assertLessThan(0.4, \microtime(true) - $started);
    }

    public function testRetentionIsBoundedAndTrimsTheOldest(): void
    {
        $feed = new Feed(new Memory('small', maxSize: 3));

        foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
            $feed->append($type);
        }

        $this->assertSame(['c', 'd', 'e'], \array_map(fn (CloudEvent $e): string => $e->type, $feed->read()));
    }

    /**
     * The one case a caller has to design for: a consumer that fell behind the
     * trim horizon gets what is left, not an error and not a gap it can detect.
     */
    public function testAPositionBelowTheTrimHorizonReadsWhatIsLeft(): void
    {
        $feed = new Feed($journal = new Memory('small', maxSize: 2));

        $first = $feed->append('a');
        $feed->append('b');
        $feed->append('c');

        $this->assertSame(2, $journal->count());
        $this->assertSame(['b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $feed->read($first)));
    }

    public function testExposesItsIdentity(): void
    {
        $this->assertSame('edge', $this->feed->getName());
        $this->assertSame('urn:appwrite:cloud:fra', $this->feed->getSource());
        $this->assertSame($this->journal, $this->feed->getJournal());
    }

    public function testAnUnconfiguredBackendFailsLoudlyRatherThanDroppingEvents(): void
    {
        $feed = new Feed(new None('edge'));

        $this->expectException(Unsupported::class);

        $feed->append('test');
    }

    public function testAnUnconfiguredBackendCannotBeRead(): void
    {
        $feed = new Feed(new None('edge'));

        $this->expectException(Unsupported::class);

        $feed->read();
    }

    public function testRejectsAnEmptyFeedName(): void
    {
        $this->expectException(Invalid::class);

        new Memory('');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unusableRetention(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    /**
     * Backends disagree about what a non-positive cap means — some keep
     * nothing, some keep everything — so it is refused at construction rather
     * than resolved one way in a test and the other way in production.
     *
     * @dataProvider unusableRetention
     */
    public function testRejectsARetentionCapThatWouldNotBoundTheFeed(int $maxSize): void
    {
        $this->expectException(Invalid::class);

        new Memory('edge', maxSize: $maxSize);
    }

    public function testAcceptsTheSmallestUsefulRetentionCap(): void
    {
        $feed = new Feed(new Memory('edge', maxSize: 1));

        $feed->append('a');
        $feed->append('b');

        $events = $feed->read();

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }

    public function testFlushingMemoryDoesNotReissuePositions(): void
    {
        $before = $this->feed->append('a');
        $this->journal->flush();
        $after = $this->feed->append('b');

        $this->assertSame(1, Id::compare($after, $before), 'A reissued position would make a consumer skip events');
    }
}
