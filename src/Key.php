<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * The one place a backend key is shaped, so every adapter agrees on it.
 *
 * Names may contain the `:` the keys join on, so they are escaped rather than
 * rejected — `Remote` reads third-party feeds whose names are arbitrary path
 * segments.
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

    /** The key a feed's newest id lives under, for backends that cannot answer "anything new?" cheaply. */
    public static function tip(string $name): string
    {
        return self::feed($name) . ':tip';
    }

    /** The escape character goes first, so the encoding stays reversible. */
    private static function escape(string $name): string
    {
        return \str_replace(['%', ':'], ['%25', '%3A'], $name);
    }
}
