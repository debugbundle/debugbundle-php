<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\Laravel\DebugBundleMiddleware;
use DebugBundle\Framework\Symfony\DebugBundleEventSubscriber;
use DebugBundle\Tests\Support\FakeConfigFetcher;
use DebugBundle\Tests\Support\FakeConfigResponse;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Tests\Support\ManualClock;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Http\Response as LaravelResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpRateLimitContextTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    private function createBalancedSdk(FakeTransport $transport, ManualClock $clock): DebugBundleSdk
    {
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'gallery-api',
            'environment' => 'production',
            'configFetcher' => new FakeConfigFetcher([
                new FakeConfigResponse(200, [
                    'probes_enabled' => false,
                    'remote_probes_enabled' => false,
                    'active_probes' => [],
                    'poll_interval_ms' => 60000,
                    'capture_policy' => [
                        'preset' => 'balanced',
                        'capture_logs' => 'warning',
                        'capture_request_events' => 'failures_only',
                        'capture_breadcrumbs' => 'local_only',
                        'capture_probe_events' => 'buffer_only',
                    ],
                ]),
            ]),
        ]);
        $sdk->refreshRemoteConfig(true);
        return $sdk;
    }

    public function testCoreCaptureRequestPreservesRouteTemplateAndAllowlistedResponseHeadersOnly(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = $this->createBalancedSdk($transport, $clock);

        $sdk->captureRequest(
            [
                'method' => 'GET',
                'path' => '/api/galleries/42/photos',
                'route_template' => '/api/galleries/{gallery}/photos',
                'query' => [],
                'headers' => ['user-agent' => 'test-agent'],
            ],
            [
                'status_code' => 429,
                'duration_ms' => 14,
                'headers' => [
                    'Retry-After' => ['60'],
                    'X-RateLimit-Limit' => '120',
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => '1764000060',
                    'Set-Cookie' => 'session=secret-cookie',
                    'X-Guest-Email' => 'guest@example.com',
                ],
            ],
            ['rate_limiter' => 'gallery-public']
        );
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        $event = $transport->calls[0]['events'][0];
        self::assertSame('request_event', $event['event_type']);
        self::assertSame('/api/galleries/{gallery}/photos', $event['payload']['route_template']);
        self::assertSame([
            'retry-after' => '60',
            'x-ratelimit-limit' => '120',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '1764000060',
        ], $event['payload']['response_headers']);
        self::assertSame('gallery-public', $event['context']['rate_limiter']);
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-cookie', $serialized);
        self::assertStringNotContainsString('guest@example.com', $serialized);
    }

    public function testLaravelMiddlewareCapturesReturned429WithRouteTemplateRateLimitHeadersAndStaticLimiter(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = $this->createBalancedSdk($transport, $clock);

        $middleware = new DebugBundleMiddleware($sdk, [$clock, 'time']);
        $request = LaravelRequest::create('/api/galleries/99/download', 'GET');
        $route = new class() {
            public function uri(): string
            {
                return 'api/galleries/{gallery}/download';
            }

            /** @return list<string> */
            public function gatherMiddleware(): array
            {
                return ['api', 'throttle:gallery-guest'];
            }
        };

        $response = $middleware->handle($request, static function (LaravelRequest $req) use ($route, $clock): LaravelResponse {
            // Simulate routing resolving after global middleware starts.
            $req->setRouteResolver(static fn () => $route);
            $clock->advance(0.015);
            return new LaravelResponse('Too Many Attempts.', 429, [
                'Retry-After' => '30',
                'X-RateLimit-Limit' => '60',
                'X-RateLimit-Remaining' => '0',
                'Set-Cookie' => 'laravel_session=private',
                'X-Debug-Client-Ip' => '203.0.113.9',
            ]);
        });
        $sdk->flush();

        self::assertSame(429, $response->getStatusCode());
        self::assertCount(1, $transport->calls);
        self::assertCount(1, $transport->calls[0]['events']);
        $event = $transport->calls[0]['events'][0];
        self::assertSame('request_event', $event['event_type']);
        self::assertSame(429, $event['payload']['response_status']);
        self::assertSame('/api/galleries/{gallery}/download', $event['payload']['route_template']);
        self::assertSame([
            'retry-after' => '30',
            'x-ratelimit-limit' => '60',
            'x-ratelimit-remaining' => '0',
        ], $event['payload']['response_headers']);
        self::assertSame('gallery-guest', $event['context']['rate_limiter']);
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('laravel_session', $serialized);
        self::assertStringNotContainsString('203.0.113.9', $serialized);
    }

    public function testLaravelMiddlewareCapturesThrown429OnceWithoutDuplicateBackendExceptionOrDynamicLimiter(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = $this->createBalancedSdk($transport, $clock);

        $middleware = new DebugBundleMiddleware($sdk, [$clock, 'time']);
        $request = LaravelRequest::create('/api/galleries/99/photos', 'GET');
        $route = new class() {
            public function uri(): string
            {
                return 'api/galleries/{gallery}/photos';
            }

            /** @return list<string> */
            public function gatherMiddleware(): array
            {
                // Numeric/parameterized throttle should not be emitted as a static limiter name.
                return ['api', 'throttle:60,1'];
            }
        };

        try {
            $middleware->handle($request, static function (LaravelRequest $req) use ($route, $clock): never {
                $req->setRouteResolver(static fn () => $route);
                $clock->advance(0.01);
                throw new TooManyRequestsHttpException(45, 'Too Many Attempts.', null, 0, [
                    'X-RateLimit-Limit' => '60',
                    'X-RateLimit-Remaining' => '0',
                    'X-Private-Header' => 'do-not-capture',
                ]);
            });
            self::fail('Expected TooManyRequestsHttpException to be rethrown.');
        } catch (TooManyRequestsHttpException) {
            // Expected rethrow.
        }
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        self::assertCount(1, $transport->calls[0]['events']);
        $event = $transport->calls[0]['events'][0];
        self::assertSame('request_event', $event['event_type']);
        self::assertSame(429, $event['payload']['response_status']);
        self::assertSame('/api/galleries/{gallery}/photos', $event['payload']['route_template']);
        self::assertSame([
            'retry-after' => '45',
            'x-ratelimit-limit' => '60',
            'x-ratelimit-remaining' => '0',
        ], $event['payload']['response_headers']);
        self::assertArrayNotHasKey('rate_limiter', is_array($event['context'] ?? null) ? $event['context'] : []);
        self::assertStringNotContainsString('do-not-capture', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function testSymfonySubscriberCapturesThrownAndRendered429ExactlyOnceWithRouteAndAllowlistedHeaders(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = $this->createBalancedSdk($transport, $clock);

        $subscriber = new DebugBundleEventSubscriber($sdk, [$clock, 'time']);
        $kernel = new class() implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse('ok');
            }
        };

        $request = SymfonyRequest::create('/galleries/12/items', 'GET');
        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $request->attributes->set('_route', 'gallery_items_index');
        $request->attributes->set('_route_pattern', '/galleries/{id}/items');
        $clock->advance(0.02);

        $exception = new TooManyRequestsHttpException(120, 'Rate limit exceeded', null, 0, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => '1764000120',
            'Authorization' => 'Bearer secret-token',
        ]);
        $subscriber->onKernelException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        // Symfony also dispatches kernel.response for the rendered 429 response; must not duplicate.
        $subscriber->onKernelResponse(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new SymfonyResponse('Too many requests', 429, ['Retry-After' => '120']))
        );
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        self::assertCount(1, $transport->calls[0]['events']);
        $event = $transport->calls[0]['events'][0];
        self::assertSame('request_event', $event['event_type']);
        self::assertSame(429, $event['payload']['response_status']);
        self::assertSame('/galleries/{id}/items', $event['payload']['route_template']);
        self::assertSame([
            'retry-after' => '120',
            'x-ratelimit-limit' => '100',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '1764000120',
        ], $event['payload']['response_headers']);
        self::assertStringNotContainsString('secret-token', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function testMinimalPresetPreservesUnchanged429Eligibility(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'gallery-api',
            'environment' => 'production',
            'configFetcher' => new FakeConfigFetcher([
                new FakeConfigResponse(200, [
                    'probes_enabled' => false,
                    'remote_probes_enabled' => false,
                    'active_probes' => [],
                    'poll_interval_ms' => 60000,
                    'capture_policy' => [
                        'preset' => 'minimal',
                        'capture_logs' => 'error',
                        'capture_request_events' => 'failures_only',
                        'capture_breadcrumbs' => 'local_only',
                        'capture_probe_events' => 'buffer_only',
                    ],
                ]),
            ]),
        ]);
        $sdk->refreshRemoteConfig(true);

        $middleware = new DebugBundleMiddleware($sdk, [$clock, 'time']);
        $request = LaravelRequest::create('/api/galleries/99/download', 'GET');
        $middleware->handle($request, static fn (): LaravelResponse => new LaravelResponse('Too Many Attempts.', 429, ['Retry-After' => '30']));
        $sdk->flush();

        // Minimal preset keeps 429 off unless balanced/investigative or explicit immediateClientErrorStatuses enables it.
        self::assertCount(0, $transport->calls);
    }
}
