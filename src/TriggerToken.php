<?php

declare(strict_types=1);

namespace DebugBundle;

final class TriggerToken
{
    private const HEADER_NAME = 'x-debugbundle-probe-trigger';
    private const QUERY_PARAMETER_NAME = '_debug_probe';
    private const TOKEN_PREFIX = 'dbundle_probe_';

    /**
     * @param array<string, mixed>|null $request
     * @return list<RemoteProbeDirective>
     */
    public static function resolveRequestTriggerDirectives(?array $request, ?string $triggerTokenKey, int $nowMs): array
    {
        if ($request === null || $triggerTokenKey === null || $triggerTokenKey === '') {
            return [];
        }

        $token = self::extractTriggerToken($request);
        if ($token === null || !str_starts_with($token, self::TOKEN_PREFIX)) {
            return [];
        }

        $encoded = substr($token, strlen(self::TOKEN_PREFIX));
        $separatorIndex = strpos($encoded, '.');
        if ($separatorIndex === false || $separatorIndex <= 0 || $separatorIndex === strlen($encoded) - 1) {
            return [];
        }

        $payloadSegment = substr($encoded, 0, $separatorIndex);
        $signatureSegment = substr($encoded, $separatorIndex + 1);
        if (!self::hasValidSignature($payloadSegment, $signatureSegment, $triggerTokenKey)) {
            return [];
        }

        $payload = self::decodePayloadSegment($payloadSegment);
        if ($payload === null || RemoteConfig::expiresAtMs($payload['trigger_expires_at']) <= $nowMs) {
            return [];
        }

        return [new RemoteProbeDirective(
            $payload['activation_id'],
            $payload['label_pattern'],
            $payload['service'],
            $payload['environment'],
            $payload['trigger_expires_at'],
        )];
    }

    /** @param array<string, mixed> $request */
    private static function extractTriggerToken(array $request): ?string
    {
        $headers = isset($request['headers']) && is_array($request['headers']) ? $request['headers'] : [];
        $headerToken = self::extractMapValue($headers, self::HEADER_NAME, true);
        if ($headerToken !== null) {
            return $headerToken;
        }

        $query = isset($request['query']) && is_array($request['query']) ? $request['query'] : [];

        return self::extractMapValue($query, self::QUERY_PARAMETER_NAME, false);
    }

    /** @return array{activation_id: string, label_pattern: string, service: string, environment: string, trigger_expires_at: string}|null */
    private static function decodePayloadSegment(string $payloadSegment): ?array
    {
        try {
            $decoded = self::base64UrlDecode($payloadSegment);
            if ($decoded === null) {
                return null;
            }

            $parsed = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($parsed)) {
            return null;
        }

        $activationId = self::asNonEmptyString($parsed['activation_id'] ?? null);
        $labelPattern = self::asNonEmptyString($parsed['label_pattern'] ?? null);
        $service = self::asNonEmptyString($parsed['service'] ?? null);
        $environment = self::asNonEmptyString($parsed['environment'] ?? null);
        $expiresAt = self::asNonEmptyString($parsed['trigger_expires_at'] ?? null);

        if ($activationId === null || $labelPattern === null || $service === null || $environment === null || $expiresAt === null) {
            return null;
        }

        if (RemoteConfig::expiresAtMs($expiresAt) === 0) {
            return null;
        }

        return [
            'activation_id' => $activationId,
            'label_pattern' => $labelPattern,
            'service' => $service,
            'environment' => $environment,
            'trigger_expires_at' => $expiresAt,
        ];
    }

    private static function hasValidSignature(string $payloadSegment, string $signatureSegment, string $triggerTokenKey): bool
    {
        $expected = hash_hmac('sha256', $payloadSegment, $triggerTokenKey, true);
        $actual = self::base64UrlDecode($signatureSegment);
        if ($actual === null || strlen($expected) !== strlen($actual)) {
            return false;
        }

        return hash_equals($expected, $actual);
    }

    /**
     * @param array<string, mixed> $map
     */
    private static function extractMapValue(array $map, string $key, bool $caseInsensitive): ?string
    {
        foreach ($map as $candidateKey => $value) {
            $matches = $caseInsensitive
                ? strtolower($candidateKey) === strtolower($key)
                : $candidateKey === $key;
            if (!$matches) {
                continue;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry) && $entry !== '') {
                        return $entry;
                    }
                }
            }
        }

        return null;
    }

    private static function asNonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}