<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Feed\Event;
use Utopia\Feed\Exception\Invalid;

class EventTest extends TestCase
{
    public function testDecodesACloudEvent(): void
    {
        $event = Event::fromArray([
            'specversion' => '1.0',
            'id' => '1690000000000-0',
            'type' => 'io.appwrite.edge.invalidate-rule',
            'source' => 'urn:appwrite:cloud:fra',
            'time' => '2026-07-29T12:00:00.000Z',
            'subject' => 'preview.example.com',
            'datacontenttype' => 'application/json',
            'data' => ['tags' => ['domain' => 'preview.example.com']],
        ]);

        $this->assertSame('1690000000000-0', $event->id);
        $this->assertSame('io.appwrite.edge.invalidate-rule', $event->type);
        $this->assertSame('urn:appwrite:cloud:fra', $event->source);
        $this->assertSame('2026-07-29T12:00:00.000Z', $event->time);
        $this->assertSame('preview.example.com', $event->subject);
        $this->assertSame(['tags' => ['domain' => 'preview.example.com']], $event->data);
    }

    public function testRejectsAnEventWithoutAnId(): void
    {
        $this->expectException(Invalid::class);

        Event::fromArray(['type' => 'io.appwrite.edge.invalidate']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableIds(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'numeric' => [42],
            'array' => [[]],
        ];
    }

    /**
     * @dataProvider unusableIds
     */
    public function testRejectsAnIdThatIsNotANonEmptyString(mixed $id): void
    {
        $this->expectException(Invalid::class);

        Event::fromArray(['id' => $id, 'type' => 'test']);
    }

    public function testDefaultsEveryFieldExceptTheId(): void
    {
        $event = Event::fromArray(['id' => '1-0']);

        $this->assertSame('1-0', $event->id);
        $this->assertSame('', $event->type);
        $this->assertSame('', $event->source);
        $this->assertSame('', $event->subject);
        $this->assertSame('', $event->time);
        $this->assertSame([], $event->data);
    }

    /**
     * A producer that adds a field must not break a consumer written before
     * it, which is what makes a feed safe to evolve.
     */
    public function testIgnoresUnknownFields(): void
    {
        $event = Event::fromArray([
            'id' => '1-0',
            'type' => 'test',
            'dataschema' => 'https://example.com/schema.json',
            'somethingnew' => ['a' => 'b'],
        ]);

        $this->assertSame('1-0', $event->id);
        $this->assertSame('test', $event->type);
    }

    public function testCoercesAMalformedFieldToItsDefaultRatherThanFailing(): void
    {
        $event = Event::fromArray([
            'id' => '1-0',
            'type' => ['not', 'a', 'string'],
            'data' => 'not an array',
        ]);

        $this->assertSame('', $event->type);
        $this->assertSame([], $event->data);
    }

    public function testRoundTripsThroughItsArrayForm(): void
    {
        $event = new Event(
            id: '1-0',
            type: 'io.appwrite.edge.invalidate',
            data: ['tags' => ['project' => 'proj-1']],
            source: 'urn:appwrite:cloud:fra',
            subject: 'proj-1',
            time: '2026-07-29T12:00:00.000Z',
        );

        $this->assertEquals($event, Event::fromArray($event->toArray()));
    }

    public function testSurvivesAJsonRoundTrip(): void
    {
        $event = new Event(id: '1-0', type: 'test', data: ['nested' => ['a' => 1]]);

        $encoded = \json_encode($event->toArray());
        $this->assertIsString($encoded);

        $decoded = Event::fromArray((array) \json_decode($encoded, true));

        $this->assertSame(['nested' => ['a' => 1]], $decoded->data);
    }

    public function testAlwaysReportsTheSpecVersionAndContentType(): void
    {
        $encoded = (new Event(id: '1-0', type: 'test'))->toArray();

        $this->assertSame('1.0', $encoded['specversion']);
        $this->assertSame('application/json', $encoded['datacontenttype']);
    }

    /**
     * Every CloudEvents attribute is emitted, and none of them are null, even
     * on an event that set almost nothing.
     *
     * This is what makes the wire form portable: a stricter CloudEvents reader
     * — including `utopia-php/cloudevents`, whose `fromArray()` reads
     * `specversion`, `source`, `id` and `time` without defaulting them — can
     * consume it directly. Dropping an empty field here, or letting one be
     * null, would break those readers without breaking any test that only
     * round-trips through this class.
     */
    public function testTheWireFormIsPortableToStricterCloudEventsReaders(): void
    {
        $encoded = (new Event(id: '1-0', type: 'test'))->toArray();

        $this->assertSame([
            'specversion',
            'id',
            'type',
            'source',
            'time',
            'subject',
            'datacontenttype',
            'data',
        ], \array_keys($encoded));

        foreach ($encoded as $field => $value) {
            $this->assertNotNull($value, "The {$field} attribute must never be null on the wire");
        }
    }

    public function testReadsPayloadKeysWithADefault(): void
    {
        $event = new Event(id: '1-0', type: 'test', data: ['tags' => ['a' => 'b']]);

        $this->assertSame(['a' => 'b'], $event->getData('tags'));
        $this->assertNull($event->getData('missing'));
        $this->assertSame('fallback', $event->getData('missing', 'fallback'));
    }

    public function testWithIdLeavesEverythingElseAlone(): void
    {
        $event = new Event(id: '', type: 'test', data: ['a' => 'b'], subject: 's');
        $stamped = $event->withId('7-0');

        $this->assertSame('7-0', $stamped->id);
        $this->assertSame('test', $stamped->type);
        $this->assertSame(['a' => 'b'], $stamped->data);
        $this->assertSame('s', $stamped->subject);
        $this->assertSame('', $event->id, 'The original must not be mutated');
    }

    public function testNowIsRfc3339WithMilliseconds(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', Event::now());
    }
}
