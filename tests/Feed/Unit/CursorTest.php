<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor;
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

    #[DataProvider('unusableNames')]
    public function testTheNoneStoreStillRejectsEmptyNames(string $feed, string $consumer): void
    {
        $this->expectException(Invalid::class);

        (new None())->load($feed, $consumer);
    }

    /**
     * A cache answers a failed write with false rather than raising, so
     * ignoring it reports a position as saved that was never stored.
     */
    public function testACacheThatCannotBeWrittenRaisesTransport(): void
    {
        $cursor = new CacheCursor(new UtopiaCache(new BrokenCache()));

        $this->expectException(Transport::class);

        $cursor->save('edge', 'invalidator', '1-0');
    }

    /**
     * The consequence through the API an operator reaches for: seek() persists
     * immediately, so a failed one must not look like it worked.
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
     * Resetting is not held to the same rule: false also means "was never
     * there", the ordinary case for a consumer with no position yet.
     */
    public function testResettingAPositionThatWasNeverSavedIsHarmless(): void
    {
        $consumer = new Consumer(new MemoryStore('edge'), new CacheCursor(new UtopiaCache(new BrokenCache())), 'invalidator');

        $consumer->reset();

        $this->assertNull($consumer->position());
        $this->assertSame(0, $consumer->consume(fn (CloudEvent $event) => null));
    }

    /**
     * The other way a cache fails: it lets the backend's own error out.
     * Unwrapped, that escapes this library entirely.
     *
     * @param callable(Cursor): void $operation
     */
    #[DataProvider('operations')]
    public function testABackendThatIsDownRaisesTransport(callable $operation): void
    {
        $cursor = new CacheCursor(new UtopiaCache(new BrokenCache(raises: true)));

        $this->expectException(Transport::class);

        $operation($cursor);
    }

    /**
     * @return array<string, array{callable(Cursor): void}>
     */
    public static function operations(): array
    {
        return [
            'load' => [static function (Cursor $cursor): void {
                $cursor->load('edge', 'invalidator');
            }],
            'save' => [static function (Cursor $cursor): void {
                $cursor->save('edge', 'invalidator', '1-0');
            }],
            'reset' => [static function (Cursor $cursor): void {
                $cursor->reset('edge', 'invalidator');
            }],
        ];
    }

    /** An unusable name stays Invalid even when the backend is also down. */
    #[DataProvider('unusableNames')]
    public function testAnUnusableNameIsStillInvalidOnABackendThatIsDown(string $feed, string $consumer): void
    {
        $cursor = new CacheCursor(new UtopiaCache(new BrokenCache(raises: true)));

        $this->expectException(Invalid::class);

        $cursor->load($feed, $consumer);
    }
}
