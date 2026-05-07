<?php

declare(strict_types=1);

namespace DebugBundle;

final class Suppression
{
    private const DUPLICATE_WINDOW_SECONDS = 30.0;
    private const LOOP_WINDOW_SECONDS = 2.0;
    private const LOOP_THRESHOLD = 10;
    private const LOOP_RESET_AFTER_SECONDS = 60.0;
    private const LOOP_CHECKPOINT_SECONDS = 30.0;
    private const MAX_NORMAL_EVENTS_PER_WINDOW = 3;

    /** @var array<string, array<string, float|int|bool|null>> */
    private array $states = [];

    public function shouldCapture(string $key, float $now): bool
    {
        if (!isset($this->states[$key])) {
            $this->states[$key] = [
                'window_started_at' => $now,
                'emitted_count' => 0,
                'pending_suppressed_count' => 0,
                'pending_first_seen_at' => null,
                'pending_last_seen_at' => null,
                'last_aggregate_emitted_at' => null,
                'loop_window_started_at' => $now,
                'loop_hit_count' => 0,
                'suppression_mode' => false,
                'last_seen_at' => $now,
            ];
        }

        $state = &$this->states[$key];

        if (($state['suppression_mode'] === true) && ($now - (float) $state['last_seen_at']) >= self::LOOP_RESET_AFTER_SECONDS) {
            $this->states[$key] = [
                'window_started_at' => $now,
                'emitted_count' => 0,
                'pending_suppressed_count' => 0,
                'pending_first_seen_at' => null,
                'pending_last_seen_at' => null,
                'last_aggregate_emitted_at' => null,
                'loop_window_started_at' => $now,
                'loop_hit_count' => 0,
                'suppression_mode' => false,
                'last_seen_at' => $now,
            ];
            $state = &$this->states[$key];
        }

        if (($now - (float) $state['window_started_at']) >= self::DUPLICATE_WINDOW_SECONDS) {
            $state['window_started_at'] = $now;
            $state['emitted_count'] = 0;
        }

        if (($now - (float) $state['loop_window_started_at']) >= self::LOOP_WINDOW_SECONDS) {
            $state['loop_window_started_at'] = $now;
            $state['loop_hit_count'] = 0;
        }

        $state['loop_hit_count'] = (int) $state['loop_hit_count'] + 1;
        $state['last_seen_at'] = $now;

        if ((int) $state['loop_hit_count'] > self::LOOP_THRESHOLD) {
            $state['suppression_mode'] = true;
        }

        if ($state['suppression_mode'] === true) {
            $this->markSuppressed($state, $now);
            return false;
        }

        if ((int) $state['emitted_count'] < self::MAX_NORMAL_EVENTS_PER_WINDOW) {
            $state['emitted_count'] = (int) $state['emitted_count'] + 1;
            return true;
        }

        $this->markSuppressed($state, $now);
        return false;
    }

    /** @return list<array<string, mixed>> */
    public function drainAggregates(float $now): array
    {
        $aggregates = [];

        foreach ($this->states as $key => &$state) {
            if ((int) $state['pending_suppressed_count'] === 0 || $state['pending_first_seen_at'] === null || $state['pending_last_seen_at'] === null) {
                continue;
            }

            if (
                $state['suppression_mode'] === true
                && $state['last_aggregate_emitted_at'] !== null
                && ($now - (float) $state['last_aggregate_emitted_at']) < self::LOOP_CHECKPOINT_SECONDS
            ) {
                continue;
            }

            $aggregates[] = [
                'event_type' => 'error_suppressed',
                'payload' => [
                    'fingerprint' => hash('sha256', $key),
                    'suppressed_count' => (int) $state['pending_suppressed_count'],
                    'first_seen' => self::toIso((float) $state['pending_first_seen_at']),
                    'last_seen' => self::toIso((float) $state['pending_last_seen_at']),
                    'window_seconds' => (int) self::DUPLICATE_WINDOW_SECONDS,
                ],
            ];

            $state['pending_suppressed_count'] = 0;
            $state['pending_first_seen_at'] = null;
            $state['pending_last_seen_at'] = null;
            $state['last_aggregate_emitted_at'] = $now;
        }

        return $aggregates;
    }

    /** @param array<string, float|int|bool|null> $state */
    private function markSuppressed(array &$state, float $now): void
    {
        if ((int) $state['pending_suppressed_count'] === 0) {
            $state['pending_first_seen_at'] = $state['window_started_at'];
        }

        $state['pending_suppressed_count'] = (int) $state['pending_suppressed_count'] + 1;
        $state['pending_last_seen_at'] = $now;
    }

    private static function toIso(float $timestamp): string
    {
        return gmdate('Y-m-d\\TH:i:s', (int) $timestamp) . 'Z';
    }
}