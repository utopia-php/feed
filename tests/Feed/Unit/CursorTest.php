<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Cache as CacheCursor;
use Utopia\Feed\Cursor\None;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Producer;
use Utopia\Feed\Store\Memory as MemoryStore;
use Utopia\Tests\Support\BrokenCache;

/**
 * The cursor behaviours a working adapter cannot show: the store that
 * deliberately remembers nothing, and a backend that cannot be written.
 * Everything a real adapter can exercise lives in
 * {@see \Utopia\Tests\Consumer\Base} instead.
 */
class CursorTest extends TestCase
{
    public function testTheNoneStoreRemembersNothing(): void
    {
        $cursor = new None();

        $cursor->save('edge', 'invalidator', '1-0');

        $this->assertNull($cursor->load('edge', 'invalidator'), 'Nothing is stored, so nothing comes back');
    }

    public function testResettingTheNoneStoreIsHarmless(): void
    {
        $cursor = new None();

        $cursor->reset('edge', 'invalidator');

        $this->assertNull($cursor->load('edge', 'invalidator'));
    }

    /**
     * A stand-in that accepted names a real store rejects would let a bug
     * through in development and surface it in production instead.
     *
     * @return array<string, array{string, string}>
     */
    public static function unusableNames(): array
    {
        return [
            'no feed' => ['', 'invalidator'],
            'no consumer' => ['edge', ''],
        ];
    }

    /**
     * @dataProvider unusableNames
     */
    public function testTheNoneStoreStillRejectsEmptyNames(string $feed, string $consumer): void
    {
        $this->expectException(Invalid::class);

        (new None())->load($feed, $consumer);
    }

    /**
     * A cache adapter answers a failed write with false rather than raising,
     * so a cursor that ignores the return value reports a position as saved
     * that was never stored — and the consumer replays its whole retained
     * backlog on the next restart with nothing to explain why.
     */
    public function testACacheThatCannotBeWrittenRaisesTransport(): void
    {
        $cursor = new CacheCursor(new UtopiaCache(new BrokenCache()));

        $this->expectException(Transport::class);

        $cursor->save('edge', 'invalidator', '1-0');
    }

    /**
     * The operational consequence, through the API an operator actually
     * reaches for: seek() is documented as persisting immediately, so a store
     * that cannot hold the new position must not let the operator believe
     * they stepped past a poison event.
     */
    public function testASeekThroughAnUnwritableCacheFailsLoudly(): void
    {
        $store = new MemoryStore('edge');
        $poison = (new Producer($store, 'urn:test'))->produce('poison');

        $consumer = new Consumer($store, new CacheCursor(new UtopiaCache(new BrokenCache())), 'invalidator');

        try {
            $consumer->seek($poison);
            $this->fail('The store failure should have been raised');
        } catch (Transport) {
            // Expected.
        }

        $this->assertNull($consumer->position(), 'The in-memory position must not move on a failed seek');
    }

    /**
     * Resetting is not held to the same rule: an adapter answers false both
     * for a write it could not do and for a key that was never there, and the
     * second is the ordinary case — a consumer that has not saved a position
     * yet. Only a raising backend is a failure to report.
     */
    public function testResettingAPositionThatWasNeverSavedIsHarmless(): void
    {
        $consumer = new Consumer(new MemoryStore('edge'), new CacheCursor(new UtopiaCache(new BrokenCache())), 'invalidator');

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }
}
