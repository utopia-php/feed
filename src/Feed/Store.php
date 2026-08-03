<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;

abstract class Store implements Readable
{
    protected const int MAX_SIZE = 100_000; // entries
    protected const int POLL_INTERVAL = 500; // ms

    public function __construct(
        protected readonly string $name,
        protected readonly int $maxSize = self::MAX_SIZE,
        protected readonly int $pollInterval = self::POLL_INTERVAL,
    ) {
        if ($name === '') {
            throw new Invalid('Feed name is required');
        }

        if ($maxSize < 1) {
            throw new Invalid('Feed retention must be at least 1 event');
        }

        if ($pollInterval < 1) {
            throw new Invalid('Feed poll interval must be at least 1 millisecond');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** The backend key this feed's events live under. */
    protected function key(): string
    {
        return Key::feed($this->name);
    }

    /** @return list<CloudEvent> */
    abstract public function read(?string $lastEventId, int $limit): array;

    abstract public function tip(): ?string;

    protected function resolve(?string $lastEventId): ?string
    {
        return $lastEventId === Readable::TIP ? $this->tip() : $lastEventId;
    }

    /**
     * @return list<CloudEvent>
     */
    public function poll(?string $lastEventId, int $limit, int $timeout): array
    {
        $lastEventId = $this->resolve($lastEventId);

        $deadline = \microtime(true) + $timeout / 1000;

        while (true) {
            $events = $this->read($lastEventId, $limit);

            $remaining = $deadline - \microtime(true);

            if ($events !== [] || $remaining <= 0) {
                return $events;
            }

            \usleep((int) \min($this->pollInterval * 1000, \ceil($remaining * 1_000_000)));
        }
    }

    /**
     * Every attribute a CloudEvent carries, flattened to strings, with the
     * empty string for an absent one — the spec has no null attribute values,
     * so it cannot collide. `id` and `specversion` are left out: the store
     * assigns the first, and `1.0` is the only version {@see self::decode()}
     * can restore.
     *
     * @return array<string, string>
     */
    protected static function encode(CloudEvent $event): array
    {
        return [
            'type' => $event->type,
            'source' => $event->source,
            'subject' => $event->subject ?? '',
            'datacontenttype' => $event->datacontenttype ?? '',
            'dataschema' => $event->dataschema ?? '',
            'time' => $event->time ?? '',
            'data' => self::json($event->data, 'data'),
            'extensions' => self::json($event->extensions, 'extensions'),
        ];
    }

    /** @param array<array-key, mixed> $fields */
    protected static function decode(string $id, array $fields): CloudEvent
    {
        $extensions = \json_decode(self::field($fields, 'extensions'), true);

        $event = [
            'specversion' => '1.0',
            'id' => $id,
            'type' => self::field($fields, 'type'),
            'source' => self::field($fields, 'source'),
            'data' => \json_decode(self::field($fields, 'data'), true),
        ];

        foreach (['subject', 'datacontenttype', 'dataschema', 'time'] as $optional) {
            $value = self::field($fields, $optional);

            if ($value !== '') {
                $event[$optional] = $value;
            }
        }

        // Filtered, not merged verbatim: a read decodes every entry, so one a
        // foreign writer poisoned would fail every read past it forever.
        $event += Extensions::filter(\is_array($extensions) ? $extensions : []);

        try {
            // The docblock wants array<string, mixed>, but a digit-only
            // extension name — legal per the spec — is an integer key in PHP.
            // @phpstan-ignore argument.type
            return CloudEvent::fromArray($event);
        } catch (\InvalidArgumentException $error) {
            throw new Invalid("Feed entry {$id} could not be read as an event: {$error->getMessage()}", previous: $error);
        }
    }

    private static function json(mixed $value, string $attribute): string
    {
        try {
            return \json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Invalid("Feed event {$attribute} must be JSON encodable: {$error->getMessage()}", previous: $error);
        }
    }

    /** @param array<array-key, mixed> $fields */
    private static function field(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
