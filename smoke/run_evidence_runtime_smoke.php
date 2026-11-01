<?php

declare(strict_types=1);

require __DIR__ . '/evidence_runtime_support.php';
$autoload = ($argv[1] ?? '') !== '' ? $argv[1] : null;
loadEvidenceRuntimeSdk($autoload);

try {
    $corpus = json_decode((string) file_get_contents(__DIR__ . '/../tests/fixtures/evidence-wire-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    $original = ['schema_version' => '2026-03-01', 'event_id' => '00000000-0000-4000-8000-000000000099',
        'event_type' => 'log_event', 'occurred_at' => '2026-10-26T00:00:00Z', 'sdk_name' => 'debugbundle/sdk-php',
        'sdk_version' => 'original', 'service' => ['name' => 'test', 'environment' => 'test'],
        'payload' => ['level' => 'error', 'message' => 'safe original', 'attributes' => []]];
    foreach ($corpus['events'] as $fixture) {
        $candidate = $fixture['event']; $candidate['sdk_version'] = 'hook-accepted';
        $result = \DebugBundle\BeforeSend::apply($original, static fn (): array => $candidate);
        evidenceRuntimeAssert(($result['sdk_version'] === 'hook-accepted') === $fixture['valid'], 'canonical hook ' . $fixture['id']);
    }
    $transport = new class implements \DebugBundle\Transport\TransportInterface {
        public array $requests = [];
        public function send(array $request): \DebugBundle\Transport\TransportResponse
        {
            $this->requests[] = $request;
            return new \DebugBundle\Transport\TransportResponse(202, null, ['accepted' => count($request['events']), 'rejected' => 0, 'errors' => []]);
        }
    };
    $sdk = new \DebugBundle\DebugBundleSdk($transport);
    $sdk->init(['projectToken' => 'synthetic-project', 'captureRecoveryObservations' => true, 'batchSize' => 1000,
        'evidenceCapabilities' => ['version' => 1, 'features' => ['exception_evidence_v1', 'recovery_v1']]]);
    $result = $sdk->runWithOperationContext(['kind' => 'job', 'name' => 'synthetic.consume'], static function () use ($sdk): int {
        $reference = '00000000-0000-4000-8000-000000000001';
        $sdk->captureException(new RuntimeException('outer', 0, new Exception('inner')), ['failureRef' => $reference]);
        $sdk->captureRecovery(['failureRef' => $reference, 'attemptId' => '00000000-0000-4000-8000-000000000002', 'state' => 'recovery_observed']);
        return 42;
    });
    $sdk->flush(); $sdk->reset();
    $events = $transport->requests[0]['events'];
    evidenceRuntimeAssert($result === 42 && count($events) === 2, 'operation/recovery delivery');
    evidenceRuntimeAssert($events[0]['payload']['exception_evidence']['nodes'][1]['message'] === 'inner', 'native previous exception');
    evidenceRuntimeAssert($events[0]['payload']['execution']['kind'] === 'job', 'owned operation');
    evidenceRuntimeAssert($events[1]['payload']['scope'] === $events[0]['context']['evidence_reference']['scope'], 'same failure scope');

    foreach ([true, false] as $nativeTraceEnabled) {
        $output = tempnam(sys_get_temp_dir(), 'debugbundle-evidence-fatal-');
        if ($output === false) throw new RuntimeException('fatal fixture output');
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'fatal_error_backtraces=' . ($nativeTraceEnabled ? '1' : '0'),
            __DIR__ . '/fixtures/evidence_native_fatal.php', $output, $autoload ?? ''],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('fatal fixture process');
        try {
            fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
            $captured = json_decode((string) file_get_contents($output), true, 32, JSON_THROW_ON_ERROR);
            evidenceRuntimeAssert($exit !== 0 && is_array($captured) && count($captured) === 1, 'native fatal captured once: ' . $stderr);
            $payload = $captured[0]['payload']; $graph = $payload['exception_evidence'];
            evidenceRuntimeAssert($graph['nodes'][0]['trace_origin'] === 'native_fatal', 'fatal origin');
            evidenceRuntimeAssert(str_contains($graph['nodes'][0]['frames'][0]['file'], "eval()'d code"), 'actual fatal source');
            evidenceRuntimeAssert($payload['execution']['kind'] === 'request', 'fatal operation ownership');
            evidenceRuntimeAssert(strlen(json_encode(['exception_evidence' => $graph, 'execution' => $payload['execution']], JSON_THROW_ON_ERROR)) <= 4096, 'fatal total budget');
            if (PHP_VERSION_ID >= 80500 && $nativeTraceEnabled)
                evidenceRuntimeAssert($graph['nodes'][0]['parsing_status'] === 'parsed', 'supplied native fatal trace');
            else evidenceRuntimeAssert($graph['nodes'][0]['parsing_status'] === 'partial', 'honest unavailable native fatal trace');
        } finally { unlink($output); }
    }
    echo 'PHP evidence runtime smoke passed on ' . PHP_VERSION . '. Native fatal traces: ' . (PHP_VERSION_ID >= 80500 ? 'supported' : 'unsupported') . ".\n";
} catch (Throwable $error) {
    if (isset($sdk)) $sdk->reset();
    fwrite(STDERR, 'PHP evidence runtime smoke failed: ' . $error->getMessage() . "\n"); exit(1);
}
