<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client;
use Utopia\Feed\Consumer;
use Utopia\Feed\Cursor\Memory as MemoryCursor;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Exception\Unsupported;
use Utopia\Feed\Batch;
use Utopia\Feed\Producer;
use Utopia\Feed\Readable;
use Utopia\Feed\Remote;
use Utopia\Feed\Server;
use Utopia\Tests\Support\FakeTransport;
use Utopia\Tests\Support\FeedServer;
use Utopia\Tests\Support\MidPollStore;

class RemoteTest extends TestCase
{
    /**
     * @param list<ResponseInterface|\Throwable> $responses
     * @return array{Remote, FakeTransport}
     */
    private function remote(array $responses = []): array
    {
        $transport = FakeTransport::of($responses);

        return [new Remote($transport, 'edge'), $transport];
    }

    /**
     * A response body as a producer would put it on the wire.
     *
     * @param list<CloudEvent> $events
     * @return list<array<array-key, mixed>>
     */
    private static function batch(array $events): array
    {
        return (new Batch($events, \count($events)))->toArray();
    }

    public function testReadsAFeedOverHttp(): void
    {
        [$remote] = $this->remote([FakeTransport::json(self::batch([
            new CloudEvent(id: '1-0', type: 'io.appwrite.edge.invalidate-rule', source: 'urn:test', data: ['tags' => ['domain' => 'example.com']]),
            new CloudEvent(id: '1-1', type: 'io.appwrite.edge.invalidate', source: 'urn:test'),
        ]))]);

        $events = $remote->read();

        $this->assertCount(2, $events);
        $this->assertSame('1-0', $events[0]->id);
        $this->assertSame(['tags' => ['domain' => 'example.com']], $events[0]->data);
    }

    /**
     * The endpoint lives on the client — the feed asks for its name as a
     * relative path and the client resolves it against its base URI.
     */
    public function testResolvesTheFeedNameAgainstTheClientsBaseUri(): void
    {
        $transport = FakeTransport::of([]);
        $client = (new Client($transport))->withBaseUri('https://cloud.example.com/v1/feeds');

        (new Remote($client, 'edge'))->read();

        $this->assertStringStartsWith('https://cloud.example.com/v1/feeds/edge', $transport->recorder->last()['uri']);
    }

    public function testEncodesAFeedNameThatNeedsIt(): void
    {
        $transport = FakeTransport::of([]);
        $client = (new Client($transport))->withBaseUri('https://cloud.example.com/v1/feeds/');

        (new Remote($client, 'a b/c'))->read();

        $this->assertStringStartsWith(
            'https://cloud.example.com/v1/feeds/a%20b%2Fc',
            $transport->recorder->last()['uri'],
        );
    }

    public function testRejectsAnEmptyFeedName(): void
    {
        $this->expectException(Invalid::class);

        new Remote(FakeTransport::of([]), '');
    }

    public function testExposesTheFeedItReads(): void
    {
        [$remote] = $this->remote();

        $this->assertSame('edge', $remote->getName());
    }

    /**
     * The tip is the producer's to resolve — a consumer starting at the tip
     * sends the sentinel instead of asking for the newest id first.
     */
    public function testARemoteFeedHasNoLocalTip(): void
    {
        [$remote] = $this->remote();

        $this->expectException(Unsupported::class);

        $remote->tip();
    }

    public function testReadsWithGet(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read();

        $this->assertSame('GET', $transport->recorder->last()['method']);
    }

    public function testAsksForTheFeedMediaType(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read();

        $this->assertSame(Remote::MEDIA_TYPE, $transport->recorder->last()['headers']['Accept'] ?? null);
        $this->assertSame('application/cloudevents-batch+json', Remote::MEDIA_TYPE);
    }

    public function testSendsThePositionAndLimit(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read('1-0', 250);

        $uri = $transport->recorder->last()['uri'];

        $this->assertStringContainsString('lastEventId=1-0', $uri);
        $this->assertStringContainsString('limit=250', $uri);
    }

    public function testSendsNoPositionOnAFirstFullRead(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read(null, Readable::MAX_BATCH);

        $this->assertStringNotContainsString('lastEventId', $transport->recorder->last()['uri']);
    }

    /**
     * The producer does the waiting, so a poll is one request rather than a
     * client-side loop.
     */
    public function testDelegatesLongPollingToTheProducer(): void
    {
        [$remote, $transport] = $this->remote();

        $started = \microtime(true);
        $remote->poll(null, 100, 5000);

        // The request count is the real check; the clock is bounded by the 5s
        // a client-side loop would take, not by the ~0s this takes.
        $this->assertLessThan(1, \microtime(true) - $started, 'Must not wait client-side');
        $this->assertCount(1, $transport->recorder->requests, 'Must not poll in a loop');
        $this->assertStringContainsString('timeout=5000', $transport->recorder->last()['uri']);
    }

