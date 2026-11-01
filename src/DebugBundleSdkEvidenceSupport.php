<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkEvidenceSupport
{
    /** @var list<string> */
    private array $evidenceFeatures = [];
    private bool $captureExceptionEvidence = true;
    private bool $captureRecoveryObservations = false;
    private DiagnosticCaptureBudget $diagnosticBudget;

    /** @param array<string, mixed> $config */
    private function initializeEvidence(array $config): void
    {
        $this->evidenceFeatures = EvidenceWire::descriptor($config['evidenceCapabilities'] ?? null)['features'];
        $this->captureExceptionEvidence = !array_key_exists('captureExceptionEvidence', $config) || $config['captureExceptionEvidence'] === true;
        $this->captureRecoveryObservations = ($config['captureRecoveryObservations'] ?? false) === true;
    }
    private function resetEvidence(): void { $this->evidenceFeatures = []; $this->captureExceptionEvidence = true; $this->captureRecoveryObservations = false; $this->diagnosticBudget = new DiagnosticCaptureBudget(); }
    private function supportsEvidence(string $feature): bool { return in_array($feature, $this->evidenceFeatures, true); }
    private function allowsRecovery(): bool { return $this->captureRecoveryObservations && $this->supportsEvidence('recovery_v1') && $this->capturePolicy->preset !== 'minimal' && $this->capturePolicy->captureDiagnosticContext; }

    /** @param array<string, mixed> $observation */
    public function captureRecovery(array $observation): void
    {
        if (!$this->enabled || !$this->allowsRecovery() || !$this->preAdmissionAllows('diagnostic_context')) return;
        $generation = $this->generation;
        $owned = null;
        try {
            $payload = EvidenceWire::recovery($observation, $this->operations->scope()); if ($payload === null) return;
            $correlation = [];
            if (is_array($observation['correlation'] ?? null)) foreach (['request_id', 'trace_id', 'session_id', 'user_id_hash'] as $key) {
                $value = $observation['correlation'][$key] ?? null; if (is_string($value) || $value === null) $correlation[$key] = $value;
            }
            $original = $this->protectEvent($this->baseEvent('diagnostic_context', $payload, $correlation));
            if ($original === null || !is_string($original['event_id'] ?? null) || !$this->diagnosticBudget->reserve($original['event_id'], $payload,
                strlen(json_encode($original, JSON_THROW_ON_ERROR)), $this->now() * 1000)) return;
            $owned = $original;
            $event = $this->applyBeforeSend($original);
            if ($generation !== $this->generation) return;
            $owned = $event;
            if ($event !== null && !$this->passesSampleRate()) { $this->releaseDiagnostic($event); $event = null; }
            if ($event !== null) $this->enqueueEvent($event);
        } catch (\Throwable) {
            if ($generation === $this->generation && $owned !== null) $this->releaseDiagnostic($owned);
        }
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed>|null $replacement
     * @return array<string, mixed>|null
     */
    private function reconcileEvidence(array $original, ?array $replacement): ?array
    {
        $event = $replacement === null ? null : EvidenceWire::supported($replacement, $this->evidenceFeatures);
        if (($event['event_type'] ?? null) === 'diagnostic_context') {
            $id = $event['event_id'] ?? null; $payload = $event['payload'] ?? null;
            if (!$this->allowsRecovery() || !is_string($id) || !is_array($payload) || ($payload['kind'] ?? null) !== 'recovery'
                || !EvidenceWire::optionalEvidence($event)) {
                $this->releaseDiagnostic($original); return null;
            }
            $bytes = strlen(json_encode($event, JSON_THROW_ON_ERROR));
            $owned = ($original['event_type'] ?? null) === 'diagnostic_context' && is_string($original['event_id'] ?? null)
                ? $this->diagnosticBudget->replace($original['event_id'], $id, $payload, $bytes, $this->now() * 1000)
                : $this->diagnosticBudget->reserve($id, $payload, $bytes, $this->now() * 1000);
            if (!$owned) { $this->releaseDiagnostic($original); return null; }
        } else {
            $this->releaseDiagnostic($original);
            $context = is_array($event['context'] ?? null) ? $event['context'] : [];
            $reference = EvidenceWire::reference($context['evidence_reference'] ?? null);
            if ($event !== null && $reference !== null && is_string($event['event_id'] ?? null) && is_string($event['occurred_at'] ?? null)) {
                $at = strtotime($event['occurred_at']); if ($at !== false) $this->diagnosticBudget->registerFailure($reference, $event['event_id'], $at * 1000.0);
            }
        }
        return $event;
    }
    /** @param array<string, mixed> $event */
    private function releaseDiagnostic(array $event): void { if (($event['event_type'] ?? null) === 'diagnostic_context' && is_string($event['event_id'] ?? null)) $this->diagnosticBudget->release([$event['event_id']]); }
    private function finalizeEvidenceBuffer(): void
    {
        $events = [];
        foreach ($this->buffer as $original) {
            $candidate = $this->transport instanceof \DebugBundle\Transport\HttpTransport ? IngestionWire::fit($original) : $original;
            $event = $this->reconcileEvidence($original, $candidate);
            if ($event !== null) $events[] = $event;
        }
        $this->replaceBufferedEvents($events);
    }
    private function observeEvidence(mixed $body, bool $required = false, bool $valid = true): bool
    {
        $supplied = is_array($body) && array_key_exists('evidence_capabilities', $body);
        if (!$required && !$supplied) return false;
        $features = EvidenceWire::descriptor($supplied && $valid ? $body['evidence_capabilities'] : null)['features'];
        $lost = array_diff($this->evidenceFeatures, $features) !== []; $this->evidenceFeatures = $features; return $lost;
    }
}
