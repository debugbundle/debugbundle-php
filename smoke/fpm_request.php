<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use DebugBundle\DebugBundleSdk;
use DebugBundle\Transport\TransportInterface;
use DebugBundle\Transport\TransportResponse;

final class FpmHeldTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function send(array $request): TransportResponse
    {
        usleep(300_000);
        $this->calls[] = $request;
        $count = count($request['events'] ?? []);
        return new TransportResponse(202, null, ['accepted' => $count, 'rejected' => 0, 'errors' => []]);
    }
}

$transport = new FpmHeldTransport();
$sdk = new DebugBundleSdk($transport);
$sdk->init(['projectToken' => 'dbundle_proj_fpm_smoke', 'batchSize' => 2]);
for ($index = 0; $index < 10_000; $index++) {
    $sdk->captureLog('filtered info', 'info');
}
for ($index = 0; $index < 30; $index++) {
    $sdk->captureLog("warning {$index}", 'warning');
}
$sdk->captureException(new RuntimeException('fpm priority exception'));
$inlineCalls = count($transport->calls);

register_shutdown_function(static function () use ($transport, $inlineCalls): void {
    $events = $transport->calls[0]['events'] ?? [];
    header('Content-Type: application/json');
    echo json_encode([
        'inline_calls' => $inlineCalls,
        'request_end_calls' => count($transport->calls),
        'event_types' => array_column($events, 'event_type'),
        'suppressed_count' => array_values(array_filter($events,
            static fn (array $event): bool => $event['event_type'] === 'error_suppressed'))[0]['payload']['suppressed_count'] ?? null,
    ], JSON_THROW_ON_ERROR);
});
