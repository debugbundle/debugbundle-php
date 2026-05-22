# DebugBundle PHP SDK

PHP SDK for DebugBundle.

![Packagist](https://img.shields.io/packagist/v/debugbundle/sdk-php?label=packagist)
![CI](https://img.shields.io/github/actions/workflow/status/debugbundle/debugbundle-php/ci.yml?branch=main&label=ci)
![License](https://img.shields.io/badge/license-AGPL--3.0--only-blue)

Use this package to capture PHP backend exceptions, request metadata, Monolog records, runtime context, and probe data. It supports vanilla PHP plus Laravel, Symfony, Monolog, and browser relay adapters.

Requires PHP 8.2 or newer.

## Installation

```bash
composer require debugbundle/sdk-php
```

## Quick Start

```php
<?php

use DebugBundle\DebugBundle;

DebugBundle::init([
    'projectToken' => getenv('DEBUGBUNDLE_PROJECT_TOKEN'),
    'service' => 'checkout-api',
    'environment' => 'production',
]);

DebugBundle::captureErrors();
DebugBundle::captureExceptions();
DebugBundle::captureShutdown();
```

Capture handled errors, logs, messages, and probes explicitly:

```php
DebugBundle::captureException($throwable);
DebugBundle::captureLog('payment retry failed', 'warning', ['order_id' => $orderId]);
DebugBundle::captureMessage('worker started');
DebugBundle::probe('checkout.cart', ['item_count' => count($cart->items)]);

DebugBundle::flush();
```

## Framework Integrations

| Runtime | Integration |
| --- | --- |
| Laravel | Service provider, request middleware, exception handler decoration, and log tap |
| Symfony | Bundle/subscriber integration and Monolog service handler |
| Monolog | `DebugBundle\Logging\DebugBundleHandler` |
| Vanilla PHP | `captureErrors()`, `captureExceptions()`, and `captureShutdown()` |

Example apps live in `examples/laravel` and `examples/symfony`.

## Browser Relay

PHP backends can host the browser relay endpoint used by `@debugbundle/sdk-browser`.

| Runtime | Integration |
| --- | --- |
| Laravel | `DebugBundle\Framework\Laravel\DebugBundleRelayMiddleware` |
| Symfony | `DebugBundle\Framework\Symfony\DebugBundleRelayController` |
| Generic PHP | `DebugBundle\Relay\BrowserRelayHandler` |

The relay validates JSON batches, enforces same-origin or allowed origins, strips trust-sensitive browser fields, keeps the server-side project token private, and supports local-only file writes or durable connected forwarding.

## Configuration

| Option | Default | Purpose |
| --- | --- | --- |
| `projectToken` | required | Write-only DebugBundle project token. |
| `service` | `php-service` | Service name shown on incidents and bundles. |
| `environment` | `development` | Runtime environment such as `production`, `staging`, or `development`. |
| `endpoint` | `https://api.debugbundle.com/v1/events` | Ingestion endpoint for connected mode or self-hosting. |
| `enabled` | `true` | Disable all capture without removing instrumentation. |
| `logger` | none | Optional Monolog logger to attach. |
| `logLevel` | `warning` | Minimum captured log severity. |
| `sampleRate` | `1.0` | Fraction of events to keep before transport. |
| `batchSize` | `25` | Events per batch before flushing. |
| `redactFields` | common sensitive fields | Additional field names to redact. |
| `maxProbeLabels` | `50` | Maximum distinct probe labels buffered in memory. |
| `maxProbeEntriesPerLabel` | `10` | Maximum entries retained per probe label. |
| `probeFlushOnError` | `true` | Attach buffered probe data to captured exceptions. |
| `probesPollInterval` | `60000` | Remote probe config poll interval in milliseconds. |
| `configFetcher` | none | Custom remote-config fetch callable for tests or advanced routing. |

## Laravel Logging

For Laravel's Monolog-backed stack, add the SDK tap class to a channel in `config/logging.php`:

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

## Symfony Logging

For Symfony applications using MonologBundle, register the SDK handler as a Monolog service:

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

## Safety Defaults

- SDK failures are caught internally and do not crash the host process.
- Sensitive fields are redacted before transport.
- Duplicate event storms are suppressed locally.
- Runtime context excludes environment variables.
- Browser relay requests cannot smuggle server-side credentials.

## Development

```bash
composer install
composer test
composer typecheck
```

CI validates Composer metadata, PHPUnit, PHPStan, event schema fixtures, real HTTP transport coverage, and coverage gates.

## Documentation

- PHP SDK docs: <https://debugbundle.com/docs/sdks/php>
- SDK overview: <https://debugbundle.com/docs/sdks>
- Browser relay: <https://debugbundle.com/docs/sdks/browser-relay>
- Repository: <https://github.com/debugbundle/debugbundle-php>

## License

AGPL-3.0-only. See `LICENSE`.
