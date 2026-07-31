<?php

declare(strict_types=1);

namespace Utopia\Feed;

// Client class: the pull loop — reads what it has not seen and records how far it got.
// Also runs server-side, when a job inside the producer consumes the feed it produces.
class Consumer
{
    public const int BATCH = 100;

    private ?string $position = null;

    private bool $restored = false;

    public function __construct(
        protected readonly Feed $feed,
        protected readonly string $name,
        protected readonly Cursor $cursor,
        protected readonly int $batch = self::BATCH,
        protected readonly int $timeout = 0,
        protected readonly Start $start = Start::Oldest,
    ) {
        if ($name === '') {
            throw new Exception\Invalid('Feed consumer requires a name');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function consume(callable $handler): int
    {
        $batch = $this->feed->poll($this->position() ?? $this->origin(), $this->batch, $this->timeout);

        if ($batch->isEmpty()) {
            return 0;
        }

        $handled = 0;
        $processed = null;
        $failure = null;

        foreach ($batch as $event) {
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
     * event, or — for Start::Tip — the tip sentinel, which the journal (or
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

    public function reset(): void
    {
        $this->cursor->reset($this->feed->getName(), $this->name);

        $this->position = null;
        $this->restored = true;
    }
}
