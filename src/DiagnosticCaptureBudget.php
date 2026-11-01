<?php

declare(strict_types=1);

namespace DebugBundle;

/** Normalized DTOs only; one instance/destination. Offline receipts do not renew.
 * Account serialized tracker state as well as pending protected payload bytes.
 * @phpstan-type Window array{starts: float, failure_at: ?float, failure_id: ?string, attempts: array<string, bool>, observations: int, resource: bool, ambiguous: bool, pending: int}
 * @phpstan-type Pending array{key: string, kind: string, attempt: ?string, bytes: int}
 */
final class DiagnosticCaptureBudget
{
    /** @var array<string, Window> */
    private array $windows = [];
    /** @var array<string, Pending> */
    private array $pending = [];

    /** @param array<string, mixed> $reference */
    public function registerFailure(array $reference, string $eventId, float $nowMs): bool
    {
        if (!is_finite($nowMs)) return false;
        $this->prune($nowMs); $key = self::key($reference); $old = $this->windows[$key] ?? null;
        if ($old !== null && $old['failure_id'] !== null && $old['failure_id'] !== $eventId) { $this->windows[$key]['ambiguous'] = true; return false; }
        if ($old === null && count($this->windows) >= 50) return false;
        $window = $old ?? self::window($nowMs); $window['failure_id'] = $eventId; $window['failure_at'] ??= $nowMs;
        $this->windows[$key] = $window;
        if ($this->accountedBytes() > 65536) { if ($old === null) unset($this->windows[$key]); else $this->windows[$key] = $old; return false; }
        return !$window['ambiguous'];
    }

    /** @param array<string, mixed> $payload */
    public function reserve(string $eventId, array $payload, int $bytes, float $nowMs): bool
    {
        if (!is_finite($nowMs) || $bytes < 0 || $bytes > 65536 || isset($this->pending[$eventId])) return false;
        $this->prune($nowMs); $key = self::key($payload); $old = $this->windows[$key] ?? null;
        if (($old === null && count($this->windows) >= 50) || ($old['ambiguous'] ?? false)) return false;
        $window = $old ?? self::window($nowMs);
        if ($nowMs < $window['starts'] || $nowMs > ($window['failure_at'] ?? $window['starts']) + 300000 || $window['observations'] >= 20) return false;
        $attempt = ($payload['kind'] ?? null) === 'recovery' && is_string($payload['attempt_id'] ?? null) ? $payload['attempt_id'] : null;
        if ($attempt !== null) {
            if (!isset($window['attempts'][$attempt]) && count($window['attempts']) >= 5) return false;
            $window['attempts'][$attempt] = true;
        } else {
            if ($window['resource'] || $window['failure_id'] !== ($payload['source_event_id'] ?? null) || $window['failure_at'] === null || $nowMs > $window['failure_at'] + 30000) return false;
            $window['resource'] = true;
        }
        $window['observations']++; $window['pending']++;
        $this->windows[$key] = $window;
        $this->pending[$eventId] = ['key' => $key, 'kind' => $attempt === null ? 'resource_observation' : 'recovery', 'attempt' => $attempt, 'bytes' => $bytes];
        if ($this->accountedBytes() > 65536) {
            unset($this->pending[$eventId]); if ($old === null) unset($this->windows[$key]); else $this->windows[$key] = $old; return false;
        }
        return true;
    }

    /** @param array<string, mixed> $payload */
    public function replace(string $originalId, string $eventId, array $payload, int $bytes, float $nowMs): bool
    {
        $entry = $this->pending[$originalId] ?? null;
        $attempt = ($payload['kind'] ?? null) === 'recovery' && is_string($payload['attempt_id'] ?? null) ? $payload['attempt_id'] : null;
        if ($entry !== null && $entry['key'] === self::key($payload) && $entry['kind'] === ($payload['kind'] ?? null) && $entry['attempt'] === $attempt) {
            if ($eventId !== $originalId && isset($this->pending[$eventId])) { $this->release([$originalId]); return false; }
            unset($this->pending[$originalId]); $entry['bytes'] = $bytes; $this->pending[$eventId] = $entry;
            if ($bytes < 0 || $bytes > 65536 || $this->accountedBytes() > 65536 || !$this->eligible($eventId, $payload)) { $this->release([$eventId]); return false; }
            return true;
        }
        $this->release([$originalId]); return $this->reserve($eventId, $payload, $bytes, $nowMs);
    }

    /** @param array<string, mixed> $payload */
    public function eligible(string $eventId, array $payload): bool
    {
        $entry = $this->pending[$eventId] ?? null; $window = $this->windows[self::key($payload)] ?? null;
        return $entry !== null && $entry['key'] === self::key($payload) && $window !== null && !$window['ambiguous']
            && (($payload['kind'] ?? null) === 'recovery' || $window['failure_id'] === ($payload['source_event_id'] ?? null));
    }
    /** @param list<string> $ids */
    public function release(array $ids): void
    {
        foreach ($ids as $id) {
            $entry = $this->pending[$id] ?? null; if ($entry === null) continue;
            unset($this->pending[$id]);
            if (isset($this->windows[$entry['key']])) $this->windows[$entry['key']]['pending'] = max(0, $this->windows[$entry['key']]['pending'] - 1);
        }
    }
    public function accountedBytes(): int
    {
        if ($this->windows === [] && $this->pending === []) return 0;
        return strlen(json_encode([$this->windows, $this->pending], JSON_THROW_ON_ERROR)) + array_sum(array_column($this->pending, 'bytes'));
    }
    public function clear(): void { $this->windows = []; $this->pending = []; }
    private function prune(float $nowMs): void { foreach ($this->windows as $key => $window) if ($window['pending'] === 0 && $nowMs >= $window['starts'] + 300000) unset($this->windows[$key]); }
    /** @return Window */
    private static function window(float $nowMs): array { return ['starts' => $nowMs, 'failure_at' => null, 'failure_id' => null, 'attempts' => [], 'observations' => 0, 'resource' => false, 'ambiguous' => false, 'pending' => 0]; }
    /** @param array<string, mixed> $reference */
    private static function key(array $reference): string { return hash('sha256', json_encode([$reference['failure_ref'] ?? null, $reference['scope'] ?? null], JSON_THROW_ON_ERROR)); }
}
