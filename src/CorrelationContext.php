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

    /** @param array<string, mixed> $headers */
    private static function extractHeaderValue(array $headers, string $headerName): ?string
    {
        foreach ($headers as $candidateKey => $value) {
            if (strtolower($candidateKey) !== $headerName) {
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
}