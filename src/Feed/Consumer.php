<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Client\Adapter;
use Utopia\CloudEvents\CloudEvent;

class Consumer
{
    public const int BATCH = 100;

    /** Where a consumer with no stored position begins. */
    public const string START_OLDEST = 'oldest';
    public const string START_TIP = 'tip';

    protected readonly Readable $feed;

    private ?string $position = null;

    private bool $restored = false;

    /** Bumped by every hand-made move, so a run never saves over one. */
    private int $moved = 0;

    /**
     * @throws Exception\Invalid When a name is missing, $feed contradicts the source, or $start is not a START_* constant.
     */
    public function __construct(
        Adapter|Readable $source,
        protected readonly Cursor $cursor,
        protected readonly string $name,
        string $feed = '',
        protected readonly int $batch = self::BATCH,
        protected readonly int $timeout = 0,
        protected readonly string $start = self::START_OLDEST,
    ) {
        if ($name === '') {
            throw new Exception\Invalid('Feed consumer requires a name');
        }

        if ($start !== self::START_OLDEST && $start !== self::START_TIP) {
            throw new Exception\Invalid("Feed consumer start must be Consumer::START_OLDEST or Consumer::START_TIP, got {$start}");
        }

        if ($source instanceof Adapter) {
            $this->feed = new Remote($source, $feed);
        } else {
            if ($feed !== '' && $feed !== $source->getName()) {
                throw new Exception\Invalid("The source already names its feed {$source->getName()}, which {$feed} contradicts");
            }

            $this->feed = $source;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The handler receives one event at a time and answers with its return
     * value: an exact `false` means unprocessed — the run stops there, the
     * position stays before the event, and the next run re-delivers it, the
     * calm form of what throwing does. Anything else, including nothing,
     * means processed. Only that exact `false` counts: handlers predate this
     * contract and return all sorts of things, and a stray null or 0 must
     * not stall the feed.
     *
     * @param callable(CloudEvent): mixed $handler
     * @return int How many events the position advanced past.
     */
    public function consume(callable $handler): int
    {
        $moved = $this->moved;
        $events = $this->poll();

        $handled = 0;
        $processed = null;
        $failure = null;

        foreach ($events as $event) {
            try {
                $result = $handler($event);
            } catch (\Throwable $error) {
                $failure = $error;
                break;
            }

            if ($result === false) {
                break;
            }

            $processed = $event->id;
            $handled++;
        }

        $this->advance($processed, $moved);

        if ($failure !== null) {
            throw $failure;
        }

        return $handled;
    }

    /**
     * Like {@see Consumer::consume()}, but the handler receives the whole
     * poll — up to `batch` events — as one `list<CloudEvent>`, and its
     * return value answers for all of them: the position moves past the
     * chunk or, on an exact `false`, not at all. A handler that made partial
     * progress before answering `false` can {@see Consumer::seek()} to the
     * last event it completed; a move made mid-run is never saved over.
     *
     * The handler is not called for an empty poll — a caught-up consumer has
     * nothing to decide about.
     *
     * @param callable(list<CloudEvent>): mixed $handler
     * @return int How many events the position advanced past — the chunk, or 0.
     */
    public function consumeChunk(callable $handler): int
    {
        $moved = $this->moved;
        $events = $this->poll();

        if ($events === []) {
            return 0;
        }

        if ($handler($events) === false) {
            return 0;
        }

        $this->advance($events[\array_key_last($events)]->id, $moved);

        return \count($events);
    }

    /** @return list<CloudEvent> */
    private function poll(): array
    {
        return $this->feed->poll(
            $this->position() ?? $this->origin(),
            \max(1, \min($this->batch, Readable::MAX_BATCH)),
            \max(0, \min($this->timeout, Readable::MAX_TIMEOUT)),
        );
    }

    private function advance(?string $processed, int $moved): void
    {
        if ($processed === null || $this->moved !== $moved) {
            return;
        }

        $expected = $this->position;
        $this->position = $processed;

        // Conditional for the same reason as the $moved guard, but across
        // instances: a save lands only if the position is still where this
        // run started. Refused means another instance moved it — progress,
        // a seek, or a reset — and that newer decision stands.
        if (!$this->cursor->advance($this->feed->getName(), $this->name, $processed, $expected)) {
            $this->position = null;
            $this->restored = false;
        }
    }

    private function origin(): ?string
    {
        return $this->start === self::START_TIP ? Readable::TIP : null;
    }

    public function position(): ?string
    {
        if (!$this->restored) {
            $this->position = $this->cursor->load($this->feed->getName(), $this->name);
            $this->restored = true;
        }

        return $this->position;
    }

    /**
     * Move the position by hand.
     *
     * What counts as a usable id is the feed's to say: a local {@see Store}
     * mints `{ms}-{seq}` positions and pages by decoding them, while a remote
     * producer is the authority on its own (http-feeds endpoints commonly use
     * UUIDs). Both refuse the tip sentinel, which is a start, not a position.
     *
     * @throws Exception\Invalid When $eventId cannot be a position on this feed
     * @throws Exception When the cursor store cannot be written
     */
    public function seek(string $eventId): void
    {
        $usable = $this->feed instanceof Store
            ? Id::isValid($eventId)
            : $eventId !== '' && $eventId !== Readable::TIP;

        if (!$usable) {
            throw new Exception\Invalid('Invalid feed event id: ' . $eventId);
        }

        $this->cursor->save($this->feed->getName(), $this->name, $eventId);

        $this->position = $eventId;
        $this->restored = true;
        $this->moved++;
    }

    public function reset(): void
    {
        $this->cursor->reset($this->feed->getName(), $this->name);

        $this->position = null;
        $this->restored = true;
        $this->moved++;
    }
}
