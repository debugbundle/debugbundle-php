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

        $stream = @fopen($this->endpoint, 'rb', false, $context);
        if ($stream === false) {
            return new TransportResponse(500, null);
        }

        $statusCode = 500;
        $retryAfterMs = null;
        $metadata = stream_get_meta_data($stream);
        $responseBody = @stream_get_contents($stream);
        fclose($stream);
        $responseHeaders = self::normalizeResponseHeaders($metadata['wrapper_data'] ?? []);

        foreach ($responseHeaders as $headerLine) {
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

        $decodedBody = null;
        if (is_string($responseBody) && $responseBody !== '') {
            try {
                $decodedBody = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decodedBody = null;
            }
        }

        return new TransportResponse($statusCode, $retryAfterMs, $decodedBody);
    }

    /** @return list<string> */
    private static function normalizeResponseHeaders(mixed $headers): array
    {
        if (is_string($headers)) {
            return [$headers];
        }

        if (is_array($headers)) {
            return array_values(array_filter($headers, static fn (mixed $header): bool => is_string($header)));
        }

        return [];
    }
}
