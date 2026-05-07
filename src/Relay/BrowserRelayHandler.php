<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

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
        'probe_event',
    ];

    /** @var list<string> */
    private array $allowedOrigins;

    private int $maxBodyBytes;
    private int $rateLimitPerMinute;

    /** @var \Closure(BrowserRelayAcceptedBatch): void */
    private \Closure $onAccept;

    /** @var array<string, list<int>> */
    private array $rateLimitState = [];

    /** @param array{allowedOrigins?:list<string>,maxBodyBytes?:int,rateLimitPerMinute?:int,onAccept?:callable(BrowserRelayAcceptedBatch):void} $options */
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
    }

    /** @param array{method?:string,headers?:array<string,string>,body:string,ipAddress?:string|null} $request */
    public function handle(array $request): BrowserRelayResponse
    {
        $method = strtoupper((string) ($request['method'] ?? 'POST'));
        if ($method !== 'POST') {
            return new BrowserRelayResponse(405);
        }

        $headers = $this->normalizeHeaders($request['headers'] ?? []);
        if (!$this->isOriginAllowed($headers)) {
            return new BrowserRelayResponse(403);
        }

        if (!$this->isSupportedContentType($headers['content-type'] ?? null)) {
            return new BrowserRelayResponse(400, [
                'accepted' => 0,
                'rejected' => 0,
                'errors' => ['Relay requests must use Content-Type: application/json.'],
            ]);
        }

        $body = $request['body'];
        if (strlen($body) > $this->maxBodyBytes) {
            return new BrowserRelayResponse(413);
        }

        $ipAddress = $request['ipAddress'] ?? null;
        if ($this->isRateLimited($ipAddress)) {
            return new BrowserRelayResponse(429);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new BrowserRelayResponse(400, [
                'accepted' => 0,
                'rejected' => 0,
                'errors' => ['Relay request body must be valid JSON.'],
            ]);
        }

        $batch = $decoded['batch'] ?? null;
        if (!is_array($batch)) {
            return new BrowserRelayResponse(400, [
                'accepted' => 0,
                'rejected' => 0,
                'errors' => ['Relay request body must include a batch array.'],
            ]);
        }

        $acceptedEvents = [];
        $errors = [];

        foreach (array_values($batch) as $index => $candidate) {
            if (!is_array($candidate)) {
                $errors[] = sprintf('batch[%d]: Relay events must be objects.', $index);
                continue;
            }

            $eventType = $candidate['event_type'] ?? null;
            if (!is_string($eventType) || !in_array($eventType, self::ACCEPTED_EVENT_TYPES, true)) {
                $errors[] = sprintf('batch[%d]: Unsupported browser relay event type %s.', $index, is_string($eventType) ? $eventType : 'unknown');
                continue;
            }

            $sanitized = $this->sanitizeEvent($candidate);
            if ($sanitized === null) {
                $errors[] = sprintf('batch[%d]: Invalid browser relay event payload.', $index);
                continue;
            }

            $acceptedEvents[] = $sanitized;
        }

        if ($acceptedEvents !== []) {
            $callback = $this->onAccept;
            $callback(new BrowserRelayAcceptedBatch(
                $acceptedEvents,
                $this->stripSensitiveHeaders($headers),
                $ipAddress,
                gmdate('Y-m-d\\TH:i:s') . 'Z',
            ));
        }

        if ($errors !== []) {
            return new BrowserRelayResponse(400, [
                'accepted' => count($acceptedEvents),
                'rejected' => count($errors),
                'errors' => $errors,
            ]);
        }

        return new BrowserRelayResponse(202, [
            'accepted' => count($acceptedEvents),
            'rejected' => 0,
            'errors' => [],
        ]);
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

    private function isRateLimited(?string $ipAddress): bool
    {
        $key = $ipAddress ?? 'unknown';
        $now = time();
        $windowStart = $now - 60;
        $timestamps = array_values(array_filter(
            $this->rateLimitState[$key] ?? [],
            static fn (int $timestamp): bool => $timestamp > $windowStart
        ));

        if (count($timestamps) >= $this->rateLimitPerMinute) {
            $this->rateLimitState[$key] = $timestamps;
            return true;
        }

        $timestamps[] = $now;
        $this->rateLimitState[$key] = $timestamps;
        return false;
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

        $serviceName = $service['name'] ?? null;
        $environment = $service['environment'] ?? null;
        if (!is_string($serviceName) || $serviceName === '' || !is_string($environment) || $environment === '') {
            return null;
        }

        $correlation = [];
        if (isset($event['correlation']) && is_array($event['correlation'])) {
            $traceId = $event['correlation']['trace_id'] ?? null;
            if (is_string($traceId) || $traceId === null) {
                $correlation['trace_id'] = $traceId;
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

        if ($correlation !== []) {
            $sanitized['correlation'] = $correlation;
        }

        return $sanitized;
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
}