<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

use DebugBundle\TelemetryPrivacy;
use DebugBundle\EvidenceWire;

use DebugBundle\Transport\TransportInterface;

final class BrowserRelayHandler
{
    private const DEFAULT_MAX_BODY_BYTES = 262144;
    private const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;
    private const BROWSER_SDK_NAME = '@debugbundle/sdk-browser';

    /** @var list<string> */
    private const ACCEPTED_EVENT_TYPES = [
        'frontend_exception',
        'error_suppressed',
        'frontend_breadcrumb',
        'request_event',
        'probe_event',
        'analytics_event',
        'diagnostic_context',
    ];

    /** @var list<string> */
    private array $allowedOrigins;

    private int $maxBodyBytes;
    private int $rateLimitPerMinute;

    /** @var \Closure(BrowserRelayAcceptedBatch): void */
    private \Closure $onAccept;

    private ?string $projectMode;
    private string $projectToken;
    private ?string $endpoint;
    private ?string $localEventsDir;
    private ?string $spoolDir;
    private bool $durableWrite;
    private ?string $serviceOverride;
    private ?string $environmentOverride;
    private ?RelayForwardTransport $forwardTransport;
    private BrowserRelayRateLimitStore $rateLimitStore;
    private RelayEvidencePath $evidence;

    /** @var array<string, RelayFileTransport> */
    private array $localTransports = [];

    /** @var array<string, RelayFileTransport> */
    private array $spoolTransports = [];

    /** @param array{allowedOrigins?:list<string>,maxBodyBytes?:int,rateLimitPerMinute?:int,onAccept?:callable(BrowserRelayAcceptedBatch):void,projectMode?:string,projectToken?:string,endpoint?:string,localEventsDir?:string,spoolDir?:string,durableWrite?:bool,service?:string,environment?:string,forwardTransport?:TransportInterface,rateLimitStore?:BrowserRelayRateLimitStore,evidenceCapabilities?:mixed} $options */
    public function __construct(array $options = [])
    {
        $this->allowedOrigins = array_values(array_filter(
            $options['allowedOrigins'] ?? [],
            static fn (string $origin): bool => $origin !== ''
        ));
        $this->maxBodyBytes = max(1, (int) ($options['maxBodyBytes'] ?? self::DEFAULT_MAX_BODY_BYTES));
        $this->rateLimitPerMinute = max(1, (int) ($options['rateLimitPerMinute'] ?? self::DEFAULT_RATE_LIMIT_PER_MINUTE));
        $this->onAccept = isset($options['onAccept'])
            ? \Closure::fromCallable($options['onAccept'])
            : static function (): void {
            };
        $normalizedProjectMode = strtolower(trim((string) ($options['projectMode'] ?? '')));
        $this->projectMode = $normalizedProjectMode !== '' ? $normalizedProjectMode : null;
        if (!in_array($this->projectMode, [null, 'connected', 'local-only'], true)) {
            $this->projectMode = null;
        }
        $this->projectToken = trim((string) ($options['projectToken'] ?? ''));
        $this->endpoint = isset($options['endpoint']) && $options['endpoint'] !== '' ? (string) $options['endpoint'] : null;
        $this->localEventsDir = isset($options['localEventsDir']) && $options['localEventsDir'] !== '' ? (string) $options['localEventsDir'] : null;
        $this->spoolDir = isset($options['spoolDir']) && $options['spoolDir'] !== '' ? (string) $options['spoolDir'] : null;
        $this->durableWrite = ($options['durableWrite'] ?? true) !== false;
        $this->serviceOverride = isset($options['service']) && $options['service'] !== '' ? (string) $options['service'] : null;
        $this->environmentOverride = isset($options['environment']) && $options['environment'] !== '' ? (string) $options['environment'] : null;
        $this->rateLimitStore = $options['rateLimitStore'] ?? new InMemoryBrowserRelayRateLimitStore();
        $this->evidence = new RelayEvidencePath($options['evidenceCapabilities'] ?? null);
        $this->forwardTransport = $this->projectMode === 'connected'
            ? (isset($options['forwardTransport'])
                ? new RelayForwardTransport($options['forwardTransport'], $this->evidence)
                : ($this->endpoint !== null ? RelayForwardTransport::fromEndpoint($this->endpoint, $this->evidence) : null))
            : null;
    }

