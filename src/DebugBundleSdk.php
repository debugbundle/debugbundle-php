<?php

declare(strict_types=1);

namespace DebugBundle;

use DebugBundle\Logging\DebugBundleHandler;
use DebugBundle\Transport\HttpTransport;
use DebugBundle\Transport\TransportInterface;
use Monolog\Logger;

final class DebugBundleSdk
{
    private const SDK_NAME = 'debugbundle/sdk-php';
    private const SDK_VERSION = '0.1.10';
    private const SCHEMA_VERSION = '2026-03-01';
    private const DEFAULT_ENDPOINT = 'https://api.debugbundle.com/v1/events';
    private const DEFAULT_BATCH_SIZE = 25;
    private const DEFAULT_LOG_LEVEL = 'warning';
    private const LEVEL_RANKS = [
        'debug' => 10,
        'info' => 20,
        'warning' => 30,
        'error' => 40,
        'critical' => 50,
    ];
    private const BALANCED_IMMEDIATE_REQUEST_STATUSES = [408, 423, 424, 425, 429];
    private const INVESTIGATIVE_IMMEDIATE_REQUEST_STATUSES = [408, 423, 424, 425, 429, 409];
    private const BALANCED_STANDARD_ANOMALY_STATUSES = [401, 403, 404, 409, 422];
    private const BALANCED_HIGH_VOLUME_ANOMALY_STATUSES = [400, 410];
    private const INVESTIGATIVE_ANOMALY_STATUSES = [401, 403, 404, 409, 422, 400, 410];

    private ?TransportInterface $transportOverride;
    private ?TransportInterface $transport = null;
    private bool $enabled = false;
    private string $projectToken = '';
    private string $service = 'php-service';
    private string $environment = 'development';
    private string $endpoint = self::DEFAULT_ENDPOINT;
    private int $batchSize = self::DEFAULT_BATCH_SIZE;
    private string $logLevel = self::DEFAULT_LOG_LEVEL;
    private float $sampleRate = 1.0;
    private bool $probeFlushOnError = true;
    private float $retryAfter = 0.0;
    private ?float $lastEventAt = null;
    private int $consecutiveFailures = 0;
    private int $maxProbeLabels = 50;
    private int $maxProbeEntriesPerLabel = 10;
    private bool $errorsHooked = false;
    private bool $exceptionsHooked = false;
    private bool $shutdownHooked = false;
    private bool $probesEnabled = true;
    private int $processStartedAtNs;

    /** @var \Closure(): float */
    private \Closure $timeProvider;

    private ?\Closure $configFetcher = null;
    private int $configuredProbesPollIntervalMs = RemoteConfig::DEFAULT_PROBES_POLL_INTERVAL_MS;
    private ?string $remoteConfigEtag = null;
    private ?RemoteConfigSnapshot $remoteConfigSnapshot = null;
    private CapturePolicy $capturePolicy;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, bool> */
    private array $redactFields = [];

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $probeBuffers = [];

    /** @var list<RemoteProbeDirective> */
    private array $requestTriggerDirectives = [];

    /** @var array<string, string> */
    private array $requestCorrelation = [];

    /** @var list<array{logger: Logger, handler: DebugBundleHandler}> */
    private array $loggerBindings = [];

    /** @var \WeakMap<\Throwable, bool> */
    private \WeakMap $capturedExceptions;

    private Suppression $suppression;

    public function __construct(?TransportInterface $transport = null, ?callable $timeProvider = null)
    {
        $this->transportOverride = $transport;
        $this->timeProvider = $timeProvider instanceof \Closure
            ? $timeProvider
            : \Closure::fromCallable($timeProvider ?? static fn (): float => microtime(true));
        $this->suppression = new Suppression();
        $this->capturedExceptions = new \WeakMap();
        $this->redactFields = $this->buildRedactFields(null);
        $this->capturePolicy = RemoteConfig::balancedCapturePolicy();
        $this->processStartedAtNs = hrtime(true);
    }

