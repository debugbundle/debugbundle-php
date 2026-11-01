<?php

declare(strict_types=1);

require __DIR__ . '/../smoke/evidence_runtime_support.php';
loadEvidenceRuntimeSdk(null);

function retainedProperty(object $owner, string $name): mixed
{
    return (new ReflectionProperty($owner, $name))->getValue($owner);
}

function compareEvidence(bool $enabled, bool $pressure): array
{
    $transport = new class implements \DebugBundle\Transport\TransportInterface {
        public function send(array $request): \DebugBundle\Transport\TransportResponse
        {
            return new \DebugBundle\Transport\TransportResponse(202, null,
                ['accepted' => count($request['events']), 'rejected' => 0, 'errors' => []]);
        }
    };
    $sdk = new \DebugBundle\DebugBundleSdk($transport);
    $sdk->init(['projectToken' => 'synthetic', 'environment' => 'development', 'batchSize' => 1001,
        'captureExceptionEvidence' => $enabled, 'captureRecoveryObservations' => $enabled,
        'evidenceCapabilities' => ['version' => 1, 'features' => ['exception_evidence_v1', 'recovery_v1']]]);
    try {
        if ($pressure) {
            for ($index = 0; $index < 1000; $index++) $sdk->captureLog("warning $index", 'warning');
            evidenceRuntimeAssert(count(retainedProperty($sdk, 'buffer')) === 1000, 'warning queue not filled');
        }
        $calls = [];
        for ($index = 0; $index < 20; $index++) {
            $context = ['failureRef' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                'evidenceScope' => ['kind' => 'operation', 'operation_id' => '00000000-0000-4000-8000-000000000100']];
            $calls[] = [new RuntimeException("outer $index", 0, new Exception("inner $index")), $context,
                $context + ['attemptId' => sprintf('00000000-0000-4000-8000-%012d', $index + 200),
                    'state' => 'attempt_completed', 'statusCode' => 200]];
        }
        // PHP exposes peak/live allocation, not a cumulative allocation counter.
        // Reset after fixture/queue setup; the same instrumentation covers both modes.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(false);
        $started = hrtime(true);
        foreach ($calls as [$error, $context, $observation]) {
            $sdk->captureException($error, $context);
            $sdk->captureRecovery($observation);
        }
        $elapsed = hrtime(true) - $started;
        $peak = memory_get_peak_usage(false) - $baseline;
        $live = memory_get_usage(false) - $baseline;
        $events = retainedProperty($sdk, 'buffer');
        $failures = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'backend_exception'));
        evidenceRuntimeAssert(count($failures) === count($calls), 'original failures displaced');
        foreach ($failures as $event) {
            evidenceRuntimeAssert(isset($event['payload']['exception_evidence']) === $enabled, 'evidence mode not exercised');
            if ($enabled) evidenceRuntimeAssert(count($event['payload']['exception_evidence']['nodes']) === 2, 'native previous cause lost');
        }
        $diagnostics = count(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'diagnostic_context'));
        // Existing PHP context priority may replace warnings, but never original exceptions.
        evidenceRuntimeAssert($diagnostics === ($enabled ? count($calls) : 0), 'recovery admission changed');
        $bytes = retainedProperty($sdk, 'bufferBytes');
        $diagnosticBytes = retainedProperty($sdk, 'diagnosticBudget')->accountedBytes();
        evidenceRuntimeAssert(count($events) <= 1000 && $bytes <= 8 * 1024 * 1024 && $diagnosticBytes <= 65536, 'retention ceiling exceeded');
        return ['php' => PHP_VERSION, 'evidence' => $enabled, 'pressure' => $pressure, 'pairs' => count($calls),
            'capture_ns_per_pair' => $elapsed / count($calls), 'peak_allocated_bytes' => $peak, 'live_allocated_bytes' => $live,
            'retained_count' => count($events), 'retained_wire_bytes' => $bytes, 'diagnostic_bytes' => $diagnosticBytes];
    } finally { $sdk->reset(); }
}

compareEvidence(true, false);
for ($repeat = 1; $repeat <= 3; $repeat++) {
    foreach ([false, true] as $pressure) foreach ([false, true] as $enabled) {
        echo json_encode(['repeat' => $repeat] + compareEvidence($enabled, $pressure), JSON_THROW_ON_ERROR) . "\n";
    }
}
