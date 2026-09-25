<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkQueueSupport
{
    private const MAX_BUFFER_EVENTS = 1_000;
    private const MAX_BUFFER_BYTES = 8 * 1024 * 1024;
    private const REQUEST_END_MAX_EVENTS = 25;
    private const REQUEST_END_MAX_BYTES = 256 * 1024;

    private int $bufferBytes = 0;

    /** @var array<int, int> */
    private array $bufferPriorities = [0, 0, 0, 0];

    /** @var array<string, array{count: int, first_seen: string, last_seen: string}> */
    private array $pressureDrops = [];

    private function preAdmissionAllows(string $eventType, string $level = 'warning', ?int $statusCode = null): bool
    {
        if (count($this->buffer) < self::MAX_BUFFER_EVENTS && $this->bufferBytes < self::MAX_BUFFER_BYTES) {
            return true;
        }

        $priority = $this->eventPriority($eventType, $level, $statusCode);
        if ($this->hasLowerPriority($priority)) {
            return true;
        }
        $this->recordPressure($eventType, $level, gmdate('Y-m-d\TH:i:s\Z'));
        return false;
    }

    /** @param array<string, mixed> $event */
    private function admitProtectedEvent(array $event, bool $countDrop = true): bool
    {
        try {
            $bytes = strlen(json_encode($event, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return false;
        }

        if ($bytes > self::MAX_BUFFER_BYTES) {
            if ($countDrop) {
                $this->recordEventPressure($event);
            }
            return false;
        }

        $priority = $this->eventPriorityForEnvelope($event);
        while (count($this->buffer) >= self::MAX_BUFFER_EVENTS || $this->bufferBytes + $bytes > self::MAX_BUFFER_BYTES) {
            if (!$this->hasLowerPriority($priority)) {
                if ($countDrop) {
                    $this->recordEventPressure($event);
                }
                return false;
            }
            $eviction = null;
            foreach ($this->buffer as $index => $queued) {
                if ($this->eventPriorityForEnvelope($queued) < $priority) {
                    $eviction = $index;
                    break;
                }
            }
            if ($eviction === null) {
                if ($countDrop) {
                    $this->recordEventPressure($event);
                }
                return false;
            }
            $removed = array_splice($this->buffer, $eviction, 1)[0];
            $this->bufferBytes -= strlen(json_encode($removed, JSON_THROW_ON_ERROR));
            $this->bufferPriorities[$this->eventPriorityForEnvelope($removed)]--;
            $this->recordEventPressure($removed);
        }

        $this->buffer[] = $event;
        $this->bufferBytes += $bytes;
        $this->bufferPriorities[$priority]++;
        return true;
    }

    private function hasLowerPriority(int $priority): bool
    {
        for ($rank = 0; $rank < $priority; $rank++) {
            if ($this->bufferPriorities[$rank] > 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $event */
    private function eventPriorityForEnvelope(array $event): int
    {
        $status = $event['payload']['response_status'] ?? null;
        return $this->eventPriority(
            (string) $event['event_type'],
            (string) ($event['payload']['level'] ?? 'warning'),
            is_numeric($status) ? (int) $status : null,
        );
    }

    private function eventPriority(string $eventType, string $level, ?int $statusCode = null): int
    {
        if ($eventType === 'backend_exception') {
            return 3;
        }
        if ($eventType === 'error_suppressed') {
            return 2;
        }
        if ($eventType === 'request_event') {
            return $statusCode !== null && $statusCode >= 400 ? 2 : 1;
        }
        if ($eventType !== 'log_event') {
            return 1;
        }
        return (self::LEVEL_RANKS[$level] ?? 0) >= self::LEVEL_RANKS['error'] ? 2 : 0;
    }

    /** @param array<string, mixed> $event */
    private function recordEventPressure(array $event): void
    {
        $this->recordPressure(
            (string) $event['event_type'],
            (string) ($event['payload']['level'] ?? 'warning'),
            (string) ($event['occurred_at'] ?? gmdate('Y-m-d\TH:i:s\Z'))
        );
    }

    private function recordPressure(string $eventType, string $level, string $occurredAt): void
    {
        $kind = match ($eventType) {
            'backend_exception' => 'exception',
            'request_event' => 'request',
            'log_event' => $this->normalizeLevel($level),
            default => 'other',
        };
        $state = $this->pressureDrops[$kind] ?? ['count' => 0, 'first_seen' => $occurredAt, 'last_seen' => $occurredAt];
        $state['count']++;
        $state['last_seen'] = $occurredAt;
        $this->pressureDrops[$kind] = $state;
    }

    /** @param list<array<string, mixed>> $events */
    private function replaceBufferedEvents(array $events): void
    {
        $this->buffer = [];
        $this->bufferBytes = 0;
        $this->bufferPriorities = [0, 0, 0, 0];
        foreach ($events as $event) {
            $this->admitProtectedEvent($event);
        }
    }

    private function appendPressureAggregates(): void
    {
        foreach ($this->pressureDrops as $kind => $state) {
            if ($state['count'] <= 0 || count($this->buffer) >= self::MAX_BUFFER_EVENTS) {
                continue;
            }
            $event = $this->applyBeforeSend($this->baseEvent('error_suppressed', [
                'fingerprint' => hash('sha256', 'php-queue-pressure:' . $kind),
                'suppressed_count' => $state['count'],
                'window_seconds' => 60,
                'first_seen' => $state['first_seen'],
                'last_seen' => $state['last_seen'],
                'reason' => 'queue_pressure',
                'level' => $kind,
            ]));
            if ($event !== null && $this->admitProtectedEvent($event, false)) {
                unset($this->pressureDrops[$kind]);
            }
        }
    }

    /** One bounded best-effort automatic delivery attempt per PHP request. */
    public function flushAtRequestEnd(): void
    {
        if ($this->requestEndAttempted || !$this->enabled) {
            return;
        }
        $this->requestEndAttempted = true;
        $this->appendSuppressionAggregates();
        $this->appendPressureAggregates();

        $limit = min($this->batchSize, self::REQUEST_END_MAX_EVENTS);
        [$selected, $dropped] = $this->selectRequestEndEvents($this->buffer, $limit);
        // Reserve one slot for one loss summary even when every retained item is an exception.
        if ($dropped !== [] && count($selected) === $limit && $limit > 1) {
            $reserved = array_pop($selected);
            if ($reserved !== null) {
                $dropped[] = $reserved;
            }
        }
        foreach ($dropped as $event) {
            $this->recordEventPressure($event);
        }
        $this->replaceBufferedEvents($selected);
        $this->appendPressureAggregates();
        [$final] = $this->selectRequestEndEvents($this->buffer, $limit);
        $this->replaceBufferedEvents($final);
        $this->flush();
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function selectRequestEndEvents(array $events, int $limit): array
    {
        usort($events, fn (array $left, array $right): int =>
            $this->eventPriorityForEnvelope($right) <=> $this->eventPriorityForEnvelope($left));
        $selected = [];
        $dropped = [];
        $selectedBytes = 0;
        foreach ($events as $event) {
            try {
                $bytes = strlen(json_encode($event, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                $dropped[] = $event;
                continue;
            }
            if (count($selected) >= $limit || $selectedBytes + $bytes > self::REQUEST_END_MAX_BYTES) {
                $dropped[] = $event;
                continue;
            }
            $selected[] = $event;
            $selectedBytes += $bytes;
        }
        return [$selected, $dropped];
    }
}
