<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkEventBoundary
{
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
            if (!TelemetryPrivacy::hasSafeEventIdentity($event, $this->redactFields)) {
                return null;
            }
            $fields = TelemetryPrivacy::protect([
                'payload' => $event['payload'] ?? null,
                'context' => $event['context'] ?? null,
                'service' => $event['service'] ?? null,
            ], $this->redactFields);
            if (!is_array($fields) || !is_array($fields['payload'] ?? null)
                || !is_array($fields['service'] ?? null)
                || !is_string($fields['service']['name'] ?? null)
                || !is_string($fields['service']['environment'] ?? null)) {
                return null;
            }
            $event['payload'] = $fields['payload'];
            $event['service'] = $fields['service'];
            if (isset($event['context'])) {
                if (!is_array($fields['context'])) {
                    return null;
                }
                $event['context'] = $fields['context'];
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
