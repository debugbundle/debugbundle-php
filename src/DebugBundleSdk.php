<?php

declare(strict_types=1);

namespace DebugBundle;

use DebugBundle\Transport\IngestionAcknowledgementDecision;
use DebugBundle\Logging\DebugBundleHandler;
use DebugBundle\Transport\HttpTransport;
use DebugBundle\Transport\TransportInterface;
use Monolog\Logger;

final class DebugBundleSdk
{
    use DebugBundleSdkEventSupport;
    use DebugBundleSdkPolicySupport;

    private const SDK_NAME = 'debugbundle/sdk-php';
    private const SDK_VERSION = '1.3.0';
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
    private ?\Closure $beforeSend = null;
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
        $this->beforeSend = isset($config['beforeSend']) && is_callable($config['beforeSend'])
            ? \Closure::fromCallable($config['beforeSend'])
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
        if (!$this->enabled) {
            return;
        }

        $payload = [
            'level' => $normalizedLevel,
            'message' => $message,
            'attributes' => $this->normalizeMap($this->redactArray($context ?? [])),
        ];

        $event = $this->applyBeforeSend($this->baseEvent('log_event', $payload, $context ?? []));
        if (
            $event === null
            || !$this->passesSampleRate()
            || $this->capturePolicy->captureLogs === 'off'
            || !$this->levelEnabled($normalizedLevel, $this->effectiveLogThreshold())
        ) {
            return;
        }

        $this->enqueueEvent($event);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $response
     * @param array<string, mixed>|null $context
     */
    public function captureRequest(array $request, ?array $response = null, ?array $context = null): void
    {
        if (!$this->enabled) {
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
            'response_status' => isset($redactedResponse['status_code']) ? (int) $redactedResponse['status_code'] : 0,
            'duration_ms' => isset($redactedResponse['duration_ms']) ? max(0, (int) $redactedResponse['duration_ms']) : 0,
        ];

        $event = $this->applyBeforeSend($this->baseEvent('request_event', $payload, $redactedContext));
        if ($event === null || !$this->passesSampleRate() || !$this->shouldCaptureRequestEvent($request, $response)) {
            return;
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

        try {
            $value = is_callable($data) ? $data() : $data;
        } catch (\Throwable) {
            return;
        }
        if (!is_array($value) || array_is_list($value)) {
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

        $batch = $this->buffer;
        $response = null;
        try {
            $response = $transport->send([
                'project_token' => $this->projectToken,
                'events' => $batch,
            ]);
        } catch (\Throwable) {
            $this->consecutiveFailures++;
            return;
        }

        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            $acknowledgement = IngestionAcknowledgementDecision::decide($response->body, count($batch));
            if ($acknowledgement->kind === 'protocol_failure') {
                $this->consecutiveFailures++;
                $retryAfterMs = $response->retryAfterMs ?? 1000;
                $this->retryAfter = $now + ($retryAfterMs / 1000);
                return;
            }
            if ($acknowledgement->kind === 'legacy') {
                $this->buffer = array_slice($this->buffer, count($batch));
                $this->retryAfter = 0.0;
                $this->lastEventAt = $this->now() * 1000;
                $this->consecutiveFailures = 0;
                return;
            }

            $trailingEvents = array_slice($this->buffer, count($batch));
            $retryableEvents = [];
            foreach ($acknowledgement->retryableIndices as $index) {
                if (isset($batch[$index])) {
                    $retryableEvents[] = $batch[$index];
                }
            }
            $this->buffer = [...$retryableEvents, ...$trailingEvents];
            if ($acknowledgement->accepted > 0) {
                $this->lastEventAt = $this->now() * 1000;
            }
            if ($retryableEvents !== []) {
                $this->consecutiveFailures++;
                $retryAfterMs = $response->retryAfterMs ?? 1000;
                $this->retryAfter = $now + ($retryAfterMs / 1000);
                return;
            }
            $this->retryAfter = 0.0;
            $this->consecutiveFailures = $acknowledgement->accepted > 0 ? 0 : 3;
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
        $this->beforeSend = null;
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
                false,
                false
            );
        }

        $this->flush();
    }

    /** @param array<string, mixed>|null $context */
    private function captureExceptionInternal(
        \Throwable $error,
        ?array $context,
        bool $handled,
        bool $runBeforeSend = true
    ): void
    {
        if (!$this->enabled) {
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

        $event = $this->baseEvent('backend_exception', $payload, $redactedContext);
        if ($runBeforeSend) {
            $event = $this->applyBeforeSend($event);
        }
        if ($event === null || !$this->passesSampleRate()) {
            return;
        }

        $eventPayload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $suppressionKey = sprintf(
            '%s:%s:%s:%s',
            (string) ($event['event_type'] ?? 'backend_exception'),
            (string) ($eventPayload['name'] ?? ''),
            (string) ($eventPayload['message'] ?? ''),
            (string) ($eventPayload['stack'] ?? '')
        );
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
            $event = $this->applyBeforeSend(
                $this->baseEvent((string) $aggregate['event_type'], (array) $aggregate['payload'])
            );
            if ($event !== null) {
                $this->buffer[] = $event;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function applyBeforeSend(array $event): ?array
    {
        return BeforeSend::apply($event, $this->beforeSend);
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

    /** @param array<string, mixed>|null $context */
    private function readContextString(string $key, ?array $context = null): ?string
    {
        $value = ($context ?? $this->context)[$key] ?? null;
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
