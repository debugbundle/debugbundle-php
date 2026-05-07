<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    /** @var resource|null */
    private $serverProcess = null;

    private ?string $stdoutPath = null;
    private ?string $stderrPath = null;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        foreach ([$this->stdoutPath, $this->stderrPath] as $path) {
            if ($path !== null && file_exists($path)) {
                unlink($path);
            }
        }

        $this->serverProcess = null;
        $this->stdoutPath = null;
        $this->stderrPath = null;

        parent::tearDown();
    }

    public function testSendPostsJsonAndParsesRetryAfterHeader(): void
    {
        $captureFile = tempnam(sys_get_temp_dir(), 'debugbundle-http-capture-');
        self::assertNotFalse($captureFile);

        $port = $this->startRouterServer();
        $endpoint = sprintf(
            'http://127.0.0.1:%d/?status=429&retry_after=1.5&capture_file=%s',
            $port,
            rawurlencode($captureFile)
        );

        $transport = new HttpTransport($endpoint);
        $response = $transport->send([
            'project_token' => 'dbundle_proj_test',
            'events' => [['event_type' => 'backend_exception', 'payload' => ['message' => 'boom']]],
        ]);

        $captured = json_decode((string) file_get_contents($captureFile), true, 512, JSON_THROW_ON_ERROR);
        unlink($captureFile);

        self::assertSame(429, $response->statusCode);
        self::assertSame(1500, $response->retryAfterMs);
        self::assertSame('Bearer dbundle_proj_test', $captured['headers']['Authorization'] ?? null);
        self::assertStringContainsString('application/json', $captured['headers']['Content-Type'] ?? '');
        self::assertSame([
            'events' => [['event_type' => 'backend_exception', 'payload' => ['message' => 'boom']]],
        ], $captured['body']);
    }

    public function testSendReturnsSyntheticFailureWhenNetworkThrows(): void
    {
        $transport = new HttpTransport('http://127.0.0.1:9/unreachable');

        $response = $transport->send([
            'project_token' => 'dbundle_proj_test',
            'events' => [],
        ]);

        self::assertSame(500, $response->statusCode);
        self::assertNull($response->retryAfterMs);
    }

    private function startRouterServer(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr((string) strrchr($address, ':'), 1);
        fclose($socket);

        $this->stdoutPath = tempnam(sys_get_temp_dir(), 'debugbundle-http-out-') ?: null;
        $this->stderrPath = tempnam(sys_get_temp_dir(), 'debugbundle-http-err-') ?: null;
        $routerPath = __DIR__ . '/fixtures/http_transport_router.php';

        $process = proc_open(
            [PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $port), $routerPath],
            [
                0 => ['pipe', 'r'],
                1 => ['file', (string) $this->stdoutPath, 'w'],
                2 => ['file', (string) $this->stderrPath, 'w'],
            ],
            $pipes,
            __DIR__
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->serverProcess = $process;

        $deadline = microtime(true) + 5.0;
        do {
            $connection = @fsockopen('127.0.0.1', $port);
            if (is_resource($connection)) {
                fclose($connection);
                return $port;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for the test HTTP server to start.');
    }
}