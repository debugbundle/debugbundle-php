<?php

declare(strict_types=1);

namespace DebugBundle;

use DebugBundle\Transport\IngestionAcknowledgementDecision;
use DebugBundle\Logging\DebugBundleHandler;
use DebugBundle\Transport\HttpTransport;
use DebugBundle\Transport\RetryAfter;
use DebugBundle\Transport\TransportInterface;
use Monolog\Logger;

final class DebugBundleSdk
{
    use DebugBundleSdkEventSupport;
    use DebugBundleSdkPolicySupport;
    use DebugBundleSdkQueueSupport;
    use DebugBundleSdkExceptionCapture;
    use DebugBundleSdkEvidenceSupport;
    use DebugBundleSdkEventBoundary;

    private const SDK_NAME = 'debugbundle/sdk-php';
    private const SDK_VERSION = '2.1.1';
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
    private int $generation = 0;
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
    private bool $requestEndAttempted = false;
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

    private OperationContext $operations;

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
        $this->operations = new OperationContext();
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
        $this->bufferBytes = 0;
        $this->bufferPriorities = [0, 0, 0, 0];
        $this->pressureDrops = [];
        $this->context = [];
        $this->probeBuffers = [];
        $this->retryAfter = 0.0;
        $this->lastEventAt = null;
        $this->consecutiveFailures = 0;
        $this->requestEndAttempted = false;
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
        $this->capturePolicy = $this->configFetcher === null
            ? RemoteConfig::balancedCapturePolicy()
            : RemoteConfig::minimalCapturePolicy();

        if ($this->transport === null && $this->enabled) {
            $this->transport = new HttpTransport($this->endpoint);
        }

        $this->initializeEvidence($config);
        $this->attachLoggerIntegration($config['logger'] ?? null);

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
        if (
            $this->capturePolicy->captureLogs === 'off'
            || !$this->levelEnabled($normalizedLevel, $this->effectiveLogThreshold())
            || !$this->preAdmissionAllows('log_event', $normalizedLevel)
        ) {
            return;
        }

        if (in_array($normalizedLevel, ['error', 'critical'], true) && ($context['exception'] ?? null) instanceof \Throwable) {
            $error = $context['exception'];
            unset($context['exception']);
            $context['log_message'] = $message;
            $this->captureException($error, $context);
            return;
        }
        if (!$this->passesSampleRate()) {
            return;
        }

        $payload = [
            'level' => $normalizedLevel,
            'message' => $message,
            'attributes' => $this->normalizeMap($this->redactArray($context ?? [])),
        ];

