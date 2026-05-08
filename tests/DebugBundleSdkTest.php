<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundle;
use DebugBundle\DebugBundleSdk;
use DebugBundle\Transport\TransportResponse;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Tests\Support\ManualClock;
use PHPUnit\Framework\TestCase;

final class DebugBundleSdkTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;
        self::setFacadeSdk(null);

        parent::tearDown();
    }

    public function testStaticFacadeExposesUniversalSurface(): void
    {
        foreach ([
            'init',
            'captureException',
            'captureError',
            'captureLog',
            'captureRequest',
            'captureMessage',
            'setContext',
            'flush',
            'probe',
            'captureErrors',
            'captureExceptions',
            'captureShutdown',
        ] as $methodName) {
            self::assertTrue(method_exists(DebugBundle::class, $methodName));
        }
    }

    public function testStaticFacadeLazilyCreatesSdkInstance(): void
    {
        self::setFacadeSdk(null);

        DebugBundle::flush();

        self::assertInstanceOf(DebugBundleSdk::class, self::getFacadeSdk());
    }

    public function testStaticFacadeDelegatesCallsToConfiguredSdk(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        self::setFacadeSdk($sdk);

        DebugBundle::init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        DebugBundle::setContext('tenant', 'acme');
        DebugBundle::captureMessage('facade message', 'warning');
        DebugBundle::captureLog('facade log', 'error', ['request_id' => 'req_1']);
        DebugBundle::captureRequest(
            ['method' => 'GET', 'path' => '/facade', 'headers' => ['x-request-id' => 'req_1'], 'query' => []],
            ['status_code' => 204, 'duration_ms' => 12]
        );
        DebugBundle::probe('facade.probe', ['secret' => 'value']);
        DebugBundle::captureException(new \RuntimeException('facade exception'));
        DebugBundle::captureError(new \RuntimeException('facade error'));
        DebugBundle::captureErrors();
        DebugBundle::captureExceptions();
        DebugBundle::captureShutdown();
        DebugBundle::flush();

        self::assertCount(1, $transport->calls);
        $eventTypes = array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']);
        self::assertContains('log_event', $eventTypes);
        self::assertContains('request_event', $eventTypes);
        self::assertContains('backend_exception', $eventTypes);
        self::assertContains('backend_exception', $eventTypes);

        $logEvent = array_values(array_filter(
            $transport->calls[0]['events'],
            static fn (array $event): bool => $event['event_type'] === 'log_event' && $event['payload']['message'] === 'facade message'
        ))[0];
        self::assertSame(['tenant' => 'acme'], $logEvent['context']);
    }

    public function testInvalidConfigDegradesSilently(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;

        $sdk->init([
            'projectToken' => '',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureException(new \RuntimeException('boom'));
        $sdk->captureMessage('still-running', 'error');

        $sdk->flush();
        self::assertSame([], $transport->calls);
    }

    public function testRetainsBufferedEventsWhenTransportFails(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(500, null),
            new TransportResponse(202, null),
        ]);
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureException(new \RuntimeException('database unavailable'));
        $sdk->flush();
        $sdk->flush();

        self::assertCount(2, $transport->calls);
        self::assertSame('database unavailable', $transport->calls[1]['events'][0]['payload']['message']);
    }

    public function testAppliesRetryBackoffAfter429Response(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport([
            new TransportResponse(429, 1000),
            new TransportResponse(202, null),
        ]);
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureMessage('retry me', 'error');
        $sdk->flush();
        $sdk->flush();

        self::assertCount(1, $transport->calls);

        $clock->advance(1.001);
        $sdk->flush();

        self::assertCount(2, $transport->calls);
    }

    public function testFlushesWhenBatchSizeIsReached(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'batchSize' => 2,
        ]);

        $sdk->captureMessage('first', 'warning');
        $sdk->captureMessage('second', 'warning');

        self::assertCount(1, $transport->calls);
        self::assertCount(2, $transport->calls[0]['events']);
    }

    public function testRedactsSensitiveRequestFieldsBeforeTransport(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureException(new \RuntimeException('login failed'), [
            'request' => [
                'method' => 'POST',
                'path' => '/login',
                'headers' => ['authorization' => 'Bearer secret-token'],
                'query' => ['token' => 'query-secret'],
                'body' => ['password' => 'super-secret'],
            ],
            'response' => ['status_code' => 401],
        ]);
        $sdk->flush();

        $requestPayload = $transport->calls[0]['events'][0]['payload']['request'];
        self::assertSame('[REDACTED]', $requestPayload['headers']['authorization']);
        self::assertSame('[REDACTED]', $requestPayload['query']['token']);
        self::assertSame('[REDACTED]', $requestPayload['body']['password']);
    }

    public function testFlushesAlwaysOnProbeDataAndKeepsHeavyProbesDormant(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $invocationCount = 0;
        $heavyProbe = static function () use (&$invocationCount): array {
            $invocationCount++;
            return ['plan' => 'full scan'];
        };

        $sdk->probe('checkout.tax', ['secret' => 'tax-secret', 'rate' => 0.2]);
        $sdk->probe('db.query-plan', $heavyProbe, ['heavy' => true]);
        $sdk->captureException(new \RuntimeException('checkout failed'));
        $sdk->flush();

        self::assertSame(0, $invocationCount);
        $probeData = $transport->calls[0]['events'][0]['payload']['probe_data'];
        self::assertSame('checkout.tax', $probeData['items'][0]['label']);
        self::assertSame('[REDACTED]', $probeData['items'][0]['data']['secret']);
    }

    public function testEmitsContractShapedEventEnvelopes(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureMessage('warning raised', 'warning', ['tenant' => 'acme']);
        $sdk->captureRequest(
            ['method' => 'GET', 'path' => '/orders', 'headers' => ['x-request-id' => 'req_1'], 'query' => ['page' => '1']],
            ['status_code' => 503, 'duration_ms' => 45]
        );
        $sdk->captureException(new \RuntimeException('checkout failed'), [
            'request' => ['method' => 'POST', 'path' => '/checkout', 'headers' => ['authorization' => 'secret'], 'query' => []],
            'response' => ['status_code' => 500],
        ]);
        $sdk->flush();

        $events = $transport->calls[0]['events'];
        foreach ($events as $event) {
            self::assertSame('2026-03-01', $event['schema_version']);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $event['event_id']);
            self::assertSame('debugbundle/sdk-php', $event['sdk_name']);
            self::assertSame('0.1.0', $event['sdk_version']);
            self::assertStringEndsWith('Z', $event['occurred_at']);
            self::assertSame([
                'name' => 'checkout-api',
                'runtime' => 'php',
                'framework' => null,
                'environment' => 'production',
            ], $event['service']);
            self::assertSame([
                'request_id' => null,
                'trace_id' => null,
                'session_id' => null,
                'user_id_hash' => null,
            ], $event['correlation']);
        }

        $logEvent = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'log_event'))[0];
        self::assertSame([
            'level' => 'warning',
            'message' => 'warning raised',
            'attributes' => ['tenant' => 'acme'],
        ], $logEvent['payload']);

        $requestEvent = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'request_event'))[0];
        self::assertSame([
            'method' => 'GET',
            'path' => '/orders',
            'query' => ['page' => '1'],
            'headers' => ['x-request-id' => 'req_1'],
            'response_status' => 503,
            'duration_ms' => 45,
        ], $requestEvent['payload']);

        $exceptionEvent = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'backend_exception'))[0];
        self::assertSame('RuntimeException', $exceptionEvent['payload']['name']);
        self::assertSame('checkout failed', $exceptionEvent['payload']['message']);
        self::assertTrue($exceptionEvent['payload']['handled']);
        self::assertSame('/checkout', $exceptionEvent['payload']['request']['path']);
        self::assertSame(500, $exceptionEvent['payload']['response']['status_code']);
        self::assertSame(['version' => PHP_VERSION], $exceptionEvent['payload']['runtime']);
    }

    public function testSuppressesDuplicateExceptionsAfterTheFirstThree(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        for ($index = 0; $index < 5; $index++) {
            $sdk->captureException(new \RuntimeException('same failure'));
        }

        $sdk->flush();

        $events = $transport->calls[0]['events'];
        $eventTypes = array_map(static fn (array $event): string => $event['event_type'], $events);
        self::assertSame(3, count(array_filter($eventTypes, static fn (string $eventType): bool => $eventType === 'backend_exception')));
        $suppressed = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'error_suppressed'));
        self::assertCount(1, $suppressed);
        self::assertSame(2, $suppressed[0]['payload']['suppressed_count']);
    }

    private static function getFacadeSdk(): ?DebugBundleSdk
    {
        $reflection = new \ReflectionClass(DebugBundle::class);
        $property = $reflection->getProperty('sdk');
        $value = $property->getValue();

        return $value instanceof DebugBundleSdk ? $value : null;
    }

    private static function setFacadeSdk(?DebugBundleSdk $sdk): void
    {
        $reflection = new \ReflectionClass(DebugBundle::class);
        $property = $reflection->getProperty('sdk');
        $current = $property->getValue();
        if ($current instanceof DebugBundleSdk) {
            $current->reset();
        }

        $property->setValue(null, $sdk);
    }

    // ── Health status tests ──

    public function testStatusDisconnectedBeforeInit(): void
    {
        $sdk = new DebugBundleSdk();
        $this->sdk = $sdk;
        self::assertSame('disconnected', $sdk->getStatus());
        self::assertNull($sdk->getLastEventAt());
    }

    public function testStatusHealthyAfterInit(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        self::assertSame('healthy', $sdk->getStatus());
        self::assertNull($sdk->getLastEventAt());
    }

    public function testStatusHealthyWithLastEventAtAfterFlush(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, fn () => $clock->time());
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        $sdk->captureException(new \RuntimeException('test'));
        $sdk->flush();
        self::assertSame('healthy', $sdk->getStatus());
        self::assertSame($clock->now * 1000, $sdk->getLastEventAt());
    }

    public function testStatusDegradedOn429(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport([new TransportResponse(429, 5_000)]);
        $sdk = new DebugBundleSdk($transport, fn () => $clock->time());
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        $sdk->captureException(new \RuntimeException('test'));
        $sdk->flush();
        self::assertSame('degraded', $sdk->getStatus());
    }

    public function testStatusRecoversToHealthyAfterDegraded(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport([
            new TransportResponse(429, 1_000),
            new TransportResponse(202, null),
        ]);
        $sdk = new DebugBundleSdk($transport, fn () => $clock->time());
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        $sdk->captureException(new \RuntimeException('first'));
        $sdk->flush();
        self::assertSame('degraded', $sdk->getStatus());

        $clock->advance(2.0);
        $sdk->flush();
        self::assertSame('healthy', $sdk->getStatus());
    }

    public function testStatusDisconnectedAfter3Failures(): void
    {
        $transport = new FakeTransport([new TransportResponse(500, null)]);
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        for ($i = 0; $i < 3; $i++) {
            $sdk->captureException(new \RuntimeException("error-{$i}"));
            $sdk->flush();
        }
        self::assertSame('disconnected', $sdk->getStatus());
    }

    public function testStatusDisconnectedAfter3TransportErrors(): void
    {
        $throwingTransport = new class implements \DebugBundle\Transport\TransportInterface {
            public function send(array $request): TransportResponse
            {
                throw new \RuntimeException('network down');
            }
        };
        $sdk = new DebugBundleSdk($throwingTransport);
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        for ($i = 0; $i < 3; $i++) {
            $sdk->captureException(new \RuntimeException("error-{$i}"));
            $sdk->flush();
        }
        self::assertSame('disconnected', $sdk->getStatus());
    }

    public function testStatusResetsOnReinit(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        $sdk->captureException(new \RuntimeException('test'));
        $sdk->flush();
        self::assertNotNull($sdk->getLastEventAt());

        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        self::assertSame('healthy', $sdk->getStatus());
        self::assertNull($sdk->getLastEventAt());
    }

    public function testConsecutiveFailuresResetOnSuccess(): void
    {
        $dynamicTransport = new class() implements \DebugBundle\Transport\TransportInterface {
            private int $callCount = 0;

            public function send(array $request): TransportResponse
            {
                $this->callCount++;
                return $this->callCount <= 2
                    ? new TransportResponse(500, null)
                    : new TransportResponse(202, null);
            }
        };
        $sdk = new DebugBundleSdk($dynamicTransport);
        $this->sdk = $sdk;
        $sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'test', 'environment' => 'test']);
        $sdk->captureException(new \RuntimeException('fail-1'));
        $sdk->flush();
        $sdk->captureException(new \RuntimeException('fail-2'));
        $sdk->flush();
        $sdk->captureException(new \RuntimeException('success'));
        $sdk->flush();
        self::assertSame('healthy', $sdk->getStatus());
        self::assertNotNull($sdk->getLastEventAt());
    }
}