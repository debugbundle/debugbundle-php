<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\Laravel\DebugBundleExceptionHandler;
use DebugBundle\Framework\Laravel\DebugBundleMiddleware;
use DebugBundle\Framework\Laravel\DebugBundleRelayMiddleware;
use DebugBundle\Framework\Laravel\DebugBundleServiceProvider;
use DebugBundle\Framework\Symfony\DebugBundleBundle;
use DebugBundle\Framework\Symfony\DebugBundleEventSubscriber;
use DebugBundle\Framework\Symfony\DebugBundleRelayController;
use DebugBundle\Tests\Support\FakeConfigFetcher;
use DebugBundle\Tests\Support\FakeConfigResponse;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Tests\Support\ManualClock;
use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandlerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Http\Response as LaravelResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class FrameworkIntegrationTest extends TestCase
{
    private ?DebugBundleSdk $sdk = null;

    protected function tearDown(): void
    {
        $this->sdk?->reset();
        $this->sdk = null;

        parent::tearDown();
    }

    public function testLaravelMiddlewareCapturesRequestEvents(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $middleware = new DebugBundleMiddleware($sdk, [$clock, 'time']);
        $request = LaravelRequest::create('/orders?page=2', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'req_1',
            'HTTP_X_DEBUGBUNDLE_TRACE_ID' => 'trace-laravel',
        ]);

        $clock->advance(0.045);
        $response = $middleware->handle($request, static function () use ($sdk): LaravelResponse {
            $sdk->captureLog('laravel warning', 'error');
            return new LaravelResponse('ok', 201);
        });
        $sdk->flush();

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['log_event', 'request_event'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('trace-laravel', $transport->calls[0]['events'][0]['correlation']['trace_id']);
        self::assertSame('req_1', $transport->calls[0]['events'][0]['correlation']['request_id']);
        self::assertSame('/orders', $transport->calls[0]['events'][1]['payload']['path']);
        self::assertSame(201, $transport->calls[0]['events'][1]['payload']['response_status']);
        self::assertSame('trace-laravel', $transport->calls[0]['events'][1]['correlation']['trace_id']);
        self::assertSame('req_1', $transport->calls[0]['events'][1]['correlation']['request_id']);
    }

    public function testLaravelMiddlewareCapturesExceptionsAndRethrows(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $middleware = new DebugBundleMiddleware($sdk);
        $request = LaravelRequest::create('/checkout', 'POST', [], [], [], [
            'HTTP_X_CORRELATION_ID' => 'corr-laravel',
            'HTTP_X_DEBUGBUNDLE_TRACE_ID' => 'trace-laravel-error',
        ]);

        try {
            $middleware->handle($request, static function () use ($sdk): LaravelResponse {
                $sdk->captureLog('laravel failed warning', 'error');
                throw new \RuntimeException('laravel failed');
            });
            self::fail('Expected RuntimeException to be rethrown.');
        } catch (\RuntimeException $error) {
            self::assertSame('laravel failed', $error->getMessage());
        }

        $sdk->flush();
        self::assertSame(['log_event', 'backend_exception'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('trace-laravel-error', $transport->calls[0]['events'][0]['correlation']['trace_id']);
        self::assertSame('corr-laravel', $transport->calls[0]['events'][0]['correlation']['request_id']);
        self::assertSame('/checkout', $transport->calls[0]['events'][1]['payload']['request']['path']);
        self::assertSame('trace-laravel-error', $transport->calls[0]['events'][1]['correlation']['trace_id']);
        self::assertSame('corr-laravel', $transport->calls[0]['events'][1]['correlation']['request_id']);
    }

    public function testSymfonySubscriberCapturesRequestAndExceptionEvents(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $subscriber = new DebugBundleEventSubscriber($sdk, [$clock, 'time']);
        $kernel = new class() implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse('ok');
            }
        };

        $request = SymfonyRequest::create('/inventory?page=3', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'req_4',
            'HTTP_X_DEBUGBUNDLE_TRACE_ID' => 'trace-symfony',
        ]);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $sdk->captureLog('symfony warning', 'error');
        $clock->advance(0.02);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new SymfonyResponse('ok', 202)));

        $failingRequest = SymfonyRequest::create('/inventory/boom', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => 'corr-symfony',
            'HTTP_X_DEBUGBUNDLE_TRACE_ID' => 'trace-symfony-error',
        ]);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $failingRequest, HttpKernelInterface::MAIN_REQUEST));
        $sdk->captureLog('symfony failure warning', 'error');
        $subscriber->onKernelException(new ExceptionEvent($kernel, $failingRequest, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('symfony failed')));
        $sdk->flush();

        self::assertCount(4, $transport->calls[0]['events']);
        self::assertSame(['log_event', 'request_event', 'log_event', 'backend_exception'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('trace-symfony', $transport->calls[0]['events'][0]['correlation']['trace_id']);
        self::assertSame('req_4', $transport->calls[0]['events'][0]['correlation']['request_id']);
        self::assertSame('/inventory', $transport->calls[0]['events'][1]['payload']['path']);
        self::assertSame('trace-symfony', $transport->calls[0]['events'][1]['correlation']['trace_id']);
        self::assertSame('req_4', $transport->calls[0]['events'][1]['correlation']['request_id']);
        self::assertSame('trace-symfony-error', $transport->calls[0]['events'][2]['correlation']['trace_id']);
        self::assertSame('corr-symfony', $transport->calls[0]['events'][2]['correlation']['request_id']);
        self::assertSame('/inventory/boom', $transport->calls[0]['events'][3]['payload']['request']['path']);
        self::assertSame('trace-symfony-error', $transport->calls[0]['events'][3]['correlation']['trace_id']);
        self::assertSame('corr-symfony', $transport->calls[0]['events'][3]['correlation']['request_id']);
    }

    public function testLaravelProviderAndSymfonyBundleScaffoldLoad(): void
    {
        $app = $this->createMock(Application::class);
        $app->expects(self::once())
            ->method('singleton')
            ->with(
                DebugBundleSdk::class,
                self::callback(static fn (mixed $factory): bool => $factory instanceof \Closure)
            );

        $provider = new DebugBundleServiceProvider($app);
        $provider->register();

        $bundle = new DebugBundleBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);
        self::assertTrue(class_exists(DebugBundleBundle::class));
    }

    public function testLaravelProviderInitializesSdkFromArrayConfig(): void
    {
        $factory = $this->captureLaravelProviderFactory();

        $sdk = $factory($this->createLaravelContainerStub([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]));
        $this->sdk = $sdk;

        self::assertTrue($this->readSdkBoolean($sdk, 'enabled'));
        self::assertSame('checkout-api', $this->readSdkString($sdk, 'service'));
        self::assertSame('production', $this->readSdkString($sdk, 'environment'));
    }

    public function testLaravelProviderSkipsInitForInvalidConfigShape(): void
    {
        $factory = $this->captureLaravelProviderFactory();

        $sdk = $factory($this->createLaravelContainerStub('invalid-config'));
        $this->sdk = $sdk;

        self::assertFalse($this->readSdkBoolean($sdk, 'enabled'));
        self::assertSame('php-service', $this->readSdkString($sdk, 'service'));
    }

    public function testLaravelExceptionHandlerCapturesUnhandledReportsAndSkipsDuplicateThrowables(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $innerHandler = new class() implements LaravelExceptionHandlerContract {
            public int $reported = 0;

            public function report(\Throwable $e)
            {
                $this->reported++;
            }

            public function shouldReport(\Throwable $e)
            {
                return true;
            }

            public function render($request, \Throwable $e): SymfonyResponse
            {
                return new SymfonyResponse('error', 500);
            }

            public function renderForConsole($output, \Throwable $e): void
            {
            }
        };

        $handler = new DebugBundleExceptionHandler($sdk, $innerHandler);
        $error = new \RuntimeException('queue worker failed');

        $handler->report($error);
        $handler->report($error);
        $sdk->flush();

        self::assertSame(2, $innerHandler->reported);
        self::assertCount(1, $transport->calls);
        self::assertSame(['backend_exception'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('queue worker failed', $transport->calls[0]['events'][0]['payload']['message']);
    }

    public function testLaravelProviderDecoratesExistingExceptionHandlerBinding(): void
    {
        $decorator = null;
        $sdk = new DebugBundleSdk();
        $innerHandler = new class() implements LaravelExceptionHandlerContract {
            public function report(\Throwable $e)
            {
            }

            public function shouldReport(\Throwable $e)
            {
                return true;
            }

            public function render($request, \Throwable $e): SymfonyResponse
            {
                return new SymfonyResponse('error', 500);
            }

            public function renderForConsole($output, \Throwable $e): void
            {
            }
        };

        $app = $this->createMock(Application::class);
        $app->expects(self::once())
            ->method('singleton');
        $app->expects(self::once())
            ->method('bound')
            ->with(LaravelExceptionHandlerContract::class)
            ->willReturn(true);
        $app->expects(self::once())
            ->method('extend')
            ->with(
                LaravelExceptionHandlerContract::class,
                self::callback(static function (mixed $closure) use (&$decorator): bool {
                    $decorator = $closure;
                    return $closure instanceof \Closure;
                })
            );
        $app->expects(self::once())
            ->method('make')
            ->with(DebugBundleSdk::class)
            ->willReturn($sdk);

        $provider = new DebugBundleServiceProvider($app);
        $provider->register();

        self::assertInstanceOf(\Closure::class, $decorator);
        self::assertInstanceOf(DebugBundleExceptionHandler::class, $decorator($innerHandler));
    }

    public function testLaravelExceptionHandlerDelegatesRenderAndSkipsCaptureWhenInnerHandlerWouldNotReport(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $renderedResponse = new SymfonyResponse('delegated', 418);
        $innerHandler = new class($renderedResponse) implements LaravelExceptionHandlerContract {
            public int $reported = 0;
            public int $rendered = 0;
            public int $renderedForConsole = 0;

            public function __construct(private readonly SymfonyResponse $response)
            {
            }

            public function report(\Throwable $e)
            {
                $this->reported++;
            }

            public function shouldReport(\Throwable $e)
            {
                return false;
            }

            public function render($request, \Throwable $e): SymfonyResponse
            {
                $this->rendered++;
                return $this->response;
            }

            public function renderForConsole($output, \Throwable $e): void
            {
                $this->renderedForConsole++;
            }
        };

        $handler = new DebugBundleExceptionHandler($sdk, $innerHandler);
        $error = new \RuntimeException('skip capture');
        $request = LaravelRequest::create('/jobs/failed', 'GET');
        $output = $this->createMock(OutputInterface::class);

        self::assertFalse($handler->shouldReport($error));
        self::assertSame($renderedResponse, $handler->render($request, $error));

        $handler->renderForConsole($output, $error);
        $handler->report($error);
        $sdk->flush();

        self::assertSame(1, $innerHandler->reported);
        self::assertSame(1, $innerHandler->rendered);
        self::assertSame(1, $innerHandler->renderedForConsole);
        self::assertCount(0, $transport->calls);
    }

    public function testSymfonySubscriberIgnoresSubRequestsAndFallsBackForInvalidStartTime(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
        ]);

        $subscriber = new DebugBundleEventSubscriber($sdk, [$clock, 'time']);
        $kernel = new class() implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse('ok');
            }
        };

        $subRequest = SymfonyRequest::create('/health', 'GET');
        $subscriber->onKernelRequest(new RequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST, new SymfonyResponse('ok', 204)));
        $subscriber->onKernelException(new ExceptionEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST, new \RuntimeException('ignore')));

        self::assertNull($subRequest->attributes->get('_debugbundle_started_at'));
        self::assertSame([
            'kernel.request' => 'onKernelRequest',
            'kernel.response' => 'onKernelResponse',
            'kernel.exception' => 'onKernelException',
        ], DebugBundleEventSubscriber::getSubscribedEvents());

        $mainRequest = SymfonyRequest::create('/fallback', 'GET');
        $mainRequest->attributes->set('_debugbundle_started_at', 'not-a-number');
        $clock->advance(0.01);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $mainRequest, HttpKernelInterface::MAIN_REQUEST, new SymfonyResponse('ok', 204)));
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        self::assertSame('request_event', $transport->calls[0]['events'][0]['event_type']);
        self::assertSame('/fallback', $transport->calls[0]['events'][0]['payload']['path']);
        self::assertSame(204, $transport->calls[0]['events'][0]['payload']['response_status']);
    }

    public function testLaravelMiddlewareActivatesTriggerTokenFromQueryForSingleRequestOnly(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $triggerTokenKey = 'trigger-key-123';
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => new FakeConfigFetcher([
                new FakeConfigResponse(200, [
                    'probes_enabled' => true,
                    'remote_probes_enabled' => true,
                    'active_probes' => [],
                    'poll_interval_ms' => 15000,
                    'trigger_token_key' => $triggerTokenKey,
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

        $middleware = new DebugBundleMiddleware($sdk, [$clock, 'time']);
        $request = LaravelRequest::create(
            '/orders?_debug_probe=' . rawurlencode($this->createTriggerToken($triggerTokenKey, [
                'activation_id' => '11111111-1111-4111-8111-111111111111',
                'label_pattern' => 'checkout.*',
                'service' => 'checkout-api',
                'environment' => 'production',
                'trigger_expires_at' => '2023-11-14T22:13:30.000Z',
            ])),
            'GET'
        );

        $clock->advance(0.045);
        $middleware->handle($request, function () use ($sdk): LaravelResponse {
            $sdk->probe('checkout.tax', ['rate' => 0.2]);
            return new LaravelResponse('ok', 201);
        });
        $sdk->flush();

        self::assertSame(['probe_event', 'request_event'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('11111111-1111-4111-8111-111111111111', $transport->calls[0]['events'][0]['payload']['activation_id']);

        $transport->calls = [];
        $middleware->handle(LaravelRequest::create('/orders', 'GET'), function () use ($sdk): LaravelResponse {
            $sdk->probe('checkout.tax', ['rate' => 0.2]);
            return new LaravelResponse('ok', 204);
        });
        $sdk->flush();

        $secondLaravelCalls = $transport->calls;
        self::assertCount(1, $secondLaravelCalls);
        /** @var non-empty-list<array<string, mixed>> $secondLaravelCalls */
        $secondLaravelCall = $secondLaravelCalls[0];
        self::assertSame(['request_event'], array_map(static fn (array $event): string => $event['event_type'], $secondLaravelCall['events']));
    }

    public function testSymfonySubscriberPrefersTriggerHeaderAndSilentlyRejectsExpiredQueryToken(): void
    {
        $clock = new ManualClock();
        $transport = new FakeTransport();
        $triggerTokenKey = 'trigger-key-123';
        $sdk = new DebugBundleSdk($transport, [$clock, 'time']);
        $this->sdk = $sdk;
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'checkout-api',
            'environment' => 'production',
            'configFetcher' => new FakeConfigFetcher([
                new FakeConfigResponse(200, [
                    'probes_enabled' => true,
                    'remote_probes_enabled' => true,
                    'active_probes' => [],
                    'poll_interval_ms' => 15000,
                    'trigger_token_key' => $triggerTokenKey,
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

        $subscriber = new DebugBundleEventSubscriber($sdk, [$clock, 'time']);
        $kernel = new class() implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse('ok');
            }
        };

        $request = SymfonyRequest::create(
            '/inventory?_debug_probe=' . rawurlencode($this->createTriggerToken($triggerTokenKey, [
                'activation_id' => '22222222-2222-4222-8222-222222222222',
                'label_pattern' => 'checkout.*',
                'service' => 'checkout-api',
                'environment' => 'production',
                'trigger_expires_at' => '2023-11-14T22:13:10.000Z',
            ])),
            'GET',
            [],
            [],
            [],
            [
                'HTTP_X_DEBUGBUNDLE_PROBE_TRIGGER' => $this->createTriggerToken($triggerTokenKey, [
                    'activation_id' => '33333333-3333-4333-8333-333333333333',
                    'label_pattern' => 'checkout.*',
                    'service' => 'checkout-api',
                    'environment' => 'production',
                    'trigger_expires_at' => '2023-11-14T22:13:30.000Z',
                ]),
            ]
        );

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $sdk->probe('checkout.tax', ['rate' => 0.2]);
        $clock->advance(0.02);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new SymfonyResponse('ok', 202)));
        $sdk->flush();

        self::assertSame(['probe_event', 'request_event'], array_map(static fn (array $event): string => $event['event_type'], $transport->calls[0]['events']));
        self::assertSame('33333333-3333-4333-8333-333333333333', $transport->calls[0]['events'][0]['payload']['activation_id']);

        $transport->calls = [];
        $expiredOnly = SymfonyRequest::create(
            '/inventory?_debug_probe=' . rawurlencode($this->createTriggerToken($triggerTokenKey, [
                'activation_id' => '44444444-4444-4444-8444-444444444444',
                'label_pattern' => 'checkout.*',
                'service' => 'checkout-api',
                'environment' => 'production',
                'trigger_expires_at' => '2023-11-14T22:13:10.000Z',
            ])),
            'GET'
        );
        $subscriber->onKernelRequest(new RequestEvent($kernel, $expiredOnly, HttpKernelInterface::MAIN_REQUEST));
        $sdk->probe('checkout.tax', ['rate' => 0.2]);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $expiredOnly, HttpKernelInterface::MAIN_REQUEST, new SymfonyResponse('ok', 204)));
        $sdk->flush();

        $secondSymfonyCalls = $transport->calls;
        self::assertCount(1, $secondSymfonyCalls);
        /** @var non-empty-list<array<string, mixed>> $secondSymfonyCalls */
        $secondSymfonyCall = $secondSymfonyCalls[0];
        self::assertSame(['request_event'], array_map(static fn (array $event): string => $event['event_type'], $secondSymfonyCall['events']));
    }

    public function testLaravelRelayMiddlewareHandlesBrowserRelayRoute(): void
    {
        $accepted = [];
        $middleware = new DebugBundleRelayMiddleware([
            'onAccept' => static function ($batch) use (&$accepted): void {
                $accepted[] = $batch;
            },
        ]);

        $request = LaravelRequest::create(
            '/debugbundle/browser',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://app.example.com',
                'HTTP_HOST' => 'app.example.com',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'batch' => [[
                    'schema_version' => '2026-03-01',
                    'event_id' => '00000000-0000-4000-8000-000000000304',
                    'event_type' => 'frontend_exception',
                    'occurred_at' => '2026-03-31T10:00:00Z',
                    'sdk_version' => '1.2.3',
                    'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                    'payload' => ['name' => 'TypeError', 'message' => 'broken'],
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        $response = $middleware->handle($request, static fn () => new LaravelResponse('next', 204));

        self::assertSame(202, $response->getStatusCode());
        self::assertCount(1, $accepted);
    }

    public function testSymfonyRelayControllerHandlesBrowserRelayRoute(): void
    {
        $accepted = [];
        $controller = new DebugBundleRelayController([
            'onAccept' => static function ($batch) use (&$accepted): void {
                $accepted[] = $batch;
            },
        ]);

        $request = SymfonyRequest::create(
            '/debugbundle/browser',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://app.example.com',
                'HTTP_HOST' => 'app.example.com',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'batch' => [[
                    'schema_version' => '2026-03-01',
                    'event_id' => '00000000-0000-4000-8000-000000000305',
                    'event_type' => 'frontend_exception',
                    'occurred_at' => '2026-03-31T10:00:00Z',
                    'sdk_version' => '1.2.3',
                    'service' => ['name' => 'checkout-web', 'environment' => 'production'],
                    'payload' => ['name' => 'TypeError', 'message' => 'broken'],
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        $response = $controller($request);

        self::assertSame(202, $response->getStatusCode());
        self::assertCount(1, $accepted);
    }

    private function captureLaravelProviderFactory(): \Closure
    {
        $factory = null;
        $app = $this->createMock(Application::class);
        $app->expects(self::once())
            ->method('singleton')
            ->willReturnCallback(static function (string $abstract, \Closure $closure) use (&$factory): void {
                self::assertSame(DebugBundleSdk::class, $abstract);
                $factory = $closure;
            });

        $provider = new DebugBundleServiceProvider($app);
        $provider->register();

        self::assertInstanceOf(\Closure::class, $factory);

        return $factory;
    }

    /** @return \ArrayAccess<string, object> */
    private function createLaravelContainerStub(mixed $configValue): \ArrayAccess
    {
        return new class($configValue) implements \ArrayAccess {
            private object $config;

            public function __construct(mixed $value)
            {
                $this->config = new class($value) {
                    public function __construct(private mixed $value)
                    {
                    }

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $key === 'debugbundle' ? $this->value : $default;
                    }
                };
            }

            public function offsetExists(mixed $offset): bool
            {
                return $offset === 'config';
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $offset === 'config' ? $this->config : null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };
    }

    private function readSdkBoolean(DebugBundleSdk $sdk, string $property): bool
    {
        $reflection = new \ReflectionClass($sdk);
        return (bool) $reflection->getProperty($property)->getValue($sdk);
    }

    private function readSdkString(DebugBundleSdk $sdk, string $property): string
    {
        $reflection = new \ReflectionClass($sdk);
        return (string) $reflection->getProperty($property)->getValue($sdk);
    }

    /** @param array<string, string> $payload */
    private function createTriggerToken(string $key, array $payload): string
    {
        $payloadSegment = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payloadSegment, $key, true);
        $signatureSegment = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return 'dbundle_probe_' . $payloadSegment . '.' . $signatureSegment;
    }
}