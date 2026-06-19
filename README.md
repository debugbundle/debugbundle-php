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

The relay validates JSON batches, enforces same-origin defaults or explicit allowed origins, strips browser-supplied trust fields, keeps the server-side project token private, and supports local-only file writes or durable connected forwarding.

Relay behavior and defaults:

- Same-origin is the default when `allowedOrigins` is omitted; split frontend/backend deployments must set an explicit allowlist.
- Relay requests must use `Content-Type: application/json` and stay below the default `256 KB` body limit.
- Per-IP rate limiting defaults to `60` requests per minute.
- `projectMode="local-only"` writes browser events to `.debugbundle/local/events` and never forwards them.
- `projectMode="connected"` writes durable spool files by default and forwards with the server-side `projectToken` only.
- Leaving `projectMode` unset disables local writes and forwarding; accepted batches only surface through `onAccept`.
- Connected relay mode without a usable project token still accepts and can spool events, but forwarding remains disabled until the server provides credentials. This is the relay missing token behavior.

## Configuration Reference

Configuration sources and precedence:

- The SDK only reads the array passed to `DebugBundle::init([...])` or `DebugBundleSdk::init([...])`.
- Environment variables, Laravel config files, Symfony service definitions, or other framework-native settings are convenience sources that your application maps into that init array; the SDK does not read them directly.
- Explicit init arguments always win because they are the only configuration source the runtime consumes.
- Capture-policy fields are server-owned and are not accepted in local SDK config. The SDK learns capture policy through `GET /v1/sdk/config` and applies it locally before transport.

| Option | Default | Purpose |
| --- | --- | --- |
| `projectToken` | required for connected capture | Write-only DebugBundle project token. Blank or missing tokens disable connected capture and leave the SDK status at `disconnected`. |
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

Framework-native wiring:

- Laravel: initialize the SDK through your service provider or config bootstrap, then register `DebugBundleMiddleware`, `DebugBundleExceptionHandler`, and `DebugBundleLogTap` where those surfaces apply.
- Symfony: initialize the SDK in your container, then register `DebugBundleEventSubscriber`, `DebugBundleRelayController`, and the Monolog service handler.
- Generic PHP: initialize with `DebugBundle::init([...])`, then opt into `captureErrors()`, `captureExceptions()`, and `captureShutdown()`.

## Install Examples by Mode

Vanilla PHP:

```php
<?php

use DebugBundle\DebugBundle;

DebugBundle::init([
    'projectToken' => getenv('DEBUGBUNDLE_PROJECT_TOKEN'),
    'service' => 'worker',
    'environment' => 'production',
]);

DebugBundle::captureErrors();
DebugBundle::captureExceptions();
DebugBundle::captureShutdown();
```

Laravel:

```php
<?php

use DebugBundle\DebugBundle;
use DebugBundle\Framework\Laravel\DebugBundleLogTap;

DebugBundle::init([
    'projectToken' => env('DEBUGBUNDLE_PROJECT_TOKEN'),
    'service' => 'checkout-api',
    'environment' => 'production',
]);

// config/logging.php
'tap' => [DebugBundleLogTap::class];
```

Symfony:

```yaml
services:
  DebugBundle\DebugBundleSdk:
    calls:
      - method: init
        arguments:
          -
            projectToken: '%env(DEBUGBUNDLE_PROJECT_TOKEN)%'
            service: 'checkout-api'
            environment: 'production'
```

Monolog logger integration:

```php
<?php

use DebugBundle\DebugBundleSdk;
use Monolog\Logger;

$sdk = new DebugBundleSdk();
$sdk->init([
    'projectToken' => getenv('DEBUGBUNDLE_PROJECT_TOKEN'),
    'service' => 'checkout-api',
    'environment' => 'production',
    'logger' => new Logger('checkout'),
]);
```

Connected browser relay:

```php
<?php

use DebugBundle\Framework\Symfony\DebugBundleRelayController;

$relay = new DebugBundleRelayController([
    'allowedOrigins' => ['https://app.example.com'],
    'projectMode' => 'connected',
    'projectToken' => getenv('DEBUGBUNDLE_PROJECT_TOKEN'),
    'endpoint' => 'https://api.debugbundle.com/v1/events',
]);
```

Local-only browser relay:

```php
<?php

use DebugBundle\Relay\BrowserRelayHandler;

$relay = new BrowserRelayHandler([
    'allowedOrigins' => ['http://localhost:3000'],
    'projectMode' => 'local-only',
    'localEventsDir' => '.debugbundle/local/events',
]);
```

There is no separate zero-install fallback for the PHP SDK itself in V1. The nearest low-friction path is the browser relay mounted on an existing PHP application.

## Runtime and Framework Support

