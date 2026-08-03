<?php

declare(strict_types=1);

namespace Utopia\Tests\Consumer;

use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Appendable;
use Utopia\Feed\Consumer;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Feed\Remote;
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

    /**
     * The feed name is the one thing on the wire only the serving side can
     * check. `Remote` encodes it into the request path, and the consumer's own
     * `feed:` check is client-side — so nothing confirmed end to end that the
     * name reaching the endpoint is the one it holds. A consumer pointed at
     * the wrong feed must fail rather than read the right events by accident.
     */
    public function testAConsumerPointedAtAnotherFeedIsNotServedThisOne(): void
    {
        $this->producer->produce('a');

        $consumer = new Consumer($this->source($this->store), $this->cursor, 'invalidator', feed: $this->name . '-other');

        try {
            $consumer->consume(fn (CloudEvent $event) => null);
            $this->fail('The endpoint holds another feed, so the read should have failed');
        } catch (Transport $error) {
            $this->assertSame(404, $error->getCode());
        }

        $this->assertNull($consumer->position(), 'And nothing was recorded as read');
    }

    /**
     * A name that needs encoding survives the round trip: `Remote` percent-
     * encodes it into one path segment and the endpoint decodes that segment
     * back. Asserting the URI string alone, as the encoding test does, cannot
     * show that anything decodes it to the name the producer knows.
     */
    public function testAFeedNameThatNeedsEncodingStillRoutes(): void
    {
        $store = $this->store('a b/c');
        (new Producer($store, 'urn:test'))->produce('a');

        $consumer = new Consumer($this->source($store), $this->cursor, 'invalidator', feed: 'a b/c');

        $this->assertSame(['a'], $this->drain($consumer));
        $this->assertStringContainsString('a%20b%2Fc', $this->endpoint->recorder->last()['uri']);
    }

    /**
     * The two halves of the media type handshake, checked against each other
     * rather than each against a literal: the `Accept` the consumer sends is
     * the `Content-Type` the producer answers with. Both now alias one
     * constant, so this is what would notice if they stopped.
     */
    public function testTheAcceptSentIsTheContentTypeServed(): void
    {
        $this->producer->produce('a');

        $this->drain($this->consumer());

        $request = $this->endpoint->recorder->last();

        $this->assertSame(Remote::MEDIA_TYPE, $request['headers']['Accept'] ?? null);
        $this->assertSame(Remote::MEDIA_TYPE, $request['contentType'], 'The producer answers with what the consumer asked for');
    }

    /**
     * One event through the real wire code in both directions — `Batch`
     * encoding it in the endpoint, `Remote` decoding it on the way back —
     * with every attribute asserted on the far side.
     *
     * The shared consumer scenarios record only `type`, and the producer and
     * server suites round-trip the rest through the *store*. So an encoder or
     * decoder that dropped `subject`, `source`, `dataschema` or an extension
     * on the wire specifically would pass everything else in the suite.
     */
    public function testAnEventSurvivesTheWireWithEveryAttribute(): void
    {
        $id = $this->producer->publish(new CloudEvent(
            id: '',
            type: 'io.appwrite.edge.invalidate-rule',
            source: 'ignored, the producer stamps its own',
            subject: 'example.com',
            time: '2026-07-31T09:15:02.123Z',
            datacontenttype: 'application/json',
            data: ['tags' => ['domain' => 'example.com'], 'depth' => [1, 2, 3]],
            dataschema: 'https://example.com/schema.json',
            extensions: ['traceparent' => '00-abc-def-01', 'retrycount' => 2, 'replayed' => true],
        ));

        $received = null;
        $this->consumer()->consume(function (CloudEvent $event) use (&$received): void {
            $received = $event;
        });

        $this->assertInstanceOf(CloudEvent::class, $received);
        $this->assertSame($id, $received->id);
        $this->assertSame('1.0', $received->specversion);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $received->type);
        $this->assertSame('urn:test', $received->source);
        $this->assertSame('example.com', $received->subject);
        $this->assertSame('2026-07-31T09:15:02.123Z', $received->time);
        $this->assertSame('application/json', $received->datacontenttype);
        $this->assertSame('https://example.com/schema.json', $received->dataschema);
        $this->assertSame(['tags' => ['domain' => 'example.com'], 'depth' => [1, 2, 3]], $received->data);
        $this->assertSame('00-abc-def-01', $received->extensions['traceparent']);
        $this->assertSame(2, $received->extensions['retrycount']);
        $this->assertTrue($received->extensions['replayed']);
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