    /**
     * Without the margin the client's deadline races the producer's, and a
     * poll that correctly waits out its timeout surfaces as a failure on every
     * quiet tick.
     */
    public function testAllowsTheClientLongerThanTheLongPollTimeout(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->poll(null, 100, 5000);

        // Seconds, which is what the client takes; the margin is in
        // milliseconds, like the timeout the producer is given.
        $this->assertSame(15.0, $transport->recorder->last()['timeout']);
    }

    public function testLeavesTheConfiguredTimeoutAloneWhenNotLongPolling(): void
    {
        [$remote, $transport] = $this->remote();

        $remote->read();

        $this->assertNull($transport->recorder->last()['timeout'], 'A plain read must not override the client');
    }

    /**
     * The status is carried on the exception so a consumer can tell a producer
     * that does not serve the feed yet — normal during a staged rollout — from
     * one that is broken.
     */
    public function testCarriesTheStatusOfARejectedRead(): void
    {
        [$remote] = $this->remote([FakeTransport::json([], 404)]);

        try {
            $remote->read();
            $this->fail('A 404 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(404, $error->getCode());
        }
    }

    public function testRaisesServerErrors(): void
    {
        [$remote] = $this->remote([FakeTransport::json([], 503)]);

        try {
            $remote->read();
            $this->fail('A 503 should have been raised');
        } catch (Transport $error) {
            $this->assertSame(503, $error->getCode());
        }
    }

    /**
     * PSR-18 returns 4xx and 5xx rather than throwing, so the transport has to
     * check the status itself — a producer error must not read as an empty
     * batch, which the consumer would take for "caught up".
     */
    public function testAnErrorStatusIsNotMistakenForAnEmptyBatch(): void
    {
        [$remote] = $this->remote([FakeTransport::json([], 500)]);

        $this->expectException(Transport::class);

        $remote->read();
    }

    public function testWrapsATransportFailure(): void
    {
        [$remote] = $this->remote([FakeTransport::offline()]);

        $this->expectException(Transport::class);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $remote->read();
    }

    public function testWrapsABodyThatIsNotJson(): void
    {
        [$remote] = $this->remote([FakeTransport::raw('<html>502 Bad Gateway</html>')]);

        $this->expectException(Transport::class);

        $remote->read();
    }

    public function testRejectsABodyThatIsNotABatch(): void
    {
        [$remote] = $this->remote([FakeTransport::raw('"a string"')]);

        $this->expectException(Invalid::class);

        $remote->read();
    }

    /**
     * An empty batch means "you are caught up". A JSON object means "you did
     * not reach the feed" — a misrouted request, a proxy's JSON error page, an
     * endpoint that moved. Reading one as an empty batch would leave a
     * consumer sitting quietly at a position that never advances again.
     *
     * @param array<array-key, mixed> $payload
     */
    #[DataProvider('notBatches')]
    public function testARespondingEndpointThatIsNotAFeedIsNotMistakenForBeingCaughtUp(array $payload): void
    {
        [$remote] = $this->remote([FakeTransport::json($payload)]);

        $this->expectException(Invalid::class);

        $remote->read();
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function notBatches(): array
    {
        return [
            'the old envelope' => [['total' => 0, 'events' => []]],
            'some other API' => [['data' => [], 'status' => 'ok']],
            'an error body' => [['message' => 'Not found', 'code' => 404]],
        ];
    }

    /**
     * One event as a producer would put it on the wire.
     *
     * Overrides win, and are unioned rather than merged: `array_merge()`
     * renumbers a digits-only name, losing it inside the fixture.
     *
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    private static function raw(string $id, string $type, array $overrides = []): array
    {
        return $overrides + [
            'specversion' => '1.0',
            'id' => $id,
            'type' => $type,
            'source' => 'urn:test',
        ];
    }

    /**
     * An event with no id has no position, so a consumer cannot record having
     * passed it. Returning the usable prefix lets those events be handled and
     * the position advance to the last of them; the broken event is then at
     * the head of the next batch, where it stops the feed loudly.
     */
    public function testKeepsTheEventsBeforeAnUndecodableOne(): void
    {
        [$remote] = $this->remote([FakeTransport::json([
            self::raw('1-0', 'a'),
            self::raw('1-1', 'b'),
            self::raw('', 'no id'),
            self::raw('1-3', 'd'),
        ])]);

        $events = $remote->read();

        $this->assertCount(2, $events);
        $this->assertSame(['a', 'b'], \array_map(fn (CloudEvent $e): string => $e->type, $events));
    }

    public function testFailsWhenTheFirstEventIsUndecodable(): void
    {
        [$remote] = $this->remote([FakeTransport::json([self::raw('', 'no id'), self::raw('1-1', 'b')])]);

        $this->expectException(Invalid::class);

        $remote->read();
    }

    public function testFailsWhenTheFirstEntryIsNotAnEvent(): void
    {
        [$remote] = $this->remote([FakeTransport::json(['a string'])]);

        $this->expectException(Invalid::class);

        $remote->read();
    }

    public function testKeepsTheEventsBeforeAnEntryThatIsNotAnEvent(): void
    {
        [$remote] = $this->remote([FakeTransport::json([self::raw('1-0', 'a'), 'a string'])]);

        $this->assertCount(1, $remote->read());
    }

    /**
     * `specversion` is REQUIRED by the spec and a feed's own producer always
     * sends it, so an entry without one is not a CloudEvent at all — the batch
     * stops there rather than the attribute being invented.
     */
    public function testFailsWhenAnEventIsNotACloudEventAtAll(): void
    {
        [$remote] = $this->remote([FakeTransport::json([['id' => '1-0', 'type' => 'a']])]);

        $this->expectException(Invalid::class);

        $remote->read();
    }

    /**
     * Every attribute this library models has to come off the wire, not only
     * the ones a test asserting on `id` and `data` happens to look at.
     */
    public function testEveryModelledAttributeComesOffTheWire(): void
    {
        [$remote] = $this->remote([FakeTransport::json([self::raw('1-0', 'io.appwrite.edge.invalidate-rule', [
            'subject' => 'example.com',
            'time' => '2026-07-31T09:15:02.123Z',
            'datacontenttype' => 'application/xml',
            'dataschema' => 'https://example.com/schema.json',
            'data' => '<invalidate/>',
            'traceparent' => '00-abc-def-01',
        ])])]);

        $event = $remote->read()[0];

        $this->assertSame('1-0', $event->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $event->type);
        $this->assertSame('urn:test', $event->source);
        $this->assertSame('1.0', $event->specversion);
        $this->assertSame('example.com', $event->subject);
        $this->assertSame('2026-07-31T09:15:02.123Z', $event->time);
        $this->assertSame('application/xml', $event->datacontenttype);
        $this->assertSame('https://example.com/schema.json', $event->dataschema);
        $this->assertSame('<invalidate/>', $event->data);
        $this->assertSame('00-abc-def-01', $event->extensions['traceparent']);
    }

    /** An absent `datacontenttype` means JSON, so it must not be invented on decode. */
    public function testAnAbsentDatacontenttypeIsNotInvented(): void
    {
        [$remote] = $this->remote([FakeTransport::json([self::raw('1-0', 'a')])]);

        $this->assertNull($remote->read()[0]->datacontenttype);
    }

    /**
     * The forward-compatibility property a feed depends on: it is read by
     * consumers older than the producer by design, so a producer that adds an
     * attribute or moves the spec version forward must not stop one that
     * predates it.
     */
    public function testSurvivesAProducerThatMovedAhead(): void
    {
        [$remote] = $this->remote([FakeTransport::json([
            self::raw('1-0', 'a', [
                'specversion' => '1.1',
                'somethingnew' => 'ignored',
                'traceparent' => '00-abc-def-01',
            ]),
        ])]);

        $events = $remote->read();

        $this->assertCount(1, $events);
        $this->assertSame('1.1', $events[0]->specversion);
        $this->assertSame('00-abc-def-01', $events[0]->extensions['traceparent']);
    }

    /**
     * An attribute the spec cannot carry is dropped and the event still
     * delivered, rather than one odd attribute costing the whole event.
     * Dropping is a choice, so each shape it takes is named here.
     *
     * @param array<string, mixed> $extension
     */
    #[DataProvider('unusableExtensions')]
    public function testAnExtensionTheSpecCannotCarryIsDroppedAndTheEventKept(array $extension): void
    {
        [$remote] = $this->remote([FakeTransport::json([
            self::raw('1-0', 'a', $extension + ['keeps' => 'this one']),
        ])]);

        $events = $remote->read();

        $this->assertCount(1, $events, 'The event is still delivered');
        $this->assertSame(['keeps' => 'this one'], $events[0]->extensions);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableExtensions(): array
    {
        return [
            // A JSON number with a decimal point decodes as a float.
            'a float value' => [['ratio' => 1.5]],
            'an array value' => [['tags' => ['a', 'b']]],
            'an object value' => [['nested' => ['a' => 'b']]],
            'a null value' => [['missing' => null]],
            'an uppercase name' => [['traceParent' => '00-abc-def-01']],
            'a name with a dash' => [['trace-parent' => '00-abc-def-01']],
            'a name with an underscore' => [['trace_parent' => '00-abc-def-01']],
        ];
    }

    /** The types the spec allows, including a digits-only name — an integer key in PHP. */
    public function testEveryExtensionTheSpecAllowsIsKept(): void
    {
        [$remote] = $this->remote([FakeTransport::json([
            self::raw('1-0', 'a', ['trace' => 'abc', 'retrycount' => 2, 'replayed' => true, '123' => 'digits']),
        ])]);

        $extensions = $remote->read()[0]->extensions;

        $this->assertSame('abc', $extensions['trace']);
        $this->assertSame(2, $extensions['retrycount']);
        $this->assertTrue($extensions['replayed']);
        // @phpstan-ignore offsetAccess.notFound ('123' is an integer key in PHP)
        $this->assertSame('digits', $extensions['123']);
    }

    /**
     * The spec's optional compaction/deletion feature marks an event with a
     * `method` attribute. This library does not implement the feature, but a
     * feed that uses it must still be readable — the attribute rides along as
     * an extension rather than breaking the batch.
     */
    public function testAnEventCarryingTheSpecsMethodAttributeDecodes(): void
    {
        [$remote] = $this->remote([FakeTransport::json([self::raw('1-0', 'a', ['method' => 'DELETE'])])]);

        $events = $remote->read();

        $this->assertCount(1, $events);
        $this->assertSame('DELETE', $events[0]->extensions['method']);
    }

    /**
     * Anything implementing the client's adapter interface works, including
     * the client itself wrapping a transport — which is how this is actually
     * built in a service.
     */
    public function testWorksThroughTheClientItself(): void
    {
        $transport = FakeTransport::of([FakeTransport::json(self::batch([new CloudEvent(id: '1-0', type: 'a', source: 'urn:test')]))]);

        $client = (new Client($transport))
            ->withBaseUri('https://cloud.example.com/v1/feeds')
            ->withHeaders(['x-appwrite-jwt' => 'token']);
        $remote = new Remote($client, 'edge');

        $events = $remote->read();

        $this->assertCount(1, $events);
        $this->assertSame('token', $transport->recorder->last()['headers']['x-appwrite-jwt'] ?? null);
    }

    /**
     * The point of this class: a remote feed is consumed with exactly the
     * code a local one is. The consumer is built straight over the client —
     * it wraps the feed name and the client into a Remote itself.
     */
    public function testConsumesARemoteFeedThroughTheSameConsumer(): void
    {
        $transport = FakeTransport::of([
            FakeTransport::json(self::batch([
                new CloudEvent(id: '1-0', type: 'a', source: 'urn:test'),
                new CloudEvent(id: '1-1', type: 'b', source: 'urn:test'),
            ])),
            FakeTransport::json(self::batch([new CloudEvent(id: '1-2', type: 'c', source: 'urn:test')])),
            FakeTransport::json(self::batch([])),
        ]);

        $cursor = new MemoryCursor();
        $consumer = new Consumer($transport, $cursor, 'invalidator', feed: 'edge');

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(2, $consumer->consume($handler));
        $this->assertSame('1-1', $cursor->load('edge', 'invalidator'));

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(0, $consumer->consume($handler));

        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertStringContainsString('lastEventId=1-1', $transport->recorder->uris()[1]);
    }

    /**
     * The tip sentinel crosses the wire as `lastEventId=$` and the producer
     * resolves it inside the held request — end to end, a tip consumer skips
     * the backlog and still gets what lands mid-poll.
     */
    public function testTipStartWorksOverHttp(): void
    {
        $store = new MidPollStore('edge');
        $producer = new Producer($store, 'urn:test');
        $producer->produce('old');

        $endpoint = new FeedServer(new Server($store));
        $consumer = new Consumer($endpoint, new MemoryCursor(), 'notifier', feed: 'edge', timeout: 5_000, start: Consumer::START_TIP);

        $seen = [];
        $handler = function (CloudEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        };

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(['landed'], $seen, 'The backlog is skipped; the mid-wait event is not');
        $this->assertMatchesRegularExpression('/lastEventId=(%24|\$)/', $endpoint->recorder->last()['uri']);

        // The position now saves as a real id, so the sentinel never
        // appears on the wire again.
        $producer->produce('after');

        $this->assertSame(1, $consumer->consume($handler));
        $this->assertSame(['landed', 'after'], $seen);
        $this->assertDoesNotMatchRegularExpression('/lastEventId=(%24|\$)/', $endpoint->recorder->last()['uri']);
    }
}
