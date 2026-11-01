<?php

declare(strict_types=1);

namespace DebugBundle;

/** A Fiber never inherits the main request implicitly. Completion and reset
 * revoke ownership, including for detached work resumed after a request ends. */
final class OperationContext
{
    private ?OperationState $main = null;
    /** @var \WeakMap<object, OperationState> */
    private \WeakMap $fibers;
    /** @var \WeakMap<object, OperationState> */
    private \WeakMap $owners;
    private int $generation = 0;
    public function __construct() { $this->fibers = new \WeakMap(); $this->owners = new \WeakMap(); }

    public function current(): ?OperationState
    {
        $fiber = \Fiber::getCurrent();
        $state = $fiber === null ? $this->main : ($this->fibers[$fiber] ?? null);
        return $state?->active === true ? $state : null;
    }

    /** @param array<string, mixed> $options
     * @param array<string, string> $correlation
     * @param list<RemoteProbeDirective> $directives
     */
    public function begin(array $options, array $correlation = [], array $directives = [], ?object $owner = null): ?OperationState
    {
        $this->end();
        $state = $this->create($options, $correlation, $directives);
        $this->set($state);
        if ($owner !== null && $state !== null) {
            $previous = $this->owners[$owner] ?? null; if ($previous !== null) $previous->active = false;
            $this->owners[$owner] = $state;
        }
        return $state;
    }

    public function end(?object $owner = null): void
    {
        if ($owner !== null) {
            $state = $this->owners[$owner] ?? null;
            if ($state === null) return;
            unset($this->owners[$owner]);
            $current = $this->current(); $state->active = false;
            if ($current === $state) $this->set(null);
            return;
        }
        $state = $this->current(); if ($state !== null) $state->active = false;
        $this->set(null);
    }

    /** @template Result
     * @param array<string, mixed> $options
     * @param callable(): Result $callback
     * @return Result
     */
    public function run(array $options, callable $callback): mixed
    {
        $previous = $this->current(); $generation = $this->generation;
        $state = $this->create($options, $previous->correlation ?? [], $previous->directives ?? []);
        $this->set($state);
        try { return $callback(); }
        finally {
            if ($state !== null) $state->active = false;
            if ($generation === $this->generation) $this->set($previous?->active === true ? $previous : null);
        }
    }

    /** @return array<string, mixed>|null */
    public function snapshot(): ?array
    {
        $state = $this->current();
        return $state === null ? null : ['version' => 1, 'kind' => $state->kind, 'name' => $state->name,
            'elapsed_ms' => max(0.0, (hrtime(true) - $state->started) / 1_000_000), 'limit_ms' => $state->limit];
    }
    /** @return array<string, mixed>|null */
    public function scope(): ?array
    {
        $state = $this->current(); return $state === null ? null : ['kind' => 'operation', 'operation_id' => $state->id];
    }
    public function clear(): void
    {
        $this->generation++; $this->main = null; $this->fibers = new \WeakMap(); $this->owners = new \WeakMap();
    }
    private function set(?OperationState $state): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) $this->main = $state;
        elseif ($state === null) unset($this->fibers[$fiber]);
        else $this->fibers[$fiber] = $state;
    }
    /** @param array<string, mixed> $options
     * @param array<string, string> $correlation
     * @param list<RemoteProbeDirective> $directives
     */
    private function create(array $options, array $correlation, array $directives): ?OperationState
    {
        try {
            $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
            $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            $kind = in_array($options['kind'] ?? null, ['request', 'command', 'job'], true) ? $options['kind'] : 'unknown';
            $name = $options['name'] ?? null;
            if (!is_string($name) || strlen($name) > 128 || $name === '' || TelemetryPrivacy::protect($name) !== $name) $name = null;
            $limit = $options['limitMs'] ?? null;
            if ((!is_int($limit) && !is_float($limit)) || !is_finite((float) $limit) || $limit < 0) $limit = null;
            return new OperationState($id, $kind, $name, hrtime(true), $limit, $correlation, $directives);
        } catch (\Throwable) { return null; }
    }
}
