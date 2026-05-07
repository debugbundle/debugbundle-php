<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Laravel;

use DebugBundle\DebugBundleSdk;
use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandlerContract;
use Illuminate\Support\ServiceProvider;

final class DebugBundleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DebugBundleSdk::class, function ($app): DebugBundleSdk {
            $sdk = new DebugBundleSdk();
            $config = $app['config']->get('debugbundle', []);
            if (is_array($config)) {
                $sdk->init($config);
            }

            return $sdk;
        });

        if ($this->app->bound(LaravelExceptionHandlerContract::class)) {
            $this->app->extend(LaravelExceptionHandlerContract::class, function (LaravelExceptionHandlerContract $handler): DebugBundleExceptionHandler {
                return new DebugBundleExceptionHandler($this->app->make(DebugBundleSdk::class), $handler);
            });
        }
    }
}