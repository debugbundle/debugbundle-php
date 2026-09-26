<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Tests\Support\ManualClock;
use DebugBundle\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    public function testCustomRetryHintsAreCappedOnEveryRetryPath(): void
    {
        foreach ([new TransportResponse(429, PHP_INT_MAX), new TransportResponse(503, PHP_INT_MAX),
            new TransportResponse(202, PHP_INT_MAX, ['accepted' => 2, 'rejected' => 0, 'errors' => []]),
            new TransportResponse(202, PHP_INT_MAX, ['accepted' => 0, 'rejected' => 1,
                'errors' => [['index' => 0, 'reason' => 'rate_limited']]])] as $response) {
            $clock = new ManualClock();
            $transport = new FakeTransport([$response, new TransportResponse(202, null)]);
            $delayed = new class($transport, $clock) implements \DebugBundle\Transport\TransportInterface {
                public function __construct(private FakeTransport $transport, private ManualClock $clock) {}
                public function send(array $request): TransportResponse
                {
                    $this->clock->advance(10);
                    return $this->transport->send($request);
                }
            };
            $sdk = new DebugBundleSdk($delayed, [$clock, 'time']);
            $sdk->init(['projectToken' => 'dbundle_proj_test', 'batchSize' => 100]);
            try {
                $sdk->captureMessage('retry', 'error');
                $sdk->flush();
                self::assertSame($clock->now + 300, (new \ReflectionProperty(DebugBundleSdk::class, 'retryAfter'))->getValue($sdk));
                $clock->advance(299);
                $sdk->flush();
                self::assertCount(1, $transport->calls);
                $clock->advance(2);
                $sdk->flush();
                self::assertCount(2, $transport->calls);
                self::assertNotNull($sdk->getLastEventAt());
            } finally {
                $sdk->reset();
            }
        }
    }
}
