<?php

declare(strict_types=1);

require dirname(__DIR__) . '/evidence_runtime_support.php';
loadEvidenceRuntimeSdk(($argv[2] ?? '') !== '' ? $argv[2] : null);

$destination = $argv[1];
$transport = new class($destination) implements \DebugBundle\Transport\TransportInterface {
    public function __construct(private string $path) {}
    public function send(array $request): \DebugBundle\Transport\TransportResponse
    {
        file_put_contents($this->path, json_encode($request['events'], JSON_THROW_ON_ERROR));
        return new \DebugBundle\Transport\TransportResponse(202, null, ['accepted' => count($request['events']), 'rejected' => 0, 'errors' => []]);
    }
};
$sdk = new \DebugBundle\DebugBundleSdk($transport);
$sdk->init(['projectToken' => 'synthetic-project', 'evidenceCapabilities' => ['version' => 1, 'features' => ['exception_evidence_v1']],
    'beforeSend' => static function (): never { throw new RuntimeException('fatal path must bypass this hook'); }]);
$sdk->beginRequest(['route_template' => '/synthetic/{id}']);

function debugbundleEvidenceNativeFatal(): void
{
    // Duplicate declarations are an actual engine fatal rather than a new Throwable.
    eval('function debugbundleEvidenceDuplicate() {} function debugbundleEvidenceDuplicate() {}');
}
debugbundleEvidenceNativeFatal();
