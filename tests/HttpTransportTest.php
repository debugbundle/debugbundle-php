<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\ManualClock;
use DebugBundle\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    public function testBuiltInHttpRetainsUnacknowledgedBatchAndRecovers(): void
    {
        $port = $this->startRouterServer();
        foreach (['',
            '{"accepted":2,"rejected":0,"errors":{}}',
            '{"accepted":1,"rejected":1,"errors":{"0":{"index":1,"reason":"rate_limited"}}}',
            '{"accepted":null,"rejected":2,"errors":[{"index":0,"reason":"rate_limited"},{"index":1,"reason":"rate_limited"}]}',
            '{"accepted":2,"rejected":0,"errors":null}',
            '{"accepted":1,"rejected":1,"errors":[{"index":4294967296,"reason":"rate_limited"}]}',
            '{"accepted":1,"rejected":1,"errors":{"one":{"index":1,"reason":"rate_limited"}}}', '<html>proxy</html>', '{}', '[]', 'null',
            '{"accepted":1,"rejected":0,"errors":[]}',
            '{"accepted":0,"rejected":2,"errors":[{"index":0,"reason":"rate_limited"},{"index":0,"reason":"rate_limited"}]}',
            '{"accepted":1,"rejected":1,"errors":[{"index":2,"reason":"rate_limited"}]}'] as $body) {
            $clock = new ManualClock();
            $endpoint = sprintf('http://127.0.0.1:%d/?retry_after=300&response_body=%s', $port, rawurlencode($body));
            $sdk = new DebugBundleSdk(new HttpTransport($endpoint), [$clock, 'time']);
            $sdk->init(['projectToken' => 'dbundle_proj_test', 'batchSize' => 100]);
            try {
                $sdk->captureMessage('first', 'error');
                $sdk->captureMessage('second', 'error');
                $sdk->flush();
                self::assertNull($sdk->getLastEventAt(), $body);
                $bufferProperty = new \ReflectionProperty(DebugBundleSdk::class, 'buffer');
                $buffer = $bufferProperty->getValue($sdk);
                self::assertIsArray($buffer);
                self::assertCount(2, $buffer);
                self::assertSame($clock->now + 300, (new \ReflectionProperty(DebugBundleSdk::class, 'retryAfter'))->getValue($sdk));
                $valid = '{"accepted":2,"rejected":0,"errors":[]}';
                (new \ReflectionProperty(DebugBundleSdk::class, 'transport'))->setValue($sdk,
                    new HttpTransport(sprintf('http://127.0.0.1:%d/?response_body=%s', $port, rawurlencode($valid))));
                $sdk->flush();
                self::assertNull($sdk->getLastEventAt());
                $clock->advance(301);
                $sdk->flush();
                self::assertNotNull($sdk->getLastEventAt());
                self::assertSame([], $bufferProperty->getValue($sdk));
            } finally {
                $sdk->reset();
            }
        }
    }

    public function testRetryAfterHintsAreBoundedBeforeIntegerConversion(): void
    {
        $port = $this->startRouterServer();
        foreach (['0.25' => 250, '999999' => 300000, '1e300' => 300000, '-1' => 0,
            'NaN' => null, 'Infinity' => null, 'nope' => null,
            gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT' => 300000,
            'Sun, 06 Nov 1994 08:49:37 GMT' => 0,
            'Wednesday, 01-Jan-31 00:00:00 GMT' => 300000,
            'Wed Jan  1 00:00:00 2031' => 300000] as $header => $expected) {
            $transport = new HttpTransport(sprintf('http://127.0.0.1:%d/?status=429&retry_after=%s', $port, rawurlencode((string) $header)));
            $response = $transport->send(['project_token' => 'dbundle_proj_test', 'events' => []]);
            self::assertSame($expected, $response->retryAfterMs, (string) $header);
        }
    }

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

    public function testSlowEndpointUsesShortAdvisoryTimeout(): void
    {
        $port = $this->startRouterServer();
        $transport = new HttpTransport(sprintf('http://127.0.0.1:%d/?delay_ms=3000', $port));

        $started = microtime(true);
        $response = $transport->send(['project_token' => 'dbundle_proj_test', 'events' => []]);
        $elapsed = microtime(true) - $started;

        self::assertSame(500, $response->statusCode);
        self::assertLessThan(1.5, $elapsed);
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
