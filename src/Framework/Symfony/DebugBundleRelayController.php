<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Symfony;

use DebugBundle\Relay\BrowserRelayHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DebugBundleRelayController
{
    private BrowserRelayHandler $handler;

    /** @param array{allowedOrigins?:list<string>,maxBodyBytes?:int,rateLimitPerMinute?:int,onAccept?:callable(\DebugBundle\Relay\BrowserRelayAcceptedBatch):void} $options */
    public function __construct(array $options = [])
    {
        $this->handler = new BrowserRelayHandler($options);
    }

    public function __invoke(Request $request): Response
    {
        $response = $this->handler->handle([
            'method' => $request->getMethod(),
            'headers' => $this->normalizeHeaders($request->headers->all()),
            'body' => (string) $request->getContent(),
            'ipAddress' => $request->getClientIp(),
        ]);

        return new JsonResponse($response->body, $response->status);
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