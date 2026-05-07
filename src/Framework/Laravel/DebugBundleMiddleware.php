<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Laravel;

use DebugBundle\DebugBundleSdk;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $this->sdk->beginRequest($normalizedRequest);

        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            $this->sdk->captureException($error, [
                'request' => $normalizedRequest,
            ]);
            throw $error;
        } finally {
            if (!isset($response) || !$response instanceof Response) {
                $this->sdk->endRequest();
            }
        }

        if ($response instanceof Response) {
            $this->sdk->captureRequest(
                $normalizedRequest,
                [
                    'status_code' => $response->getStatusCode(),
                    'duration_ms' => (int) round(($this->now() - $startedAt) * 1000),
                ]
            );
        }

        $this->sdk->endRequest();

        return $response;
    }

    /** @return array<string, mixed> */
    private function normalizeRequest(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'headers' => $request->headers->all(),
        ];
    }

    private function now(): float
    {
        $provider = $this->timeProvider;
        return $provider();
    }
}