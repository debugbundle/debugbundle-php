# DebugBundle PHP SDK

DebugBundle SDK for PHP.

Current scaffold coverage includes the universal static SDK facade, vanilla PHP error/exception/shutdown hooks, request-scoped batching, redaction, duplicate suppression, always-on probe buffering, Monolog log capture, Laravel middleware plus service-provider scaffolding, Symfony subscriber plus bundle scaffolding, request-local browser/backend correlation propagation from incoming trace and request-id headers, the first remote-config / capture-policy control plane for paid-tier probe activation, request-scoped trigger-token probe activation from query/header inputs, vendored machine-readable schema validation for emitted event envelopes, a standalone GitHub Actions CI workflow that validates Composer metadata, PHPUnit, and PHPStan across the supported PHP runtime range, an enforced per-file coverage gate with focused facade, suppression, framework-adapter, and real HTTP transport coverage, runnable Laravel/Symfony example apps that emit incidents through the real SDK transport path, and a browser relay foundation with same-origin validation plus Laravel/Symfony relay adapters.

## Installation

Requires PHP 8.4 or newer.

```bash
composer require debugbundle/sdk-php
```

## Quick Start

```php
<?php

use DebugBundle\DebugBundle;

DebugBundle::init([
    'projectToken' => $_ENV['DEBUGBUNDLE_TOKEN'],
    'service' => 'checkout-api',
    'environment' => 'production',
    'logger' => $logger,
]);
```

## Examples

- `examples/laravel` demonstrates the Laravel-oriented service-provider and middleware path.
- `examples/symfony` demonstrates the Symfony event-subscriber path.

Each example exposes `/`, `/log`, and `/exception` routes and can be run with PHP's built-in server while pointing `DEBUGBUNDLE_ENDPOINT` at a local ingest stub or the DebugBundle API.

## Laravel Exception Handler

The SDK now includes `DebugBundle\Framework\Laravel\DebugBundleExceptionHandler`, a thin decorator for Laravel's existing exception handler binding.

```php
use DebugBundle\Framework\Laravel\DebugBundleExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;

$app->extend(ExceptionHandler::class, function (ExceptionHandler $handler) use ($sdk) {
    return new DebugBundleExceptionHandler($sdk, $handler);
});
```

When the service provider runs in an application that already binds Laravel's exception handler contract, it decorates that binding automatically. This captures reportable exceptions outside the middleware path while skipping repeat capture of the same throwable.

## Framework Logging

### Laravel

For Laravel's Monolog-backed logging stack, add the SDK tap class to the channel configuration in `config/logging.php`:

```php
use DebugBundle\Framework\Laravel\DebugBundleLogTap;

'channels' => [
        'stack' => [
                'driver' => 'stack',
                'channels' => ['single'],
                'tap' => [DebugBundleLogTap::class],
        ],
],
```

### Symfony

For Symfony applications using MonologBundle, register the existing SDK handler as a Monolog service in `monolog.yaml`:

```yaml
services:
    DebugBundle\Logging\DebugBundleHandler:
        arguments:
            $sdk: '@DebugBundle\DebugBundleSdk'

monolog:
    handlers:
        debugbundle:
            type: service
            id: DebugBundle\Logging\DebugBundleHandler
            level: warning
```

## Framework Correlation

The Laravel middleware and Symfony subscriber bind request-local correlation metadata from incoming headers and attach it automatically to request, log, and exception events emitted during that request.

- `X-DebugBundle-Trace-Id` populates `correlation.trace_id`
- `X-Request-Id` populates `correlation.request_id`
- `X-Correlation-Id` is used as the request-id fallback when `X-Request-Id` is absent

When no incoming trace header is present, the SDK leaves the correlation fields unset and continues normally.

## Trigger Tokens

When the config endpoint returns a `trigger_token_key`, the Laravel middleware and Symfony subscriber will validate one-request probe trigger tokens from either request surface:

- query parameter `_debug_probe=dbundle_probe_...`
- header `X-DebugBundle-Probe-Trigger: dbundle_probe_...`

Header values take precedence when both are present. Invalid or expired tokens are ignored silently, and activated probes are cleared after the current request.

## Browser Relay

The SDK now includes a framework-agnostic `DebugBundle\Relay\BrowserRelayHandler` for implementing the contract-required `POST /debugbundle/browser` endpoint in PHP servers.

- `DebugBundle\Framework\Laravel\DebugBundleRelayMiddleware` handles the relay route in Laravel-style middleware stacks.
- `DebugBundle\Framework\Symfony\DebugBundleRelayController` handles the relay route in Symfony applications.

The relay foundation enforces `application/json`, rejects oversized bodies, applies per-IP rate limiting, validates same-origin or configured allowed origins, accepts only supported browser event types, strips trust-sensitive request headers, removes client-supplied trust fields, forces `sdk_name` to `@debugbundle/sdk-browser`, and preserves `correlation.trace_id` when present.

## Docs

https://debugbundle.com/docs/sdks/php

## License

AGPL-3.0-only