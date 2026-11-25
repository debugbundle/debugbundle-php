<?php

declare(strict_types=1);

namespace DebugBundle\Framework;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class HttpRateLimitContext
{
    public const ALLOWED_RESPONSE_HEADERS = [
        'retry-after',
        'x-ratelimit-limit',
        'x-ratelimit-remaining',
        'x-ratelimit-reset',
    ];

    private const MAX_ROUTE_TEMPLATE_LENGTH = 256;
    private const MAX_HEADER_VALUE_LENGTH = 128;
    private const MAX_RATE_LIMITER_LENGTH = 64;

    public static function normalizeRouteTemplate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            return null;
        }
        if (str_contains($trimmed, '?')) {
            $trimmed = explode('?', $trimmed, 2)[0];
        }
        if (str_contains($trimmed, '#')) {
            $trimmed = explode('#', $trimmed, 2)[0];
        }
        $trimmed = trim($trimmed);
        if ($trimmed === '') {
            return null;
        }
        if (!str_starts_with($trimmed, '/')) {
            $trimmed = '/' . $trimmed;
        }
        if (strlen($trimmed) > self::MAX_ROUTE_TEMPLATE_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_ROUTE_TEMPLATE_LENGTH);
        }
        return $trimmed;
    }

    /**
     * @param array<int|string, mixed> $headers
     * @return array<string, string>
     */
    public static function extractAllowedResponseHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $rawValue) {
            if (!is_string($name)) {
                continue;
            }
            $normalizedName = strtolower(trim($name));
            if (!in_array($normalizedName, self::ALLOWED_RESPONSE_HEADERS, true)) {
                continue;
            }
            $value = self::normalizeHeaderScalar($rawValue);
            if ($value !== null) {
                $filtered[$normalizedName] = $value;
            }
        }
        ksort($filtered);
        return $filtered;
    }

    public static function extractLaravelRouteTemplate(object $request): ?string
    {
        if (!method_exists($request, 'route')) {
            return null;
        }
        try {
            $route = $request->route();
        } catch (\Throwable) {
            return null;
        }
        if (!is_object($route) || !method_exists($route, 'uri')) {
            return null;
        }
        try {
            return self::normalizeRouteTemplate($route->uri());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function extractLaravelRateLimiter(object $request): ?string
    {
        if (!method_exists($request, 'route')) {
            return null;
        }
        try {
            $route = $request->route();
        } catch (\Throwable) {
            return null;
        }
        if (!is_object($route)) {
            return null;
        }

        $middlewares = [];
        foreach (['gatherMiddleware', 'middleware'] as $method) {
            if (!method_exists($route, $method)) {
                continue;
            }
            try {
                $candidate = $route->{$method}();
                if (is_array($candidate)) {
                    $middlewares = $candidate;
                    break;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $namedLimiters = [];
        foreach ($middlewares as $middleware) {
            if (!is_string($middleware)) {
                continue;
            }
            if (
                preg_match(
                    '/^(?:throttle|Illuminate\\\\Routing\\\\Middleware\\\\ThrottleRequests|Illuminate\\\\Routing\\\\Middleware\\\\ThrottleRequestsWithRedis):([A-Za-z][A-Za-z0-9._-]{0,63})$/',
                    trim($middleware),
                    $matches
                ) === 1
            ) {
                $namedLimiters[$matches[1]] = true;
            }
        }

        if (count($namedLimiters) !== 1) {
            return null;
        }
        $limiter = (string) array_key_first($namedLimiters);
        return strlen($limiter) <= self::MAX_RATE_LIMITER_LENGTH ? $limiter : null;
    }

    public static function extractSymfonyRouteTemplate(SymfonyRequest $request): ?string
    {
        foreach (['_debugbundle_route_template', '_route_pattern', '_route'] as $attribute) {
            $value = $request->attributes->get($attribute);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $trimmed = trim($value);
            if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
                continue;
            }
            if (strlen($trimmed) > self::MAX_ROUTE_TEMPLATE_LENGTH) {
                $trimmed = substr($trimmed, 0, self::MAX_ROUTE_TEMPLATE_LENGTH);
            }
            return $trimmed;
        }
        return null;
    }

    private static function normalizeHeaderScalar(mixed $rawValue): ?string
    {
        $candidate = null;
        if (is_array($rawValue)) {
            foreach ($rawValue as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $trimmed = trim((string) $item);
                    if ($trimmed !== '') {
                        $candidate = $trimmed;
                        break;
                    }
                }
            }
        } elseif (is_string($rawValue) || is_int($rawValue) || is_float($rawValue)) {
            $trimmed = trim((string) $rawValue);
            if ($trimmed !== '') {
                $candidate = $trimmed;
            }
        }

        if ($candidate === null || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }
        if (strlen($candidate) > self::MAX_HEADER_VALUE_LENGTH) {
            $candidate = substr($candidate, 0, self::MAX_HEADER_VALUE_LENGTH);
        }
        return $candidate;
    }
}
