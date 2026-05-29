<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Laravel;

use DebugBundle\Relay\BrowserRelayHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DebugBundleRelayMiddleware
{
    private BrowserRelayHandler $handler;

    /** @param array{allowedOrigins?:list<string>,maxBodyBytes?:int,rateLimitPerMinute?:int,onAccept?:callable(\DebugBundle\Relay\BrowserRelayAcceptedBatch):void,projectMode?:string,projectToken?:string,endpoint?:string,localEventsDir?:string,spoolDir?:string,durableWrite?:bool,service?:string,environment?:string,forwardTransport?:\DebugBundle\Transport\TransportInterface,rateLimitStore?:\DebugBundle\Relay\BrowserRelayRateLimitStore} $options */
    public function __construct(array $options = [], private readonly string $routePath = '/debugbundle/browser')
    {
        $this->handler = new BrowserRelayHandler($options);
    }

    public function handle(Request $request, callable $next): mixed
    {
        if ($request->getPathInfo() !== $this->routePath) {
            return $next($request);
        }

        $response = $this->handler->handle([
            'method' => $request->getMethod(),
            'headers' => $this->normalizeHeaders($request->headers->all()),
            'body' => (string) $request->getContent(),
            'ipAddress' => $request->ip(),
        ]);

        return new JsonResponse($response->body, $response->status, $response->headers);
    }

    /**
     * @param array<string, list<string|null>> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $values) {
            $normalized[$key] = implode(', ', array_values(array_filter(
                $values,
                static fn (?string $value): bool => $value !== null
            )));
        }

        return $normalized;
    }
}