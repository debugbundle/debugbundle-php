<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    /** @return iterable<string, array{int}> */
    public static function maskedSeverities(): iterable
    {
        foreach ([E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED] as $severity) {
            yield (string) $severity => [$severity];
        }
    }

    #[DataProvider('maskedSeverities')]
    public function testErrorHookRespectsCurrentReportingMask(int $severity): void
    {
        $transport = $this->initializeSdk();
        $handler = set_error_handler(static fn () => false);
        restore_error_handler();
        self::assertIsCallable($handler);
        $previous = error_reporting();
        try {
            error_reporting(E_ALL & ~$severity);
            self::assertFalse($handler($severity, 'masked', __FILE__, __LINE__));
            $this->sdk?->flush();
            self::assertCount(0, $transport->calls);
            error_reporting(E_ALL);
            self::assertFalse($handler($severity, 'enabled', __FILE__, __LINE__));
            $this->sdk?->flush();
            self::assertSame('enabled', $transport->calls[0]['events'][0]['payload']['message']);
        } finally {
            error_reporting($previous);
        }
    }

    #[WithoutErrorHandler]
    public function testAtOperatorSuppressesNativeAndUserWarnings(): void
    {
        $transport = $this->initializeSdk();
        $previous = error_reporting(E_ALL);
        try {
            @trigger_error('suppressed user warning', E_USER_WARNING);
            @fopen(__DIR__ . '/missing-suppressed-error-fixture', 'r');
            $this->sdk?->flush();
            self::assertSame([], $transport->calls);
        } finally {
            error_reporting($previous);
            error_clear_last();
        }
    }

    public function testReportingMaskDoesNotDisableExplicitCaptureOrFatalShutdown(): void
    {
        $transport = $this->initializeSdk();
        $previous = error_reporting(0);
        try {
            $this->sdk?->captureException(new \RuntimeException('explicit'));
            $method = (new \ReflectionClass(DebugBundleSdk::class))->getMethod('handleShutdown');
            $method->invoke($this->sdk, ['type' => E_ERROR, 'message' => 'fatal', 'file' => __FILE__, 'line' => __LINE__]);
            self::assertSame(['explicit', 'fatal'], array_column(array_column($transport->calls[0]['events'], 'payload'), 'message'));
        } finally {
            error_reporting($previous);
        }
    }

    private function initializeSdk(): FakeTransport
    {
        $transport = new FakeTransport();
        $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init(['projectToken' => 'dbundle_proj_test', 'service' => 'hooks-test', 'environment' => 'production']);
        return $transport;
    }

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    public function testCaptureErrorsHooksSetErrorHandlerAndCapturesError(): void
    {
        $previous = error_reporting(E_ALL);
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureErrors();
        $registeredHandler = set_error_handler(static fn () => false);
        restore_error_handler();

        self::assertIsCallable($registeredHandler);
        try {
            $registeredHandler(E_USER_WARNING, 'boom', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }
        $sdk->flush();

        self::assertSame('backend_exception', $transport->calls[0]['events'][0]['event_type']);
        self::assertSame('boom', $transport->calls[0]['events'][0]['payload']['message']);

    }

    public function testCaptureExceptionsHooksSetExceptionHandlerAndCapturesException(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureExceptions();
        $registeredHandler = set_exception_handler(static fn () => null);
        restore_exception_handler();

        self::assertIsCallable($registeredHandler);
        $registeredHandler(new \RuntimeException('uncaught'));
        $sdk->flush();

        self::assertSame('backend_exception', $transport->calls[0]['events'][0]['event_type']);
        self::assertSame('uncaught', $transport->calls[0]['events'][0]['payload']['message']);

    }

    public function testCaptureShutdownFlushesBufferedEventsOnFatalError(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $sdk->captureShutdown();

        $reflection = new \ReflectionClass($sdk);
        $method = $reflection->getMethod('handleShutdown');
        $method->invoke($sdk, [
            'type' => E_ERROR,
            'message' => 'fatal shutdown',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);

        self::assertCount(1, $transport->calls);
        self::assertSame('fatal shutdown', $transport->calls[0]['events'][0]['payload']['message']);
    }
}
