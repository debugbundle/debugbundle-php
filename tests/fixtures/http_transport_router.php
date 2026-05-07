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

$captureFile = isset($_GET['capture_file']) && is_string($_GET['capture_file']) ? $_GET['capture_file'] : null;
if ($captureFile !== null && $captureFile !== '') {
    file_put_contents(
        $captureFile,
        json_encode([
            'headers' => $headers,
            'body' => json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR)
    );
}

$status = isset($_GET['status']) ? (int) $_GET['status'] : 202;
$retryAfter = isset($_GET['retry_after']) ? (string) $_GET['retry_after'] : null;

if ($retryAfter !== null && $retryAfter !== '') {
    header('Retry-After: ' . $retryAfter);
}

header('Content-Type: application/json');
http_response_code($status);

echo '{"ok":true}';