        $event = $this->applyBeforeSend($this->baseEvent('log_event', $payload, $context ?? []));
        if ($event === null) {
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
        if (!$this->enabled || !$this->shouldCaptureRequestEvent($request, $response) || !$this->passesSampleRate()
            || !$this->preAdmissionAllows('request_event', 'warning',
                isset($response['status_code']) && is_numeric($response['status_code']) ? (int) $response['status_code'] : null)) {
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
        if ($event === null) {
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
        try {
            $protected = TelemetryPrivacy::protect([$key => $value], $this->redactFields);
            if (is_array($protected) && array_key_exists($key, $protected)) {
                $this->context[$key] = $protected[$key];
            }
        } catch (\Throwable) {
            // SDK-owned context must not retain unsupported or over-budget input.
        }
    }

    /** @param array<string, mixed>|null $opts */
    public function probe(string $label, mixed $data, ?array $opts = null): void
    {
        if (!$this->enabled || !$this->probesEnabled) {
            return;
        }

        $generation = $this->generation;
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
        if ($generation !== $this->generation) return;
        if (!is_array($value) || array_is_list($value)) {
            $value = ['value' => $value];
        }

        try {
            $redactedValue = TelemetryPrivacy::protect($value, $this->redactFields);
            $label = TelemetryPrivacy::protect($label, $this->redactFields);
            if (!is_array($redactedValue) || !is_string($label)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

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

        $generation = $this->generation;
        $request = [
            'method' => 'GET',
            'headers' => ['authorization' => 'Bearer ' . $this->projectToken],
        ];
        if ($this->remoteConfigEtag !== null) {
            $request['headers']['if-none-match'] = $this->remoteConfigEtag;
        }

        $fetcher = $this->configFetcher;

        try {
            $response = $fetcher(EvidenceWire::discoveryUrl($this->configEndpoint()), $request);
        } catch (\Throwable) {
            if ($generation !== $this->generation) return;
            if ($initial) {
                $this->capturePolicy = RemoteConfig::minimalCapturePolicy();
                $this->remoteConfigSnapshot = null;
                $this->probesEnabled = true;
            }
            return;
        }
        if ($generation !== $this->generation) return;

        try {
        $statusCode = isset($response->statusCode) && is_int($response->statusCode)
            ? $response->statusCode
            : null;
        if ($statusCode === 304) {
            return;
        }
        $configPayload = $this->extractConfigPayload($response);
        if ($generation !== $this->generation) return;
        if ($statusCode !== 200 || $configPayload === null) {
            $this->observeEvidence(null, true);
            if ($initial) {
                $this->capturePolicy = RemoteConfig::minimalCapturePolicy();
                $this->remoteConfigSnapshot = null;
                $this->probesEnabled = true;
            }
            return;
        }

        $headers = isset($response->headers) && is_array($response->headers) ? $response->headers : [];
        $snapshot = RemoteConfig::parseRemoteConfig($configPayload, $this->configuredProbesPollIntervalMs, (int) round($this->now() * 1000));
        $this->observeEvidence($configPayload, true, $snapshot !== null);
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
        } catch (\Throwable) {
            if ($generation !== $this->generation) return;
            $this->observeEvidence(null, true);
            if ($initial) { $this->capturePolicy = RemoteConfig::minimalCapturePolicy(); $this->remoteConfigSnapshot = null; }
        }
    }

    /** @param array<string, mixed> $request */
    public function beginRequest(array $request, ?object $owner = null): void
    {
        $triggerTokenKey = $this->remoteConfigSnapshot?->triggerTokenKey;
        $correlation = CorrelationContext::resolveRequestCorrelation($request);
        $directives = TriggerToken::resolveRequestTriggerDirectives(
            $request,
            $triggerTokenKey,
            (int) round($this->now() * 1000),
        );
        $this->operations->begin(['kind' => 'request', 'name' => $request['route_template'] ?? null], $correlation, $directives, $owner);
    }

    public function endRequest(?object $owner = null): void
    {
        $this->operations->end($owner);
    }

    /** @template Result
     * @param array<string, mixed> $options
     * @param callable(): Result $callback
     * @return Result
     */
    public function runWithOperationContext(array $options, callable $callback): mixed
    {
        return $this->operations->run($options, $callback);
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

    private bool $flushing = false;

    public function flush(): void
    {
        if ($this->flushing) return;
        $this->flushing = true;
        $generation = $this->generation;
        $deadline = hrtime(true) + 250_000_000;
        try {
            // One shared native deadline bounds the whole flush, including byte
            // chunks. Request-end selection already fits one bounded request.
            for ($cycle = 0; $cycle <= self::MAX_BUFFER_EVENTS; $cycle++) {
                if (!$this->flushBufferedBatch($deadline) || $generation !== $this->generation
                    || !$this->transport instanceof HttpTransport || $this->buffer === []
                    || hrtime(true) >= $deadline) return;
            }
        } catch (\Throwable) {
            // SDK failure cannot propagate into the host or release pending data.
        } finally { $this->flushing = false; }
    }

    private function flushBufferedBatch(int $deadline): bool
    {
        if (!$this->enabled || $this->transport === null) {
            return false;
        }

        $generation = $this->generation;
        $this->finalizeEvidenceBuffer();
        $this->appendSuppressionAggregates();
        if ($generation !== $this->generation) return false;
        $this->appendPressureAggregates();
        if ($generation !== $this->generation) return false;
        if ($this->buffer === []) {
            return false;
        }

        $now = $this->now();
        if ($now < $this->retryAfter) {
            return false;
        }

        $transport = $this->transport;
        if ($transport === null) {
            return false;
        }

        $batch = $transport instanceof HttpTransport ? IngestionWire::prefix($this->buffer) : $this->buffer;
        $response = null;
        try {
            $request = [
                'project_token' => $this->projectToken,
                'events' => $batch,
            ];
            if ($transport instanceof HttpTransport) $request['evidence_discovery'] = true;
            $response = $transport instanceof HttpTransport ? $transport->sendUntil($request, $deadline) : $transport->send($request);
        } catch (\Throwable) {
            if ($generation !== $this->generation) return false;
            $this->consecutiveFailures++;
            return false;
        }
        if ($generation !== $this->generation) return false;

        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            $acknowledgement = IngestionAcknowledgementDecision::decide($response->body, count($batch), $this->transport instanceof HttpTransport);
            $lostEvidence = $this->observeEvidence($response->body, $transport instanceof HttpTransport, $acknowledgement->kind === 'acknowledged');
            if ($acknowledgement->kind === 'protocol_failure') {
                $this->consecutiveFailures++;
                $retryAfterMs = RetryAfter::bounded($response->retryAfterMs);
                $this->retryAfter = $this->now() + ($retryAfterMs / 1000);
                return false;
            }
            if ($acknowledgement->kind === 'legacy') {
                $this->replaceBufferedEvents(array_slice($this->buffer, count($batch)));
                $this->retryAfter = 0.0;
                $this->lastEventAt = $this->now() * 1000;
                $this->consecutiveFailures = 0;
                return true;
            }

            $trailingEvents = array_slice($this->buffer, count($batch));
            $retryableEvents = [];
            foreach ($acknowledgement->retryableIndices as $index) {
                if (isset($batch[$index])) {
                    $retryableEvents[] = $batch[$index];
                }
            }
            if ($lostEvidence) foreach ($acknowledgement->terminalErrors as $error) {
                if ($error['reason'] !== 'invalid_event' || !isset($batch[$error['index']])) continue;
                $legacy = EvidenceWire::withoutOptional($batch[$error['index']]);
                if ($legacy !== null) $retryableEvents[] = $legacy;
            }
            $this->replaceBufferedEvents([...$retryableEvents, ...$trailingEvents]);
            if ($acknowledgement->accepted > 0) {
                $this->lastEventAt = $this->now() * 1000;
            }
            if ($retryableEvents !== []) {
                $this->consecutiveFailures++;
                $retryAfterMs = RetryAfter::bounded($response->retryAfterMs);
                $this->retryAfter = $this->now() + ($retryAfterMs / 1000);
                return false;
            }
            $this->retryAfter = 0.0;
            $this->consecutiveFailures = $acknowledgement->accepted > 0 ? 0 : 3;
            return true;
        }

        $this->consecutiveFailures++;
        if ($response->statusCode === 429 || ($response->statusCode >= 500 && $response->retryAfterMs !== null)) {
            $retryAfterMs = RetryAfter::bounded($response->retryAfterMs);
            $this->retryAfter = $this->now() + ($retryAfterMs / 1000);
            return false;
        }

        if ($response->statusCode >= 400 && $response->statusCode < 500) {
            $this->replaceBufferedEvents(array_slice($this->buffer, count($batch)));
            $this->retryAfter = 0.0;
        }
        return false;
    }

    public function captureErrors(): void
    {
        if ($this->errorsHooked) {
            return;
        }

        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
            // PHP invokes custom handlers even for @-suppressed or masked errors.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            $this->captureExceptionInternal(new \ErrorException($message, 0, $severity, $file, $line), null, false, true, null, true);
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
            $this->captureExceptionInternal($error, null, false, false);
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
        $this->generation++;
        $this->operations->clear();
        $this->resetEvidence();
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
        $this->bufferBytes = 0;
        $this->bufferPriorities = [0, 0, 0, 0];
        $this->pressureDrops = [];
        $this->context = [];
        $this->probeBuffers = [];
        $this->transport = $this->transportOverride;
        $this->retryAfter = 0.0;
        $this->lastEventAt = null;
        $this->consecutiveFailures = 0;
        $this->requestEndAttempted = false;
        $this->configFetcher = null;
        $this->beforeSend = null;
        $this->configuredProbesPollIntervalMs = RemoteConfig::DEFAULT_PROBES_POLL_INTERVAL_MS;
        $this->remoteConfigEtag = null;
        $this->remoteConfigSnapshot = null;
        $this->capturePolicy = RemoteConfig::balancedCapturePolicy();
        $this->probesEnabled = true;
        $this->capturedExceptions = new \WeakMap();
    }

    /** @param array<string, mixed> $event */
    private function enqueueEvent(array $event, bool $countDrop = true): bool
    {
        $original = $event;
        $event = $this->protectEvent($event);
        if ($event === null) {
            $this->releaseDiagnostic($original);
            return false;
        }
        $event = $this->reconcileEvidence($event, $event);
        if ($event === null) return false;
        if (in_array($event['event_type'] ?? null, ['backend_exception', 'frontend_exception'], true)) {
            $payload = $event['payload'];
            $key = sprintf('%s:%s:%s:%s', $event['event_type'], $payload['name'] ?? '', $payload['message'] ?? '', $payload['stack'] ?? '');
            if (!$this->suppression->shouldCapture($key, $this->now())) return false;
        }
        if ($this->admitProtectedEvent($event, $countDrop)) return true;
        $this->releaseDiagnostic($event);
        return false;
    }

    private function appendSuppressionAggregates(): void
    {
        $generation = $this->generation;
        foreach ($this->suppression->drainAggregates($this->now()) as $aggregate) {
            $event = $this->applyBeforeSend(
                $this->baseEvent((string) $aggregate['event_type'], (array) $aggregate['payload'])
            );
            if ($generation !== $this->generation) return;
            if ($event !== null) $this->enqueueEvent($event);
        }
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
