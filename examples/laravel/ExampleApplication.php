<?php

declare(strict_types=1);

namespace DebugBundle\Examples\Laravel;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\Laravel\DebugBundleMiddleware;
use DebugBundle\Framework\Laravel\DebugBundleServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ServiceProvider;

final class ExampleApplication
{
    private DebugBundleMiddleware $middleware;
    private DebugBundleSdk $sdk;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $container = new ExampleLaravelApplication($config);
        $provider = new DebugBundleServiceProvider($container);
        $provider->register();

        $this->sdk = $container->make(DebugBundleSdk::class);
        $this->middleware = new DebugBundleMiddleware($this->sdk);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->middleware->handle($request, fn (Request $incomingRequest): Response => $this->dispatch($incomingRequest));
        } catch (\Throwable) {
            $response = new Response(
                json_encode(['error' => 'laravel example failure'], JSON_THROW_ON_ERROR),
                500,
                ['Content-Type' => 'application/json']
            );
        }

        $this->sdk->flush();

        return $response;
    }

    public function reset(): void
    {
        $this->sdk->reset();
    }

    private function dispatch(Request $request): Response
    {
        return match ($request->getPathInfo()) {
            '/log' => $this->logResponse(),
            '/exception' => throw new \RuntimeException('laravel example failure'),
            default => new Response(
                json_encode(['ok' => true, 'framework' => 'laravel'], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json']
            ),
        };
    }

    private function logResponse(): Response
    {
        $this->sdk->captureLog('laravel example log', 'error', ['framework' => 'laravel']);

        return new Response(
            json_encode(['ok' => true, 'logged' => true], JSON_THROW_ON_ERROR),
            202,
            ['Content-Type' => 'application/json']
        );
    }
}

final class ExampleLaravelApplication extends Container implements Application
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->instance('config', new ExampleConfigRepository($config));
    }

    public function version(): string
    {
        return '11.x-example';
    }

    public function basePath($path = ''): string
    {
        return $this->pathFromBase($path);
    }

    public function bootstrapPath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'bootstrap' : 'bootstrap/' . $path);
    }

    public function configPath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'config' : 'config/' . $path);
    }

    public function databasePath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'database' : 'database/' . $path);
    }

    public function langPath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'lang' : 'lang/' . $path);
    }

    public function publicPath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'public' : 'public/' . $path);
    }

    public function resourcePath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'resources' : 'resources/' . $path);
    }

    public function storagePath($path = ''): string
    {
        return $this->pathFromBase($path === '' ? 'storage' : 'storage/' . $path);
    }

    /** @param string|array<int, string> ...$environments */
    public function environment(...$environments): string|bool
    {
        $current = 'development';
        if ($environments === []) {
            return $current;
        }

        return in_array($current, $environments, true);
    }

    public function runningInConsole(): bool
    {
        return false;
    }

    public function runningUnitTests(): bool
    {
        return true;
    }

    public function hasDebugModeEnabled(): bool
    {
        return true;
    }

    public function maintenanceMode(): MaintenanceMode
    {
        return new class() implements MaintenanceMode {
            /** @param array<string, mixed> $payload */
            public function activate(array $payload): void
            {
            }

            public function deactivate(): void
            {
            }

            public function active(): bool
            {
                return false;
            }

            /** @return array<string, mixed> */
            public function data(): array
            {
                return [];
            }
        };
    }

    public function isDownForMaintenance(): bool
    {
        return false;
    }

    public function registerConfiguredProviders(): void
    {
    }

    public function register($provider, $force = false): ServiceProvider
    {
        if (is_string($provider)) {
            $provider = new $provider($this);
        }

        if (!$provider instanceof ServiceProvider) {
            throw new \RuntimeException('Expected a Laravel service provider instance.');
        }

        $provider->register();

        return $provider;
    }

    public function registerDeferredProvider($provider, $service = null): void
    {
    }

    public function resolveProvider($provider): ServiceProvider
    {
        $resolved = new $provider($this);
        if (!$resolved instanceof ServiceProvider) {
            throw new \RuntimeException('Expected a Laravel service provider instance.');
        }

        return $resolved;
    }

    public function boot(): void
    {
    }

    public function booting($callback): void
    {
    }

    public function booted($callback): void
    {
    }

    /** @param array<int, object|string> $bootstrappers */
    public function bootstrapWith(array $bootstrappers): void
    {
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function getNamespace(): string
    {
        return 'DebugBundle\\Examples\\Laravel\\';
    }

    /** @return array<int, ServiceProvider> */
    public function getProviders($provider): array
    {
        return [];
    }

    public function hasBeenBootstrapped(): bool
    {
        return true;
    }

    public function loadDeferredProviders(): void
    {
    }

    public function setLocale($locale): void
    {
    }

    public function shouldSkipMiddleware(): bool
    {
        return false;
    }

    public function terminating($callback): Application
    {
        return $this;
    }

    public function terminate(): void
    {
    }

    private function pathFromBase(string $path): string
    {
        $base = dirname(__DIR__);
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

final class ExampleConfigRepository
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $key === 'debugbundle' ? $this->config : $default;
    }
}