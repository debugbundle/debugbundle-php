<?php

declare(strict_types=1);

namespace DebugBundle\Transport;

use DebugBundle\EvidenceWire;
use DebugBundle\IngestionWire;

final class HttpTransport implements TransportInterface
{
    private const MAX_RESPONSE_BYTES = 262144;
    public function __construct(private readonly string $endpoint)
    {
    }

    public function send(array $request): TransportResponse
    {
        return $this->sendUntil($request, hrtime(true) + 250_000_000);
    }

    /** @param array<string, mixed> $request */
    public function sendUntil(array $request, int $deadline): TransportResponse
    {
        $remaining = min(250_000_000, $deadline - hrtime(true));
        if ($remaining <= 0) return new TransportResponse(500, null);
        $body = IngestionWire::body($request['events'] ?? []);
        if (strlen($body) > IngestionWire::MAX_BODY_BYTES) return new TransportResponse(413, null);
        $headers = [
            'Authorization: Bearer ' . (string) ($request['project_token'] ?? ''),
            'Content-Type: application/json',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => $remaining / 1_000_000_000,
            ],
        ]);

        $endpoint = ($request['evidence_discovery'] ?? false) === true ? EvidenceWire::discoveryUrl($this->endpoint) : $this->endpoint;
        $stream = @fopen($endpoint, 'rb', false, $context);
        if ($stream === false) {
            return new TransportResponse(500, null);
        }

        $statusCode = 500;
        $retryAfterMs = null;
        $metadata = stream_get_meta_data($stream);
        try { $responseBody = self::readBody($stream, $deadline); }
        finally { fclose($stream); }
        $responseHeaders = self::normalizeResponseHeaders($metadata['wrapper_data'] ?? []);

        foreach ($responseHeaders as $headerLine) {
            if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $headerLine, $matches) === 1) {
                $statusCode = (int) $matches[1];
                continue;
            }

            if (stripos($headerLine, 'Retry-After:') === 0) {
                $retryAfter = trim(substr($headerLine, strlen('Retry-After:')));
                $retryAfterMs = RetryAfter::parse($retryAfter);
            }
        }

        $decodedBody = null;
        if (is_string($responseBody) && $responseBody !== '') {
            try {
                $decodedBody = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                $wireBody = json_decode($responseBody, false, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedBody) && array_key_exists('errors', $decodedBody)) {
                    // Associative decoding loses the distinction between [] and {} (or numeric-key objects).
                    if ($wireBody instanceof \stdClass && !is_array($wireBody->errors)) {
                        $decodedBody = null;
                    }
                }
                if (is_array($decodedBody) && array_key_exists('evidence_capabilities', $decodedBody)
                    && (!$wireBody instanceof \stdClass || !($wireBody->evidence_capabilities ?? null) instanceof \stdClass
                        || !is_array($wireBody->evidence_capabilities->features ?? null))) {
                    $decodedBody['evidence_capabilities'] = null;
                }
            } catch (\JsonException) {
                $decodedBody = null;
            }
        }

        return new TransportResponse($statusCode, $retryAfterMs, $decodedBody);
    }

    /** @param resource $stream */
    private static function readBody($stream, int $deadline): ?string
    {
        if (!stream_set_blocking($stream, false)) return null;
        $body = '';
        while (!feof($stream)) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) return null;
            // PHP's chunk-decoding HTTP stream cannot always be cast for
            // stream_select. Read its nonblocking buffer directly; an empty
            // read yields briefly under the original whole-response deadline.
            $chunk = @fread($stream, max(1, min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body))));
            if ($chunk === false) return null;
            $body .= $chunk;
            if (strlen($body) > self::MAX_RESPONSE_BYTES) return null;
            if ($chunk === '' && !feof($stream)) usleep(min(1000, max(1, (int) (($deadline - hrtime(true)) / 1000))));
        }
        return $body;
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