    /** @param array<string, mixed> $config */
    public function init(array $config): void
    {
        $this->reset();

        $this->projectToken = trim((string) ($config['projectToken'] ?? ''));
        $this->service = (string) ($config['service'] ?? 'php-service');
        $this->environment = (string) ($config['environment'] ?? 'development');
        $this->enabled = (bool) ($config['enabled'] ?? true) && $this->projectToken !== '';
        $this->endpoint = (string) ($config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $this->batchSize = max(1, (int) ($config['batchSize'] ?? self::DEFAULT_BATCH_SIZE));
        $this->logLevel = $this->normalizeLevel((string) ($config['logLevel'] ?? self::DEFAULT_LOG_LEVEL));
        $this->sampleRate = min(max((float) ($config['sampleRate'] ?? 1.0), 0.0), 1.0);
        $this->probeFlushOnError = (bool) ($config['probeFlushOnError'] ?? true);
        $this->maxProbeLabels = max(1, (int) ($config['maxProbeLabels'] ?? 50));
        $this->maxProbeEntriesPerLabel = max(1, (int) ($config['maxProbeEntriesPerLabel'] ?? 10));
        $this->redactFields = $this->buildRedactFields($config['redactFields'] ?? null);
        $this->buffer = [];
        $this->context = [];
        $this->probeBuffers = [];
        $this->requestTriggerDirectives = [];
        $this->requestCorrelation = [];
        $this->retryAfter = 0.0;
        $this->lastEventAt = null;
        $this->consecutiveFailures = 0;
        $this->suppression = new Suppression();
        $this->capturedExceptions = new \WeakMap();
        $this->transport = $this->transportOverride;
        $this->probesEnabled = true;
        $this->configFetcher = isset($config['configFetcher']) && is_callable($config['configFetcher'])
            ? \Closure::fromCallable($config['configFetcher'])
            : null;
        $this->configuredProbesPollIntervalMs = max(1, (int) ($config['probesPollInterval'] ?? RemoteConfig::DEFAULT_PROBES_POLL_INTERVAL_MS));
        $this->remoteConfigEtag = null;
        $this->remoteConfigSnapshot = null;
        $this->capturePolicy = RemoteConfig::balancedCapturePolicy();

        if ($this->transport === null && $this->enabled) {
            $this->transport = new HttpTransport($this->endpoint);
        }

        $this->attachLoggerIntegration($config['logger'] ?? null);

        if ($this->enabled && $this->configFetcher !== null) {
            $this->refreshRemoteConfig(true);
        }

        $this->captureErrors();
        $this->captureExceptions();
        $this->captureShutdown();
    }

    /** @param array<string, mixed>|null $context */
    public function captureException(\Throwable $error, ?array $context = null): void
    {
        $this->captureExceptionInternal($error, $context, true);
    }

    /** @param array<string, mixed>|null $context */
    public function captureError(\Throwable $error, ?array $context = null): void
    {
        $this->captureException($error, $context);
    }

    /** @param array<string, mixed>|null $context */
    public function captureLog(string $message, string $level = self::DEFAULT_LOG_LEVEL, ?array $context = null): void
    {
        $normalizedLevel = $this->normalizeLevel($level);
        if (
            !$this->enabled
            || !$this->passesSampleRate()
            || $this->capturePolicy->captureLogs === 'off'
            || !$this->levelEnabled($normalizedLevel, $this->effectiveLogThreshold())
        ) {
            return;
        }

        $payload = [
            'level' => $normalizedLevel,
            'message' => $message,
            'attributes' => $this->normalizeMap($this->redactArray($context ?? [])),
        ];

        $this->enqueueEvent($this->baseEvent('log_event', $payload));
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $response
     * @param array<string, mixed>|null $context
     */
    public function captureRequest(array $request, ?array $response = null, ?array $context = null): void
    {
        if (!$this->enabled || !$this->passesSampleRate() || !$this->shouldCaptureRequestEvent($response)) {
            return;
        }

        $redactedRequest = $this->redactArray($request);
        $redactedResponse = $this->redactArray($response ?? []);
        $redactedContext = $this->redactArray($context ?? []);

        $payload = [
            'method' => (string) ($redactedRequest['method'] ?? 'GET'),
            'path' => (string) ($redactedRequest['path'] ?? '/'),
            'query' => $this->normalizeMap(is_array($redactedRequest['query'] ?? null) ? $redactedRequest['query'] : []),
            'headers' => $this->normalizeMap(is_array($redactedRequest['headers'] ?? null) ? $redactedRequest['headers'] : []),
            'response_status' => isset($redactedResponse['status_code']) ? (int) $redactedResponse['status_code'] : null,
            'duration_ms' => isset($redactedResponse['duration_ms']) ? (int) $redactedResponse['duration_ms'] : null,
        ];

        $event = $this->baseEvent('request_event', $payload);
        if ($redactedContext !== []) {
            $event['context'] = $redactedContext;
        }

        $this->enqueueEvent($event);
    }

    /** @param array<string, mixed>|null $context */
    public function captureMessage(string $message, ?string $level = null, ?array $context = null): void
    {
        $this->captureLog($message, $level ?? self::DEFAULT_LOG_LEVEL, $context);
    }

    public function setContext(string $key, mixed $value): void
    {
        $this->context[$key] = Redaction::redactValue($value, $this->redactFields);
    }

    /** @param array<string, mixed>|null $opts */
    public function probe(string $label, mixed $data, ?array $opts = null): void
    {
        if (!$this->enabled || !$this->probesEnabled) {
            return;
        }

        $matchingDirectives = $this->findMatchingProbeDirectives($label);
        $isHeavy = ($opts['heavy'] ?? false) === true;
        if ($isHeavy && $matchingDirectives === []) {
            return;
        }

        if (!isset($this->probeBuffers[$label]) && count($this->probeBuffers) >= $this->maxProbeLabels) {
            return;
        }

        $value = is_callable($data) ? $data() : $data;
        if (!is_array($value)) {
            $value = ['value' => $value];
        }

        $redactedValue = $this->redactArray($value);

        if ($isHeavy) {
            $this->emitProbeEvents($label, $redactedValue, $matchingDirectives);
            return;
        }

        $entry = [
            'label' => $label,
            'data' => $redactedValue,
            'timestamp' => $this->isoNow(),
            'activation_id' => null,
        ];

        $bucket = $this->probeBuffers[$label] ?? [];
        $bucket[] = $entry;
        if (count($bucket) > $this->maxProbeEntriesPerLabel) {
            array_shift($bucket);
        }

        $this->probeBuffers[$label] = $bucket;
        $this->emitProbeEvents($label, $redactedValue, $matchingDirectives);
    }

    public function refreshRemoteConfig(bool $initial = false): void
    {
        if (!$this->enabled || $this->configFetcher === null) {
            return;
        }

        $request = [
            'method' => 'GET',
            'headers' => [],
        ];
        if ($this->remoteConfigEtag !== null) {
            $request['headers']['if-none-match'] = $this->remoteConfigEtag;
        }

        $fetcher = $this->configFetcher;

        try {
            $response = $fetcher($this->configEndpoint(), $request);
        } catch (\Throwable) {
            if ($initial) {
                $this->capturePolicy = RemoteConfig::minimalCapturePolicy();
                $this->remoteConfigSnapshot = null;
                $this->probesEnabled = true;
            }
            return;
        }

        $statusCode = isset($response->statusCode) && is_int($response->statusCode)
            ? $response->statusCode
            : null;
        if ($statusCode === 304) {
            return;
        }
        $configPayload = $this->extractConfigPayload($response);
        if ($statusCode !== 200 || $configPayload === null) {
            if ($initial) {
                $this->capturePolicy = RemoteConfig::minimalCapturePolicy();
                $this->remoteConfigSnapshot = null;
                $this->probesEnabled = true;
            }
            return;
        }

        $headers = isset($response->headers) && is_array($response->headers) ? $response->headers : [];
        $snapshot = RemoteConfig::parseRemoteConfig($configPayload, $this->configuredProbesPollIntervalMs, (int) round($this->now() * 1000));
        if ($snapshot === null) {
            if ($initial) {
                $this->capturePolicy = RemoteConfig::minimalCapturePolicy();
                $this->remoteConfigSnapshot = null;
                $this->probesEnabled = true;
            }
            return;
        }

        $this->remoteConfigSnapshot = $snapshot;
        $this->capturePolicy = $snapshot->capturePolicy;
        $this->probesEnabled = $snapshot->probesEnabled;
        $etag = $headers['etag'] ?? null;
        $this->remoteConfigEtag = is_string($etag) && $etag !== '' ? $etag : $this->remoteConfigEtag;
    }

    /** @param array<string, mixed> $request */
    public function beginRequest(array $request): void
    {
        $triggerTokenKey = $this->remoteConfigSnapshot?->triggerTokenKey;
        $this->requestCorrelation = CorrelationContext::resolveRequestCorrelation($request);
        $this->requestTriggerDirectives = TriggerToken::resolveRequestTriggerDirectives(
            $request,
            $triggerTokenKey,
            (int) round($this->now() * 1000),
        );
    }

    public function endRequest(): void
    {
        $this->requestCorrelation = [];
        $this->requestTriggerDirectives = [];
    }

    /** @return array<string, mixed>|null */
    private function extractConfigPayload(mixed $response): ?array
    {
        if (!is_object($response) || !method_exists($response, 'json')) {
            return null;
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : null;
    }

    /** @return 'healthy'|'degraded'|'disconnected' */
    public function getStatus(): string
    {
        if (!$this->enabled) {
            return 'disconnected';
        }

        if ($this->consecutiveFailures >= 3) {
            return 'disconnected';
        }

        if ($this->retryAfter > 0.0 && $this->now() < $this->retryAfter) {
            return 'degraded';
        }

        return 'healthy';
    }

    public function getLastEventAt(): ?float
    {
        return $this->lastEventAt;
    }

    public function flush(): void
    {
        if (!$this->enabled || $this->transport === null) {
            return;
        }

        $this->appendSuppressionAggregates();
        if ($this->buffer === []) {
            return;
        }

        $now = $this->now();
        if ($now < $this->retryAfter) {
            return;
        }

        $transport = $this->transport;
        if ($transport === null) {
            return;
        }

        $response = null;
        try {
            $response = $transport->send([
                'project_token' => $this->projectToken,
                'events' => $this->buffer,
            ]);
        } catch (\Throwable) {
            $this->consecutiveFailures++;
            return;
        }

        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            $this->buffer = [];
            $this->retryAfter = 0.0;
            $this->lastEventAt = $this->now() * 1000;
            $this->consecutiveFailures = 0;
            return;
        }

        $this->consecutiveFailures++;
        if ($response->statusCode === 429) {
            $retryAfterMs = $response->retryAfterMs ?? 1000;
            $this->retryAfter = $now + ($retryAfterMs / 1000);
            return;
        }

        if ($response->statusCode >= 400 && $response->statusCode < 500) {
            $this->buffer = [];
            $this->retryAfter = 0.0;
        }
    }

    public function captureErrors(): void
    {
        if ($this->errorsHooked) {
            return;
        }

        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
            $this->captureExceptionInternal(new \ErrorException($message, 0, $severity, $file, $line), null, false);
            return false;
        });
        $this->errorsHooked = true;
    }

