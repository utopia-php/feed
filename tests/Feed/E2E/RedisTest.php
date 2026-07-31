<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Journal\Redis as RedisJournal;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Redis as RedisCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Batch;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Feed;
use Utopia\Feed\Producer;
use Utopia\Feed\Id;

/**
 * The behaviours that only a real Redis can confirm: that `XADD` ids are
 * ordered the way {@see Id} assumes, that `MAXLEN` trimming leaves a consumer
 * able to resume, and that a position survives the process that recorded it.
 */
class RedisTest extends TestCase
{
    private \Redis $redis;

    private string $name;

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        $this->redis->connect((string) (\getenv('REDIS_HOST') ?: 'redis'), (int) (\getenv('REDIS_PORT') ?: 6379));

        // A fresh feed per test: these assert on positions, and a shared
        // stream would leak them between tests.
        $this->name = 'test-' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->redis->del('feed:' . $this->name);

        foreach ((array) $this->redis->keys('feed:' . $this->name . ':cursor:*') as $key) {
            if (\is_string($key)) {
                $this->redis->del($key);
            }
        }

        $this->redis->close();
    }

    private function feed(int $maxSize = 100_000): Feed
    {
        return new Feed(new RedisJournal($this->redis, $this->name, $maxSize));
    }

    /** @return list<CloudEvent> */
    private static function events(Batch $batch): array
    {
        return \array_values(\iterator_to_array($batch));
    }

    private function producer(int $maxSize = 100_000): Producer
    {
        return new Producer(new RedisJournal($this->redis, $this->name, $maxSize), 'urn:test:e2e');
    }

    /**
     * The two halves of one feed: what a producing service builds over a single
     * journal to append to its feed and serve it.
     *
     * @return array{Producer, Feed}
     */
    private function feedAndProducer(int $maxSize = 100_000): array
    {
        $journal = new RedisJournal($this->redis, $this->name, $maxSize);

        return [new Producer($journal, 'urn:test:e2e'), new Feed($journal)];
    }

    public function testAppendsAndReadsBack(): void
    {
        [$producer, $feed] = $this->feedAndProducer();

        $id = $producer->append('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = self::events($feed->read());

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame('example.com', $events[0]->subject);
        $this->assertSame('urn:test:e2e', $events[0]->source);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
        $this->assertNotNull($events[0]->time);
    }

    public function testStreamIdsMatchTheFormatPositionsAreParsedWith(): void
    {
        $id = $this->producer()->append('test');

        $this->assertTrue(Id::isValid($id), "Redis returned an id this library cannot page from: {$id}");
    }

    public function testIdsIncreaseAcrossRapidAppends(): void
    {
        [$producer, $feed] = $this->feedAndProducer();

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $producer->append('test');
        }

        $this->assertSame($ids, \array_unique($ids));

        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertGreaterThan(Id::decode($ids[$i - 1]), Id::decode($ids[$i]));
        }
    }

    /**
     * The reason positions are advanced arithmetically instead of with Redis'
     * `(`-exclusive range syntax: this has to hold on every server and proxy
     * that speaks the Redis 5 stream API, not only the ones that added it.
     */
    public function testReadsStrictlyAfterAPosition(): void
    {
        [$producer, $feed] = $this->feedAndProducer();

        $first = $producer->append('a');
        $second = $producer->append('b');

        $events = self::events($feed->read($first));

        $this->assertCount(1, $events);
        $this->assertSame($second, $events[0]->id);
        $this->assertCount(0, $feed->read($second));
    }

    public function testExtensionsAndDataschemaSurviveTheRoundTrip(): void
    {
        $this->producer()->publish(new CloudEvent(
            id: '',
            type: 'test',
            source: '',
            dataschema: 'https://example.com/schema.json',
            extensions: ['traceparent' => '00-abc-def-01'],
        ));

        $event = self::events($this->feed()->read())[0];

        $this->assertSame('https://example.com/schema.json', $event->dataschema);
        $this->assertSame('00-abc-def-01', $event->extensions['traceparent']);
    }

    public function testAnAbsentSubjectStaysAbsent(): void
    {
        $this->producer()->append('test');

        $this->assertNull(self::events($this->feed()->read())[0]->subject);
    }

    public function testAScalarPayloadSurvivesTheRoundTrip(): void
    {
        $this->producer()->append('test', 'a string');

        $this->assertSame('a string', self::events($this->feed()->read())[0]->data);
    }

    public function testNestedPayloadsSurviveTheRoundTrip(): void
    {
        $data = [
            'tags' => ['domain' => 'example.com', 'project' => 'p1'],
            'flags' => ['isAppwriteNetwork' => true],
            'count' => 42,
            'unicode' => 'ünïcøde ✓',
        ];

        $this->producer()->append('test', $data);

        $this->assertSame($data, self::events($this->feed()->read())[0]->data);
    }

    public function testHonoursTheLimit(): void
    {
        [$producer, $feed] = $this->feedAndProducer();

        foreach (\range(1, 10) as $i) {
            $producer->append('test');
        }

        $this->assertCount(3, $feed->read(null, 3));
    }

    public function testRejectsAPositionThatIsNotAFeedId(): void
    {
        $this->expectException(Invalid::class);

        $this->feed()->read('not-a-position');
    }

    /**
     * Trimming is approximate, so this asserts the property a consumer relies
     * on — that a position below the horizon still reads — rather than an
     * exact retained count.
     */
    public function testAPositionBelowTheTrimHorizonReadsWhatIsLeft(): void
    {
        [$producer, $feed] = $this->feedAndProducer(maxSize: 10);

        $first = $producer->append('first');

        foreach (\range(1, 500) as $i) {
            $producer->append('event-' . $i);
        }

        $events = $feed->read($first);

        $this->assertFalse($events->isEmpty(), 'A consumer that fell behind must still get what is retained');
        $this->assertLessThan(500, $this->redis->xLen('feed:' . $this->name), 'The feed must be trimmed');
    }

    public function testLongPollingReturnsAsSoonAsTheFeedHasSomething(): void
    {
        [$producer, $feed] = $this->feedAndProducer();
        $producer->append('a');

        $started = \microtime(true);
        $events = $feed->poll(null, 10, 3000);

        $this->assertCount(1, $events);
        $this->assertLessThan(1, \microtime(true) - $started);
    }

    public function testLongPollingGivesUpAtTheTimeout(): void
    {
        $started = \microtime(true);
        $events = $this->feed()->poll(null, 10, 700);

        $this->assertCount(0, $events);
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started);
    }

    public function testConsumesThroughAPersistedCursor(): void
    {
        [$producer, $feed] = $this->feedAndProducer();
        $cursor = new RedisCursor($this->redis);

        $producer->append('a');
        $last = $producer->append('b');

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(2, (new Consumer($feed, 'invalidator', $cursor))->consume($handler));
        $this->assertSame($last, $cursor->load($this->name, 'invalidator'));

        // A second Consumer stands in for a restart: it has no in-memory
        // position, so it has to pick the stored one up to avoid replaying.
        $this->assertSame(0, (new Consumer($feed, 'invalidator', $cursor))->consume($handler));
        $this->assertSame(['a', 'b'], $seen);
    }

    public function testASecondConsumerOfTheSameFeedGetsItsOwnPosition(): void
    {
        [$producer, $feed] = $this->feedAndProducer();
        $cursor = new RedisCursor($this->redis);

        $producer->append('a');

        $this->assertSame(1, (new Consumer($feed, 'one', $cursor))->consume(fn (CloudEvent $e) => null));
        $this->assertSame(1, (new Consumer($feed, 'two', $cursor))->consume(fn (CloudEvent $e) => null));
    }

    public function testResetReplaysTheRetainedFeed(): void
    {
        [$producer, $feed] = $this->feedAndProducer();
        $cursor = new RedisCursor($this->redis);

        $producer->append('a');
        $producer->append('b');

        $consumer = new Consumer($feed, 'invalidator', $cursor);
        $consumer->consume(fn (CloudEvent $e) => null);
        $consumer->reset();

        $this->assertNull($cursor->load($this->name, 'invalidator'));
        $this->assertSame(2, (new Consumer($feed, 'invalidator', $cursor))->consume(fn (CloudEvent $e) => null));
    }

    public function testCursorsAreStoredUnderTheFeedTheyBelongTo(): void
    {
        (new RedisCursor($this->redis))->save($this->name, 'invalidator', '1-0');

        $this->assertSame('1-0', $this->redis->get('feed:' . $this->name . ':cursor:invalidator'));
    }
}
