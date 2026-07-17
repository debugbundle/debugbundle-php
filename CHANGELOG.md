# Changelog

## [Unreleased]

## [1.2.0] - 2026-07-17

### Added
- Corrected the semantic release line for browser-relay analytics support. Relay handlers accept credential-free `analytics_event` envelopes while preserving only the required analytics correlation fields and stripping browser-supplied credentials.

## [1.1.3] - 2026-07-16

### Added
- Accepted canonical browser `analytics_event` envelopes through the framework relay, preserving the same validation, origin controls, rate limits, and durable delivery behavior as existing browser events.

### Fixed
- Suppressed expected filesystem-race warnings in the relay spool while retaining explicit failure handling when secure spool writes cannot be created.

## [1.1.2] - 2026-06-19

### Fixed
- Release quality gates so the published PHP SDK patch ships with clean static-analysis metadata and unchanged runtime behavior.

## [1.1.1] - 2026-06-19

### Fixed
- Normalized canonical event-envelope emission so custom app context now stays in envelope `context`, request events avoid legacy payload extras, and installed projects stop tripping malformed ingestion rejects after upgrade.
- Aligned emitted PHP SDK event metadata so `sdk_version` now reports `1.1.1`.

## [1.1.0] - 2026-06-08

### Added
- Added path-scoped immediate client-error incident promotion support in remote capture-policy parsing so explicitly configured `4xx` routes can emit standalone `request_event` incident signals without widening the status globally.

### Changed
- Unpromoted client-error request telemetry now remains context-only under repeated traffic, while `5xx` handling and explicitly promoted client-error behavior are preserved.
- Aligned emitted PHP SDK event metadata so `sdk_version` now reports `1.1.0`.

## [1.0.0] - 2026-05-31

### Changed
- Declared the PHP SDK stable at `1.0.0` after release-hardening the public package, framework adapters, browser relay surfaces, and Packagist smoke coverage.
- Aligned emitted PHP SDK event metadata so `sdk_version` now reports `1.0.0`.

## [0.1.10] - 2026-05-29

### Fixed
- Added `OPTIONS /debugbundle/browser` preflight handling and matching CORS headers for explicitly allowed split-host browser relay traffic across the framework-agnostic, Laravel, and Symfony relay surfaces.

### Added
- Added a repo-local `make smoke` app-driven release gate that builds a Composer archive, installs the package into a fresh consumer fixture, drives server capture plus Symfony relay forwarding against a mock ingestion endpoint, and is now reused by CI and the published-package release smoke.

### Changed
- Expanded the README to cover the shared SDK release documentation gates: configuration precedence, mode-specific install examples, support labels, dependency alignment, relay behavior, service naming, safe startup/status semantics, and first-event verification.
- Aligned emitted PHP SDK event metadata to the current package release so `sdk_version` now reports `0.1.9`.

## [0.1.7] - 2026-05-19

### Added
- Full browser relay delivery parity, including local-only event-file writes, connected durable spool writes, connected cloud forwarding with server-side project credentials, shared relay compliance fixtures, and an injectable `BrowserRelayRateLimitStore` for shared-nothing runtimes.

## [0.1.6] - 2026-05-19

### Added
- Remote capture-policy parsing now honors `immediate_client_error_statuses` so configured `4xx` responses are emitted as immediate `request_event` incident signals even when generic request capture is disabled.

### Changed
- Lowered the declared PHP support floor for the SDK package, documentation, and GitHub Actions validation to PHP 8.2 while keeping the published dependency graph aligned with the supported runtime range.

## [0.1.3] - 2026-05-12

### Added
- Safe backend runtime process facts on `backend_exception.payload.runtime`, including platform, architecture, pid, cwd, uptime, hostname, and best-effort memory metadata without reading environment variables.

## [0.1.2] - 2026-05-11

### Changed
- Aligned PHP SDK capture-policy fallback defaults with the service presets so minimal and balanced modes capture 5xx request failures by default.

### Fixed
- Preserved 5xx request-event capture even when standalone request capture is otherwise disabled.
- PHP browser relay validation now accepts browser-originated `request_event` payloads for promoted 5xx request failures.

## [0.1.0] - 2026-05-07

- Completed the PHP SDK pre-ship review and fixed self-hosted control-plane drift so remote-config refreshes now derive `/sdk/config` from the configured ingestion endpoint instead of hardcoding the hosted API base.
- Added a Laravel-native Monolog tap helper so PHP SDK users can attach the SDK log handler through `config/logging.php` without replacing existing channel handlers, and documented the corresponding Symfony `monolog.yaml` service registration path.
- Added a thin Laravel exception-handler decorator for the PHP SDK so applications can wrap the existing Laravel exception handler binding and capture reportable exceptions outside the middleware path without double-reporting the same throwable.
- Raised the declared PHP support floor for the PHP SDK package and CI workflow to PHP 8.4 so Composer metadata, GitHub Actions validation, and the shipped dependency graph now agree on the minimum supported runtime.
- Added request-local framework correlation binding for the PHP SDK so Laravel middleware and the Symfony subscriber now read `X-DebugBundle-Trace-Id` plus `X-Request-Id`/`X-Correlation-Id` fallback headers at request entry and attach the resulting `trace_id` / `request_id` metadata to request, log, and exception events emitted during that request.
- Added the initial PHP SDK scaffold with the universal static facade, request-scoped batching, retry/backoff handling, redaction, duplicate suppression, always-on probe buffering, and vanilla PHP hook capture.
- Added Monolog-backed in-process log capture with SDK-owned handler attachment, warning-level filtering, and reset-time cleanup.
- Added the first Laravel and Symfony framework adapters: Laravel middleware plus service-provider scaffolding, and Symfony request/exception subscriber plus bundle scaffolding.
- Added remote config parsing and init-time config refresh with ETag reuse, minimal-policy fallback, capture-policy enforcement for log and request events, and remote heavy-probe activation for the standalone PHP SDK.
- Added vendored JSON Schema validation coverage for emitted PHP SDK events, plus the contract-shape fixes required for inline probe payloads, standalone probe events, and empty map serialization.
- Added a standalone GitHub Actions CI workflow for the PHP SDK that validates Composer package metadata and runs PHPUnit plus PHPStan on PHP 8.1, 8.2, and 8.3.
- Added an enforced per-file coverage gate for `src/` with a checked-in Clover coverage checker, CI coverage job, real HTTP transport coverage, and focused tests for the static facade, suppression logic, and framework adapter edge paths.
- Added runnable Laravel and Symfony example apps, including built-in-server entrypoints and integration tests that verify both examples emit request, log, and exception events through the real HTTP transport path.
- Added the initial browser relay handler for the PHP SDK, including a framework-agnostic relay handler, Laravel middleware and Symfony controller adapters, and contract coverage for origin validation, JSON-only relay requests, accepted browser event filtering, body limits, and per-IP rate limiting.
- Added request-scoped trigger-token probe activation for the PHP SDK, including remote-config `trigger_token_key` parsing, HMAC-validated `_debug_probe` and `X-DebugBundle-Probe-Trigger` handling, and Laravel/Symfony coverage for header precedence, expiry rejection, and per-request reset.