    public function captureExceptions(): void
    {
        if ($this->exceptionsHooked) {
            return;
        }

        set_exception_handler(function (\Throwable $error): void {
            $this->captureExceptionInternal($error, null, false);
            $this->flush();
        });
        $this->exceptionsHooked = true;
    }

    public function captureShutdown(): void
    {
        if ($this->shutdownHooked) {
            return;
        }

        register_shutdown_function(function (): void {
            $this->handleShutdown(error_get_last());
        });
        $this->shutdownHooked = true;
    }

    public function reset(): void
    {
        if ($this->errorsHooked) {
            restore_error_handler();
        }

        if ($this->exceptionsHooked) {
            restore_exception_handler();
        }

        $this->errorsHooked = false;
        $this->exceptionsHooked = false;
        foreach ($this->loggerBindings as $binding) {
            $remainingHandlers = array_values(array_filter(
                $binding['logger']->getHandlers(),
                static fn (object $handler): bool => $handler !== $binding['handler']
            ));
            $binding['logger']->setHandlers($remainingHandlers);
        }
        $this->loggerBindings = [];
        $this->buffer = [];
        $this->context = [];
        $this->probeBuffers = [];
        $this->requestCorrelation = [];
        $this->requestTriggerDirectives = [];
        $this->transport = $this->transportOverride;
        $this->retryAfter = 0.0;
        $this->lastEventAt = null;
        $this->consecutiveFailures = 0;
        $this->configFetcher = null;
        $this->configuredProbesPollIntervalMs = RemoteConfig::DEFAULT_PROBES_POLL_INTERVAL_MS;
        $this->remoteConfigEtag = null;
        $this->remoteConfigSnapshot = null;
        $this->capturePolicy = RemoteConfig::balancedCapturePolicy();
        $this->probesEnabled = true;
        $this->capturedExceptions = new \WeakMap();
    }

