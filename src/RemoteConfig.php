<?php

declare(strict_types=1);

namespace DebugBundle;

final class CapturePolicy
{
    public function __construct(
        public readonly string $preset,
        public readonly string $captureLogs,
        public readonly string $captureRequestEvents,
        public readonly string $captureBreadcrumbs,
        public readonly string $captureProbeEvents,
    ) {
    }
}

final class RemoteProbeDirective
{
    public function __construct(
        public readonly string $id,
        public readonly string $labelPattern,
        public readonly string $service,
        public readonly string $environment,
        public readonly string $expiresAt,
    ) {
    }
}

final class RemoteConfigSnapshot
{
    /** @param list<RemoteProbeDirective> $directives */
    public function __construct(
        public readonly bool $probesEnabled,
        public readonly bool $remoteProbesEnabled,
        public readonly array $directives,
        public readonly int $pollIntervalMs,
        public readonly CapturePolicy $capturePolicy,
        public readonly ?string $triggerTokenKey,
    ) {
    }
}

final class RemoteConfig
{
    public const DEFAULT_PROBES_POLL_INTERVAL_MS = 60000;

    public static function balancedCapturePolicy(): CapturePolicy
    {
        return new CapturePolicy(
            'balanced',
            'warning',
            'failures_only',
            'exception_only',
            'buffer_only',
        );
    }

    public static function minimalCapturePolicy(): CapturePolicy
    {
        return new CapturePolicy(
            'minimal',
            'error',
            'failures_only',
            'local_only',
            'buffer_only',
        );
    }

    public static function parseRemoteConfig(mixed $payload, int $fallbackPollIntervalMs, int $nowMs): ?RemoteConfigSnapshot
    {
        if (!is_array($payload)) {
            return null;
        }

        $probesEnabled = ($payload['probes_enabled'] ?? false) === true;
        $remoteProbesEnabled = ($payload['remote_probes_enabled'] ?? false) === true;
        $pollIntervalCandidate = $payload['poll_interval_ms'] ?? null;
        $pollIntervalMs = is_numeric($pollIntervalCandidate) && (int) $pollIntervalCandidate > 0
            ? (int) $pollIntervalCandidate
            : $fallbackPollIntervalMs;

        $directives = [];
        if (isset($payload['active_probes']) && is_array($payload['active_probes'])) {
            foreach ($payload['active_probes'] as $directivePayload) {
                $directive = self::parseDirective($directivePayload);
                if ($directive !== null && self::expiresAtMs($directive->expiresAt) > $nowMs) {
                    $directives[] = $directive;
                }
            }
        }

        $capturePolicy = self::parseCapturePolicy($payload['capture_policy'] ?? null);
        if ($capturePolicy === null) {
            return null;
        }

        $triggerTokenKey = self::asNonEmptyString($payload['trigger_token_key'] ?? null);

        return new RemoteConfigSnapshot(
            $probesEnabled,
            $remoteProbesEnabled,
            $directives,
            $remoteProbesEnabled ? $pollIntervalMs : self::DEFAULT_PROBES_POLL_INTERVAL_MS,
            $capturePolicy,
            $triggerTokenKey,
        );
    }

    /**
     * @param list<RemoteProbeDirective> $directives
     * @return list<RemoteProbeDirective>
     */
    public static function findMatchingRemoteProbeDirectives(
        array $directives,
        string $label,
        string $service,
        string $environment,
        int $nowMs,
    ): array {
        $matches = [];
        foreach ($directives as $directive) {
            if (self::expiresAtMs($directive->expiresAt) <= $nowMs) {
                continue;
            }
            if ($directive->service !== '*' && $directive->service !== $service) {
                continue;
            }
            if ($directive->environment !== '*' && $directive->environment !== $environment) {
                continue;
            }
            if (self::matchesLabelPattern($directive->labelPattern, $label)) {
                $matches[] = $directive;
            }
        }

        return $matches;
    }

    public static function expiresAtMs(string $value): int
    {
        try {
            $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
            $timestamp = new \DateTimeImmutable($normalized);
            return (int) $timestamp->format('U') * 1000;
        } catch (\Exception) {
            return 0;
        }
    }

    private static function parseCapturePolicy(mixed $payload): ?CapturePolicy
    {
        if ($payload === null) {
            return self::balancedCapturePolicy();
        }
        if (!is_array($payload)) {
            return null;
        }

        $preset = self::asNonEmptyString($payload['preset'] ?? null) ?? self::balancedCapturePolicy()->preset;
        $captureLogs = self::asNonEmptyString($payload['capture_logs'] ?? null);
        $captureRequestEvents = self::asNonEmptyString($payload['capture_request_events'] ?? null);
        $captureBreadcrumbs = self::asNonEmptyString($payload['capture_breadcrumbs'] ?? null);
        $captureProbeEvents = self::asNonEmptyString($payload['capture_probe_events'] ?? null);

        if (!in_array($captureLogs, ['off', 'error', 'warning', 'info'], true)) {
            return null;
        }
        if (!in_array($captureRequestEvents, ['off', 'failures_only', 'filtered', 'all'], true)) {
            return null;
        }
        if (!in_array($captureBreadcrumbs, ['local_only', 'exception_only', 'standalone'], true)) {
            return null;
        }
        if (!in_array($captureProbeEvents, ['buffer_only', 'standalone_when_activated'], true)) {
            return null;
        }

        return new CapturePolicy(
            $preset,
            $captureLogs,
            $captureRequestEvents,
            $captureBreadcrumbs,
            $captureProbeEvents,
        );
    }

    private static function parseDirective(mixed $payload): ?RemoteProbeDirective
    {
        if (!is_array($payload)) {
            return null;
        }

        $id = self::asNonEmptyString($payload['id'] ?? null);
        $labelPattern = self::asNonEmptyString($payload['label_pattern'] ?? null);
        $service = self::asNonEmptyString($payload['service'] ?? null);
        $environment = self::asNonEmptyString($payload['environment'] ?? null);
        $expiresAt = self::asNonEmptyString($payload['expires_at'] ?? null);

        if ($id === null || $labelPattern === null || $service === null || $environment === null || $expiresAt === null) {
            return null;
        }
        if (self::expiresAtMs($expiresAt) === 0) {
            return null;
        }

        return new RemoteProbeDirective($id, $labelPattern, $service, $environment, $expiresAt);
    }

    private static function matchesLabelPattern(string $pattern, string $label): bool
    {
        if ($pattern === '*') {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -2);
            return $label === $prefix || str_starts_with($label, $prefix . '.');
        }

        return $pattern === $label;
    }

    private static function asNonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}