<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\Relay\RelayFileTransport;
use DebugBundle\Relay\RelayForwardTransport;
use DebugBundle\Transport\TransportInterface;
use DebugBundle\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class RelayTransportTest extends TestCase
{
    public function testRelayFileTransportReturnsAcceptedForEmptyBatch(): void
    {
        $transport = new RelayFileTransport(sys_get_temp_dir() . '/debugbundle-php-empty-' . bin2hex(random_bytes(4)), 'checkout api');

        $result = $transport->write([]);

        self::assertSame(202, $result->statusCode);
        self::assertNull($result->writtenFilePath);
    }

    public function testRelayFileTransportWritesSanitizedEventFileAndMarksDelivery(): void
    {
        $eventsDir = sys_get_temp_dir() . '/debugbundle-php-relay-file-' . bin2hex(random_bytes(4));
        $transport = new RelayFileTransport($eventsDir, 'checkout api/web');

        $result = $transport->write([['event_type' => 'frontend_exception', 'payload' => ['message' => 'broken']]]);

        self::assertSame(202, $result->statusCode);
        self::assertNotNull($result->writtenFilePath);
        self::assertFileExists($result->writtenFilePath);
        self::assertStringContainsString('checkout-api-web.events.json', $result->writtenFilePath);
        self::assertSame(
            [['event_type' => 'frontend_exception', 'payload' => ['message' => 'broken']]],
            json_decode((string) file_get_contents($result->writtenFilePath), true, 512, JSON_THROW_ON_ERROR),
        );

        RelayFileTransport::markDelivered($result->writtenFilePath);
        self::assertFileExists($result->writtenFilePath . '.delivered');
    }

    public function testRelayFileTransportExposesDefaultDirectories(): void
    {
        $cwd = '/tmp/debugbundle-php-defaults';

        self::assertSame($cwd . '/.debugbundle/local/events', RelayFileTransport::resolveDefaultLocalEventsDir($cwd));
        self::assertSame($cwd . '/.debugbundle/local/browser-relay-spool', RelayFileTransport::resolveDefaultRelaySpoolDir($cwd));
    }

    public function testRelayFileTransportReturnsServerErrorWhenDirectoryCannotBeCreated(): void
    {
        $parentFile = tempnam(sys_get_temp_dir(), 'debugbundle-php-relay-parent-');
        self::assertNotFalse($parentFile);

        $transport = new RelayFileTransport($parentFile . '/events', 'checkout-api');

        $result = $transport->write([['event_type' => 'frontend_exception']]);

        self::assertSame(500, $result->statusCode);
        self::assertNull($result->writtenFilePath);
    }

    public function testRelayForwardTransportSkipsEmptyProjectToken(): void
    {
        $transport = new class() implements TransportInterface {
            public int $calls = 0;

            public function send(array $request): TransportResponse
            {
                $this->calls++;
                return new TransportResponse(202, null);
            }
        };

        $forwardTransport = new RelayForwardTransport($transport);

        self::assertSame([false, false], $forwardTransport->send('', [['event_type' => 'frontend_exception']]));
        self::assertSame(0, $transport->calls);
    }

    public function testRelayForwardTransportAttachesProjectTokenOnSuccess(): void
    {
        $transport = new class() implements TransportInterface {
            /** @var array<string, mixed>|null */
            public ?array $request = null;

            public function send(array $request): TransportResponse
            {
                $this->request = $request;
                return new TransportResponse(202, null);
            }
        };

        $forwardTransport = new RelayForwardTransport($transport);

        self::assertSame([true, true], $forwardTransport->send('dbundle_proj_test', [['event_type' => 'frontend_exception']]));
        self::assertSame('dbundle_proj_test', $transport->request['project_token']);
        self::assertSame('dbundle_proj_test', $transport->request['events'][0]['project_token']);
    }

    public function testRelayForwardTransportReturnsFalseForNonSuccessStatus(): void
    {
        $transport = new class() implements TransportInterface {
            public function send(array $request): TransportResponse
            {
                return new TransportResponse(500, null);
            }
        };

        $forwardTransport = new RelayForwardTransport($transport);

        self::assertSame([true, false], $forwardTransport->send('dbundle_proj_test', [['event_type' => 'frontend_exception']]));
    }

    public function testRelayForwardTransportHandlesTransportExceptions(): void
    {
        $transport = new class() implements TransportInterface {
            public function send(array $request): TransportResponse
            {
                throw new \RuntimeException('boom');
            }
        };

        $forwardTransport = new RelayForwardTransport($transport);

        self::assertSame([true, false], $forwardTransport->send('dbundle_proj_test', [['event_type' => 'frontend_exception']]));
    }
}
