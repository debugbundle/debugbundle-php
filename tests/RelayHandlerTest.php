<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\Relay\BrowserRelayAcceptedBatch;
use DebugBundle\Relay\BrowserRelayHandler;
use PHPUnit\Framework\TestCase;

final class RelayHandlerTest extends TestCase
{
    public function testAcceptsValidBrowserEventsAndStripsTrustSensitiveFields(): void
    {
        $acceptedBatch = null;
        $handler = new BrowserRelayHandler([
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$acceptedBatch): void {
                $acceptedBatch = $batch;
            },
        ]);

        $response = $handler->handle($this->createRequest([
            'batch' => [[
                'schema_version' => '2026-03-01',
                'event_id' => '00000000-0000-4000-8000-000000000301',
                'event_type' => 'frontend_exception',
                'occurred_at' => '2026-03-31T10:00:00Z',
                'sdk_name' => 'spoofed-browser-sdk',
                'sdk_version' => '1.2.3',
                'project_token' => 'dbundle_proj_stolen',
                'organization_id' => 'org_123',
                'service' => [
                    'name' => 'checkout-web',
                    'environment' => 'production',
                ],
                'correlation' => [
                    'trace_id' => '11111111-1111-4111-8111-111111111111',
                ],
                'payload' => [
                    'name' => 'TypeError',
                    'message' => 'broken',
                ],
                'unexpected_top_level' => 'drop-me',
            ]],
        ], [
            'content-type' => 'application/json; charset=utf-8',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
            'authorization' => 'Bearer browser-should-not-send-this',
        ]));

