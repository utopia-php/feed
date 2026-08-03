<?php

declare(strict_types=1);

namespace Utopia\Tests\Producer;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Key;
use Utopia\Feed\Producer;
use Utopia\Feed\Store;
use Utopia\Feed\Store\Redis as RedisStore;
use Utopia\Tests\Support\UsesRedis;

class RedisTest extends Base
{
    use UsesRedis;

    /**
     * The wire format other tools rely on: `GET`-able keys named after the
     * feed, holding a plain Redis stream — which is what lets an operator
     * answer "what is in this feed?" from a shell.
     */
    public function testEventsLiveInAStreamUnderTheFeedsKey(): void
    {
        $this->producer->produce('a');
        $this->producer->produce('b');

        $this->assertSame(2, $this->redis()->xLen('feed:' . $this->name));
    }

    /**
     * Feeds and cursors share one Redis keyspace, and both keys are built by
     * joining names with `:`. Joined raw, a feed named `<feed>:cursor:x` takes
     * the key consumer `x`'s position on `<feed>` occupies, so a cursor `SET`
     * lands on a stream — a WRONGTYPE at best, and at worst one silently
     * destroying the other.
     */
    public function testAFeedNamedLikeACursorKeyDoesNotCollideWithOne(): void
    {
        $store = $this->store($this->name . ':cursor:x');
        (new Producer($store, 'urn:test'))->produce('a');

        $cursor = $this->cursor();
        $cursor->save($this->name, 'x', '1-0');

        $this->assertSame('1-0', $cursor->load($this->name, 'x'), 'The position is readable back');
        $this->assertCount(1, $store->read(null, 10), 'And the feed still holds its event');
    }

    /** Trimming must happen on the server, not only in what read() returns. */
    public function testTheStreamItselfIsTrimmed(): void
    {
        $store = $this->store($this->name, maxSize: 10);
        $producer = new Producer($store, 'urn:test');

        foreach (\range(1, 300) as $i) {
            $producer->produce('event-' . $i);
        }

        $this->assertLessThan(300, $this->redis()->xLen('feed:' . $this->name), 'The stream must be trimmed');
    }

    /**
     * A stream is a shared, writable thing: another tool can `XADD` into a
     * feed, and this library's own producer cannot be the only writer assumed.
     * An entry carrying an attribute a CloudEvent cannot hold must therefore
     * decode without it, exactly as the same event would arriving over HTTP.
     *
     * The failure this prevents is the worst shape a feed has: a read decodes
     * every entry in the batch, so one poisoned entry would fail every read
     * past it — permanently, for every consumer, until it fell off the trim
     * horizon.
     */
    public function testAForeignWritersUnusableExtensionIsDroppedRatherThanWedgingTheFeed(): void
    {
        $this->producer->produce('a');

        $this->redis()->xAdd(Key::feed($this->name), '*', [
            'type' => 'foreign',
            'source' => 'urn:somebody:else',
            'subject' => '',
            'datacontenttype' => '',
            'dataschema' => '',
            'time' => '',
            'data' => '{"ok":true}',
            'extensions' => '{"ratio":1.5,"trace":"abc"}',
        ]);

        $this->producer->produce('c');

        $events = $this->store->read(null, 10);

        $this->assertSame(['a', 'foreign', 'c'], \array_map(fn (CloudEvent $e): string => $e->type, $events), 'Nothing behind it is lost');
        $this->assertSame(['trace' => 'abc'], $events[1]->extensions, 'Only the attribute it could not hold is gone');
    }

    /**
     * The README promises `Transport` when "the backend or network failed:
     * Redis errors, HTTP failures". The HTTP half of that promise is tested
     * thoroughly; the Redis half — the flagship production adapter — was not
     * tested at all, so a regression letting a raw `\RedisException` out would
     * have shipped green and crashed every consumer catching
     * `Utopia\Feed\Exception` per the README.
     *
     * @param callable(Store&Appendable): void $operation
     */
    #[DataProvider('operations')]
    public function testABackendThatCannotBeReachedRaisesTransport(callable $operation): void
    {
        $store = new RedisStore(self::unreachableRedis(), $this->name);

        $this->expectException(Transport::class);

        $operation($store);
    }

    /**
     * @return array<string, array{callable(Store&Appendable): void}>
     */
    public static function operations(): array
    {
        return [
            'read' => [static function (Store&Appendable $store): void {
                $store->read(null, 10);
            }],
            'tip' => [static function (Store&Appendable $store): void {
                $store->tip();
            }],
            'append' => [static function (Store&Appendable $store): void {
                $store->append(new CloudEvent(id: '', type: 'test', source: 'urn:test'));
            }],
        ];
    }

    /**
     * A foreign value under the feed's key — someone else's key collision, or
     * a leftover from another tool — makes Redis answer every stream command
     * with an error rather than raising. Appending must not report a position
     * for an event that is not in the feed, so the reply is checked rather
     * than trusted.
     */
    public function testAppendingOverAForeignValueRaisesTransport(): void
    {
        $this->redis()->set(Key::feed($this->name), 'not a stream');

        $this->expectException(Transport::class);

        $this->store->append(new CloudEvent(id: '', type: 'test', source: 'urn:test'));
    }

    /**
     * Reading past the same value is the opposite call: a feed nobody can read
     * is an empty feed — a replay at worst — and failing the read instead
     * would stall every consumer of it. Same policy the cache store applies to
     * a foreign value under its key.
     */
    public function testReadingPastAForeignValueIsAnEmptyFeed(): void
    {
        $this->redis()->set(Key::feed($this->name), 'not a stream');

        $this->assertSame([], $this->store->read(null, 10));
        $this->assertNull($this->store->tip());
    }
}
