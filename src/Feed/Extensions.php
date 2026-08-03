<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * The one place this library decides what an extension attribute is.
 *
 * A feed is read by consumers older than the producer by design, so an event
 * carrying something unexpected has to stay readable. The spec is narrow about
 * what an extension may be — a name of lowercase letters and digits, a value
 * that is a boolean, an integer or a string — and anything outside that is
 * dropped so one odd attribute cannot cost the whole event.
 *
 * Both decode paths go through here. They used to disagree: over HTTP a
 * foreign `"ratio": 1.5` was filtered out and the event delivered, while the
 * same event read from a local store was handed to `CloudEvent::fromArray()`
 * whole, which rejected it — and since a store read decodes every entry in the
 * batch, one such entry made every read past it fail permanently. Agreeing was
 * the only version of that anybody would have chosen on purpose.
 */
final class Extensions
{
    /**
     * The context attributes this library models. Anything else in an event is
     * a candidate extension.
     */
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
     * Keep only what a CloudEvent can carry as an extension.
     *
     * @param array<array-key, mixed> $candidates A decoded event, or just its
     *        extension attributes — modelled attributes are dropped either way.
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

            // A digit-only name is legal per the spec and arrives as an
            // integer key in PHP, so the name is compared as a string.
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
