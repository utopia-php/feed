<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Client\Adapter;

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

    public function consume(callable $handler): int
    {
        $moved = $this->moved;

        $events = $this->feed->poll(
            $this->position() ?? $this->origin(),
            \max(1, \min($this->batch, Readable::MAX_BATCH)),
            \max(0, \min($this->timeout, Readable::MAX_TIMEOUT)),
        );

        if ($events === []) {
            return 0;
        }

        $handled = 0;
        $processed = null;
        $failure = null;

        foreach ($events as $event) {
            try {
                $handler($event);
            } catch (\Throwable $error) {
                $failure = $error;
                break;
            }

            $processed = $event->id;
            $handled++;
        }

        if ($processed !== null && $this->moved === $moved) {
            $this->position = $processed;
            $this->cursor->save($this->feed->getName(), $this->name, $processed);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $handled;
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
