<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Transport\TransportInterface;
use DebugBundle\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class SdkEvidenceTest extends TestCase
{
    private DebugBundleSdk $sdk;
    private const PROOF = ['version' => 1, 'features' => ['exception_evidence_v1', 'recovery_v1']];
    private const FAILURE = '00000000-0000-4000-8000-000000000001';
    private const SCOPE = ['kind' => 'operation', 'operation_id' => '00000000-0000-4000-8000-000000000002'];

    protected function tearDown(): void { if (isset($this->sdk)) $this->sdk->reset(); }

    /** @param array<string, mixed> $config */
    private function fixture(array $config = []): EvidenceRecordingTransport
    {
        $transport = new EvidenceRecordingTransport();
        $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init(array_replace(['projectToken' => 'synthetic-project', 'service' => 'test', 'batchSize' => 1000], $config));
        return $transport;
    }

    public function testCanonicalValidatorFailureCannotEscapeIntoApplicationCapture(): void
    {
        $property = new \ReflectionProperty(\DebugBundle\CanonicalEventSchema::class, 'instance');
        $previous = $property->getValue();
        $broken = (new \ReflectionClass(\DebugBundle\CanonicalEventSchema::class))->newInstanceWithoutConstructor();
        $property->setValue(null, $broken);
        try {
            $transport = $this->fixture();
            $this->sdk->captureLog('safe failure', 'error');
            $this->sdk->flush();
            self::assertSame([], $transport->requests);
        } finally { $property->setValue(null, $previous); }
    }

    public function testRequiresProofAndPreservesOriginalLegacyShapeWhenUnknown(): void
    {
        $transport = $this->fixture(); $this->sdk->captureException(new \Exception('original')); $this->sdk->flush();
        self::assertArrayNotHasKey('exception_evidence', $transport->requests[0]['events'][0]['payload']);
        self::assertSame('original', $transport->requests[0]['events'][0]['payload']['message']);
    }

    public function testMalformedAcknowledgementCannotGrantEvidenceProof(): void
    {
        $transport = new \DebugBundle\Tests\Support\FakeTransport([new TransportResponse(202, null, ['accepted' => 99, 'rejected' => 0, 'errors' => [], 'evidence_capabilities' => self::PROOF])]);
        $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init(['projectToken' => 'synthetic-project', 'batchSize' => 1000]);
        $this->sdk->captureException(new \Exception('legacy first')); $this->sdk->flush();
        self::assertSame([], (new \ReflectionProperty($this->sdk, 'evidenceFeatures'))->getValue($this->sdk));
    }

    public function testCapturesNativeEvidenceAndScopedReferenceWithoutCustomFormatter(): void
    {
        $transport = $this->fixture(['evidenceCapabilities' => self::PROOF]);
        SdkEvidenceUnsafeFormatter::$calls = 0;
        $this->sdk->captureException(new SdkEvidenceUnsafeFormatter('outer', 0, new \Exception('inner')),
            ['failureRef' => self::FAILURE, 'evidenceScope' => self::SCOPE]);
        $this->sdk->flush(); $event = $transport->requests[0]['events'][0];
        self::assertSame(0, SdkEvidenceUnsafeFormatter::$calls);
        self::assertSame('inner', $event['payload']['exception_evidence']['nodes'][1]['message']);
        self::assertSame(self::FAILURE, $event['context']['evidence_reference']['failure_ref']);
    }

    public function testSendsRecoveryIndependentlyOfErrorsLogsAndProbeActivation(): void
    {
        $transport = $this->fixture(['evidenceCapabilities' => self::PROOF, 'captureRecoveryObservations' => true, 'logLevel' => 'critical']);
        $this->sdk->captureRecovery(['failureRef' => self::FAILURE, 'evidenceScope' => self::SCOPE,
            'attemptId' => '00000000-0000-4000-8000-000000000003', 'state' => 'attempt_completed', 'statusCode' => 200]);
        $this->sdk->flush(); $event = $transport->requests[0]['events'][0];
        self::assertSame('diagnostic_context', $event['event_type']);
        self::assertSame('recovery', $event['payload']['kind']);
        self::assertSame('attempt_completed', $event['payload']['state']);
        self::assertSame(200, $event['payload']['status_code']);
    }

    public function testKeepsFatalNativeSourceSeparateAndBypassesApplicationHook(): void
    {
        $calls = 0; $transport = $this->fixture(['evidenceCapabilities' => self::PROOF,
            'beforeSend' => static function (array $event) use (&$calls): array { $calls++; return $event; }]);
        (new \ReflectionMethod($this->sdk, 'handleShutdown'))->invoke($this->sdk,
            ['type' => E_ERROR, 'message' => 'native fatal', 'file' => '/app/fatal.php', 'line' => 47]);
        $event = $transport->requests[0]['events'][0];
        self::assertSame(0, $calls);
        self::assertSame('native_fatal', $event['payload']['exception_evidence']['nodes'][0]['trace_origin']);
        self::assertSame('/app/fatal.php', $event['payload']['exception_evidence']['nodes'][0]['frames'][0]['file']);
        self::assertSame(47, $event['payload']['exception_evidence']['nodes'][0]['frames'][0]['line']);
    }
    public function testReplacementCannotBypassTheResultingLogPolicy(): void
    {
        $transport = $this->fixture(['evidenceCapabilities' => self::PROOF, 'captureRecoveryObservations' => true,
            'beforeSend' => static function (array $event): array { $event['event_type'] = 'log_event'; $event['payload'] = ['level' => 'debug', 'message' => 'filtered', 'attributes' => ['safe' => true]]; return $event; }]);
        $this->sdk->captureRecovery(['failureRef' => self::FAILURE, 'evidenceScope' => self::SCOPE,
            'attemptId' => '00000000-0000-4000-8000-000000000003', 'state' => 'attempt_completed']);
        $this->sdk->flush(); self::assertSame([], $transport->requests);
    }
    public function testReentrantCaptureDoesNotRecursivelyInvokeTheSameHook(): void
    {
        $calls = 0;
        $transport = $this->fixture(['beforeSend' => function (array $event) use (&$calls): array {
            $calls++; if ($calls < 4) $this->sdk->captureLog('nested', 'error'); return $event;
        }]);
        $this->sdk->captureLog('outer', 'error'); $this->sdk->flush();
        self::assertSame(1, $calls); self::assertCount(2, $transport->requests[0]['events']);
    }
    public function testDiagnosticReplacementCannotStealAnotherPendingReceipt(): void
    {
        $first = null; $payload = null;
        $transport = $this->fixture(['evidenceCapabilities' => self::PROOF, 'captureRecoveryObservations' => true,
            'beforeSend' => static function (array $event) use (&$first, &$payload): array {
                if ($first === null) { $first = $event['event_id']; $payload = $event['payload']; }
                else { $event['event_id'] = $first; $event['event_type'] = 'diagnostic_context'; $event['payload'] = $payload; }
                return $event;
            }]);
        $observation = ['failureRef' => self::FAILURE, 'evidenceScope' => self::SCOPE,
            'attemptId' => '00000000-0000-4000-8000-000000000003', 'state' => 'attempt_started'];
        $this->sdk->captureRecovery($observation);
        $this->sdk->captureException(new \Exception('transformed'));
        $this->sdk->captureRecovery($observation);
        $this->sdk->flush();
        self::assertCount(1, $transport->requests[0]['events']);
        self::assertSame($first, $transport->requests[0]['events'][0]['event_id']);
    }
    public function testOnlyNegotiatedInvalidEventRetriesTheStrippedOriginalFailure(): void
    {
        $transport = new \DebugBundle\Tests\Support\FakeTransport([
            new TransportResponse(202, 0, ['accepted' => 0, 'rejected' => 1, 'errors' => [['index' => 0, 'reason' => 'invalid_event']], 'evidence_capabilities' => null]),
            new TransportResponse(202, null, ['accepted' => 1, 'rejected' => 0, 'errors' => []]),
        ]);
        $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init(['projectToken' => 'synthetic-project', 'evidenceCapabilities' => self::PROOF]);
        $this->sdk->captureException(new \Exception('retained original')); $this->sdk->flush(); $this->sdk->flush();
        self::assertCount(2, $transport->calls);
        $first = $transport->calls[0]['events'][0]; $retried = $transport->calls[1]['events'][0];
        self::assertSame($first['event_id'], $retried['event_id']);
        self::assertSame($first['payload']['stack'], $retried['payload']['stack']);
        self::assertArrayNotHasKey('exception_evidence', $retried['payload']);
    }
    public function testOptionalEvidenceCannotDiscardAnOtherwiseAdmissibleLargeFailure(): void
    {
        $transport = $this->fixture(['evidenceCapabilities' => self::PROOF]);
        $error = null;
        for ($n = 0; $n < 5; $n++) $error = new \RuntimeException(str_repeat('x', 16000), 0, $error);
        $context = array_fill_keys(array_map(static fn (int $n): string => 'safe' . $n, range(1, 15)), str_repeat('z', 15000));
        $this->sdk->captureException($error, $context); $this->sdk->flush();
        self::assertCount(1, $transport->requests);
        self::assertSame(str_repeat('x', 16000), $transport->requests[0]['events'][0]['payload']['message']);
        self::assertArrayNotHasKey('exception_evidence', $transport->requests[0]['events'][0]['payload']);
    }
    public function testProbeFactoryCannotTransferOldProjectDataAcrossSdkReinitialization(): void
    {
        $transport = $this->fixture();
        $this->sdk->probe('old.operation', function (): array {
            $this->sdk->init(['projectToken' => 'new-synthetic-project']);
            return ['old_project_data' => true];
        });
        $this->sdk->captureException(new \Exception('new project')); $this->sdk->flush();
        self::assertArrayNotHasKey('probe_data', $transport->requests[0]['events'][0]['payload']);
    }
    public function testPageRecoveryPreservesExplicitCorrelationAndAcceptsTheStringZeroSession(): void
    {
        $transport = $this->fixture(['evidenceCapabilities' => self::PROOF, 'captureRecoveryObservations' => true]);
        foreach (['0', '   '] as $session) $this->sdk->captureRecovery(['failureRef' => self::FAILURE,
            'evidenceScope' => ['kind' => 'page', 'page_id' => '00000000-0000-4000-8000-000000000002'],
            'attemptId' => '00000000-0000-4000-8000-000000000003', 'state' => 'recovery_observed',
            'correlation' => ['session_id' => $session, 'trace_id' => 'synthetic-trace']]);
        $this->sdk->flush(); self::assertCount(1, $transport->requests);
        self::assertCount(1, $transport->requests[0]['events']);
        self::assertSame('0', $transport->requests[0]['events'][0]['correlation']['session_id']);
        self::assertSame('synthetic-trace', $transport->requests[0]['events'][0]['correlation']['trace_id']);
    }
}

final class EvidenceRecordingTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];
    public function send(array $request): TransportResponse
    {
        $this->requests[] = $request;
        return new TransportResponse(202, null, ['accepted' => count($request['events']), 'rejected' => 0, 'errors' => []]);
    }
}

final class SdkEvidenceUnsafeFormatter extends \Exception
{
    public static int $calls = 0;
    public function __toString(): string { self::$calls++; throw new \LogicException('custom formatter must not run'); }
}
