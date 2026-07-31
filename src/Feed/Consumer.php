<?php

declare(strict_types=1);

namespace Utopia\Feed;

use Utopia\Client\Adapter;

// Client class: the pull loop — reads what it has not seen and records how far it got.
class Consumer
{
    public const int BATCH = 100;

    protected readonly Readable $feed;

    private ?string $position = null;

    private bool $restored = false;

    /**
     * @param Adapter|Readable $source The feed to pull from — an HTTP client
     *        for another service's feed (its endpoint set on the client with
     *        withBaseUri()), or a local store for a feed this service owns.
     * @param string $name This consumer's name — what keys its position.
     * @param string $feed The feed's name. Required when $source is a client,
     *        where nothing else carries it; a local source already names its
     *        feed, so leave it out.
     *
     * @throws Exception\Invalid When a name is missing, or $feed contradicts the source.
     */
    public function __construct(
        Adapter|Readable $source,
        protected readonly Cursor $cursor,
        protected readonly string $name,
        string $feed = '',
        protected readonly int $batch = self::BATCH,
        protected readonly int $timeout = 0,
        protected readonly Start $start = Start::Oldest,
    ) {
        if ($name === '') {
            throw new Exception\Invalid('Feed consumer requires a name');
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
        $events = $this->feed->poll(
            $this->position() ?? $this->origin(),
            \max(1, \min($this->batch, Protocol::MAX_BATCH)),
            \max(0, \min($this->timeout, Protocol::MAX_TIMEOUT)),
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

        if ($processed !== null) {
            $this->position = $processed;
            $this->cursor->save($this->feed->getName(), $this->name, $processed);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $handled;
    }

    /**
     * Where a poll starts when no position is stored: the oldest retained
     * event, or — for Start::Tip — the tip sentinel, which the store (or
     * the remote producer, inside the same request) resolves to "now". Once
     * events are handled and the cursor saves, the sentinel never appears
     * again; reset() forgets the position, so the next poll anchors anew.
     */
    private function origin(): ?string
    {
        return $this->start === Start::Tip ? Protocol::TIP : null;
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
     * Set the position explicitly: treat $eventId as the last event handled,
     * so the next consume() starts strictly *after* it.
     *
     * The position is persisted immediately via Cursor::save() and mirrored
     * in memory. $eventId must be a well-formed feed position, but does not
     * need to currently exist in the feed — seeking to an id older than
     * retention or newer than the tip is legal and simply positions relative
     * to it, which is what makes seeking to a poison event's own id the way
     * to step past it deliberately.
     *
     * @throws Exception\Invalid When $eventId is not a feed position (the tip sentinel included).
     * @throws Exception When the cursor store cannot be written — a seek that
     *         did not persist must not look like one that did, so the failure
     *         is never swallowed and the in-memory position stays put.
     */
    public function seek(string $eventId): void
    {
        if (!Id::isValid($eventId)) {
            throw new Exception\Invalid('Invalid feed event id: ' . $eventId);
        }

        $this->cursor->save($this->feed->getName(), $this->name, $eventId);

        $this->position = $eventId;
        $this->restored = true;
    }

    public function reset(): void
    {
        $this->cursor->reset($this->feed->getName(), $this->name);

        $this->position = null;
        $this->restored = true;
    }
}
