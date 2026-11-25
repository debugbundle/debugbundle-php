<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkEventBoundary
{
    private const MAX_PROTECTED_EVENT_BYTES = 262144;

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function applyBeforeSend(array $event): ?array
    {
        $generation = $this->generation;
        $protected = $this->protectEvent($event);
        if ($protected === null) {
            return null;
        }
        $prepared = BeforeSend::apply($protected, $this->beforeSend);
        if ($generation !== $this->generation) return null;
        $candidate = $prepared === null ? null : $this->protectEvent($prepared);
        // Privacy may withhold a map as a scalar. Recheck the complete envelope
        // before policy/delivery; fatal capture bypasses this ordinary hook path.
        if ($candidate !== null && !CanonicalEventSchema::valid($candidate)) $candidate = null;
        if ($candidate !== null && !$this->candidatePolicyAllows($candidate)) $candidate = null;
        return $this->reconcileEvidence($protected, $candidate);
    }

    /** @param array<string, mixed> $event
     *  @return array<string, mixed>|null
     */
    private function protectEvent(array $event): ?array
    {
        try {
            if (!TelemetryPrivacy::hasSafeEventIdentity($event, $this->redactFields, $this->redactExactFields)) {
                return null;
            }
            $rawPayload = $event['payload'] ?? null;
            $rawContext = $event['context'] ?? null;
            $preservedPayload = [];
            $diagnosticPayload = null;

            if (is_array($rawPayload)) {
                $eventType = $event['event_type'] ?? null;
                if ($eventType === 'diagnostic_context') {
                    $diagnosticPayload = $this->sanitizeDiagnosticContextPayload($rawPayload);
                    if ($diagnosticPayload === null) {
                        return null;
                    }
                } else {
                    if (in_array($eventType, ['backend_exception', 'frontend_exception'], true)
                        && array_key_exists('exception_evidence', $rawPayload)
                        && EvidenceWire::graph($rawPayload['exception_evidence'], false)) {
                        /** @var array<string, mixed> $graph */
                        $graph = $rawPayload['exception_evidence'];
                        $safeGraph = $this->sanitizeExceptionEvidenceBlock($graph);
                        if ($safeGraph !== null) {
                            $preservedPayload['exception_evidence'] = $safeGraph;
                            unset($rawPayload['exception_evidence']);
                        }
                    }
                    if ($eventType === 'backend_exception'
                        && array_key_exists('execution', $rawPayload)
                        && EvidenceWire::execution($rawPayload['execution'])) {
                        /** @var array<string, mixed> $execution */
                        $execution = $rawPayload['execution'];
                        $safeExecution = $this->sanitizeExecutionBlock($execution);
                        if ($safeExecution !== null) {
                            $preservedPayload['execution'] = $safeExecution;
                            unset($rawPayload['execution']);
                        }
                    }
                    if ($eventType === 'frontend_exception'
                        && array_key_exists('resource_observations', $rawPayload)
                        && EvidenceWire::resources($rawPayload['resource_observations'])) {
                        /** @var array<string, mixed> $observations */
                        $observations = $rawPayload['resource_observations'];
                        $safeObservations = $this->sanitizeResourceObservationsBlock($observations);
                        if ($safeObservations !== null) {
                            $preservedPayload['resource_observations'] = $safeObservations;
                            unset($rawPayload['resource_observations']);
                        }
                    }
                    if ($eventType === 'error_suppressed' && ($rawPayload['reason'] ?? null) === 'queue_pressure') {
                        $preservedPayload['reason'] = 'queue_pressure';
                        unset($rawPayload['reason']);
                    }
                }
            }

            $preservedContext = [];
            if (is_array($rawContext)) {
                if (array_key_exists('evidence_reference', $rawContext)) {
                    $validRef = EvidenceWire::reference($rawContext['evidence_reference']);
                    if ($validRef !== null) {
                        $preservedContext['evidence_reference'] = $validRef;
                        unset($rawContext['evidence_reference']);
                    }
                }
                if (array_key_exists('evidence_limits', $rawContext)
                    && $this->isValidEvidenceLimits($rawContext['evidence_limits'])) {
                    $preservedContext['evidence_limits'] = $rawContext['evidence_limits'];
                    unset($rawContext['evidence_limits']);
                }
            }

            $fields = TelemetryPrivacy::protect([
                'payload' => $diagnosticPayload === null ? $rawPayload : [],
                'context' => $rawContext,
                'service' => $event['service'] ?? null,
            ], $this->redactFields, $this->redactExactFields);
            if (!is_array($fields) || !is_array($fields['payload'] ?? null)
                || !is_array($fields['service'] ?? null)
                || !is_string($fields['service']['name'] ?? null)
                || !is_string($fields['service']['environment'] ?? null)) {
                return null;
            }
            $protectedPayload = $diagnosticPayload !== null
                ? $diagnosticPayload
                : array_merge($fields['payload'], $preservedPayload);
            $event['payload'] = $protectedPayload;
            $event['service'] = $fields['service'];
            if (isset($event['context'])) {
                if (!is_array($fields['context'])) {
                    return null;
                }
                $event['context'] = array_merge($fields['context'], $preservedContext);
            }
            $encoded = json_encode([
                'payload' => $event['payload'],
                'service' => $event['service'],
                'context' => $event['context'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (strlen($encoded) > self::MAX_PROTECTED_EVENT_BYTES) {
                $reduced = EvidenceWire::withoutOptional($event);
                return $reduced === null ? null : $this->protectEvent($reduced);
            }
            if (!EvidenceWire::optionalEvidence($event)) {
                $reduced = EvidenceWire::withoutOptional($event);
                return $reduced === null ? null : $this->protectEvent($reduced);
            }
            return $event;
        } catch (\Throwable) {
            $reduced = EvidenceWire::withoutOptional($event);
            return $reduced === null || $reduced === $event ? null : $this->protectEvent($reduced);
        }
    }

    private function scrubProtocolString(string $value): ?string
    {
        $protected = TelemetryPrivacy::protect($value, $this->redactFields, $this->redactExactFields);
        return is_string($protected) ? $protected : null;
    }

    /** @param array<string, mixed> $graph
     *  @return array<string, mixed>|null
     */
    private function sanitizeExceptionEvidenceBlock(array $graph): ?array
    {
        /** @var list<array<string, mixed>> $rawNodes */
        $rawNodes = $graph['nodes'];
        $nodes = [];
        foreach ($rawNodes as $node) {
            $name = $this->scrubProtocolString((string) $node['name']);
            $message = $this->scrubProtocolString((string) $node['message']);
            $stack = $node['stack'] === null ? null : $this->scrubProtocolString((string) $node['stack']);
            if ($name === null || $message === null || ($node['stack'] !== null && $stack === null)) {
                return null;
            }
            /** @var list<array<string, mixed>> $rawFrames */
            $rawFrames = $node['frames'];
            $frames = [];
            foreach ($rawFrames as $frame) {
                $fn = $frame['function'] === null ? null : $this->scrubProtocolString((string) $frame['function']);
                $file = $frame['file'] === null ? null : $this->scrubProtocolString((string) $frame['file']);
                if (($frame['function'] !== null && $fn === null) || ($frame['file'] !== null && $file === null)) {
                    return null;
                }
                $frames[] = [
                    'function' => $fn,
                    'file' => $file,
                    'line' => $frame['line'],
                    'column' => $frame['column'],
                    'classification' => $frame['classification'],
                ];
            }
            $nodes[] = [
                'id' => $node['id'],
                'name' => $name,
                'message' => $message,
                'stack' => $stack,
                'trace_origin' => $node['trace_origin'],
                'parsing_status' => $node['parsing_status'],
                'frames' => $frames,
                'relationships' => $node['relationships'],
            ];
        }

        return [
            'version' => $graph['version'],
            'runtime' => $graph['runtime'],
            'root_id' => $graph['root_id'],
            'nodes' => $nodes,
            'truncated' => $graph['truncated'],
            'unavailable_reason' => $graph['unavailable_reason'],
        ];
    }

    /** @param array<string, mixed> $execution
     *  @return array<string, mixed>|null
     */
    private function sanitizeExecutionBlock(array $execution): ?array
    {
        $name = $execution['name'] === null ? null : $this->scrubProtocolString((string) $execution['name']);
        if ($execution['name'] !== null && $name === null) {
            return null;
        }

        return [
            'version' => $execution['version'],
            'kind' => $execution['kind'],
            'name' => $name,
            'elapsed_ms' => $execution['elapsed_ms'],
            'limit_ms' => $execution['limit_ms'],
        ];
    }

    /** @param array<string, mixed> $observations
     *  @return array<string, mixed>|null
     */
    private function sanitizeResourceObservationsBlock(array $observations): ?array
    {
        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = $observations['items'];
        $items = [];
        foreach ($rawItems as $item) {
            $sourceUrl = $item['source_url'] === null ? null : $this->scrubProtocolString((string) $item['source_url']);
            if ($item['source_url'] !== null && $sourceUrl === null) {
                return null;
            }
            $item['source_url'] = $sourceUrl;
            $items[] = $item;
        }

        return [
            'version' => $observations['version'],
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>|null
     */
    private function sanitizeDiagnosticContextPayload(array $payload): ?array
    {
        if (!EvidenceWire::diagnostic($payload)) {
            return null;
        }
        if (($payload['kind'] ?? null) === 'recovery') {
            if (array_key_exists('operation', $payload) && is_string($payload['operation'])) {
                $operation = $this->scrubProtocolString($payload['operation']);
                if ($operation === null) {
                    return null;
                }
                $payload['operation'] = $operation;
            }
            return $payload;
        }
        /** @var array<string, mixed> $observations */
        $observations = $payload['observations'];
        $safeObservations = $this->sanitizeResourceObservationsBlock($observations);
        if ($safeObservations === null) {
            return null;
        }
        $payload['observations'] = $safeObservations;
        return $payload;
    }

    private function isValidEvidenceLimits(mixed $value): bool
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1) {
            return false;
        }
        $allowedKeys = ['version', 'exception_evidence', 'execution', 'resource_observations'];
        $allowedReasons = ['budget_exceeded', 'unsafe', 'unsupported', 'unknown'];
        foreach ($value as $key => $reason) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                return false;
            }
            if ($key === 'version') {
                continue;
            }
            if (!is_string($reason) || !in_array($reason, $allowedReasons, true)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $event */
    private function candidatePolicyAllows(array $event): bool
    {
        $payload = $event['payload'];
        return match ($event['event_type']) {
            'diagnostic_context' => $this->allowsRecovery(),
            'log_event' => $this->capturePolicy->captureLogs !== 'off'
                && $this->levelEnabled($this->normalizeLevel($payload['level']), $this->effectiveLogThreshold()),
            'request_event' => $this->shouldCaptureRequestEvent($payload, ['status_code' => $payload['response_status']]),
            'frontend_breadcrumb' => $this->capturePolicy->captureBreadcrumbs === 'standalone',
            'probe_event' => $this->probesEnabled && $this->capturePolicy->captureProbeEvents === 'standalone_when_activated'
                && $this->probeCandidateActivated($payload),
            default => true,
        };
    }

    /** @param array<string, mixed> $payload */
    private function probeCandidateActivated(array $payload): bool
    {
        foreach ($this->findMatchingProbeDirectives($payload['label']) as $directive) {
            if ($directive->id === $payload['activation_id'] && $directive->labelPattern === $payload['probe_label_pattern']) return true;
        }
        return false;
    }
}
