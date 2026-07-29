<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Exception\Invalid;
use Utopia\Feed\Id;
use Utopia\Feed\Journal;

/**
 * A feed held in process memory, for tests and single-process development.
 *
 * Implements the same id and retention semantics as {@see Redis}, including the
 * awkward parts like resuming from a trimmed position, so code written against
 * it behaves the same when it is swapped out. Not for production: nothing is
 * shared between processes and nothing survives a restart.
 */
class Memory extends Journal
{
    /** @var list<CloudEvent> */
    private array $events = [];

    /**
     * Last millisecond an event was appended in, with the sequence reached
     * within it, so several appends in the same millisecond still get ordered
     * ids the way `XADD` does.
     */
    private int $timestamp = 0;

    private int $sequence = -1;

    /**
     * @throws Invalid When $name is empty, or $maxSize is below one event.
     */
    public function __construct(string $name, protected readonly int $maxSize = 100_000)
    {
        parent::__construct($name);

        self::assertRetention($maxSize);
    }

    public function append(CloudEvent $event): string
    {
        $now = (int) \floor(\microtime(true) * 1000);

        if ($now > $this->timestamp) {
            $this->timestamp = $now;
            $this->sequence = 0;
        } else {
            // Also covers a clock that stepped backwards: ids must never go
            // backwards, so the sequence keeps climbing under the old
            // millisecond rather than the timestamp following the clock down.
            $this->sequence++;
        }

        $id = Id::encode($this->timestamp, $this->sequence);

        // Stored through the same encode/decode a real backend goes through,
        // so this journal cannot accept payloads that would fail in production.
        $this->events[] = self::decode($id, self::encode($event));

        if (\count($this->events) > $this->maxSize) {
            $this->events = \array_slice($this->events, -$this->maxSize);
        }

        return $id;
    }

    public function read(?string $lastEventId, int $limit): array
    {
        // Decoded up front so a malformed position fails the same way it does
        // on every other journal, even when the feed is empty.
        $after = $lastEventId === null ? null : Id::decode($lastEventId);

        $events = [];

        foreach ($this->events as $event) {
            if ($after !== null && Id::decode($event->id) <= $after) {
                continue;
            }

            $events[] = $event;

            if (\count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }
}
