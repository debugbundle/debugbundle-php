<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Laravel;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\HttpRateLimitContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DebugBundleMiddleware
{
    /** @var \Closure(): float */
    private \Closure $timeProvider;

    public function __construct(DebugBundleSdk $sdk, ?callable $timeProvider = null)
    {
        $this->sdk = $sdk;
        $this->timeProvider = $timeProvider instanceof \Closure
            ? $timeProvider
            : \Closure::fromCallable($timeProvider ?? static fn (): float => microtime(true));
    }

    private DebugBundleSdk $sdk;

    public function handle(Request $request, callable $next): mixed
    {
        $startedAt = $this->now();
        $normalizedRequest = $this->normalizeRequest($request);
        $this->sdk->beginRequest($normalizedRequest, $request);

        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            $postRoutingRequest = $this->normalizeRequest($request);
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 429) {
                $this->captureRateLimitRequest(
                    $request,
                    $postRoutingRequest,
                    $error->getHeaders(),
                    (int) round(($this->now() - $startedAt) * 1000)
                );
            } else {
                $this->sdk->captureException($error, [
                    'request' => $postRoutingRequest,
                ]);
            }
            throw $error;
        } finally {
            if (!isset($response) || !$response instanceof Response) {
                $this->sdk->endRequest($request);
            }
        }

        if ($response instanceof Response) {
            $postRoutingRequest = $this->normalizeRequest($request);
            $statusCode = $response->getStatusCode();
            $durationMs = (int) round(($this->now() - $startedAt) * 1000);
            if ($statusCode === 429) {
                $this->captureRateLimitRequest($request, $postRoutingRequest, $response->headers->all(), $durationMs);
            } else {
                $responseHeaders = HttpRateLimitContext::extractAllowedResponseHeaders($response->headers->all());
                $this->sdk->captureRequest(
                    $postRoutingRequest,
                    [
                        'status_code' => $statusCode,
                        'duration_ms' => $durationMs,
                        ...($responseHeaders !== [] ? ['response_headers' => $responseHeaders] : []),
                    ]
                );
            }
        }

        $this->sdk->endRequest($request);

        return $response;
    }

    /**
     * @param array<string, mixed> $normalizedRequest
     * @param array<int|string, mixed> $rawHeaders
     */
    private function captureRateLimitRequest(
        Request $request,
        array $normalizedRequest,
        array $rawHeaders,
        int $durationMs
    ): void {
        $responseHeaders = HttpRateLimitContext::extractAllowedResponseHeaders($rawHeaders);
        $limiter = HttpRateLimitContext::extractLaravelRateLimiter($request);
        $this->sdk->captureRequest(
            $normalizedRequest,
            [
                'status_code' => 429,
                'duration_ms' => $durationMs,
                ...($responseHeaders !== [] ? ['response_headers' => $responseHeaders] : []),
            ],
            $limiter !== null ? ['rate_limiter' => $limiter] : null
        );
    }

    /** @return array<string, mixed> */
    private function normalizeRequest(Request $request): array
    {
        $routeTemplate = HttpRateLimitContext::extractLaravelRouteTemplate($request);
        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'headers' => $request->headers->all(),
            ...($routeTemplate !== null ? ['route_template' => $routeTemplate] : []),
        ];
    }

    private function now(): float
    {
        $provider = $this->timeProvider;
        return $provider();
    }
}
