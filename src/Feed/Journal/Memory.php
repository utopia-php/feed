<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\Feed\Journal;
use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Id;

/**
 * A feed held in process memory.
 *
 * For tests and for running a service on its own without a Redis. It
 * implements the same id and retention semantics as {@see Redis}, so code
 * written against it behaves the same when it is swapped out — including the
 * parts that are easy to get wrong, like resuming from a trimmed position.
 *
 * Not for production: nothing is shared between processes and nothing survives
 * a restart, so consumers in another worker see an empty feed.
 */
class Memory extends Journal
{
    /** @var list<CloudEvent> */
    private array $events = [];

    /**
     * Last millisecond an event was appended in, with the sequence number
     * reached within it. Tracked so several appends inside the same
     * millisecond still get ordered ids, the way `XADD` does.
     */
    private int $timestamp = 0;

    private int $sequence = -1;

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
        // rather than holding the object. Otherwise this journal would accept
        // payloads that cannot be serialized and hand back values that survived
        // a round trip they would not survive in production — which is the one
        // way a stand-in like this actively causes harm.
        $this->events[] = self::decode($id, self::encode($event));

        if (\count($this->events) > $this->maxSize) {
            $this->events = \array_slice($this->events, -$this->maxSize);
        }

        return $id;
    }

    public function read(?string $lastEventId, int $limit, int $timeout = 0): array
    {
        // Validates the position even when nothing will be returned, so a
        // malformed cursor fails the same way it does on every other journal
        // instead of only once the feed has events in it.
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

    /**
     * How many events are currently retained. Test affordance — a feed has no
     * length a consumer is allowed to care about.
     */
    public function count(): int
    {
        return \count($this->events);
    }

    /**
     * Drop every event, without resetting the id counter: positions already
     * handed out must not be reissued, or a consumer holding one would skip
     * whatever is appended next.
     */
    public function flush(): void
    {
        $this->events = [];
    }
}
