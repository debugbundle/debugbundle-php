<?php

declare(strict_types=1);

namespace DebugBundle;

final class CorrelationContext
{
    private const TRACE_ID_HEADER = 'x-debugbundle-trace-id';
    private const REQUEST_ID_HEADERS = ['x-request-id', 'x-correlation-id'];

    /**
     * @param array<string, mixed>|null $request
     * @return array<string, string>
     */
    public static function resolveRequestCorrelation(?array $request): array
    {
        if (!is_array($request)) {
            return [];
        }

        $headers = isset($request['headers']) && is_array($request['headers']) ? $request['headers'] : [];
        if (count($headers) > 256) return [];
        $correlation = [];

        $traceId = self::extractHeaderValue($headers, self::TRACE_ID_HEADER);
        if ($traceId !== null) {
            $correlation['trace_id'] = $traceId;
        }

        foreach (self::REQUEST_ID_HEADERS as $headerName) {
            $requestId = self::extractHeaderValue($headers, $headerName);
            if ($requestId !== null) {
                $correlation['request_id'] = $requestId;
                break;
            }
        }

        return $correlation;
    }

    /** @param array<array-key, mixed> $headers */
    private static function extractHeaderValue(array $headers, string $headerName): ?string
    {
        foreach ($headers as $candidateKey => $value) {
            if (!is_string($candidateKey) || strlen($candidateKey) > 128 || strtolower($candidateKey) !== $headerName) {
                continue;
            }

            if (self::safeValue($value)) {
                return $value;
            }

            if (is_array($value) && count($value) <= 16) {
                foreach ($value as $entry) {
                    if (self::safeValue($entry)) {
                        return $entry;
                    }
                }
            }
        }

        return null;
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function safeValue(mixed $value): bool
    {
        if (!is_string($value) || $value === '' || strlen($value) > 512) return false;
        try { return TelemetryPrivacy::protect($value) === $value; }
        catch (\Throwable) { return false; }
    }
}
