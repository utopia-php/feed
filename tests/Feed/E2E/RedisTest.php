<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Store\Redis as RedisStore;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Redis as RedisCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Batch;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Server;
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

    private function server(int $maxSize = 100_000): Server
    {
        return new Server(new RedisStore($this->redis, $this->name, $maxSize));
    }

    /** @return list<CloudEvent> */
    private static function events(Batch $batch): array
    {
        return \array_values(\iterator_to_array($batch));
    }

    private function producer(int $maxSize = 100_000): Producer
    {
        return new Producer(new RedisStore($this->redis, $this->name, $maxSize), 'urn:test:e2e');
    }

    /**
     * The two halves of one feed: what a producing service builds over a single
     * store to produce into its feed and serve it.
     *
     * @return array{Producer, Server}
     */
    private function serverAndProducer(int $maxSize = 100_000): array
    {
        $store = new RedisStore($this->redis, $this->name, $maxSize);

        return [new Producer($store, 'urn:test:e2e'), new Server($store)];
    }

    public function testAppendsAndReadsBack(): void
    {
        [$producer, $server] = $this->serverAndProducer();

        $id = $producer->produce('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = self::events($server->read());

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
        $id = $this->producer()->produce('test');

        $this->assertTrue(Id::isValid($id), "Redis returned an id this library cannot page from: {$id}");
    }

    public function testIdsIncreaseAcrossRapidAppends(): void
    {
        [$producer, $server] = $this->serverAndProducer();

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $producer->produce('test');
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
        [$producer, $server] = $this->serverAndProducer();

        $first = $producer->produce('a');
        $second = $producer->produce('b');

        $events = self::events($server->read($first));

        $this->assertCount(1, $events);
        $this->assertSame($second, $events[0]->id);
        $this->assertCount(0, $server->read($second));
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

        $event = self::events($this->server()->read())[0];

        $this->assertSame('https://example.com/schema.json', $event->dataschema);
        $this->assertSame('00-abc-def-01', $event->extensions['traceparent']);
    }

    public function testAnAbsentSubjectStaysAbsent(): void
    {
        $this->producer()->produce('test');

        $this->assertNull(self::events($this->server()->read())[0]->subject);
    }

    public function testAScalarPayloadSurvivesTheRoundTrip(): void
    {
        $this->producer()->produce('test', 'a string');

        $this->assertSame('a string', self::events($this->server()->read())[0]->data);
    }

    public function testNestedPayloadsSurviveTheRoundTrip(): void
    {
        $data = [
            'tags' => ['domain' => 'example.com', 'project' => 'p1'],
            'flags' => ['isAppwriteNetwork' => true],
            'count' => 42,
            'unicode' => 'ünïcøde ✓',
        ];

        $this->producer()->produce('test', $data);

        $this->assertSame($data, self::events($this->server()->read())[0]->data);
    }

    public function testHonoursTheLimit(): void
    {
        [$producer, $server] = $this->serverAndProducer();

        foreach (\range(1, 10) as $i) {
            $producer->produce('test');
        }

        $this->assertCount(3, $server->read(null, 3));
    }

    public function testRejectsAPositionThatIsNotAFeedId(): void
    {
        $this->expectException(Invalid::class);

        $this->server()->read('not-a-position');
    }

    /**
     * Trimming is approximate, so this asserts the property a consumer relies
     * on — that a position below the horizon still reads — rather than an
     * exact retained count.
     */
    public function testAPositionBelowTheTrimHorizonReadsWhatIsLeft(): void
    {
        [$producer, $server] = $this->serverAndProducer(maxSize: 10);

        $first = $producer->produce('first');

        foreach (\range(1, 500) as $i) {
            $producer->produce('event-' . $i);
        }

        $events = $server->read($first);

        $this->assertFalse($events->isEmpty(), 'A consumer that fell behind must still get what is retained');
        $this->assertLessThan(500, $this->redis->xLen('feed:' . $this->name), 'The feed must be trimmed');
    }

    public function testLongPollingReturnsAsSoonAsTheFeedHasSomething(): void
    {
        [$producer, $server] = $this->serverAndProducer();
        $producer->produce('a');

        $started = \microtime(true);
        $events = $server->poll(null, 10, 3000);

        $this->assertCount(1, $events);
        $this->assertLessThan(1, \microtime(true) - $started);
    }

    public function testLongPollingGivesUpAtTheTimeout(): void
    {
        $started = \microtime(true);
        $events = $this->server()->poll(null, 10, 700);

        $this->assertCount(0, $events);
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started);
    }

    /**
     * The tip is found with XREVRANGE, so this needs a real Redis: an empty
     * stream has no tip, and the sentinel reads nothing that already exists.
     */
    public function testTheTipSentinelSkipsTheBacklog(): void
    {
        [$producer, $server] = $this->serverAndProducer();

        $this->assertNull($server->tip(), 'An empty feed has no tip');

        $producer->produce('a');
        $last = $producer->produce('b');

        $this->assertSame($last, $server->tip());
        $this->assertCount(0, $server->read('$'));
    }

    public function testConsumesThroughAPersistedCursor(): void
    {
        $store = new RedisStore($this->redis, $this->name);
        $producer = new Producer($store, 'urn:test:e2e');
        $cursor = new RedisCursor($this->redis);

        $producer->produce('a');
        $last = $producer->produce('b');

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(2, (new Consumer($store, $cursor, 'invalidator'))->consume($handler));
        $this->assertSame($last, $cursor->load($this->name, 'invalidator'));

        // A second Consumer stands in for a restart: it has no in-memory
        // position, so it has to pick the stored one up to avoid replaying.
        $this->assertSame(0, (new Consumer($store, $cursor, 'invalidator'))->consume($handler));
        $this->assertSame(['a', 'b'], $seen);
    }

    public function testASecondConsumerOfTheSameFeedGetsItsOwnPosition(): void
    {
        $store = new RedisStore($this->redis, $this->name);
        $producer = new Producer($store, 'urn:test:e2e');
        $cursor = new RedisCursor($this->redis);

        $producer->produce('a');

        $this->assertSame(1, (new Consumer($store, $cursor, 'one'))->consume(fn (CloudEvent $e) => null));
        $this->assertSame(1, (new Consumer($store, $cursor, 'two'))->consume(fn (CloudEvent $e) => null));
    }

    public function testResetReplaysTheRetainedFeed(): void
    {
        $store = new RedisStore($this->redis, $this->name);
        $producer = new Producer($store, 'urn:test:e2e');
        $cursor = new RedisCursor($this->redis);

        $producer->produce('a');
        $producer->produce('b');

        $consumer = new Consumer($store, $cursor, 'invalidator');
        $consumer->consume(fn (CloudEvent $e) => null);
        $consumer->reset();

        $this->assertNull($cursor->load($this->name, 'invalidator'));
        $this->assertSame(2, (new Consumer($store, $cursor, 'invalidator'))->consume(fn (CloudEvent $e) => null));
    }

    public function testCursorsAreStoredUnderTheFeedTheyBelongTo(): void
    {
        (new RedisCursor($this->redis))->save($this->name, 'invalidator', '1-0');

        $this->assertSame('1-0', $this->redis->get('feed:' . $this->name . ':cursor:invalidator'));
    }
}
