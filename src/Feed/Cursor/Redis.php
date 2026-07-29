<?php

declare(strict_types=1);

namespace Utopia\Feed\Cursor;

use Utopia\Feed\Cursor;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Exception\Transport;
use Utopia\Feed\Id;

/**
 * Positions kept in Redis, as a one-entry stream.
 *
 * For consumers running inside the producer — a job that turns the feed into
 * something else, a bridge to a system that cannot poll. They have no store of
 * their own, and the feed's Redis is already there.
 *
 * Consumers reached over HTTP should not use this: keeping their positions in
 * the producer's Redis puts per-consumer state back on the producer, which is
 * exactly what the feed is arranged to avoid.
 *
 * ## Why a stream and not a string
 *
 * A feed position *is* a Redis stream id — `<ms>-<seq>` is the format `XADD`
 * allocates — and Redis refuses to append an id equal to or smaller than the
 * one already at the top of a stream. Storing the position as the id of a
 * single-entry stream therefore makes "never move a position backwards" the
 * server's rule rather than this client's, and it holds atomically: two
 * processes racing cannot both decide they are ahead, because the losing `XADD`
 * is rejected by the same operation that would have written it.
 *
 * That is the one guarantee {@see Cursor::save()}'s read-compare-write cannot
 * give, and it costs a single round trip rather than two.
 *
 * `MAXLEN 1` keeps the stream at exactly the current position; the entry's
 * payload is unused, since the id carries the whole value.
 */
class Redis extends Cursor
{
    /**
     * Placeholder field. A stream entry must carry at least one field, but the
     * position lives in the entry's id, so nothing reads this.
     */
    private const string FIELD = 'p';

    public function __construct(
        protected readonly \Redis|\RedisCluster $redis,
        string $feed,
    ) {
        parent::__construct($feed);
    }

    /**
     * Advance the position, atomically.
     *
     * No read-compare-write here: `XADD` is itself the comparison, so a
     * position that is not an advance is refused by Redis and treated as a
     * no-op. That is the whole reason for the stream.
     *
     * @throws Invalid When $eventId is not a feed position, which a stream
     *         cannot store as an id.
     * @throws Transport When Redis cannot be reached or refuses for any other
     *         reason.
     */
    public function save(string $consumer, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        if (!Id::isValid($eventId)) {
            throw new Invalid("Cannot store '{$eventId}' as a position: it is not a feed id");
        }

        $key = $this->key($consumer);

        [$reply, $error] = $this->attempt(
            fn (): mixed => $this->redis->xAdd($key, $eventId, [self::FIELD => '1'], 1, false)
        );

        if ($error === '') {
            return;
        }

        // Redis rejected the id as not newer than what is stored: another
        // process got further than this one, which is exactly the outcome the
        // stream is here to produce.
        if (self::rejectedAsStale($error)) {
            return;
        }

        // A position written by a version that stored these as plain strings.
        // Replacing the key in place keeps the position — the caller is
        // advancing past whatever the string held — so the upgrade costs no
        // replay. See docs/migration.md.
        if (self::wrongType($error)) {
            $this->replaceLegacy($key, $consumer, $eventId);

            return;
        }

        throw new Transport("Failed to save the {$consumer} cursor: {$error}");
    }

    public function load(string $consumer): ?string
    {
        $key = $this->key($consumer);

        [$entries, $error] = $this->attempt(
            fn (): mixed => $this->redis->xRevRange($key, '+', '-', 1)
        );

        if ($error !== '') {
            // Written by a version that stored positions as plain strings. Read
            // it where it is; the next save() converts the key.
            if (self::wrongType($error)) {
                return $this->loadLegacy($key, $consumer);
            }

            throw new Transport("Failed to load the {$consumer} cursor: {$error}");
        }

        if (!\is_array($entries) || $entries === []) {
            return null;
        }

        // The position is the entry's id, not its payload.
        $id = \array_key_first($entries);

        return \is_string($id) && $id !== '' ? $id : null;
    }

    public function reset(string $consumer): void
    {
        $key = $this->key($consumer);

        [, $error] = $this->attempt(fn (): mixed => $this->redis->del($key));

        if ($error !== '') {
            throw new Transport("Failed to reset the {$consumer} cursor: {$error}");
        }
    }

    /**
     * @throws Transport
     */
    private function loadLegacy(string $key, string $consumer): ?string
    {
        [$cursor, $error] = $this->attempt(fn (): mixed => $this->redis->get($key));

        if ($error !== '') {
            throw new Transport("Failed to load the {$consumer} cursor: {$error}");
        }

        return \is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * @throws Transport
     */
    private function replaceLegacy(string $key, string $consumer, string $eventId): void
    {
        [, $error] = $this->attempt(fn (): mixed => $this->redis->del($key));

        if ($error === '') {
            [, $error] = $this->attempt(
                fn (): mixed => $this->redis->xAdd($key, $eventId, [self::FIELD => '1'], 1, false)
            );
        }

        if ($error !== '') {
            throw new Transport("Failed to upgrade the {$consumer} cursor to a stream: {$error}");
        }
    }

    /**
     * Run a command and report Redis' refusal rather than letting it surface as
     * two different things.
     *
     * phpredis signals a command-level error either by throwing or by returning
     * `false` and parking the text in `getLastError()`, depending on the build
     * and the connection's options. Both are normalised here so callers can ask
     * one question — did Redis refuse, and what did it say — instead of each
     * handling the split.
     *
     * @param \Closure(): mixed $command
     * @return array{mixed, string} The reply, and Redis' error text when it
     *         refused, or an empty string when it did not.
     */
    private function attempt(\Closure $command): array
    {
        $this->redis->clearLastError();

        try {
            /** @var mixed $reply */
            $reply = $command();
        } catch (\RedisException $error) {
            return [false, $error->getMessage() !== '' ? $error->getMessage() : 'Redis command failed'];
        }

        if ($reply !== false) {
            return [$reply, ''];
        }

        $error = $this->redis->getLastError();
        $this->redis->clearLastError();

        return [false, \is_string($error) && $error !== '' ? $error : ''];
    }

    /**
     * Whether Redis refused an id for being at or behind the stream's top,
     * which is this class's definition of "not an advance".
     */
    private static function rejectedAsStale(string $error): bool
    {
        return \str_contains($error, 'equal or smaller');
    }

    /**
     * Whether the key holds something other than a stream — in practice, a
     * position written by a version that stored them as plain strings.
     */
    private static function wrongType(string $error): bool
    {
        return \str_contains($error, 'WRONGTYPE');
    }
}
