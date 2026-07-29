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
use Utopia\Feed\Producer;
use Utopia\Feed\Id;

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

    public function testReadsBackWhatWasAppended(): void
    {
        $this->producer->append('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = $this->feed->read();

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

        $this->assertSame(['a', 'b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $this->feed->read()));
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

        $events = $this->feed->read($first);

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }

    public function testReadFromTheLastEventIsEmpty(): void
    {
        $this->producer->append('a');
        $last = $this->producer->append('b');

        $this->assertSame([], $this->feed->read($last));
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

        $this->assertCount(1, $this->feed->read(null, Feed::MAX_BATCH * 10));
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
            extensions: ['traceparent' => '00-abc-def-01', 'retrycount' => 2],
        ));

        $event = $this->feed->read()[0];

        $this->assertSame('00-abc-def-01', $event->getExtension('traceparent'));
        $this->assertSame(2, $event->getExtension('retrycount'));
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
            extensions: ['123' => 'digits', 'trace' => 'ok'],
        ));

        $event = $this->feed->read()[0];

        $this->assertSame('digits', $event->getExtension('123'));
        $this->assertSame('ok', $event->getExtension('trace'));
    }

    public function testDataschemaSurvivesAppendAndRead(): void
    {
        $this->producer->publish(new CloudEvent(
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
        $this->producer->append('test', $data);

        $this->assertSame($data, $this->feed->read()[0]->data);
    }

    /**
     * CloudEvents models an absent subject as null rather than an empty string,
     * so a caller checking for one must check for null.
     */
    public function testAnEventWithNoSubjectHasANullSubject(): void
    {
        $this->producer->append('test');

        $this->assertNull($this->feed->read()[0]->subject);
    }

    public function testASubjectSurvivesAppendAndRead(): void
    {
        $this->producer->append('test', [], 'example.com');

        $this->assertSame('example.com', $this->feed->read()[0]->subject);
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
        $journal = new Memory('small', maxSize: 3);
        $producer = new Producer($journal, 'urn:appwrite:cloud:fra');
        $feed = new Feed($journal);

        foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
            $producer->append($type);
        }

        $this->assertSame(['c', 'd', 'e'], \array_map(fn (CloudEvent $e): string => $e->type, $feed->read()));
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

        $this->assertSame(['b', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $feed->read($first)));
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

        $events = $feed->read();

        $this->assertCount(1, $events);
        $this->assertSame('b', $events[0]->type);
    }
}
