<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\Laravel\DebugBundleLogTap;
use DebugBundle\Tests\Support\FakeTransport;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testRejectedExceptionDoesNotPoisonIdentityAndReentrantHookIsBounded(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->sdk = new DebugBundleSdk($transport);
        $error = new \RuntimeException('synthetic rejected first capture');
        $calls = 0;
        $sdk->init(['projectToken' => 'synthetic-token', 'beforeSend' => function ($event) use ($sdk, $error, &$calls) {
            $calls++;
            $sdk->captureException($error);
            return $calls === 1 ? null : $event;
        }]);
        $sdk->captureLog('first report', 'error', ['exception' => $error]);
        $sdk->captureException($error);
        $sdk->flush();
        self::assertSame(2, $calls);
        self::assertCount(1, array_merge(...array_column($transport->calls, 'events')));
    }

    public function testMonologNativeExceptionKeepsCauseAndDoesNotDuplicateExplicitCapture(): void
    {
        $transport = new FakeTransport();
        $logger = new Logger('synthetic');
        $sdk = $this->sdk = new DebugBundleSdk($transport);
        $sdk->init(['projectToken' => 'synthetic-token', 'logger' => $logger]);
        $error = new \RuntimeException('outer', 0, new \LogicException('inner'));
        $logger->error('operation failed', ['exception' => $error, 'password' => 'SYNTHETIC_PRIVATE']);
        $sdk->flush();
        self::assertCount(1, $transport->calls);
        self::assertSame('backend_exception', $transport->calls[0]['events'][0]['event_type']);
        $sdk->captureException($error);
        $logger->error('ordinary error');
        $sdk->flush();
        $events = array_merge(...array_column($transport->calls, 'events'));
        self::assertCount(2, $events);
        self::assertSame('backend_exception', $events[0]['event_type']);
        self::assertStringContainsString('inner', $events[0]['payload']['stack']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($events, JSON_THROW_ON_ERROR));
        self::assertSame('log_event', $events[1]['event_type']);
    }

    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    public function testInitAttachesMonologHandlerAndCapturesWarningLogs(): void
    {
        $transport = new FakeTransport();
        $logger = new Logger('checkout');
        $existingHandler = new TestHandler();
        $logger->pushHandler($existingHandler);

        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'logLevel' => 'warning',
            'logger' => $logger,
        ]);

        $logger->info('ignore info', ['request_id' => 'req_1']);
        $logger->warning('keep warning', ['request_id' => 'req_2', 'tenant' => 'acme']);
        $sdk->flush();

        self::assertTrue($existingHandler->hasWarningRecords());
        self::assertCount(1, $transport->calls);
        self::assertCount(1, $transport->calls[0]['events']);
        self::assertSame('log_event', $transport->calls[0]['events'][0]['event_type']);
        self::assertSame([
            'level' => 'warning',
            'message' => 'keep warning',
            'attributes' => ['request_id' => 'req_2', 'tenant' => 'acme'],
        ], $transport->calls[0]['events'][0]['payload']);
    }

    public function testResetRemovesSdkOwnedMonologHandler(): void
    {
        $transport = new FakeTransport();
        $logger = new Logger('checkout');

        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'logger' => $logger,
        ]);

        $logger->warning('before reset');
        $sdk->flush();
        $sdk->reset();
        $logger->warning('after reset');

        self::assertCount(1, $transport->calls);
        self::assertSame('before reset', $transport->calls[0]['events'][0]['payload']['message']);
    }

    public function testLaravelLogTapAttachesSdkHandlerWithoutReplacingExistingHandlers(): void
    {
        $transport = new FakeTransport();
        $logger = new Logger('checkout');
        $existingHandler = new TestHandler();
        $logger->pushHandler($existingHandler);

        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'logLevel' => 'warning',
        ]);

        $tap = new DebugBundleLogTap($sdk);
        $tap($logger);

        $logger->warning('laravel tapped warning', ['tenant' => 'acme']);
        $sdk->flush();

        self::assertTrue($existingHandler->hasWarningRecords());
        self::assertCount(1, $transport->calls);
        self::assertSame('laravel tapped warning', $transport->calls[0]['events'][0]['payload']['message']);
        self::assertSame(['tenant' => 'acme'], $transport->calls[0]['events'][0]['payload']['attributes']);
    }
}