    /** @param array{method?:string,url?:string,headers?:array<string,string>,body:string,ipAddress?:string|null} $request */
    public function handle(array $request): BrowserRelayResponse
    {
        $method = strtoupper((string) ($request['method'] ?? 'POST'));
        $headers = $this->normalizeHeaders($request['headers'] ?? []);
        $sourceOrigin = $this->sourceOrigin($headers);
        $discover = RelayEvidencePath::requested($request['url'] ?? null, $headers);
        if (!$this->isOriginAllowed($headers)) {
            return new BrowserRelayResponse(403);
        }

        $responseHeaders = is_string($sourceOrigin) ? $this->corsHeaders($sourceOrigin) : [];
        if (isset($responseHeaders['Access-Control-Allow-Headers']) && str_contains(strtolower($headers['access-control-request-headers'] ?? ''), 'x-debugbundle-evidence'))
            $responseHeaders['Access-Control-Allow-Headers'] .= ', x-debugbundle-evidence';
        $withHeaders = fn (BrowserRelayResponse $response): BrowserRelayResponse => new BrowserRelayResponse(
            $response->status,
            $discover && $response->body !== null ? [...$response->body, 'evidence_capabilities' => $this->evidence->descriptor()] : $response->body,
            array_merge($responseHeaders, $response->headers),
        );

        if ($method === 'OPTIONS') {
            return $withHeaders(new BrowserRelayResponse(204));
        }

        if ($method !== 'POST') {
            return $withHeaders(new BrowserRelayResponse(405));
        }

        if (!$this->isSupportedContentType($headers['content-type'] ?? null)) {
            return $withHeaders(new BrowserRelayResponse(400, [
                'accepted' => 0,
                'rejected' => 0,
                'errors' => ['Relay requests must use Content-Type: application/json.'],
            ]));
        }

        $body = $request['body'];
        if (strlen($body) > $this->maxBodyBytes) {
            return $withHeaders(new BrowserRelayResponse(413));
        }

        $ipAddress = $request['ipAddress'] ?? null;
        if ($this->isRateLimited($ipAddress)) {
            return $withHeaders(new BrowserRelayResponse(429));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $withHeaders(new BrowserRelayResponse(400, [
                'accepted' => 0,
                'rejected' => 0,
                'errors' => ['Relay request body must be valid JSON.'],
            ]));
        }

        $batch = $decoded['batch'] ?? null;
        if (!is_array($batch)) {
            return $withHeaders(new BrowserRelayResponse(400, [
                'accepted' => 0,
                'rejected' => 0,
                'errors' => ['Relay request body must include a batch array.'],
            ]));
        }

        $acceptedEvents = [];
        $errors = [];
        $evidenceErrors = [];

        foreach (array_values($batch) as $index => $candidate) {
            if (!is_array($candidate)) {
                $errors[] = sprintf('batch[%d]: Relay events must be objects.', $index);
                $evidenceErrors[] = ['index' => $index, 'reason' => 'invalid_event'];
                continue;
            }

            $eventType = $candidate['event_type'] ?? null;
            if (!is_string($eventType) || !in_array($eventType, self::ACCEPTED_EVENT_TYPES, true)) {
                $safeType = $eventType === 'backend_exception' ? $eventType : 'unknown';
                $errors[] = sprintf('batch[%d]: Unsupported browser relay event type %s.', $index, $safeType);
                $evidenceErrors[] = ['index' => $index, 'reason' => 'invalid_event'];
                continue;
            }

            $sanitized = $this->sanitizeEvent($candidate);
            if ($sanitized === null) {
                $errors[] = sprintf('batch[%d]: Invalid browser relay event payload.', $index);
                $evidenceErrors[] = ['index' => $index, 'reason' => 'invalid_event'];
                continue;
            }

            $prepared = $this->evidence->prepare($sanitized);
            if ($prepared !== null && $this->forwardTransport !== null && $this->projectToken !== '')
                $prepared = RelayForwardTransport::prepareEvent($prepared, $this->projectToken);
            if ($prepared === null) {
                $errors[] = sprintf('batch[%d]: Unsupported diagnostic evidence.', $index);
                $evidenceErrors[] = ['index' => $index, 'reason' => 'invalid_event'];
                continue;
            }
            $acceptedEvents[] = $prepared;
        }

        if ($acceptedEvents !== []) {
            try {
                if (!$this->deliverEvents($acceptedEvents, $discover)) {
                    return $withHeaders(new BrowserRelayResponse(500));
                }

                $callback = $this->onAccept;
                $callback(new BrowserRelayAcceptedBatch(
                    $acceptedEvents,
                    $this->stripSensitiveHeaders($headers),
                    $ipAddress,
                    gmdate('Y-m-d\\TH:i:s') . 'Z',
                ));
            } catch (\Throwable) {
                return $withHeaders(new BrowserRelayResponse(500));
            }
        }

        $result = ['accepted' => count($acceptedEvents), 'rejected' => count($errors), 'errors' => $errors];
        if ($discover) $result['evidence_errors'] = $evidenceErrors;
        return $withHeaders(new BrowserRelayResponse($discover || $errors === [] ? 202 : 400, $result));
    }

