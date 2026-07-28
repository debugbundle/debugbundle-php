<?php

declare(strict_types=1);

namespace DebugBundle;

final class BeforeSend
{
    /** @var array<string, list<string>> */
    private const REQUIRED_PAYLOAD_FIELDS = [
        'backend_exception' => ['name', 'message', 'stack', 'handled', 'request', 'response', 'runtime'],
        'request_event' => ['method', 'path', 'query', 'headers', 'response_status', 'duration_ms'],
        'log_event' => ['level', 'message', 'attributes'],
        'frontend_breadcrumb' => ['breadcrumb_type', 'data'],
        'frontend_exception' => ['name', 'message', 'stack'],
        'deploy_metadata' => ['commit_sha', 'version', 'branch', 'environment', 'deployed_at'],
        'error_suppressed' => ['fingerprint', 'suppressed_count', 'window_seconds', 'first_seen', 'last_seen'],
        'probe_event' => ['label', 'data', 'activation_id', 'probe_label_pattern'],
    ];
    /** @var array<string, list<string>> */
    private const ALLOWED_PAYLOAD_FIELDS = [
        'backend_exception' => ['name', 'message', 'stack', 'handled', 'request', 'response', 'runtime', 'probe_data'],
        'request_event' => ['method', 'path', 'query', 'headers', 'body', 'response_status', 'duration_ms', 'route_template', 'response_headers', 'response_body', 'device'],
        'log_event' => ['level', 'message', 'attributes', 'device'],
        'frontend_breadcrumb' => ['breadcrumb_type', 'route', 'data', 'device'],
        'frontend_exception' => ['name', 'message', 'stack', 'route', 'browser', 'breadcrumbs', 'device', 'browser_event', 'rejection_reason', 'dom_context', 'probe_data'],
        'deploy_metadata' => ['commit_sha', 'version', 'branch', 'environment', 'deployed_at'],
        'error_suppressed' => ['fingerprint', 'suppressed_count', 'window_seconds', 'first_seen', 'last_seen', 'device'],
        'probe_event' => ['label', 'data', 'activation_id', 'probe_label_pattern', 'device'],
    ];
    /** @var list<string> */
    private const ROOT_FIELDS = [
        'schema_version',
        'event_id',
        'event_type',
        'project_token',
        'project_id',
        'sdk_name',
        'sdk_version',
        'service',
        'occurred_at',
        'correlation',
        'context',
        'payload',
    ];

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function apply(array $event, ?\Closure $hook): ?array
    {
        if ($hook === null) {
            return $event;
        }

        try {
            $result = $hook(self::copy($event));
        } catch (\Throwable) {
            return $event;
        }

        if ($result === null) {
            return null;
        }

        return is_array($result) && self::isValid($result) ? $result : $event;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function isValid(array $event): bool
    {
        if (array_diff(array_keys($event), self::ROOT_FIELDS) !== []) {
            return false;
        }
        foreach (['schema_version', 'event_id', 'event_type', 'occurred_at', 'sdk_name', 'sdk_version'] as $field) {
            if (!is_string($event[$field] ?? null) || $event[$field] === '') {
                return false;
            }
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $event['event_id']) !== 1) {
            return false;
        }

        $service = $event['service'] ?? null;
        $payload = $event['payload'] ?? null;
        $eventType = $event['event_type'];
        if (
            !is_array($service)
            || !is_string($service['name'] ?? null)
            || $service['name'] === ''
            || !is_string($service['environment'] ?? null)
            || $service['environment'] === ''
            || !is_array($payload)
            || !isset(self::REQUIRED_PAYLOAD_FIELDS[$eventType])
            || array_diff(array_keys($payload), self::ALLOWED_PAYLOAD_FIELDS[$eventType]) !== []
        ) {
            return false;
        }

        foreach (self::REQUIRED_PAYLOAD_FIELDS[$eventType] as $field) {
            if (!array_key_exists($field, $payload)) {
                return false;
            }
        }
        return self::hasValidPayloadShape($eventType, $payload);
    }

    /** @param array<string, mixed> $payload */
    private static function hasValidPayloadShape(string $eventType, array $payload): bool
    {
        return match ($eventType) {
            'backend_exception' => self::hasNonEmptyStrings($payload, ['name', 'message', 'stack'])
                && is_bool($payload['handled'])
                && is_array($payload['request'])
                && is_array($payload['response'])
                && is_array($payload['runtime'])
                && (!array_key_exists('probe_data', $payload) || is_array($payload['probe_data'])),
            'request_event' => self::hasNonEmptyStrings($payload, ['method', 'path'])
                && is_array($payload['query'])
                && is_array($payload['headers'])
                && self::isNonNegativeNumber($payload['response_status'])
                && self::isNonNegativeNumber($payload['duration_ms'])
                && (!array_key_exists('response_headers', $payload) || is_array($payload['response_headers'])),
            'log_event' => self::hasNonEmptyStrings($payload, ['level', 'message'])
                && is_array($payload['attributes']),
            'frontend_breadcrumb' => self::hasNonEmptyStrings($payload, ['breadcrumb_type'])
                && is_array($payload['data']),
            'frontend_exception' => self::hasNonEmptyStrings($payload, ['name', 'message', 'stack'])
                && (!array_key_exists('breadcrumbs', $payload) || is_array($payload['breadcrumbs']))
                && (!array_key_exists('probe_data', $payload) || is_array($payload['probe_data'])),
            'deploy_metadata' => self::hasNonEmptyStrings(
                $payload,
                ['commit_sha', 'version', 'branch', 'environment']
            ) && self::isTimestamp($payload['deployed_at']),
            'error_suppressed' => self::hasNonEmptyStrings($payload, ['fingerprint'])
                && is_int($payload['suppressed_count'])
                && $payload['suppressed_count'] >= 0
                && is_int($payload['window_seconds'])
                && $payload['window_seconds'] > 0
                && self::isTimestamp($payload['first_seen'])
                && self::isTimestamp($payload['last_seen']),
            'probe_event' => self::hasNonEmptyStrings($payload, ['label', 'probe_label_pattern'])
                && is_array($payload['data'])
                && (
                    $payload['activation_id'] === null
                    || (
                        is_string($payload['activation_id'])
                        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $payload['activation_id']) === 1
                    )
                ),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    private static function hasNonEmptyStrings(array $payload, array $fields): bool
    {
        foreach ($fields as $field) {
            if (!is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                return false;
            }
        }
        return true;
    }

    private static function isNonNegativeNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0;
    }

    private static function isTimestamp(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        try {
            new \DateTimeImmutable($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function copy(array $value): array
    {
        $copy = [];
        foreach ($value as $key => $nested) {
            $copy[$key] = is_array($nested) ? self::copy($nested) : (is_object($nested) ? clone $nested : $nested);
        }
        return $copy;
    }
}
