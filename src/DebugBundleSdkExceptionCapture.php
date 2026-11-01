<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkExceptionCapture
{
    /** @param array<string, mixed>|null $lastError */
    private function handleShutdown(?array $lastError): void
    {
        if ($lastError !== null && in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->captureExceptionInternal(
                new \ErrorException(
                    (string) ($lastError['message'] ?? 'fatal error'),
                    0,
                    (int) ($lastError['type'] ?? E_ERROR),
                    (string) ($lastError['file'] ?? ''),
                    (int) ($lastError['line'] ?? 0),
                ),
                null,
                false,
                false,
                $lastError
            );
        }

        $this->flushAtRequestEnd();
    }

    /**
     * @param array<string, mixed>|null $context
     * @param array<string, mixed>|null $nativeFatal
     */
    private function captureExceptionInternal(
        \Throwable $error,
        ?array $context,
        bool $handled,
        bool $runBeforeSend = true,
        ?array $nativeFatal = null,
        bool $captureTrace = false
    ): void
    {
        try {
            $this->captureNativeException($error, $context, $handled, $runBeforeSend, $nativeFatal, $captureTrace);
        } catch (\Throwable) {
            // Native evidence and optional capture must never escape into the host application.
        }
    }

    /**
     * @param array<string, mixed>|null $context
     * @param array<string, mixed>|null $nativeFatal
     */
    private function captureNativeException(
        \Throwable $error,
        ?array $context,
        bool $handled,
        bool $runBeforeSend = true,
        ?array $nativeFatal = null,
        bool $captureTrace = false
    ): void
    {
        if (!$this->enabled) {
            return;
        }
        if (!$this->preAdmissionAllows('backend_exception')) {
            return;
        }

        if (isset($this->capturedExceptions[$error])) {
            return;
        }

        $this->capturedExceptions[$error] = true;

        $redactedContext = $this->redactArray($context ?? []);
        $reference = $this->supportsEvidence('recovery_v1') ? EvidenceWire::captureReference($redactedContext, $this->operations->scope()) : null;
        $execution = $this->operations->snapshot();
        $additional = [];
        if ($reference !== null) $additional['evidence_reference'] = $reference;
        if ($execution !== null) $additional['execution'] = $execution;
        $maximum = ($runBeforeSend ? 32768 : 4096) - strlen(json_encode($additional, JSON_THROW_ON_ERROR)) - 64;
        $captureSnapshot = ExceptionEvidence::snapshot($error, !$runBeforeSend, $maximum, $captureTrace);
        $snapshot = $nativeFatal === null ? $captureSnapshot : ExceptionEvidence::fromFatal($nativeFatal, $maximum);
        $message = $error->getMessage();
        $payload = [
            'name' => $error::class,
            'message' => $message === '' ? $error::class : $message,
            'stack' => ExceptionEvidence::legacyStack($error, $captureSnapshot),
            'handled' => $handled,
            'request' => $this->buildRequestPayload($redactedContext['request'] ?? null),
            'response' => $this->buildResponsePayload($redactedContext['response'] ?? null),
            'runtime' => $this->buildRuntimePayload(),
        ];
        if ($this->captureExceptionEvidence && $this->supportsEvidence('exception_evidence_v1')) {
            $payload['exception_evidence'] = $snapshot;
            if ($execution !== null) $payload['execution'] = $execution;
        }

        if ($this->probeFlushOnError) {
            $probeData = $this->buildProbeData();
            if ($probeData !== null) {
                $payload['probe_data'] = $probeData;
            }
        }

        $event = $this->baseEvent('backend_exception', $payload, $redactedContext);
        if ($reference !== null) $event['context']['evidence_reference'] = $reference;
        if ($runBeforeSend) {
            $event = $this->applyBeforeSend($event);
        }
        if ($event === null) return;
        if (!$this->passesSampleRate()) {
            $this->releaseDiagnostic($event);
            return;
        }

        $this->enqueueEvent($event);
    }

}