    /** @param array<string,string> $headers */
    private function isOriginAllowed(array $headers): bool
    {
        $origin = $this->sourceOrigin($headers);
        if ($origin === null) {
            return false;
        }

        if ($this->allowedOrigins !== []) {
            $normalizedOrigin = $this->normalizeOrigin($origin);
            foreach ($this->allowedOrigins as $candidate) {
                if ($this->normalizeOrigin($candidate) === $normalizedOrigin) {
                    return true;
                }
            }

            return false;
        }

        $host = $headers['host'] ?? null;
        if ($host === null || $host === '') {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        return is_string($originHost) && strtolower($originHost) === strtolower($host);
    }

    private function isSupportedContentType(?string $contentType): bool
    {
        return is_string($contentType) && str_contains(strtolower($contentType), 'application/json');
    }

    /** @return array<string, string> */
    private function corsHeaders(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'content-type',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }

    private function isRateLimited(?string $ipAddress): bool
    {
        return !$this->rateLimitStore->allow($ipAddress, $this->rateLimitPerMinute, 60, time());
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function sanitizeEvent(array $event): ?array
    {
        $schemaVersion = $event['schema_version'] ?? null;
        $eventId = $event['event_id'] ?? null;
        $eventType = $event['event_type'] ?? null;
        $occurredAt = $event['occurred_at'] ?? null;
        $sdkVersion = $event['sdk_version'] ?? null;
        $service = $event['service'] ?? null;
        $payload = $event['payload'] ?? null;

        if (!is_string($schemaVersion) || $schemaVersion === '' || !is_string($eventId) || $eventId === '' || !is_string($eventType) || !is_string($occurredAt) || $occurredAt === '' || !is_string($sdkVersion) || $sdkVersion === '' || !is_array($service) || !is_array($payload)) {
            return null;
        }

        $serviceName = $this->serviceOverride ?? ($service['name'] ?? null);
        $environment = $this->environmentOverride ?? ($service['environment'] ?? null);
        if (!is_string($serviceName) || $serviceName === '' || !is_string($environment) || $environment === '') {
            return null;
        }

        $correlation = [];
        if (isset($event['correlation']) && is_array($event['correlation'])) {
            $correlationKeys = $eventType === 'analytics_event'
                ? ['session_id', 'visitor_id_hash', 'user_id_hash', 'trace_id', 'deploy_id']
                : ['request_id', 'trace_id', 'session_id', 'user_id_hash'];
            foreach ($correlationKeys as $key) {
                if (!array_key_exists($key, $event['correlation'])) {
                    continue;
                }
                $value = $event['correlation'][$key];
                if (is_string($value) || $value === null) {
                    $correlation[$key] = $value;
                }
            }
        }

        $sanitized = [
            'schema_version' => $schemaVersion,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'sdk_name' => self::BROWSER_SDK_NAME,
            'sdk_version' => $sdkVersion,
            'service' => [
                'name' => $serviceName,
                'environment' => $environment,
            ],
            'payload' => $payload,
        ];

        $runtime = $service['runtime'] ?? null;
        if (is_string($runtime) || $runtime === null) {
            $sanitized['service']['runtime'] = $runtime;
        }

        $framework = $service['framework'] ?? null;
        if (is_string($framework) || $framework === null) {
            $sanitized['service']['framework'] = $framework;
        }

        if ($correlation !== []) {
            $sanitized['correlation'] = $correlation;
        }

        if (is_array($event['context'] ?? null)) $sanitized['context'] = $event['context'];
        $typed = $eventType !== 'analytics_event' && ($eventType === 'diagnostic_context'
            || isset($payload['exception_evidence']) || isset($payload['execution']) || isset($payload['resource_observations']));
        if ($typed && (!\DebugBundle\CanonicalEventSchema::valid($sanitized) || !EvidenceWire::optionalEvidence($sanitized)
            || ($eventType === 'frontend_exception' && !is_array($payload['browser'] ?? null)))) return null;
        try {
            if (!TelemetryPrivacy::hasSafeEventIdentity($sanitized)) {
                return null;
            }
            $protected = TelemetryPrivacy::protect([
                'service' => $sanitized['service'],
                'payload' => $sanitized['payload'],
                'context' => $sanitized['context'] ?? null,
            ]);
            if (!is_array($protected) || !is_array($protected['service'] ?? null)
                || !is_array($protected['payload'] ?? null)
                || !is_string($protected['service']['name'] ?? null)
                || !is_string($protected['service']['environment'] ?? null)) {
                return null;
            }
            $sanitized['service'] = $protected['service'];
            $sanitized['payload'] = $protected['payload'];
            if (isset($sanitized['context'])) {
                if (!is_array($protected['context'])) return null;
                $sanitized['context'] = $protected['context'];
            }
            if ($typed && !\DebugBundle\CanonicalEventSchema::valid($sanitized)) return null;
            if (!EvidenceWire::optionalEvidence($sanitized)) {
                $reduced = EvidenceWire::withoutOptional($sanitized);
                return $reduced === null ? null : $this->sanitizeEvent($reduced);
            }
            return $sanitized;
        } catch (\Throwable) {
            $reduced = EvidenceWire::withoutOptional($sanitized);
            return $reduced === null ? null : $this->sanitizeEvent($reduced);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function stripSensitiveHeaders(array $headers): array
    {
        $sanitized = $headers;
        unset($sanitized['authorization'], $sanitized['cookie'], $sanitized['x-api-key']);
        return $sanitized;
    }

    /** @param array<string, string> $headers */
    private function sourceOrigin(array $headers): ?string
    {
        $origin = trim((string) ($headers['origin'] ?? ''));
        if ($origin !== '') {
            return $origin;
        }

        $referer = trim((string) ($headers['referer'] ?? ''));
        if ($referer === '') {
            return null;
        }

        $refererOrigin = parse_url($referer, PHP_URL_SCHEME);
        $refererHost = parse_url($referer, PHP_URL_HOST);
        if (!is_string($refererOrigin) || !is_string($refererHost)) {
            return null;
        }

        return $refererOrigin . '://' . $refererHost;
    }

    private function normalizeOrigin(string $origin): string
    {
        return rtrim(strtolower(trim($origin)), '/');
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $acceptedEvents */
    private function deliverEvents(array $acceptedEvents, bool $discover = false): bool
    {
        if ($this->projectMode === null) {
            return true;
        }

        $serviceName = $this->serviceOverride ?? (string) ($acceptedEvents[0]['service']['name'] ?? 'service');

        if ($this->projectMode === 'local-only') {
            $transport = $this->localTransports[$serviceName] ??= new RelayFileTransport(
                $this->localEventsDir ?? RelayFileTransport::resolveDefaultLocalEventsDir(),
                $serviceName,
            );

            return $transport->write($acceptedEvents)->statusCode === 202;
        }

        if ($this->projectMode !== 'connected') {
            return true;
        }

        if ($this->durableWrite) {
            $transport = $this->spoolTransports[$serviceName] ??= new RelayFileTransport(
                $this->spoolDir ?? RelayFileTransport::resolveDefaultRelaySpoolDir(),
                $serviceName,
            );

            $spoolWriteResult = $transport->write($acceptedEvents);
            if ($spoolWriteResult->statusCode !== 202) {
                return false;
            }

            [$configured, $_succeeded, $complete] = $this->forwardConnectedEvents($acceptedEvents, $discover);
            if ($complete && $spoolWriteResult->writtenFilePath !== null) {
                RelayFileTransport::markDelivered($spoolWriteResult->writtenFilePath);
            }

            return $configured || $spoolWriteResult->writtenFilePath !== null;
        }

        [$configured, $succeeded] = $this->forwardConnectedEvents($acceptedEvents, $discover);
        return $configured && $succeeded;
    }

    /**
     * @param list<array<string, mixed>> $acceptedEvents
     * @return array{bool, bool, bool}
     */
    private function forwardConnectedEvents(array $acceptedEvents, bool $discover = false): array
    {
        if ($this->forwardTransport === null || $this->projectToken === '') {
            return [false, false, false];
        }

        return $this->forwardTransport->sendVerified($this->projectToken, $acceptedEvents, $discover);
    }
}