    /** @param array<string, mixed>|null $lastError */
    private function handleShutdown(?array $lastError): void
    {
        if ($lastError !== null && in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->captureExceptionInternal(
                new \ErrorException(
                    (string) ($lastError['message'] ?? 'fatal error'),
                    0,
                    (int) ($lastError['type'] ?? E_ERROR),
                    (string) ($lastError['file'] ?? ''),
                    (int) ($lastError['line'] ?? 0),
                ),
                null,
                false
            );
        }

        $this->flush();
    }

    /** @param array<string, mixed>|null $context */
    private function captureExceptionInternal(\Throwable $error, ?array $context, bool $handled): void
    {
        if (!$this->enabled || !$this->passesSampleRate()) {
            return;
        }

        if (isset($this->capturedExceptions[$error])) {
            return;
        }

        $this->capturedExceptions[$error] = true;

        $redactedContext = $this->redactArray($context ?? []);
        $payload = [
            'name' => $error::class,
            'message' => $error->getMessage(),
            'stack' => $this->stringifyThrowable($error),
            'handled' => $handled,
            'request' => $this->buildRequestPayload($redactedContext['request'] ?? null),
            'response' => $this->buildResponsePayload($redactedContext['response'] ?? null),
            'runtime' => $this->buildRuntimePayload(),
        ];

        if ($this->probeFlushOnError) {
            $probeData = $this->buildProbeData();
            if ($probeData !== null) {
                $payload['probe_data'] = $probeData;
            }
        }

        $event = $this->baseEvent('backend_exception', $payload);
        $suppressionKey = sprintf('backend_exception:%s:%s:%s', $payload['name'], $payload['message'], $payload['stack']);
        if (!$this->suppression->shouldCapture($suppressionKey, $this->now())) {
            return;
        }

        $this->enqueueEvent($event);
    }