| Surface | Minimum compatibility version | Recommended production version | Installed-base compatibility lane | Rolling CI lane | Out of scope |
| --- | --- | --- | --- | --- | --- |
| PHP runtime | 8.2 | 8.4 | 8.2 and 8.3 remain supported for installed-base coverage | 8.2, 8.3, 8.4 | 8.1 and older |
| Laravel | 11.x | latest 11.x patch | 11.x compatibility support | repo tests cover the Illuminate 11.x adapter lane | Laravel 10.x and older |
| Symfony HttpFoundation / HttpKernel | 7.2+ | latest 7.2+ patch line | 7.2+ compatibility support | repo tests cover the Symfony 7.2+ adapter lane | Symfony 6.x and older |
| Monolog | 3.x | latest 3.x patch | 3.x compatibility support | package dependency and tests install Monolog 3.x | Monolog 2.x and older |

Post-V1 planned expansions from `spec/sdk-language-targets.md` remain out of scope here: Slim, Laminas, native async runtimes, WordPress plugin behavior beyond the dedicated plugin repo, and queue-worker-specific adapters outside the shared PHP core.

## Dependency Alignment

`debugbundle/sdk-php` ships as one package in V1, so there is no multi-package BOM or Composer plugin family to align.

- Pin one `debugbundle/sdk-php` version across your web and worker repos when you want identical SDK behavior everywhere.
- Keep Monolog inside the supported `3.x` lane.
- Keep Laravel inside the supported `11.x` lane and Symfony HttpFoundation / HttpKernel inside the supported `7.2+` lane when you rely on the shipped framework adapters.
- Public snippets should not mix SDK package versions across separate repos or examples.

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
- Browser relay requests cannot smuggle server-side credentials, and the relay enforces credential isolation by forwarding with only the server-owned project token.

## Service Naming

- Use one stable backend service name per deployable, such as `checkout-api`, `billing-worker`, or `admin-api`.
- Keep browser relay traffic on the browser-owned service name by default, for example `checkout-web`; the PHP relay preserves the browser service unless you explicitly override `service` or `environment` in relay options.
- When multiple PHP deployables share one DebugBundle project, give each deployable its own `service` value instead of reusing one generic name.
- Reuse the same environment label across related surfaces, for example `production` on both `checkout-web` and `checkout-api`, so incident and bundle correlation stays readable.

## Safe Startup and Status

- The SDK never crashes the host process when configuration is invalid, transport calls fail, or remote config responses are malformed.
- `DebugBundle::init(['projectToken' => ''])` or any missing or blank connected token leaves capture disabled and `DebugBundle::getStatus()` returns `disconnected`.
- Rate-limited transports move the status to `degraded` until the retry window expires.
- Three consecutive transport failures move the status to `disconnected` until a later successful flush.
- `DebugBundle::getLastEventAt()` returns the Unix timestamp in milliseconds of the last successful delivery, or `null` before the first success.
- Connected mode without a usable project token does not silently report healthy connected capture.

## First-Event Verification

Use the repo-local smoke target to prove a fresh install end to end against a mock ingestion endpoint:

```bash
make smoke
```

That command builds a Composer archive, installs the package into a fresh consumer fixture, emits an application-owned `captureMessage()` plus `captureRequest()` event, submits a browser relay request through the Symfony relay controller, validates the received event envelopes, and confirms both paths reach the mock ingestion endpoint with the expected `service`, `environment`, SDK metadata, and correlation fields.

For published-package verification during release validation, run the same smoke path against Packagist:

```bash
php smoke/run_app_driven_smoke.php --package debugbundle/sdk-php:1.1.1
```

For a manual verification snippet inside your own app:

```php
<?php

use DebugBundle\DebugBundle;

DebugBundle::init([
  'projectToken' => getenv('DEBUGBUNDLE_PROJECT_TOKEN'),
  'service' => 'checkout-api',
  'environment' => 'staging',
]);

DebugBundle::captureMessage('debugbundle first-event verification', 'error');
DebugBundle::flush();
var_dump(DebugBundle::getStatus(), DebugBundle::getLastEventAt());
```

## Development

```bash
composer install
composer test
composer typecheck
make smoke
```

CI validates Composer metadata, PHPUnit, PHPStan, the app-driven smoke path, event schema fixtures, real HTTP transport coverage, and coverage gates.

## Documentation

- PHP SDK docs: <https://debugbundle.com/docs/sdks/php>
- SDK overview: <https://debugbundle.com/docs/sdks>
- Browser relay: <https://debugbundle.com/docs/sdks/browser-relay>
- Repository: <https://github.com/debugbundle/debugbundle-php>

## License

AGPL-3.0-only. See `LICENSE`.
