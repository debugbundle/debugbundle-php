<?php

declare(strict_types=1);

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (!is_string($value) || !str_starts_with($key, 'HTTP_')) {
        continue;
    }

    $normalized = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
    $headers[$normalized] = $value;
}

if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
    $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
}

$rawBody = (string) file_get_contents('php://input');
$body = $rawBody === '' ? [] : json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
$captureBatches = $_GET['capture_batches_file'] ?? null;
if (is_string($captureBatches)) file_put_contents($captureBatches, json_encode(['bytes' => strlen($rawBody), 'body' => $body, 'wire_body' => $rawBody], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$captureFile = isset($_GET['capture_file']) && is_string($_GET['capture_file']) ? $_GET['capture_file'] : null;
if ($captureFile !== null && $captureFile !== '') {
    file_put_contents(
        $captureFile,
        json_encode([
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'headers' => $headers,
            'body' => $body,
        ], JSON_THROW_ON_ERROR)
    );
}

if (($_GET['canonical_ack'] ?? null) === '1') {
    header('Content-Type: application/json');
    http_response_code(strlen($rawBody) <= 262144 ? 202 : 413);
    echo json_encode(['accepted' => count($body['events'] ?? []), 'rejected' => 0, 'errors' => []], JSON_THROW_ON_ERROR);
    return;
}

$status = isset($_GET['status']) ? (int) $_GET['status'] : 202;
$delayMs = isset($_GET['delay_ms']) ? min(3000, max(0, (int) $_GET['delay_ms'])) : 0;
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}
$retryAfter = isset($_GET['retry_after']) ? (string) $_GET['retry_after'] : null;

if ($retryAfter !== null && $retryAfter !== '') {
    header('Retry-After: ' . $retryAfter);
}

header('Content-Type: application/json');
http_response_code($status);

if (isset($_GET['chunked_ack'])) {
    header('Transfer-Encoding: chunked');
    $ack = '{"accepted":0,"rejected":0,"errors":[]}';
    $chunks = isset($_GET['chunk_delay_ms']) ? str_split($ack, 1) : [$ack];
    foreach ($chunks as $chunk) {
        echo dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        if (isset($_GET['chunk_delay_ms'])) {
            if (ob_get_level() > 0) ob_flush();
            flush();
            usleep(50000);
        }
    }
    echo "0\r\n\r\n";
    return;
}

if (isset($_GET['response_bytes'])) {
    $bytes = min(300000, max(0, (int) $_GET['response_bytes']));
    echo str_repeat(' ', $bytes);
    return;
}
if (isset($_GET['body_chunk_delay_ms'])) {
    for ($n = 0; $n < 20; $n++) {
        echo ' ';
        if (ob_get_level() > 0) ob_flush();
        flush();
        usleep(min(100, max(1, (int) $_GET['body_chunk_delay_ms'])) * 1000);
    }
    return;
}
echo isset($_GET['response_body']) && is_string($_GET['response_body']) ? $_GET['response_body'] : '{"ok":true}';
