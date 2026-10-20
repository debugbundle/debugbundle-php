<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeConfigFetcher;
use DebugBundle\Tests\Support\FakeConfigResponse;
use DebugBundle\Tests\Support\FakeTransport;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaValidationTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    /** @return iterable<string, array{\Throwable, string, bool}> */
    public static function exceptionMessages(): iterable
    {
        $cases = [
            'empty exception' => [new \Exception(), \Exception::class],
            'empty runtime exception' => [new \RuntimeException(), \RuntimeException::class],
            'empty native error' => [new \Error(), \Error::class],
            'empty HTTP exception' => [
                new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(),
                \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            ],
            'nonempty message' => [new \RuntimeException('database unavailable'), 'database unavailable'],
            'zero message' => [new \RuntimeException('0'), '0'],
            'whitespace message' => [new \RuntimeException(' '), ' '],
        ];
        foreach ($cases as $name => [$error, $expectedMessage]) {
            yield $name => [$error, $expectedMessage, false];
            yield $name . ' with hook' => [$error, $expectedMessage, true];
        }
    }

    #[DataProvider('exceptionMessages')]
    public function testExceptionMessagesMeetCanonicalSchema(
        \Throwable $error,
        string $expectedMessage,
        bool $withHook
    ): void {
        $transport = new FakeTransport();
        $this->sdk = new DebugBundleSdk($transport);
        $hookMessages = [];
        $config = ['projectToken' => 'dbundle_proj_test'];
        if ($withHook) {
            $config['beforeSend'] = static function (array $event) use (&$hookMessages): array {
                $hookMessages[] = $event['payload']['message'];
                return $event;
            };
        }
        $this->sdk->init($config);
        $this->sdk->captureException($error);
        $this->sdk->flush();

        self::assertCount(1, $transport->calls);
        self::assertCount(1, $transport->calls[0]['events']);
        $event = $transport->calls[0]['events'][0];
        $schema = json_decode((string) file_get_contents(__DIR__ . '/fixtures/event-envelope.schema.json'));
        $result = (new Validator())->validate(json_decode((string) json_encode($event)), $schema);
        $validationError = $result->error();
        $details = $validationError === null ? '' : json_encode((new ErrorFormatter())->format($validationError));
        self::assertTrue($result->isValid(), 'Captured exception must satisfy the ingestion event schema: ' . $details);
        self::assertSame($expectedMessage, $event['payload']['message']);
        self::assertSame($error::class, $event['payload']['name']);
        self::assertSame((string) $error, $event['payload']['stack']);
        self::assertTrue($event['payload']['handled']);
        self::assertSame($withHook ? [$expectedMessage] : [], $hookMessages);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function exceptionHookOutcomes(): iterable
    {
        yield 'valid replacement is redacted' => ['replace', 'replacement password=[REDACTED]'];
        yield 'invalid replacement retains original' => ['invalid', \RuntimeException::class];
        yield 'throwing hook retains original' => ['throw', \RuntimeException::class];
        yield 'null drops event' => ['drop', null];
    }

    #[DataProvider('exceptionHookOutcomes')]
    public function testEmptyExceptionKeepsHookAndPrivacySemantics(string $mode, ?string $expectedMessage): void
    {
        $transport = new FakeTransport();
        $this->sdk = new DebugBundleSdk($transport);
        $this->sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'beforeSend' => static function (array $event) use ($mode): ?array {
                if ($mode === 'drop') {
                    return null;
                }
                // The hook owns a clone even for the newly canonical empty object maps.
                $event['payload']['request']['headers']->authorization = 'Bearer HOOK_SECRET';
                if ($mode === 'throw') {
                    throw new \RuntimeException('hook failed');
                }
                $event['payload']['message'] = $mode === 'invalid' ? '' : 'replacement password=HOOK_SECRET';
                return $event;
            },
        ]);
        $this->sdk->captureError(new \RuntimeException());
        self::assertCount(0, $transport->calls);
        $this->sdk->flush();

        if ($expectedMessage === null) {
            self::assertSame([], $transport->calls);
            return;
        }
        self::assertCount(1, $transport->calls);
        $event = $transport->calls[0]['events'][0];
        self::assertSame($expectedMessage, $event['payload']['message']);
        self::assertStringNotContainsString('HOOK_SECRET', json_encode($event, JSON_THROW_ON_ERROR));
        self::assertSame(
            $mode === 'replace' ? ['authorization' => '[REDACTED]'] : [],
            (array) $event['payload']['request']['headers']
        );
        $schema = json_decode((string) file_get_contents(__DIR__ . '/fixtures/event-envelope.schema.json'));
        self::assertTrue((new Validator())->validate(json_decode(json_encode($event, JSON_THROW_ON_ERROR)), $schema)->isValid());
    }

    public function testEmittedPhpSdkEventsValidateAgainstVendoredEventEnvelopeSchema(): void
    {
        $schemaPath = __DIR__ . '/fixtures/event-envelope.schema.json';
        $schema = json_decode((string) file_get_contents($schemaPath));
        self::assertNotNull($schema);

        $validator = new Validator();
        $formatter = new ErrorFormatter();

        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => new FakeConfigFetcher([
                new FakeConfigResponse(200, [
                    'probes_enabled' => true,
                    'remote_probes_enabled' => true,
                    'active_probes' => [[
                        'id' => '550e8400-e29b-41d4-a716-446655440000',
                        'label_pattern' => 'checkout.*',
                        'service' => 'checkout-api',
                        'environment' => 'production',
                        'expires_at' => '2099-01-01T00:00:00.000Z',
                    ]],
                    'poll_interval_ms' => 15000,
                    'capture_policy' => [
                        'preset' => 'balanced',
                        'capture_logs' => 'warning',
                        'capture_request_events' => 'all',
                        'capture_breadcrumbs' => 'local_only',
                        'capture_probe_events' => 'standalone_when_activated',
                    ],
                ]),
            ]),
        ]);
        $sdk->refreshRemoteConfig(true);

        $sdk->probe('checkout.tax', ['rate' => 0.2]);
        $sdk->probe('checkout.deep-tax', ['region' => 'us-east-1'], ['heavy' => true]);
        for ($index = 0; $index < 5; $index++) {
            $sdk->captureException(new \RuntimeException('checkout failed'), [
                'request' => ['method' => 'POST', 'path' => '/checkout', 'headers' => [], 'query' => []],
                'response' => ['status_code' => 500],
            ]);
        }
        $sdk->captureMessage('warning raised', 'warning', ['tenant' => 'acme']);
        $sdk->captureRequest(
            ['method' => 'GET', 'path' => '/orders', 'headers' => ['x-request-id' => 'req_1'], 'query' => ['page' => '1']],
            ['status_code' => 503, 'duration_ms' => 41]
        );
        $sdk->flush();

        $calls = $transport->calls;
        self::assertCount(1, $calls);
        $events = $calls[0]['events'];
        $eventTypes = array_map(static fn (array $event): string => $event['event_type'], $events);
        foreach (['backend_exception', 'error_suppressed', 'log_event', 'request_event', 'probe_event'] as $eventType) {
            self::assertContains($eventType, $eventTypes);
        }

        foreach ($events as $event) {
            $result = $validator->validate(json_decode((string) json_encode($event)), $schema);
            $message = 'schema validation failed';
            if (!$result->isValid()) {
                $error = $result->error();
                $message = json_encode(
                    $error !== null ? $formatter->format($error) : ['schema' => ['validation failed without error details']],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: $message;
            }

            self::assertTrue($result->isValid(), $message);
        }
    }
}
