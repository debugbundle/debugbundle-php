<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeConfigFetcher;
use DebugBundle\Tests\Support\FakeConfigResponse;
use DebugBundle\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SdkEvidenceConfigTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;
    protected function tearDown(): void { $this->sdk?->reset(); }
    public function testInvalidConfigCannotGrantEvidenceCapabilities(): void
    {
        $capabilities = ['version' => 1, 'features' => ['exception_evidence_v1']];
        $fetcher = new FakeConfigFetcher([new FakeConfigResponse(200, ['capture_policy' => 'invalid', 'evidence_capabilities' => $capabilities])]);
        $transport = new FakeTransport(); $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init(['projectToken' => 'synthetic-project', 'batchSize' => 1000, 'configFetcher' => $fetcher, 'evidenceCapabilities' => $capabilities]);
        $this->sdk->refreshRemoteConfig(true);
        $this->sdk->captureException(new \Exception('preserved'));
        $this->sdk->flush();
        self::assertArrayNotHasKey('exception_evidence', $transport->calls[0]['events'][0]['payload']);
    }
    public function testDiscoversCapabilitiesOnTheExistingConfigRequestAndHonoursRecoveryPolicy(): void
    {
        $payload = ['probes_enabled' => true, 'remote_probes_enabled' => false, 'active_probes' => [],
            'capture_policy' => ['preset' => 'balanced', 'capture_logs' => 'warning', 'capture_request_events' => 'failures_only',
                'capture_breadcrumbs' => 'exception_only', 'capture_probe_events' => 'buffer_only', 'capture_diagnostic_context' => false],
            'evidence_capabilities' => ['version' => 1, 'features' => ['exception_evidence_v1', 'recovery_v1']]];
        $fetcher = new FakeConfigFetcher([new FakeConfigResponse(200, $payload, ['etag' => '"evidence"']), new FakeConfigResponse(304, null)]);
        $transport = new FakeTransport(); $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init(['projectToken' => 'synthetic-project', 'batchSize' => 1000, 'configFetcher' => $fetcher, 'captureRecoveryObservations' => true]);
        $this->sdk->refreshRemoteConfig(true); $this->sdk->refreshRemoteConfig();
        $this->sdk->captureException(new \Exception('discovered'));
        $this->sdk->captureRecovery(['failureRef' => '00000000-0000-4000-8000-000000000001',
            'evidenceScope' => ['kind' => 'operation', 'operation_id' => '00000000-0000-4000-8000-000000000002'],
            'attemptId' => '00000000-0000-4000-8000-000000000003', 'state' => 'attempt_started']);
        $this->sdk->flush();
        self::assertCount(2, $fetcher->calls);
        self::assertSame('https://api.debugbundle.com/v1/sdk/config?debugbundle_evidence=1', $fetcher->calls[0]['url']);
        self::assertCount(1, $transport->calls[0]['events']);
        self::assertArrayHasKey('exception_evidence', $transport->calls[0]['events'][0]['payload']);
        self::assertSame('"evidence"', $fetcher->calls[1]['request']['headers']['if-none-match']);
    }
}
