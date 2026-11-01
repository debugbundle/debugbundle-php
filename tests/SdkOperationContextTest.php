<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SdkOperationContextTest extends TestCase
{
    private DebugBundleSdk $sdk;
    private FakeTransport $transport;
    protected function setUp(): void
    {
        $this->transport = new FakeTransport(); $this->sdk = new DebugBundleSdk($this->transport);
        $this->sdk->init(['projectToken' => 'synthetic-project', 'batchSize' => 1000,
            'evidenceCapabilities' => ['version' => 1, 'features' => ['exception_evidence_v1', 'recovery_v1']]]);
    }
    protected function tearDown(): void { $this->sdk->reset(); }
    public function testRequestOwnershipDoesNotLeakIntoConcurrentOrUnownedFibers(): void
    {
        $this->sdk->beginRequest(['headers' => ['x-request-id' => 'main'], 'route_template' => '/main/{id}']);
        $first = new \Fiber(function (): void {
            $this->sdk->beginRequest(['headers' => ['x-request-id' => 'first'], 'route_template' => '/first/{id}']);
            \Fiber::suspend(); $this->sdk->captureException(new \Exception('first')); $this->sdk->endRequest();
        });
        $second = new \Fiber(function (): void {
            $this->sdk->beginRequest(['headers' => ['x-request-id' => 'second'], 'route_template' => '/second/{id}']);
            $this->sdk->captureException(new \Exception('second')); $this->sdk->endRequest();
        });
        $first->start(); $second->start(); $first->resume();
        (new \Fiber(fn () => $this->sdk->captureException(new \Exception('unowned'))))->start();
        $this->sdk->captureException(new \Exception('main')); $this->sdk->endRequest();
        $this->sdk->captureException(new \Exception('after')); $this->sdk->flush();
        $events = $this->transport->calls[0]['events'];
        self::assertSame(['second', 'first', null, 'main', null], array_column(array_column($events, 'correlation'), 'request_id'));
        self::assertSame('/second/{id}', $events[0]['payload']['execution']['name']);
        self::assertSame('request', $events[1]['payload']['execution']['kind']);
        self::assertNull($events[1]['payload']['execution']['limit_ms']);
        self::assertArrayNotHasKey('execution', $events[2]['payload']);
        self::assertArrayNotHasKey('execution', $events[4]['payload']);
    }
    public function testNestedOperationPreservesResultsErrorsAndOuterOwnership(): void
    {
        $error = new \RuntimeException('callback error');
        $result = $this->sdk->runWithOperationContext(['kind' => 'job', 'name' => 'queue.consume', 'limitMs' => 1000], function () use ($error): int {
            try {
                $this->sdk->runWithOperationContext(['kind' => 'command', 'name' => 'cache.warm'], function () use ($error): never {
                    $this->sdk->captureException(new \Exception('nested')); throw $error;
                });
            } catch (\RuntimeException $caught) { self::assertSame($error, $caught); }
            $this->sdk->captureException(new \Exception('outer'), ['failureRef' => '00000000-0000-4000-8000-000000000001']);
            return 42;
        });
        $this->sdk->captureException(new \Exception('after')); $this->sdk->flush();
        self::assertSame(42, $result); $events = $this->transport->calls[0]['events'];
        self::assertSame('command', $events[0]['payload']['execution']['kind']);
        self::assertSame('job', $events[1]['payload']['execution']['kind']);
        self::assertSame(1000, $events[1]['payload']['execution']['limit_ms']);
        self::assertSame('operation', $events[1]['context']['evidence_reference']['scope']['kind']);
        self::assertArrayNotHasKey('execution', $events[2]['payload']);
    }
    public function testOldRequestCompletionCannotClearANewOwnerAfterReinitialization(): void
    {
        $old = new \stdClass(); $new = new \stdClass();
        $this->sdk->beginRequest(['headers' => ['x-request-id' => 'old']], $old);
        $this->sdk->init(['projectToken' => 'new-synthetic-project', 'evidenceCapabilities' => ['version' => 1, 'features' => ['exception_evidence_v1']]]);
        $this->sdk->beginRequest(['headers' => ['x-request-id' => 'new']], $new);
        $this->sdk->endRequest($old); $this->sdk->captureException(new \Exception('new owner')); $this->sdk->flush();
        self::assertSame('new', $this->transport->calls[0]['events'][0]['correlation']['request_id']);
        self::assertSame('request', $this->transport->calls[0]['events'][0]['payload']['execution']['kind']);
    }
}