    /** @param array<string, mixed> $event */
    private function enqueueEvent(array $event): void
    {
        $this->buffer[] = $event;
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function appendSuppressionAggregates(): void
    {
        foreach ($this->suppression->drainAggregates($this->now()) as $aggregate) {
            $this->buffer[] = $this->baseEvent((string) $aggregate['event_type'], (array) $aggregate['payload']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function baseEvent(string $eventType, array $payload): array
    {
        $event = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => $this->uuidV4(),
            'event_type' => $eventType,
            'occurred_at' => $this->isoNow(),
            'sdk_name' => self::SDK_NAME,
            'sdk_version' => self::SDK_VERSION,
            'service' => [
                'name' => $this->service,
                'runtime' => 'php',
                'framework' => null,
                'environment' => $this->environment,
            ],
            'correlation' => $this->buildCorrelation(),
            'payload' => $payload,
        ];

        if ($this->context !== []) {
            $event['context'] = $this->context;
        }

        return $event;
    }

    /** @return array<string, mixed>|null */
    private function buildRequestPayload(mixed $request): ?array
    {
        if (!is_array($request)) {
            return null;
        }

        return [
            'method' => (string) ($request['method'] ?? 'GET'),
            'path' => (string) ($request['path'] ?? '/'),
            'headers' => $this->normalizeMap(is_array($request['headers'] ?? null) ? $request['headers'] : []),
            'query' => $this->normalizeMap(is_array($request['query'] ?? null) ? $request['query'] : []),
            'body' => is_array($request['body'] ?? null) ? $request['body'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function buildResponsePayload(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        return [
            'status_code' => isset($response['status_code']) ? (int) $response['status_code'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function buildRuntimePayload(): array
    {
        $pid = getmypid();
        $cwd = getcwd();
        $hostname = gethostname();

        return [
            'version' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY,
            'arch' => php_uname('m') ?: null,
            'pid' => is_int($pid) ? $pid : null,
            'cwd' => is_string($cwd) ? $cwd : null,
            'uptime_sec' => max(0.0, (hrtime(true) - $this->processStartedAtNs) / 1_000_000_000),
            'hostname' => $hostname !== false ? $hostname : null,
            'memory' => [
                'rss' => null,
                'heap_total' => memory_get_usage(true),
                'heap_used' => memory_get_usage(false),
                'external' => null,
                'peak' => memory_get_peak_usage(true),
            ],
        ];
    }

    /** @return array<string, string|null> */
    private function buildCorrelation(): array
    {
        return [
            'request_id' => $this->readContextString('request_id') ?? ($this->requestCorrelation['request_id'] ?? null),
            'trace_id' => $this->readContextString('trace_id') ?? ($this->requestCorrelation['trace_id'] ?? null),
            'session_id' => $this->readContextString('session_id'),
            'user_id_hash' => $this->readContextString('user_id_hash'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function buildProbeData(): ?array
    {
        if ($this->probeBuffers === []) {
            return null;
        }

        $items = [];
        foreach ($this->probeBuffers as $entries) {
            foreach ($entries as $entry) {
                $items[] = $entry;
            }
        }

        return [
            'version' => 1,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param list<RemoteProbeDirective> $directives
     */
    private function emitProbeEvents(string $label, array $value, array $directives): void
    {
        if ($this->capturePolicy->captureProbeEvents !== 'standalone_when_activated') {
            return;
        }

        foreach ($directives as $directive) {
            $this->enqueueEvent($this->baseEvent('probe_event', [
                'label' => $label,
                'data' => $value,
                'activation_id' => $directive->id,
                'probe_label_pattern' => $directive->labelPattern,
            ]));
        }
    }

    /** @param list<string>|mixed $customFields */
    /** @return array<string, bool> */
    private function buildRedactFields(mixed $customFields): array
    {
        $fields = [];
        foreach (Redaction::DEFAULT_REDACT_FIELDS as $field) {
            $fields[$field] = true;
        }

        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                if (is_string($field) && $field !== '') {
                    $fields[strtolower($field)] = true;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function redactArray(array $value): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = Redaction::redactValue($value, $this->redactFields);
        return $redacted;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>|object
     */
    private function normalizeMap(array $value): array|object
    {
        if ($value === []) {
            return new \stdClass();
        }

        return $value;
    }

    private function stringifyThrowable(\Throwable $error): string
    {
        return $error->__toString();
    }

    private function effectiveLogThreshold(): string
    {
        return (self::LEVEL_RANKS[$this->capturePolicy->captureLogs] ?? 0) > (self::LEVEL_RANKS[$this->logLevel] ?? 0)
            ? $this->capturePolicy->captureLogs
            : $this->logLevel;
    }

    /** @param array<string, mixed>|null $response */
    private function shouldCaptureRequestEvent(?array $response): bool
    {
        $statusCode = isset($response['status_code']) && is_numeric($response['status_code']) ? (int) $response['status_code'] : null;
        if ($this->isImmediateRequestIncidentStatus($statusCode)) {
            return true;
        }

        return match ($this->capturePolicy->captureRequestEvents) {
            'off' => false,
            'failures_only' => $this->isRequestAnomalyCandidateStatus($statusCode),
            'filtered' => false,
            default => true,
        };
    }

    private function isImmediateRequestIncidentStatus(?int $statusCode): bool
    {
        if ($statusCode === null) {
            return false;
        }

        if ($statusCode >= 500) {
            return true;
        }
        if (in_array($statusCode, $this->capturePolicy->immediateClientErrorStatuses, true)) {
            return true;
        }

        return match ($this->capturePolicy->preset) {
            'investigative' => in_array($statusCode, self::INVESTIGATIVE_IMMEDIATE_REQUEST_STATUSES, true),
            'balanced' => in_array($statusCode, self::BALANCED_IMMEDIATE_REQUEST_STATUSES, true),
            default => false,
        };
    }

    private function isRequestAnomalyCandidateStatus(?int $statusCode): bool
    {
        if ($statusCode === null || $statusCode < 400 || $statusCode >= 500) {
            return false;
        }

        return match ($this->capturePolicy->preset) {
            'investigative' => in_array($statusCode, self::INVESTIGATIVE_ANOMALY_STATUSES, true),
            'balanced' => in_array($statusCode, self::BALANCED_STANDARD_ANOMALY_STATUSES, true)
                || in_array($statusCode, self::BALANCED_HIGH_VOLUME_ANOMALY_STATUSES, true),
            default => false,
        };
    }

    private function passesSampleRate(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $this->sampleRate;
    }

    private function levelEnabled(string $candidate, string $threshold): bool
    {
        return (self::LEVEL_RANKS[$candidate] ?? 0) >= (self::LEVEL_RANKS[$threshold] ?? 0);
    }

    private function normalizeLevel(string $level): string
    {
        $normalized = strtolower(trim($level));

        return match ($normalized) {
            'warn' => 'warning',
            'exception' => 'error',
            'err' => 'error',
            default => isset(self::LEVEL_RANKS[$normalized]) ? $normalized : self::DEFAULT_LOG_LEVEL,
        };
    }

    private function readContextString(string $key): ?string
    {
        $value = $this->context[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function isoNow(): string
    {
        return gmdate('Y-m-d\\TH:i:s', (int) $this->now()) . 'Z';
    }

    private function now(): float
    {
        $provider = $this->timeProvider;
        return $provider();
    }

    private function configEndpoint(): string
    {
        $parts = parse_url($this->endpoint);
        if ($parts === false) {
            return 'https://api.debugbundle.com/v1/sdk/config';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $authority = '';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':' . $parts['pass'];
            }
            $authority .= '@';
        }

        $authority .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            $path = '/v1/events';
        }

        if (str_ends_with($path, '/events')) {
            $path = substr($path, 0, -strlen('/events')) . '/sdk/config';
        } else {
            $path .= '/sdk/config';
        }

        return $scheme . $authority . $path;
    }

    /** @return list<RemoteProbeDirective> */
    private function findMatchingProbeDirectives(string $label): array
    {
        $directives = $this->requestTriggerDirectives;
        if ($this->remoteConfigSnapshot !== null && $this->remoteConfigSnapshot->remoteProbesEnabled) {
            $directives = [...$directives, ...$this->remoteConfigSnapshot->directives];
        }

        if ($directives === []) {
            return [];
        }

        return RemoteConfig::findMatchingRemoteProbeDirectives(
            $directives,
            $label,
            $this->service,
            $this->environment,
            (int) round($this->now() * 1000),
        );
    }

    private function attachLoggerIntegration(mixed $logger): void
    {
        if (!$logger instanceof Logger) {
            return;
        }

        $handler = new DebugBundleHandler($this);
        $logger->pushHandler($handler);
        $this->loggerBindings[] = [
            'logger' => $logger,
            'handler' => $handler,
        ];
    }
}
