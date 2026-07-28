<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\CapturePolicy;
use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Tests\Support\ManualClock;
use PHPUnit\Framework\TestCase;

final class DebugBundleSdkPolicySupportTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;
        parent::tearDown();
    }

    public function testPolicyHelpersNormalizeLevelsPathsAndContext(): void
    {
        $sdk = $this->sdk();
        $sdk->setContext('tenant', 'acme');

        self::assertSame('warning', self::invoke($sdk, 'normalizeLevel', [' WARN ']));
        self::assertSame('error', self::invoke($sdk, 'normalizeLevel', ['exception']));
        self::assertSame('error', self::invoke($sdk, 'normalizeLevel', ['err']));
        self::assertSame('warning', self::invoke($sdk, 'normalizeLevel', ['unknown']));
        self::assertTrue(self::invoke($sdk, 'levelEnabled', ['error', 'warning']));
        self::assertFalse(self::invoke($sdk, 'levelEnabled', ['debug', 'warning']));
        self::assertSame('/checkout/cart', self::invoke($sdk, 'normalizeRequestPath', [
            'https://example.test/checkout/cart?token=secret#summary',
        ]));
        self::assertSame('checkout', self::invoke($sdk, 'normalizeRequestPath', ['checkout']));
        self::assertSame('acme', self::invoke($sdk, 'readContextString', ['tenant']));
        self::assertSame('override', self::invoke($sdk, 'readContextString', ['tenant', ['tenant' => 'override']]));
        self::assertNull(self::invoke($sdk, 'readContextString', ['missing']));
        self::assertIsString(self::invoke($sdk, 'effectiveLogThreshold'));
    }

    public function testSamplingClockUuidAndEndpointHelpers(): void
    {
        $clock = new ManualClock();
        $clock->advance(1_000);
        $sdk = $this->sdk($clock);

        self::setProperty($sdk, 'sampleRate', 1.0);
        self::assertTrue(self::invoke($sdk, 'passesSampleRate'));
        self::setProperty($sdk, 'sampleRate', 0.0);
        self::assertFalse(self::invoke($sdk, 'passesSampleRate'));

        $uuid = self::invoke($sdk, 'uuidV4');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
        self::assertSame($clock->time(), self::invoke($sdk, 'now'));
        self::assertSame(
            gmdate('Y-m-d\\TH:i:s', (int) $clock->time()) . 'Z',
            self::invoke($sdk, 'isoNow'),
        );

        self::setProperty($sdk, 'endpoint', 'https://user:pass@example.test:8443/v1/events');
        self::assertSame(
            'https://user:pass@example.test:8443/v1/sdk/config',
            self::invoke($sdk, 'configEndpoint'),
        );
        self::setProperty($sdk, 'endpoint', 'https://example.test/custom');
        self::assertSame('https://example.test/custom/sdk/config', self::invoke($sdk, 'configEndpoint'));
        self::setProperty($sdk, 'endpoint', 'https://example.test');
        self::assertSame('https://example.test/v1/sdk/config', self::invoke($sdk, 'configEndpoint'));
    }

    public function testRequestPolicyHelpersCoverMissingFailuresAndDefaultCapture(): void
    {
        $sdk = $this->sdk();

        self::assertFalse(self::invoke($sdk, 'isImmediateRequestIncidentStatus', [null]));
        self::assertTrue(self::invoke($sdk, 'isImmediateRequestIncidentStatus', [500]));
        self::assertFalse(self::invoke($sdk, 'matchesImmediateClientErrorPathRule', [399, '/checkout', 'POST']));
        self::assertFalse(self::invoke($sdk, 'matchesImmediateClientErrorPathRule', [404, null, 'POST']));
        self::assertFalse(self::invoke($sdk, 'matchesImmediateClientErrorPathRule', [404, '/checkout', null]));
        self::assertTrue(self::invoke($sdk, 'shouldCaptureRequestEvent', [
            ['method' => 'GET', 'url' => 'https://example.test/checkout'],
            ['status_code' => 503],
        ]));
        self::assertFalse(self::invoke($sdk, 'shouldCaptureRequestEvent', [
            ['method' => 'GET', 'path' => '/checkout'],
            ['status_code' => 200],
        ]));
        self::assertFalse(self::invoke($sdk, 'shouldCaptureRequestEvent', [
            ['method' => 'GET', 'path' => '/checkout'],
            ['status_code' => '200'],
        ]));
        self::assertFalse(self::invoke($sdk, 'shouldCaptureRequestEvent', [[], null]));

        self::setProperty($sdk, 'capturePolicy', self::policy('all'));
        self::assertTrue(self::invoke($sdk, 'shouldCaptureRequestEvent', [[], null]));
        self::setProperty($sdk, 'capturePolicy', self::policy('off'));
        self::assertFalse(self::invoke($sdk, 'shouldCaptureRequestEvent', [[], null]));
        self::setProperty($sdk, 'capturePolicy', self::policy('filtered'));
        self::assertFalse(self::invoke($sdk, 'shouldCaptureRequestEvent', [[], null]));
        self::assertSame([], self::invoke($sdk, 'findMatchingProbeDirectives', ['checkout.total']));
    }

    private function sdk(?ManualClock $clock = null): DebugBundleSdk
    {
        $sdk = new DebugBundleSdk(new FakeTransport(), $clock === null ? null : [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);
        return $sdk;
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function invoke(DebugBundleSdk $sdk, string $methodName, array $arguments = []): mixed
    {
        $method = new \ReflectionMethod($sdk, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($sdk, $arguments);
    }

    private static function setProperty(DebugBundleSdk $sdk, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($sdk, $propertyName);
        $property->setAccessible(true);
        $property->setValue($sdk, $value);
    }

    private static function policy(string $captureRequestEvents): CapturePolicy
    {
        return new CapturePolicy(
            'minimal',
            'error',
            $captureRequestEvents,
            'local_only',
            'buffer_only',
            [],
        );
    }
}
