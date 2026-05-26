<?php

declare(strict_types=1);

$captureFile = $_SERVER['DEBUGBUNDLE_CAPTURE_FILE'] ?? getenv('DEBUGBUNDLE_CAPTURE_FILE') ?: null;
if ($captureFile === null) {
    http_response_code(500);
    echo '{"error":"missing capture file"}';
    return;
}

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

$body = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents(
    $captureFile,
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path' => $_SERVER['REQUEST_URI'] ?? '/',
        'headers' => $headers,
        'body' => $body,
    ], JSON_THROW_ON_ERROR) . PHP_EOL,
    FILE_APPEND
);

header('Content-Type: application/json');
http_response_code(202);
echo '{"accepted":true}';