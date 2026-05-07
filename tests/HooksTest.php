<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    public function testCaptureErrorsHooksSetErrorHandlerAndCapturesError(): void
    {
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
        $registeredHandler(E_USER_WARNING, 'boom', __FILE__, __LINE__);
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