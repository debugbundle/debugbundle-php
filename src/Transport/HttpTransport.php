<?php

declare(strict_types=1);

namespace DebugBundle\Transport;

final class HttpTransport implements TransportInterface
{
    public function __construct(private readonly string $endpoint)
    {
    }

    public function send(array $request): TransportResponse
    {
        $headers = [
            'Authorization: Bearer ' . (string) ($request['project_token'] ?? ''),
            'Content-Type: application/json',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode(['events' => $request['events'] ?? []], JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        try {
            @file_get_contents($this->endpoint, false, $context);
        } catch (\Throwable) {
            return new TransportResponse(500, null);
        }

        $statusCode = 500;
        $retryAfterMs = null;

        foreach ($http_response_header as $headerLine) {
            if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $headerLine, $matches) === 1) {
                $statusCode = (int) $matches[1];
                continue;
            }

            if (stripos($headerLine, 'Retry-After:') === 0) {
                $retryAfter = trim(substr($headerLine, strlen('Retry-After:')));
                if (is_numeric($retryAfter)) {
                    $retryAfterMs = (int) round((float) $retryAfter * 1000);
                }
            }
        }

        return new TransportResponse($statusCode, $retryAfterMs);
    }
}