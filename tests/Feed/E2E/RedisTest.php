<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Journal\Redis as RedisJournal;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Redis as RedisCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Feed;
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
        return new Feed(new RedisJournal($this->redis, $this->name, $maxSize), 'urn:test:e2e');
    }

    public function testAppendsAndReadsBack(): void
    {
        $feed = $this->feed();

        $id = $feed->append('io.appwrite.edge.invalidate-rule', ['tags' => ['domain' => 'example.com']], 'example.com');

        $events = $feed->read();

        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $events[0]->type);
        $this->assertSame('example.com', $events[0]->subject);
        $this->assertSame('urn:test:e2e', $events[0]->source);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
        $this->assertNotSame('', $events[0]->time);
    }

    public function testStreamIdsMatchTheFormatPositionsAreParsedWith(): void
    {
        $id = $this->feed()->append('test');

        $this->assertTrue(Id::isValid($id), "Redis returned an id this library cannot page from: {$id}");
    }

    public function testIdsIncreaseAcrossRapidAppends(): void
    {
        $feed = $this->feed();

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $feed->append('test');
        }

        $this->assertSame($ids, \array_unique($ids));

        for ($i = 1; $i < \count($ids); $i++) {
            $this->assertSame(1, Id::compare($ids[$i], $ids[$i - 1]));
        }
    }

    /**
     * The reason positions are advanced arithmetically instead of with Redis'
     * `(`-exclusive range syntax: this has to hold on every server and proxy
     * that speaks the Redis 5 stream API, not only the ones that added it.
     */
    public function testReadsStrictlyAfterAPosition(): void
    {
        $feed = $this->feed();

        $first = $feed->append('a');
        $second = $feed->append('b');

        $events = $feed->read($first);

        $this->assertCount(1, $events);
        $this->assertSame($second, $events[0]->id);
        $this->assertSame([], $feed->read($second));
    }

    public function testExtensionsAndDataschemaSurviveTheRoundTrip(): void
    {
        $this->feed()->publish(new CloudEvent(
            id: '',
            type: 'test',
            dataschema: 'https://example.com/schema.json',
            extensions: ['traceparent' => '00-abc-def-01'],
        ));

        $event = $this->feed()->read()[0];

        $this->assertSame('https://example.com/schema.json', $event->dataschema);
        $this->assertSame('00-abc-def-01', $event->getExtension('traceparent'));
    }

    public function testAnAbsentSubjectStaysAbsent(): void
    {
        $this->feed()->append('test');

        $this->assertNull($this->feed()->read()[0]->subject);
    }

    public function testAScalarPayloadSurvivesTheRoundTrip(): void
    {
        $this->feed()->append('test', 'a string');

        $this->assertSame('a string', $this->feed()->read()[0]->data);
    }

    public function testNestedPayloadsSurviveTheRoundTrip(): void
    {
        $data = [
            'tags' => ['domain' => 'example.com', 'project' => 'p1'],
            'flags' => ['isAppwriteNetwork' => true],
            'count' => 42,
            'unicode' => 'ünïcøde ✓',
        ];

        $this->feed()->append('test', $data);

        $this->assertSame($data, $this->feed()->read()[0]->data);
    }

    public function testHonoursTheLimit(): void
    {
        $feed = $this->feed();

        foreach (\range(1, 10) as $i) {
            $feed->append('test');
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
        $feed = $this->feed(maxSize: 10);

        $first = $feed->append('first');

        foreach (\range(1, 500) as $i) {
            $feed->append('event-' . $i);
        }

        $events = $feed->read($first);

        $this->assertNotEmpty($events, 'A consumer that fell behind must still get what is retained');
        $this->assertLessThan(500, $this->redis->xLen('feed:' . $this->name), 'The feed must be trimmed');
    }

    public function testLongPollingReturnsAsSoonAsTheFeedHasSomething(): void
    {
        $feed = $this->feed();
        $feed->append('a');

        $started = \microtime(true);
        $events = $feed->poll(null, 10, 3000);

        $this->assertCount(1, $events);
        $this->assertLessThan(1, \microtime(true) - $started);
    }

    public function testLongPollingGivesUpAtTheTimeout(): void
    {
        $started = \microtime(true);
        $events = $this->feed()->poll(null, 10, 700);

        $this->assertSame([], $events);
        $this->assertGreaterThanOrEqual(0.4, \microtime(true) - $started);
    }

    public function testConsumesThroughAPersistedCursor(): void
    {
        $feed = $this->feed();
        $cursor = new RedisCursor($this->redis, $this->name);

        $feed->append('a');
        $last = $feed->append('b');

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(2, (new Consumer($feed, 'invalidator', $cursor))->consume($handler));
        $this->assertSame($last, $cursor->load('invalidator'));

        // A second Consumer stands in for a restart: it has no in-memory
        // position, so it has to pick the stored one up to avoid replaying.
        $this->assertSame(0, (new Consumer($feed, 'invalidator', $cursor))->consume($handler));
        $this->assertSame(['a', 'b'], $seen);
    }

    public function testASecondConsumerOfTheSameFeedGetsItsOwnPosition(): void
    {
        $feed = $this->feed();
        $cursor = new RedisCursor($this->redis, $this->name);

        $feed->append('a');

        $this->assertSame(1, (new Consumer($feed, 'one', $cursor))->consume(fn (CloudEvent $e) => null));
        $this->assertSame(1, (new Consumer($feed, 'two', $cursor))->consume(fn (CloudEvent $e) => null));
    }

    public function testResetReplaysTheRetainedFeed(): void
    {
        $feed = $this->feed();
        $cursor = new RedisCursor($this->redis, $this->name);

        $feed->append('a');
        $feed->append('b');

        $consumer = new Consumer($feed, 'invalidator', $cursor);
        $consumer->consume(fn (CloudEvent $e) => null);
        $consumer->reset();

        $this->assertNull($cursor->load('invalidator'));
        $this->assertSame(2, (new Consumer($feed, 'invalidator', $cursor))->consume(fn (CloudEvent $e) => null));
    }

    /**
     * The rolling-restart case, against a real store: the departing process
     * finishing a shorter batch must not undo the arriving one's progress.
     *
     * Here Redis enforces it rather than this library — the stale `XADD` is
     * refused by the same operation that would have written it, so there is no
     * window between the check and the write for a second process to slip into.
     */
    public function testAPositionNeverMovesBackwards(): void
    {
        $cursor = new RedisCursor($this->redis, $this->name);

        $cursor->save('invalidator', '1690000000000-5');
        $cursor->save('invalidator', '1690000000000-2');

        $this->assertSame('1690000000000-5', $cursor->load('invalidator'));

        $cursor->save('invalidator', '1690000000001-0');

        $this->assertSame('1690000000001-0', $cursor->load('invalidator'), 'A genuine advance still lands');
    }

    /**
     * `10-0` is later than `9-0` but sorts before it as a string, so a
     * comparison on the text would refuse a legitimate advance and stall the
     * consumer permanently. Redis compares the parts.
     */
    public function testAdvancingAcrossADigitBoundaryIsAccepted(): void
    {
        $cursor = new RedisCursor($this->redis, $this->name);

        $cursor->save('invalidator', '9-0');
        $cursor->save('invalidator', '10-0');

        $this->assertSame('10-0', $cursor->load('invalidator'));
    }

    public function testRewritingTheSamePositionIsAccepted(): void
    {
        $cursor = new RedisCursor($this->redis, $this->name);

        $cursor->save('invalidator', '5-0');
        $cursor->save('invalidator', '5-0');

        $this->assertSame('5-0', $cursor->load('invalidator'));
    }

    /**
     * The position is the entry's id, and the stream never grows past it.
     */
    public function testAPositionIsStoredAsAOneEntryStream(): void
    {
        $cursor = new RedisCursor($this->redis, $this->name);
        $key = 'feed:' . $this->name . ':cursor:invalidator';

        foreach (['1-0', '2-0', '3-0'] as $position) {
            $cursor->save('invalidator', $position);
        }

        $this->assertSame(\Redis::REDIS_STREAM, $this->redis->type($key));
        $this->assertSame(1, $this->redis->xLen($key), 'MAXLEN 1 keeps only the current position');
    }

    /**
     * An earlier version stored these as plain strings. Reading has to find one
     * where it is, and the next save has to replace it in place — otherwise a
     * deployment upgrading this library would either hit WRONGTYPE forever or
     * silently restart from the oldest retained event.
     */
    public function testUpgradesAPositionWrittenAsAPlainString(): void
    {
        $cursor = new RedisCursor($this->redis, $this->name);
        $key = 'feed:' . $this->name . ':cursor:invalidator';

        $this->redis->set($key, '1690000000000-0');

        $this->assertSame('1690000000000-0', $cursor->load('invalidator'), 'The old position is still readable');

        $cursor->save('invalidator', '1690000000000-1');

        $this->assertSame(\Redis::REDIS_STREAM, $this->redis->type($key), 'The key is converted on the next save');
        $this->assertSame('1690000000000-1', $cursor->load('invalidator'), 'No position is lost in the upgrade');
    }

    /**
     * A stream id is the storage, so anything that is not one is refused with a
     * clear message rather than surfacing as a Redis parse error.
     */
    public function testRejectsACursorPositionThatIsNotAFeedId(): void
    {
        $this->expectException(Invalid::class);

        (new RedisCursor($this->redis, $this->name))->save('invalidator', 'not-a-position');
    }

    public function testCursorsAreStoredUnderTheFeedTheyBelongTo(): void
    {
        (new RedisCursor($this->redis, $this->name))->save('invalidator', '1-0');

        // The position is the entry's id, so this reads the stream rather than
        // the key's value.
        $entries = $this->redis->xRevRange('feed:' . $this->name . ':cursor:invalidator', '+', '-', 1);

        $this->assertIsArray($entries);
        $this->assertSame('1-0', \array_key_first($entries));
    }
}
