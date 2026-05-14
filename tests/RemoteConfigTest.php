<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\RemoteConfig;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Tests\Support\FakeConfigFetcher;
use DebugBundle\Tests\Support\FakeConfigResponse;
use DebugBundle\Tests\Support\ManualClock;
use PHPUnit\Framework\TestCase;

final class RemoteConfigTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    public function testRemoteConfigSkipsRecurringPollingWhenRemoteProbesAreDisabled(): void
    {
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(200, [
                'probes_enabled' => true,
                'remote_probes_enabled' => false,
                'active_probes' => [],
                'poll_interval_ms' => 15000,
            ]),
        ]);

        $sdk = new DebugBundleSdk();
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
            'probesPollInterval' => 15000,
        ]);

        self::assertCount(1, $fetcher->calls);
        self::assertSame('https://api.debugbundle.com/v1/sdk/config', $fetcher->calls[0]['url']);
        self::assertSame('GET', $fetcher->calls[0]['request']['method']);
    }

    public function testRemoteConfigUsesConfiguredEndpointBaseForSelfHostedConfigRefresh(): void
    {
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(200, [
                'probes_enabled' => true,
                'remote_probes_enabled' => false,
                'active_probes' => [],
                'poll_interval_ms' => 15000,
            ]),
        ]);

        $sdk = new DebugBundleSdk();
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'endpoint' => 'http://self-hosted.test:3001/api/v1/events',
            'configFetcher' => $fetcher,
            'probesPollInterval' => 15000,
        ]);

        self::assertCount(1, $fetcher->calls);
        self::assertSame('http://self-hosted.test:3001/api/v1/sdk/config', $fetcher->calls[0]['url']);
        self::assertSame('GET', $fetcher->calls[0]['request']['method']);
    }

    public function testRemoteConfigUsesEtagAndActivatesHeavyProbesOnlyWhileDirectiveIsLive(): void
    {
        $clock = new ManualClock();
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(
                200,
                [
                    'probes_enabled' => true,
                    'remote_probes_enabled' => true,
                    'active_probes' => [[
                        'id' => '550e8400-e29b-41d4-a716-446655440000',
                        'label_pattern' => 'checkout.*',
                        'service' => 'checkout-api',
                        'environment' => 'production',
                        'expires_at' => '2023-11-14T22:13:30.000Z',
                    ]],
                    'poll_interval_ms' => 15000,
                    'capture_policy' => [
                        'preset' => 'balanced',
                        'capture_logs' => 'warning',
                        'capture_request_events' => 'failures_only',
                        'capture_breadcrumbs' => 'exception_only',
                        'capture_probe_events' => 'standalone_when_activated',
                    ],
                ],
                ['etag' => '"cfg-1"']
            ),
            new FakeConfigResponse(304, null, ['etag' => '"cfg-1"']),
        ]);
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
            'probesPollInterval' => 60000,
        ]);

        $invocations = 0;
        $heavyProbe = static function () use (&$invocations): array {
            $invocations++;
            return ['tax_rate' => 0.2];
        };

        $sdk->probe('checkout.tax', $heavyProbe, ['heavy' => true]);
        $sdk->flush();

        self::assertSame(1, $invocations);
        self::assertCount(1, $transport->calls);
        self::assertSame('probe_event', $transport->calls[0]['events'][0]['event_type']);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $transport->calls[0]['events'][0]['payload']['activation_id']);
        self::assertSame('checkout.*', $transport->calls[0]['events'][0]['payload']['probe_label_pattern']);

        $sdk->refreshRemoteConfig();
        self::assertCount(2, $fetcher->calls);
        self::assertSame('"cfg-1"', $fetcher->calls[1]['request']['headers']['if-none-match']);

        $transport->calls = [];
        $clock->advance(11);
        $sdk->probe('checkout.tax', $heavyProbe, ['heavy' => true]);
        $sdk->flush();

        self::assertSame(1, $invocations);
        self::assertSame([], $transport->calls);
    }

    public function testFailedInitConfigFetchFallsBackToMinimalPolicy(): void
    {
        $clock = new ManualClock();
        $fetcher = new FakeConfigFetcher([], new \RuntimeException('config refresh failed'));
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
            'probesPollInterval' => 25000,
        ]);

        $sdk->captureMessage('warning blocked', 'warning');
        $sdk->captureMessage('error still allowed', 'error');
        $sdk->captureMessage('info blocked', 'info');
        $sdk->captureRequest(['method' => 'GET', 'path' => '/ok', 'headers' => []], ['status_code' => 200]);
        $sdk->captureRequest(['method' => 'GET', 'path' => '/boom', 'headers' => []], ['status_code' => 503]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        self::assertSame(['log_event', 'request_event'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('error still allowed', $transport->calls[0]['events'][0]['payload']['message']);
        self::assertSame(503, $transport->calls[0]['events'][1]['payload']['response_status']);
    }

    public function testCapturePolicyFiltersLogsAndRequestEventsFromRemoteConfig(): void
    {
        $clock = new ManualClock();
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(200, [
                'probes_enabled' => true,
                'remote_probes_enabled' => true,
                'active_probes' => [],
                'poll_interval_ms' => 15000,
                'capture_policy' => [
                    'preset' => 'minimal',
                    'capture_logs' => 'error',
                    'capture_request_events' => 'failures_only',
                    'capture_breadcrumbs' => 'local_only',
                    'capture_probe_events' => 'buffer_only',
                ],
            ]),
        ]);
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
        ]);

        $sdk->captureMessage('warning blocked', 'warning');
        $sdk->captureMessage('error kept', 'error');
        $sdk->captureRequest(['method' => 'GET', 'path' => '/ok', 'headers' => []], ['status_code' => 200]);
        $sdk->captureRequest(['method' => 'GET', 'path' => '/boom', 'headers' => []], ['status_code' => 503]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        self::assertSame(['log_event', 'request_event'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('error kept', $transport->calls[0]['events'][0]['payload']['message']);
        self::assertSame(503, $transport->calls[0]['events'][1]['payload']['response_status']);
    }

    public function testBalancedCapturePolicyKeepsRequestFailureAnomalyCandidates(): void
    {
        $clock = new ManualClock();
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(200, [
                'probes_enabled' => true,
                'remote_probes_enabled' => true,
                'active_probes' => [],
                'poll_interval_ms' => 15000,
                'capture_policy' => [
                    'preset' => 'balanced',
                    'capture_logs' => 'warning',
                    'capture_request_events' => 'failures_only',
                    'capture_breadcrumbs' => 'exception_only',
                    'capture_probe_events' => 'buffer_only',
                ],
            ]),
        ]);
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
        ]);

        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 429]);
        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 404]);
        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 409]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        $requestEvents = array_values(array_filter(
            $transport->calls[0]['events'],
            static fn (array $event): bool => $event['event_type'] === 'request_event'
        ));
        self::assertSame([429, 404, 409], array_map(static fn (array $event): int => $event['payload']['response_status'], $requestEvents));
    }

    public function testInvestigativeCapturePolicyPromotes409EvenWhenRequestCaptureIsOff(): void
    {
        $clock = new ManualClock();
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(200, [
                'probes_enabled' => true,
                'remote_probes_enabled' => true,
                'active_probes' => [],
                'poll_interval_ms' => 15000,
                'capture_policy' => [
                    'preset' => 'investigative',
                    'capture_logs' => 'info',
                    'capture_request_events' => 'off',
                    'capture_breadcrumbs' => 'standalone',
                    'capture_probe_events' => 'standalone_when_activated',
                ],
            ]),
        ]);
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
        ]);

        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 409]);
        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 404]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        $requestEvents = array_values(array_filter(
            $transport->calls[0]['events'],
            static fn (array $event): bool => $event['event_type'] === 'request_event'
        ));
        self::assertSame([409], array_map(static fn (array $event): int => $event['payload']['response_status'], $requestEvents));
    }

    public function testCapturePolicyPromotesConfiguredClientErrorStatusesWhenRequestCaptureIsOff(): void
    {
        $clock = new ManualClock();
        $fetcher = new FakeConfigFetcher([
            new FakeConfigResponse(200, [
                'probes_enabled' => true,
                'remote_probes_enabled' => true,
                'active_probes' => [],
                'poll_interval_ms' => 15000,
                'capture_policy' => [
                    'preset' => 'minimal',
                    'capture_logs' => 'error',
                    'capture_request_events' => 'off',
                    'capture_breadcrumbs' => 'local_only',
                    'capture_probe_events' => 'buffer_only',
                    'immediate_client_error_statuses' => [422, 403, 403],
                ],
            ]),
        ]);
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => $fetcher,
        ]);

        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 403]);
        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 404]);
        $sdk->captureRequest(['method' => 'POST', 'path' => '/checkout', 'headers' => []], ['status_code' => 422]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        $requestEvents = array_values(array_filter(
            $transport->calls[0]['events'],
            static fn (array $event): bool => $event['event_type'] === 'request_event'
        ));
        self::assertSame([403, 422], array_map(static fn (array $event): int => $event['payload']['response_status'], $requestEvents));
    }

    public function testParseRemoteConfigIncludesTriggerTokenKeyWhenPresent(): void
    {
        $snapshot = RemoteConfig::parseRemoteConfig([
            'probes_enabled' => true,
            'remote_probes_enabled' => true,
            'active_probes' => [],
            'poll_interval_ms' => 15000,
            'trigger_token_key' => 'trigger-key-123',
            'capture_policy' => [
                'preset' => 'balanced',
                'capture_logs' => 'warning',
                'capture_request_events' => 'all',
                'capture_breadcrumbs' => 'local_only',
                'capture_probe_events' => 'standalone_when_activated',
            ],
        ], 60000, strtotime('2026-03-15T00:00:00Z') * 1000);

        self::assertNotNull($snapshot);
        self::assertSame('trigger-key-123', $snapshot->triggerTokenKey);
    }
}
