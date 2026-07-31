<?php

declare(strict_types=1);

namespace Utopia\Feed\Journal;

use Utopia\CloudEvents\CloudEvent;
use Utopia\Feed\Id;
use Utopia\Feed\Appendable;
use Utopia\Feed\Journal;

class Memory extends Journal implements Appendable
{
    /** @var list<CloudEvent> */
    private array $events = [];

    private int $timestamp = 0;
    private int $sequence = -1;

    public function __construct(string $name, protected readonly int $maxSize = 100_000)
    {
        parent::__construct($name);
    }

    public function append(CloudEvent $event): string
    {
        $now = (int) \floor(\microtime(true) * 1000);

        if ($now > $this->timestamp) {
            $this->timestamp = $now;
            $this->sequence = 0;
        } else {
            $this->sequence++;
        }

        $id = Id::encode($this->timestamp, $this->sequence);

        $this->events[] = self::decode($id, self::encode($event));

        if (\count($this->events) > $this->maxSize) {
            $this->events = \array_slice($this->events, -$this->maxSize);
        }

        return $id;
    }

    public function tip(): ?string
    {
        $count = \count($this->events);

        return $count === 0 ? null : $this->events[$count - 1]->id;
    }

    public function read(?string $lastEventId, int $limit): array
    {
        $lastEventId = $this->resolve($lastEventId);

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
