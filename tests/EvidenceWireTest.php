<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\EvidenceWire;
use DebugBundle\EvidenceJson;
use DebugBundle\ExceptionEvidence;
use PHPUnit\Framework\TestCase;

final class EvidenceWireTest extends TestCase
{
    public function testCountsEvidenceUsingAcceptingJsonNumberAndUtf8Forms(): void
    {
        /** @var array{json_sizes:list<array{value:mixed,bytes:int}>} $corpus */
        $corpus = json_decode((string) file_get_contents(__DIR__ . '/fixtures/evidence-wire-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($corpus['json_sizes'] as $fixture) self::assertSame($fixture['bytes'], EvidenceJson::bytes($fixture['value']));
        self::assertGreaterThan(32768, EvidenceJson::bytes(INF));
        self::assertGreaterThan(32768, EvidenceJson::bytes(new \stdClass()));
        self::assertGreaterThan(32768, EvidenceJson::bytes("\xff"));
        self::assertGreaterThan(32768, EvidenceJson::bytes(array_fill(0, 257, null)));
        $deep = null;
        for ($index = 0; $index < 18; $index++) $deep = [$deep];
        self::assertGreaterThan(32768, EvidenceJson::bytes($deep));
        self::assertGreaterThan(32768, EvidenceJson::bytes(array_fill(0, 20, array_fill(0, 256, null))));
    }
    public function testMatchesSharedSemanticEvidenceAndCapabilityCorpus(): void
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/evidence-wire-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var array{events:list<array{id:string,valid:bool,event:array<string,mixed>}>,capabilities:list<array{id:string,features:list<string>,input:mixed}>} $corpus */
        $corpus = $decoded;
        foreach ($corpus['events'] as $fixture) self::assertSame($fixture['valid'], EvidenceWire::optionalEvidence($fixture['event']), $fixture['id']);
        foreach ($corpus['capabilities'] as $fixture) self::assertSame($fixture['features'], EvidenceWire::descriptor($fixture['input'])['features'], $fixture['id']);
    }
    public function testValidatesNativeGraphAndRejectsCyclesUnknownFieldsAndBadCoordinates(): void
    {
        $graph = ExceptionEvidence::snapshot(new \Exception('outer', 0, new \Exception('inner')));
        self::assertTrue(EvidenceWire::graph($graph));
        $bad = $graph; $bad['nodes'][1]['relationships'][] = ['kind' => 'cause', 'target_id' => 0];
        self::assertFalse(EvidenceWire::graph($bad));
        $bad = $graph; $bad['nodes'][0]['frames'][0]['line'] = 0;
        self::assertFalse(EvidenceWire::graph($bad));
        $bad = $graph; $bad['raw_object'] = new \stdClass();
        self::assertFalse(EvidenceWire::graph($bad));
    }
    public function testCapabilitiesFailClosedAndUseCanonicalOrder(): void
    {
        self::assertSame(['version' => 1, 'features' => ['exception_evidence_v1', 'recovery_v1']], EvidenceWire::descriptor(['version' => 1.0, 'features' => ['recovery_v1', 'exception_evidence_v1']]));
        foreach ([null, ['version' => true, 'features' => ['recovery_v1']], ['version' => 2, 'features' => ['recovery_v1']],
            ['version' => 1, 'features' => ['future']], ['version' => 1, 'features' => ['recovery_v1', 'recovery_v1']]] as $bad)
            self::assertSame([], EvidenceWire::descriptor($bad)['features']);
    }
    public function testClosedRecoveryHasNoArbitraryDataAndRequiresFreshScopedIds(): void
    {
        $local = ['failureRef' => '00000000-0000-4000-8000-000000000001', 'evidenceScope' => ['kind' => 'operation', 'operation_id' => '00000000-0000-4000-8000-000000000002'],
            'attemptId' => '00000000-0000-4000-8000-000000000003', 'state' => 'attempt_completed', 'statusCode' => 200];
        $payload = EvidenceWire::recovery($local);
        self::assertNotNull($payload); self::assertTrue(EvidenceWire::diagnostic($payload));
        self::assertFalse(EvidenceWire::diagnostic($payload + ['data' => ['secret' => 'omitted']]));
        self::assertNull(EvidenceWire::recovery(array_replace($local, ['failureRef' => 'user-id'])));
        self::assertNull(EvidenceWire::recovery(array_replace($local, ['durationMs' => INF])));
    }
    public function testDoesNotInspectCustomContextObjectsOrConstrainHistoricalCustomKeys(): void
    {
        $context = new class { public function __get(string $name): mixed { throw new \LogicException('getter'); } public function __isset(string $name): bool { throw new \LogicException('isset'); } };
        $event = ['event_type' => 'backend_exception', 'context' => $context, 'payload' => ['exception_evidence' => ExceptionEvidence::snapshot(new \Exception('original'))]];
        self::assertTrue(EvidenceWire::optionalEvidence($event));
        $supported = EvidenceWire::supported($event, []);
        self::assertNotNull($supported);
        self::assertArrayNotHasKey('exception_evidence', $supported['payload']);
        self::assertTrue(EvidenceWire::optionalEvidence(['event_type' => 'log_event', 'payload' => [], 'context' => ['evidence_reference' => str_repeat('legacy', 10000)]]));
    }
    public function testResourcesRejectUnsanitizedUrlsAndOmittedClosedFields(): void
    {
        $item = ['kind' => 'timing', 'observed_at' => '2026-10-26T00:00:00.000Z', 'source_url' => 'https://cdn.example/asset.js',
            'availability' => 'withheld', 'response_status' => null, 'duration_ms' => null, 'transfer_size_bytes' => null];
        self::assertTrue(EvidenceWire::resources(['version' => 1, 'items' => [$item]]));
        foreach (['https://user@cdn.example/asset.js', 'https://cdn.example/asset.js?token=secret', '//cdn.example/asset.js'] as $url)
            self::assertFalse(EvidenceWire::resources(['version' => 1, 'items' => [array_replace($item, ['source_url' => $url])]]));
        unset($item['source_url']); self::assertFalse(EvidenceWire::resources(['version' => 1, 'items' => [$item]]));
    }
}
