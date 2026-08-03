<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * The one place this library decides what an extension attribute is, so the
 * wire and the store agree.
 *
 * A feed is read by consumers older than its producer by design, so anything
 * the spec cannot carry is dropped rather than raised: one odd attribute must
 * not cost the whole event.
 */
final class Extensions
{
    /** The context attributes this library models; anything else is a candidate extension. */
    private const array ATTRIBUTES = [
        'specversion',
        'type',
        'source',
        'id',
        'subject',
        'time',
        'datacontenttype',
        'dataschema',
        'data',
    ];

    /**
     * @param array<array-key, mixed> $candidates A decoded event, or just its extension attributes.
     * @return array<array-key, bool|int|string>
     */
    public static function filter(array $candidates): array
    {
        $extensions = [];

        /** @var mixed $value */
        foreach ($candidates as $name => $value) {
            if (\in_array($name, self::ATTRIBUTES, true)) {
                continue;
            }

            // A digits-only name is legal, and an integer key in PHP.
            if (\preg_match('/^[a-z0-9]+$/', (string) $name) !== 1) {
                continue;
            }

            if (\is_bool($value) || \is_int($value) || \is_string($value)) {
                $extensions[$name] = $value;
            }
        }

        return $extensions;
    }
}
