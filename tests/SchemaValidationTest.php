<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Tests\Support\FakeConfigFetcher;
use DebugBundle\Tests\Support\FakeConfigResponse;
use DebugBundle\Tests\Support\FakeTransport;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
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