<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use Illuminate\Http\Request as LaravelRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class ExampleAppsTest extends TestCase
{
    /** @var resource|null */
    private $serverProcess = null;

    private ?string $stdoutPath = null;
    private ?string $stderrPath = null;
    private ?string $captureFile = null;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        foreach ([$this->stdoutPath, $this->stderrPath, $this->captureFile] as $path) {
            if ($path !== null && file_exists($path)) {
                unlink($path);
            }
        }

        $this->serverProcess = null;
        $this->stdoutPath = null;
        $this->stderrPath = null;
        $this->captureFile = null;

        parent::tearDown();
    }

    public function testLaravelExampleAppEmitsRequestLogAndExceptionEvents(): void
    {
        $basePath = dirname(__DIR__);
        $examplePath = $basePath . '/examples/laravel';

        self::assertFileExists($examplePath . '/ExampleApplication.php');
        self::assertFileExists($examplePath . '/public/index.php');
        self::assertFileExists($examplePath . '/README.md');

        require_once $examplePath . '/ExampleApplication.php';

        $endpoint = $this->startCaptureServer();
        $app = new \DebugBundle\Examples\Laravel\ExampleApplication([
            'projectToken' => 'dbundle_proj_example',
            'service' => 'laravel-example',
            'environment' => 'development',
            'endpoint' => $endpoint,
        ]);

        $logResponse = $app->handle(LaravelRequest::create('/log', 'GET'));
        $exceptionResponse = $app->handle(LaravelRequest::create('/exception', 'GET'));
        $app->reset();

        self::assertSame(202, $logResponse->getStatusCode());
        self::assertSame(500, $exceptionResponse->getStatusCode());

        $requests = $this->readCapturedRequests();
        self::assertCount(2, $requests);

        $events = array_merge(...array_map(static fn (array $request): array => $request['body']['events'], $requests));
        $eventTypes = array_map(static fn (array $event): string => $event['event_type'], $events);
        self::assertContains('request_event', $eventTypes);
        self::assertContains('log_event', $eventTypes);
        self::assertContains('backend_exception', $eventTypes);
    }

    public function testSymfonyExampleAppEmitsRequestLogAndExceptionEvents(): void
    {
        $basePath = dirname(__DIR__);
        $examplePath = $basePath . '/examples/symfony';

        self::assertFileExists($examplePath . '/ExampleApplication.php');
        self::assertFileExists($examplePath . '/public/index.php');
        self::assertFileExists($examplePath . '/README.md');

        require_once $examplePath . '/ExampleApplication.php';

        $endpoint = $this->startCaptureServer();
        $app = new \DebugBundle\Examples\Symfony\ExampleApplication([
            'projectToken' => 'dbundle_proj_example',
            'service' => 'symfony-example',
            'environment' => 'development',
            'endpoint' => $endpoint,
        ]);

        $logResponse = $app->handle(SymfonyRequest::create('/log', 'GET'));
        $exceptionResponse = $app->handle(SymfonyRequest::create('/exception', 'GET'));
        $app->reset();

        self::assertSame(202, $logResponse->getStatusCode());
        self::assertSame(500, $exceptionResponse->getStatusCode());

        $requests = $this->readCapturedRequests();
        self::assertCount(2, $requests);

        $events = array_merge(...array_map(static fn (array $request): array => $request['body']['events'], $requests));
        $eventTypes = array_map(static fn (array $event): string => $event['event_type'], $events);
        self::assertContains('request_event', $eventTypes);
        self::assertContains('log_event', $eventTypes);
        self::assertContains('backend_exception', $eventTypes);
    }

    private function startCaptureServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr((string) strrchr($address, ':'), 1);
        fclose($socket);

        $this->captureFile = tempnam(sys_get_temp_dir(), 'debugbundle-example-capture-') ?: null;
        $this->stdoutPath = tempnam(sys_get_temp_dir(), 'debugbundle-example-out-') ?: null;
        $this->stderrPath = tempnam(sys_get_temp_dir(), 'debugbundle-example-err-') ?: null;
        $routerPath = __DIR__ . '/fixtures/ingest_capture_router.php';

        $env = $_ENV;
        $env['DEBUGBUNDLE_CAPTURE_FILE'] = (string) $this->captureFile;

        $process = proc_open(
            [PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $port), $routerPath],
            [
                0 => ['pipe', 'r'],
                1 => ['file', (string) $this->stdoutPath, 'w'],
                2 => ['file', (string) $this->stderrPath, 'w'],
            ],
            $pipes,
            __DIR__,
            $env,
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->serverProcess = $process;

        $deadline = microtime(true) + 5.0;
        do {
            $connection = @fsockopen('127.0.0.1', $port);
            if (is_resource($connection)) {
                fclose($connection);
                return sprintf('http://127.0.0.1:%d', $port);
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for the example ingest capture server to start.');
    }

    /** @return list<array<string, mixed>> */
    private function readCapturedRequests(): array
    {
        self::assertNotNull($this->captureFile);
        $contents = trim((string) file_get_contents($this->captureFile));
        self::assertNotSame('', $contents, 'Expected the example app to send at least one ingest request.');

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => $line !== ''))
        );
    }
}