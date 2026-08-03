<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * The one place a backend key is shaped, so every adapter agrees on it.
 *
 * Keys are built by joining names with `:`, which is also a character a name
 * may contain — so joining them raw lets distinct things share a key. A feed
 * named `edge:cursor:x` would key to `feed:edge:cursor:x`, the same key as
 * consumer `x`'s position on feed `edge`, and a `SET` would land on an
 * `XADD` stream. Two consumers could likewise share one position:
 * (`a:cursor:b`, `c`) and (`a`, `b:cursor:c`) both joined to
 * `feed:a:cursor:b:cursor:c`.
 *
 * Escaping the separator out of the names makes the mapping injective, so
 * those collisions cannot be expressed. It is deliberately not validation:
 * `Remote` reads third-party feeds whose names are arbitrary path segments,
 * and rejecting one here would make a feed unconsumable over a detail of how
 * this library happens to store positions.
 */
final class Key
{
    /** The key a feed's events live under. */
    public static function feed(string $name): string
    {
        return 'feed:' . self::escape($name);
    }

    /** The key a consumer's position on a feed lives under. */
    public static function cursor(string $feed, string $consumer): string
    {
        return 'feed:' . self::escape($feed) . ':cursor:' . self::escape($consumer);
    }

    /**
     * The key a feed's newest id lives under, for backends that cannot answer
     * "is there anything after this position?" without reading the feed.
     */
    public static function tip(string $name): string
    {
        return self::feed($name) . ':tip';
    }

    /**
     * Percent-encode the separator, and the escape character itself so the
     * encoding stays reversible. A name with neither is left exactly as it
     * was, which is what keeps the layout the README documents readable from
     * a shell for every name anyone would actually pick.
     */
    private static function escape(string $name): string
    {
        return \str_replace(['%', ':'], ['%25', '%3A'], $name);
    }
}