        self::assertSame(202, $response->status);
        self::assertSame(['accepted' => 1, 'rejected' => 0, 'errors' => []], $response->body);
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $acceptedBatch);
        self::assertCount(1, $acceptedBatch->events);
        self::assertSame('@debugbundle/sdk-browser', $acceptedBatch->events[0]['sdk_name']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $acceptedBatch->events[0]['correlation']['trace_id']);
        self::assertArrayNotHasKey('project_token', $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('organization_id', $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('unexpected_top_level', $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('authorization', $acceptedBatch->headers);
    }

    public function testRejectsUnsupportedEventTypesButAcceptsValidEventsInSameBatch(): void
    {
        $acceptedBatch = null;
        $handler = new BrowserRelayHandler([
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$acceptedBatch): void {
                $acceptedBatch = $batch;
            },
        ]);

        $response = $handler->handle($this->createRequest([
            'batch' => [
                [
                    'schema_version' => '2026-03-01',
                    'event_id' => '00000000-0000-4000-8000-000000000302',
                    'event_type' => 'frontend_exception',
                    'occurred_at' => '2026-03-31T10:00:00Z',
                    'sdk_version' => '1.2.3',
                    'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                    'payload' => ['name' => 'TypeError', 'message' => 'broken'],
                ],
                [
                    'schema_version' => '2026-03-01',
                    'event_id' => '00000000-0000-4000-8000-000000000303',
                    'event_type' => 'backend_exception',
                    'occurred_at' => '2026-03-31T10:00:00Z',
                    'sdk_version' => '1.2.3',
                    'service' => ['name' => 'checkout-api', 'environment' => 'production'],
                    'payload' => ['name' => 'Error', 'message' => 'boom'],
                ],
            ],
        ], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ]));

        self::assertSame(400, $response->status);
        self::assertSame(['accepted' => 1, 'rejected' => 1, 'errors' => ['batch[1]: Unsupported browser relay event type backend_exception.']], $response->body);
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $acceptedBatch);
        self::assertCount(1, $acceptedBatch->events);
        self::assertSame('frontend_exception', $acceptedBatch->events[0]['event_type']);
    }

    public function testAcceptsBrowserRequestEventsForRequestFailureIncidents(): void
    {
        $acceptedBatch = null;
        $handler = new BrowserRelayHandler([
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$acceptedBatch): void {
                $acceptedBatch = $batch;
            },
        ]);

        $response = $handler->handle($this->createRequest([
            'batch' => [[
                'schema_version' => '2026-03-01',
                'event_id' => '00000000-0000-4000-8000-000000000304',
                'event_type' => 'request_event',
                'occurred_at' => '2026-03-31T10:00:00Z',
                'sdk_version' => '1.2.3',
                'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                'payload' => [
                    'method' => 'POST',
                    'path' => '/v1/billing/checkout',
                    'query' => ['plan' => 'team'],
                    'headers' => [],
                    'response_status' => 503,
                    'duration_ms' => 84,
                ],
            ]],
        ], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ]));

        self::assertSame(202, $response->status);
        self::assertSame(['accepted' => 1, 'rejected' => 0, 'errors' => []], $response->body);
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $acceptedBatch);
        self::assertSame('request_event', $acceptedBatch->events[0]['event_type']);
        self::assertSame(503, $acceptedBatch->events[0]['payload']['response_status']);
    }

    public function testRejectsRequestsFromNonMatchingOrigins(): void
    {
        $handler = new BrowserRelayHandler();

        $response = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://evil.example.com',
        ]));

        self::assertSame(403, $response->status);
        self::assertNull($response->body);
    }

    public function testAcceptsRefererFallbackWhenConfiguredOriginMatchesAllowList(): void
    {
        $accepted = 0;
        $handler = new BrowserRelayHandler([
            'allowedOrigins' => ['https://dashboard.example.net'],
            'onAccept' => static function () use (&$accepted): void {
                $accepted++;
            },
        ]);

        $response = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'application/json',
            'host' => 'relay.internal.example',
            'origin' => '',
            'referer' => 'https://dashboard.example.net/settings',
        ], '203.0.113.10', [[
            'schema_version' => '2026-03-01',
            'event_id' => '00000000-0000-4000-8000-000000000306',
            'event_type' => 'frontend_exception',
            'occurred_at' => '2026-03-31T10:00:00Z',
            'sdk_version' => '1.2.3',
            'service' => ['name' => 'checkout-web', 'environment' => 'production'],
            'payload' => ['name' => 'TypeError', 'message' => 'broken'],
        ]]));

        self::assertSame(202, $response->status);
        self::assertSame(1, $accepted);
    }

    public function testRejectsUnsupportedContentTypes(): void
    {
        $handler = new BrowserRelayHandler();

        $response = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'text/plain',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ]));

        self::assertSame(400, $response->status);
        self::assertSame(['accepted' => 0, 'rejected' => 0, 'errors' => ['Relay requests must use Content-Type: application/json.']], $response->body);
    }

    public function testRejectsPayloadsLargerThanLimit(): void
    {
        $handler = new BrowserRelayHandler(['maxBodyBytes' => 8]);

        $response = $handler->handle([
            'headers' => [
                'content-type' => 'application/json',
                'host' => 'app.example.com',
                'origin' => 'https://app.example.com',
            ],
            'body' => '{"batch":[]}',
            'ipAddress' => '203.0.113.10',
        ]);

        self::assertSame(413, $response->status);
        self::assertNull($response->body);
    }

    public function testRateLimitsByIpAddress(): void
    {
        $accepted = 0;
        $handler = new BrowserRelayHandler([
            'rateLimitPerMinute' => 1,
            'onAccept' => static function () use (&$accepted): void {
                $accepted++;
            },
        ]);

        $first = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ], '203.0.113.10', [[
            'schema_version' => '2026-03-01',
            'event_id' => '00000000-0000-4000-8000-000000000307',
            'event_type' => 'frontend_exception',
            'occurred_at' => '2026-03-31T10:00:00Z',
            'sdk_version' => '1.2.3',
            'service' => ['name' => 'checkout-web', 'environment' => 'production'],
            'payload' => ['name' => 'TypeError', 'message' => 'broken'],
        ]]));
        $second = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ], '203.0.113.10', [[
            'schema_version' => '2026-03-01',
            'event_id' => '00000000-0000-4000-8000-000000000308',
            'event_type' => 'frontend_exception',
            'occurred_at' => '2026-03-31T10:00:01Z',
            'sdk_version' => '1.2.3',
            'service' => ['name' => 'checkout-web', 'environment' => 'production'],
            'payload' => ['name' => 'TypeError', 'message' => 'broken again'],
        ]]));

        self::assertSame(202, $first->status);
        self::assertSame(429, $second->status);
        self::assertSame(1, $accepted);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param list<array<string, mixed>>|null $batch
     * @return array{headers: array<string, string>, body: string, ipAddress: string}
     */
    private function createRequest(array $body, array $headers, string $ipAddress = '203.0.113.10', ?array $batch = null): array
    {
        if ($batch !== null) {
            $body['batch'] = $batch;
        }

        return [
            'headers' => $headers,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'ipAddress' => $ipAddress,
        ];
    }
}