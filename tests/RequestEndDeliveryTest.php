<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class RequestEndDeliveryTest extends TestCase
{
    public function testOutageMakesOneAttemptThenLosesUnsentEventsAtRequestExit(): void
    {
        $transport = new FakeTransport([new TransportResponse(503, null)]);
        $sdk = new DebugBundleSdk($transport);
        try {
            $sdk->init(['projectToken' => 'dbundle_proj_test']);
            $sdk->captureException(new \RuntimeException('one important failure'));

            $sdk->flushAtRequestEnd();
            $sdk->flushAtRequestEnd();
            self::assertCount(1, $transport->calls);
            $buffer = (new \ReflectionProperty(DebugBundleSdk::class, 'buffer'))->getValue($sdk);
            self::assertIsArray($buffer);
            self::assertCount(1, $buffer);
        } finally {
            $sdk->reset();
        }

        $bufferAfterExit = (new \ReflectionProperty(DebugBundleSdk::class, 'buffer'))->getValue($sdk);
        self::assertIsArray($bufferAfterExit);
        self::assertSame([], $bufferAfterExit);
    }
}
