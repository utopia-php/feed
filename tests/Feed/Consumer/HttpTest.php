<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Consumer;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Feed\Server;
use Utopia\Feed\Store;
use Utopia\Tests\Support\FeedServer;
use Utopia\Tests\Support\MidPollStore;
use Utopia\Tests\Support\UsesMemory;

/**
 * The same consumer scenarios, joined to the producer by the HTTP contract —
 * the pair this library exists to keep from drifting apart. Every consume goes
 * through the real wire code in both directions ({@see \Utopia\Feed\Batch}
 * encoding, {@see \Utopia\Feed\Remote} decoding) against a {@see FeedServer}
 * serving a real {@see Server} the way an HTTP route would.
 */
class HttpTest extends Base
{
    use UsesMemory;

    protected FeedServer $endpoint;

    protected function source(Store&Appendable $store): Adapter|Readable
    {
        return $this->endpoint = new FeedServer(new Server($store));
    }

    /** The feed belongs to another producer, which mints its own ids. */
    protected function ownsItsIdFormat(): bool
    {
        return false;
    }

    /**
     * http-feeds endpoints commonly use UUIDs, and `Remote` reads them fine —
     * a consumer tracks such a position and sends it back as `lastEventId`
     * without complaint. Judging the shape here as well would take the
     * poison-event escape hatch away from exactly the consumers that have no
     * way around it: the id `seek()` refused would be one the consumer itself
     * had just handled and saved.
     */
    public function testSeekAcceptsTheOpaqueIdARemoteFeedMayUse(): void
    {
        $opaque = '550e8400-e29b-41d4-a716-446655440000';

        $consumer = $this->consumer();
        $consumer->seek($opaque);

        $this->assertSame($opaque, $consumer->position());
        $this->assertSame($opaque, $this->cursor->load($this->name, 'invalidator'), 'And it is persisted, so a restart resumes from it');
    }

    public function testTheProducerCachesFullBatchesAndNothingElse(): void
    {
        foreach (\range(1, 5) as $i) {
            $this->producer->produce('event-' . $i);
        }

        $consumer = $this->consumer(batch: 2);

        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);
        $consumer->consume(fn (CloudEvent $event) => null);

        $this->assertSame([
            'private, max-age=31536000', // 2 of 2 — settled history
            'private, max-age=31536000', // 2 of 2 — settled history
            'no-store',                  // 1 of 2 — the live end, will grow
            'no-store',                  // 0 of 2 — caught up
        ], $this->endpoint->recorder->cacheControl());
    }

    /**
     * The tip sentinel crosses the wire as `lastEventId=$` and the producer
     * resolves it inside the held request; once something is handled the
     * position saves as a real id, so the sentinel never appears again.
     */
    public function testTipStartSendsTheSentinelOnTheWireOnceOnly(): void
    {
        $store = new MidPollStore($this->name);
        $producer = new Producer($store, 'urn:test');
        $producer->produce('old');

        $consumer = $this->consumer('notifier', timeout: 5_000, start: Consumer::START_TIP, store: $store);

        $this->assertSame(['landed'], $this->drain($consumer), 'The backlog is skipped; the mid-wait event is not');
        $this->assertMatchesRegularExpression('/lastEventId=(%24|\$)/', $this->endpoint->recorder->last()['uri']);

        $producer->produce('after');

        $this->assertSame(['after'], $this->drain($consumer));
        $this->assertDoesNotMatchRegularExpression('/lastEventId=(%24|\$)/', $this->endpoint->recorder->last()['uri']);
    }
}
