<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkPolicySupport
{
    private function effectiveLogThreshold(): string
    {
        return (self::LEVEL_RANKS[$this->capturePolicy->captureLogs] ?? 0) > (self::LEVEL_RANKS[$this->logLevel] ?? 0)
            ? $this->capturePolicy->captureLogs
            : $this->logLevel;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $response
     */
    private function shouldCaptureRequestEvent(array $request, ?array $response): bool
    {
        $statusCode = isset($response['status_code']) && is_numeric($response['status_code']) ? (int) $response['status_code'] : null;
        $requestPath = is_string($request['path'] ?? null) ? $request['path'] : (is_string($request['url'] ?? null) ? $request['url'] : null);
        $httpMethod = is_string($request['method'] ?? null) ? $request['method'] : null;
        if ($this->isImmediateRequestIncidentStatus($statusCode, $requestPath, $httpMethod)) {
            return true;
        }

        return match ($this->capturePolicy->captureRequestEvents) {
            'off' => false,
            'failures_only' => $statusCode !== null && $statusCode >= 500,
            'filtered' => false,
            default => true,
        };
    }

    private function isImmediateRequestIncidentStatus(?int $statusCode, ?string $requestPath = null, ?string $httpMethod = null): bool
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
        if ($this->matchesImmediateClientErrorPathRule($statusCode, $requestPath, $httpMethod)) {
            return true;
        }

        return match ($this->capturePolicy->preset) {
            'investigative' => in_array($statusCode, self::INVESTIGATIVE_IMMEDIATE_REQUEST_STATUSES, true),
            'balanced' => in_array($statusCode, self::BALANCED_IMMEDIATE_REQUEST_STATUSES, true),
            default => false,
        };
    }

    private function matchesImmediateClientErrorPathRule(int $statusCode, ?string $requestPath, ?string $httpMethod): bool
    {
        if ($statusCode < 400 || $statusCode > 499 || $requestPath === null) {
            return false;
        }

        $normalizedPath = $this->normalizeRequestPath($requestPath);
        $normalizedMethod = $httpMethod === null ? null : strtoupper($httpMethod);
        foreach ($this->capturePolicy->immediateClientErrorPathRules as $rule) {
            if ($rule->statusCode !== $statusCode) {
                continue;
            }
            if ($rule->methods !== [] && ($normalizedMethod === null || !in_array($normalizedMethod, $rule->methods, true))) {
                continue;
            }
            if (str_ends_with($rule->pathPattern, '*')) {
                if (str_starts_with($normalizedPath, substr($rule->pathPattern, 0, -1))) {
                    return true;
                }
            } elseif ($normalizedPath === $rule->pathPattern) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRequestPath(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            return $path;
        }
        $withoutQuery = explode('?', $value, 2)[0];
        $withoutFragment = explode('#', $withoutQuery, 2)[0];
        return str_starts_with($withoutFragment, '/') ? $withoutFragment : '/';
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
}
