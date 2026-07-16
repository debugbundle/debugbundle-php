<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\Relay\BrowserRelayAcceptedBatch;
use DebugBundle\Relay\BrowserRelayHandler;
use DebugBundle\Transport\TransportInterface;
use DebugBundle\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class RelayHandlerTest extends TestCase
{
    /** @var array{version:int,cases:list<array<string,mixed>>}|null */
    private static ?array $relayComplianceFixtures = null;

    public function testAcceptsAnalyticsEventsAndPreservesAnalyticsCorrelation(): void
    {
        $fixture = self::relayComplianceFixture('valid-analytics-event');
        $acceptedBatch = null;
        $handler = new BrowserRelayHandler([
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$acceptedBatch): void {
                $acceptedBatch = $batch;
            },
        ]);

        $response = $handler->handle($this->createRequestFromFixture($fixture['request']));

        self::assertSame(202, $response->status);
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $acceptedBatch);
        self::assertEquals($fixture['expectedEventFile'][0], $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('project_token', $acceptedBatch->events[0]);
    }

    public function testAcceptsValidBrowserEventsAndStripsTrustSensitiveFields(): void
    {
        /** @var array{request: array{method?:string,headers?:array<string,string>,bodyJson?:mixed,bodyText?:string,ipAddress?:string}, expected: array{status:int,accepted:int,rejected:int,errors:list<string>}, expectedEventFile: list<array<string,mixed>>} $fixture */
        $fixture = self::relayComplianceFixture('credential-smuggling-payload');
        $acceptedBatch = null;
        $handler = new BrowserRelayHandler([
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$acceptedBatch): void {
                $acceptedBatch = $batch;
            },
        ]);

        $response = $handler->handle($this->createRequestFromFixture($fixture['request']));

        self::assertSame($fixture['expected']['status'], $response->status);
        self::assertSame([
            'accepted' => $fixture['expected']['accepted'],
            'rejected' => $fixture['expected']['rejected'],
            'errors' => $fixture['expected']['errors'],
        ], $response->body);
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $acceptedBatch);
        self::assertCount(1, $acceptedBatch->events);
        self::assertEquals($fixture['expectedEventFile'][0], $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('project_token', $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('organization_id', $acceptedBatch->events[0]);
        self::assertArrayNotHasKey('authorization', $acceptedBatch->headers);
        self::assertArrayNotHasKey('cookie', $acceptedBatch->headers);
        self::assertArrayNotHasKey('x-api-key', $acceptedBatch->headers);
    }

    public function testRejectsUnsupportedEventTypesButAcceptsValidEventsInSameBatch(): void
    {
        /** @var array{request: array{method?:string,headers?:array<string,string>,bodyJson?:mixed,bodyText?:string,ipAddress?:string}, expected: array{status:int,accepted:int,rejected:int,errors:list<string>}} $fixture */
        $fixture = self::relayComplianceFixture('mixed-valid-invalid-batch');
        $acceptedBatch = null;
        $handler = new BrowserRelayHandler([
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$acceptedBatch): void {
                $acceptedBatch = $batch;
            },
        ]);

        $response = $handler->handle($this->createRequestFromFixture($fixture['request']));

        self::assertSame($fixture['expected']['status'], $response->status);
        self::assertSame([
            'accepted' => $fixture['expected']['accepted'],
            'rejected' => $fixture['expected']['rejected'],
            'errors' => $fixture['expected']['errors'],
        ], $response->body);
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

    public function testAnswersAllowedCrossOriginPreflight(): void
    {
        $handler = new BrowserRelayHandler([
            'allowedOrigins' => ['https://web.example.com'],
        ]);

        $response = $handler->handle([
            'method' => 'OPTIONS',
            'headers' => [
                'host' => 'api.example.com',
                'origin' => 'https://web.example.com',
                'access-control-request-method' => 'POST',
                'access-control-request-headers' => 'content-type',
            ],
            'body' => '',
            'ipAddress' => '203.0.113.10',
        ]);

        self::assertSame(204, $response->status);
        self::assertSame('https://web.example.com', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('POST, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
    }

    public function testAddsCorsHeadersToAcceptedCrossOriginPosts(): void
    {
        $handler = new BrowserRelayHandler([
            'allowedOrigins' => ['https://web.example.com'],
        ]);

        $response = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'application/json',
            'host' => 'api.example.com',
            'origin' => 'https://web.example.com',
        ], '203.0.113.10', [[
            'schema_version' => '2026-03-01',
            'event_id' => '00000000-0000-4000-8000-000000000307',
            'event_type' => 'frontend_exception',
            'occurred_at' => '2026-03-31T10:00:00Z',
            'sdk_version' => '1.2.3',
            'service' => ['name' => 'checkout-web', 'environment' => 'production'],
            'payload' => ['name' => 'TypeError', 'message' => 'broken'],
        ]]));

        self::assertSame(202, $response->status);
        self::assertSame('https://web.example.com', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers['Vary']);
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

    public function testUsesInjectedRateLimitStoreWhenProvided(): void
    {
        $calls = [];
        $rateLimitStore = new class($calls) implements \DebugBundle\Relay\BrowserRelayRateLimitStore {
            /** @var list<array{ipAddress:?string,maxRequests:int,windowSeconds:int}> */
            public array $calls = [];

            /** @param list<array{ipAddress:?string,maxRequests:int,windowSeconds:int}> $calls */
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function allow(?string $ipAddress, int $maxRequests, int $windowSeconds, int $now): bool
            {
                $this->calls[] = [
                    'ipAddress' => $ipAddress,
                    'maxRequests' => $maxRequests,
                    'windowSeconds' => $windowSeconds,
                ];

                return false;
            }
        };

        $handler = new BrowserRelayHandler([
            'rateLimitPerMinute' => 3,
            'rateLimitStore' => $rateLimitStore,
        ]);

        $response = $handler->handle($this->createRequest(['batch' => []], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ], '203.0.113.44', [[
            'schema_version' => '2026-03-01',
            'event_id' => '00000000-0000-4000-8000-000000000314',
            'event_type' => 'frontend_exception',
            'occurred_at' => '2026-03-31T10:00:00Z',
            'sdk_version' => '1.2.3',
            'service' => ['name' => 'checkout-web', 'environment' => 'production'],
            'payload' => ['name' => 'TypeError', 'message' => 'broken'],
        ]]));

        self::assertSame(429, $response->status);
        self::assertSame([
            [
                'ipAddress' => '203.0.113.44',
                'maxRequests' => 3,
                'windowSeconds' => 60,
            ],
        ], $calls);
    }

    public function testLocalOnlyModeWritesRelayEventFile(): void
    {
        $eventsDir = sys_get_temp_dir() . '/debugbundle-php-relay-events-' . bin2hex(random_bytes(4));
        $handler = new BrowserRelayHandler([
            'projectMode' => 'local-only',
            'localEventsDir' => $eventsDir,
            'allowedOrigins' => ['https://app.example.com'],
        ]);

        $response = $handler->handle($this->createRequest([
            'batch' => [[
                'schema_version' => '2026-03-01',
                'event_id' => '00000000-0000-4000-8000-000000000309',
                'event_type' => 'frontend_exception',
                'occurred_at' => '2026-03-31T10:00:00Z',
                'sdk_version' => '1.2.3',
                'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                'payload' => ['name' => 'TypeError', 'message' => 'broken'],
            ]],
        ], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ]));

        self::assertSame(202, $response->status);
        $files = glob($eventsDir . '/*.events.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
    }

    public function testConnectedDurableModeMarksDeliveredSpoolFilesAfterSuccessfulForward(): void
    {
        $spoolDir = sys_get_temp_dir() . '/debugbundle-php-relay-spool-' . bin2hex(random_bytes(4));
        $forwardTransport = new class() implements TransportInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function send(array $request): TransportResponse
            {
                $this->calls[] = $request;
                return new TransportResponse(202, null);
            }
        };

        $handler = new BrowserRelayHandler([
            'projectMode' => 'connected',
            'projectToken' => 'dbundle_proj_test',
            'endpoint' => 'https://api.debugbundle.com/v1/events',
            'spoolDir' => $spoolDir,
            'allowedOrigins' => ['https://app.example.com'],
            'forwardTransport' => $forwardTransport,
        ]);

        $response = $handler->handle($this->createRequest([
            'batch' => [[
                'schema_version' => '2026-03-01',
                'event_id' => '00000000-0000-4000-8000-000000000310',
                'event_type' => 'frontend_exception',
                'occurred_at' => '2026-03-31T10:00:00Z',
                'sdk_version' => '1.2.3',
                'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                'payload' => ['name' => 'TypeError', 'message' => 'broken'],
            ]],
        ], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ]));

        self::assertSame(202, $response->status);
        $files = glob($spoolDir . '/*.events.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertFileExists($files[0] . '.delivered');
        self::assertCount(1, $forwardTransport->calls);
        self::assertSame('dbundle_proj_test', $forwardTransport->calls[0]['events'][0]['project_token']);
    }

    public function testConnectedLowLatencyModeReturnsServerErrorWhenForwardingFails(): void
    {
        $forwardTransport = new class() implements TransportInterface {
            public function send(array $request): TransportResponse
            {
                return new TransportResponse(500, null);
            }
        };

        $handler = new BrowserRelayHandler([
            'projectMode' => 'connected',
            'projectToken' => 'dbundle_proj_test',
            'endpoint' => 'https://api.debugbundle.com/v1/events',
            'durableWrite' => false,
            'allowedOrigins' => ['https://app.example.com'],
            'forwardTransport' => $forwardTransport,
        ]);

        $response = $handler->handle($this->createRequest([
            'batch' => [[
                'schema_version' => '2026-03-01',
                'event_id' => '00000000-0000-4000-8000-000000000311',
                'event_type' => 'frontend_exception',
                'occurred_at' => '2026-03-31T10:00:00Z',
                'sdk_version' => '1.2.3',
                'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                'payload' => ['name' => 'TypeError', 'message' => 'broken'],
            ]],
        ], [
            'content-type' => 'application/json',
            'host' => 'app.example.com',
            'origin' => 'https://app.example.com',
        ]));

        self::assertSame(500, $response->status);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param list<array<string, mixed>>|null $batch
     * @return array{headers: array<string, string>, body: string, ipAddress: string}
     */
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

    /**
     * @param array{method?:string,headers?:array<string,string>,bodyJson?:mixed,bodyText?:string,ipAddress?:string} $request
     * @return array{method:string,headers:array<string,string>,body:string,ipAddress:string}
     */
    private function createRequestFromFixture(array $request): array
    {
        return [
            'method' => $request['method'] ?? 'POST',
            'headers' => $request['headers'] ?? [],
            'body' => isset($request['bodyText'])
                ? (string) $request['bodyText']
                : json_encode($request['bodyJson'] ?? ['batch' => []], JSON_THROW_ON_ERROR),
            'ipAddress' => $request['ipAddress'] ?? '203.0.113.10',
        ];
    }

    /** @return array<string, mixed> */
    private static function relayComplianceFixture(string $fixtureId): array
    {
        if (self::$relayComplianceFixtures === null) {
            /** @var array{version:int,cases:list<array<string,mixed>>} $decoded */
            $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/relay-compliance.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$relayComplianceFixtures = $decoded;
        }

        foreach (self::$relayComplianceFixtures['cases'] as $fixture) {
            if (($fixture['id'] ?? null) === $fixtureId) {
                return $fixture;
            }
        }

        throw new \RuntimeException('Missing relay compliance fixture: ' . $fixtureId);
    }
}
