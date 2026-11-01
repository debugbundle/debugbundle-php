<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\ExceptionEvidence;
use DebugBundle\Relay\BrowserRelayAcceptedBatch;
use DebugBundle\Relay\BrowserRelayHandler;
use DebugBundle\Tests\Support\FakeTransport;
use DebugBundle\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class RelayEvidenceTest extends TestCase
{
    private const PROOF = ['version' => 1, 'features' => ['exception_evidence_v1', 'resource_observations_v1', 'recovery_v1', 'evidence_provenance_v1']];
    public function testRelaySplitsAfterServerCredentialExpansionWithoutLeakingCredentials(): void
    {
        $transport = new class implements \DebugBundle\Transport\TransportInterface {
            /** @var list<array<string,mixed>> */
            public array $calls = [];
            public function send(array $request): TransportResponse {
                $this->calls[] = $request;
                return new TransportResponse(202, null, ['accepted' => count($request['events']), 'rejected' => 0, 'errors' => [], 'evidence_capabilities' => RelayEvidenceTest::proof()]);
            }
        };
        $accepted = null;
        $handler = new BrowserRelayHandler(['projectMode' => 'connected', 'projectToken' => str_repeat('x', 20000),
            'forwardTransport' => $transport, 'durableWrite' => false, 'evidenceCapabilities' => self::PROOF,
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$accepted): void { $accepted = $batch; }]);
        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $event = $this->exception(); $event['event_id'] = sprintf('00000000-0000-4000-8000-%012d', $i + 10);
            for ($part = 0; $part < 5; $part++) $event['context']['detail_' . $part] = str_repeat('x', 10000);
            $events[] = $event;
        }
        $request = $this->request($events);
        self::assertLessThanOrEqual(262144, strlen($request['body']));
        self::assertSame(202, $handler->handle($request)->status);
        self::assertCount(2, $transport->calls);
        foreach ($transport->calls as $call) self::assertLessThanOrEqual(262144, strlen(json_encode(['events' => $call['events']], JSON_THROW_ON_ERROR)));
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $accepted);
        foreach ($accepted->events as $event) self::assertArrayNotHasKey('project_token', $event);
    }

    /** @return array{version:int,features:list<string>} */
    public static function proof(): array { return self::PROOF; }
    public function testUnknownRelayAcquiresProofOnExistingCanonicalAcknowledgementTraffic(): void
    {
        $transport = new FakeTransport([new TransportResponse(202, null, ['accepted' => 1, 'rejected' => 0,
            'errors' => [], 'evidence_capabilities' => self::PROOF])]);
        $handler = new BrowserRelayHandler(['projectMode' => 'connected', 'projectToken' => 'server-owned',
            'forwardTransport' => $transport, 'durableWrite' => false]);
        self::assertSame(self::PROOF, $handler->handle($this->request([$this->exception()]))->body['evidence_capabilities'] ?? null);
        self::assertArrayNotHasKey('exception_evidence', $transport->calls[0]['events'][0]['payload']);
        self::assertSame(202, $handler->handle($this->request([$this->exception()]))->status);
        self::assertArrayHasKey('exception_evidence', $transport->calls[1]['events'][0]['payload']);
    }

    public function testUnfitServerMetadataRejectsBeforeAcceptanceOrForwarding(): void
    {
        $transport = new FakeTransport();
        $handler = new BrowserRelayHandler(['projectMode' => 'connected', 'projectToken' => str_repeat('x', 262144),
            'forwardTransport' => $transport, 'durableWrite' => false, 'evidenceCapabilities' => self::PROOF]);
        $response = $handler->handle($this->request([$this->exception()]));
        self::assertSame(0, $response->body['accepted'] ?? null);
        self::assertSame([['index' => 0, 'reason' => 'invalid_event']], $response->body['evidence_errors'] ?? null);
        self::assertSame([], $transport->calls);
    }

    public function testForwardChunksShareTheExistingDeadline(): void
    {
        $transport = new class implements \DebugBundle\Transport\TransportInterface {
            public int $calls = 0;
            public function send(array $request): TransportResponse {
                $this->calls++; usleep(300000);
                return new TransportResponse(202, null, ['accepted' => count($request['events']), 'rejected' => 0, 'errors' => []]);
            }
        };
        $events = [$this->exception(), $this->exception()];
        foreach ($events as &$event) $event['context']['detail'] = str_repeat('x', 150000);
        unset($event);
        $forwarder = new \DebugBundle\Relay\RelayForwardTransport($transport);
        self::assertSame([true, false], $forwarder->send('server-owned', $events, true));
        self::assertSame(1, $transport->calls);
    }
    public function testMalformedEvidenceIsRejectedBeforeOptionalFallback(): void
    {
        $event = $this->exception(); $event['payload']['exception_evidence']['unexpected'] = true;
        $response = (new BrowserRelayHandler(['evidenceCapabilities' => self::PROOF]))->handle($this->request([$event]));
        self::assertSame(202, $response->status);
        self::assertSame(0, $response->body['accepted'] ?? null);
        self::assertSame([['index' => 0, 'reason' => 'invalid_event']], $response->body['evidence_errors'] ?? null);
    }

    public function testLegacyCustomSuccessRetainsDurableSpoolUntilCanonicalAcceptance(): void
    {
        $directory = sys_get_temp_dir() . '/debugbundle-legacy-spool-' . bin2hex(random_bytes(4));
        $handler = new BrowserRelayHandler(['projectMode' => 'connected', 'projectToken' => 'server-owned',
            'spoolDir' => $directory, 'forwardTransport' => new FakeTransport(), 'evidenceCapabilities' => self::PROOF]);
        $request = $this->request([$this->exception()]); $request['url'] = '/debugbundle/browser';
        try {
            self::assertSame(202, $handler->handle($request)->status);
            self::assertCount(1, glob($directory . '/*.events.json') ?: []);
            self::assertSame([], glob($directory . '/*.delivered') ?: []);
        } finally { foreach (glob($directory . '/*') ?: [] as $file) unlink($file); if (is_dir($directory)) rmdir($directory); }
    }
    public function testMalformedDownstreamAcknowledgementCannotGrantProof(): void
    {
        $transport = new FakeTransport([new TransportResponse(202, null, ['accepted' => 99, 'rejected' => 0, 'errors' => [], 'evidence_capabilities' => self::PROOF])]);
        $handler = new BrowserRelayHandler(['projectMode' => 'connected', 'projectToken' => 'server-owned', 'forwardTransport' => $transport, 'durableWrite' => false]);
        self::assertSame(500, $handler->handle($this->request([$this->exception()]))->status);
        self::assertSame([], $handler->handle($this->request([]))->body['evidence_capabilities']['features'] ?? null);
    }
    /** @return array<string, mixed> */
    private function exception(): array
    {
        return ['schema_version' => '2026-03-01', 'event_id' => '00000000-0000-4000-8000-000000000001', 'event_type' => 'frontend_exception',
            'occurred_at' => '2026-10-26T00:00:00Z', 'sdk_version' => 'candidate', 'service' => ['name' => 'web', 'environment' => 'test'],
            'payload' => ['name' => 'Error', 'message' => 'original', 'stack' => 'Error: original', 'browser' => ['name' => 'fixture', 'version' => '1'], 'exception_evidence' => ExceptionEvidence::snapshot(new \Exception('cause'))],
            'context' => ['evidence_reference' => ['version' => 1, 'failure_ref' => '00000000-0000-4000-8000-000000000002',
                'scope' => ['kind' => 'operation', 'operation_id' => '00000000-0000-4000-8000-000000000003']]]];
    }
    /** @param list<array<string, mixed>> $events
     * @return array{method:string,url:string,headers:array<string,string>,body:string}
     */
    private function request(array $events): array
    {
        return ['method' => 'POST', 'url' => '/debugbundle/browser?debugbundle_evidence=1',
            'headers' => ['host' => 'app.example', 'origin' => 'https://app.example', 'content-type' => 'application/json'],
            'body' => json_encode(['batch' => $events], JSON_THROW_ON_ERROR)];
    }
    public function testPinPreservesNewEvidenceAndTypedContextInLocalDelivery(): void
    {
        $accepted = null;
        $handler = new BrowserRelayHandler(['evidenceCapabilities' => self::PROOF,
            'onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$accepted): void { $accepted = $batch; }]);
        $event = $this->exception(); $diagnostic = $event; $diagnostic['event_id'] = '00000000-0000-4000-8000-000000000004';
        $diagnostic['event_type'] = 'diagnostic_context'; $diagnostic['payload'] = $event['context']['evidence_reference'] + [
            'kind' => 'recovery', 'attempt_id' => '00000000-0000-4000-8000-000000000005', 'state' => 'recovery_observed'];
        $response = $handler->handle($this->request([$event, $diagnostic]));
        self::assertSame(202, $response->status); self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $accepted);
        self::assertCount(2, $accepted->events); self::assertSame($event['context'], $accepted->events[0]['context']);
        self::assertArrayHasKey('exception_evidence', $accepted->events[0]['payload']);
        self::assertNotNull($response->body); self::assertArrayHasKey('evidence_capabilities', $response->body);
        self::assertSame(self::PROOF, $response->body['evidence_capabilities'] ?? null);
    }
    public function testUnknownPathStripsNewExceptionAdditionsAndIndexesPartialRejections(): void
    {
        $accepted = null;
        $handler = new BrowserRelayHandler(['onAccept' => static function (BrowserRelayAcceptedBatch $batch) use (&$accepted): void { $accepted = $batch; }]);
        $bad = $this->exception(); $bad['event_type'] = 'backend_exception';
        $response = $handler->handle($this->request([$bad, $this->exception()]));
        self::assertNotNull($response->body); self::assertArrayHasKey('evidence_errors', $response->body); self::assertArrayHasKey('evidence_capabilities', $response->body);
        self::assertSame(202, $response->status); self::assertSame([['index' => 0, 'reason' => 'invalid_event']], $response->body['evidence_errors'] ?? null);
        self::assertSame([], $response->body['evidence_capabilities']['features'] ?? null);
        self::assertInstanceOf(BrowserRelayAcceptedBatch::class, $accepted);
        self::assertArrayNotHasKey('exception_evidence', $accepted->events[0]['payload']);
        self::assertArrayNotHasKey('evidence_reference', $accepted->events[0]['context']);
    }
    public function testConnectedDowngradeRetriesOnlyTheOriginalAndKeepsTheSameIdentity(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(202, null, ['accepted' => 0, 'rejected' => 1, 'errors' => [['index' => 0, 'reason' => 'invalid_event']]]),
            new TransportResponse(202, null, ['accepted' => 1, 'rejected' => 0, 'errors' => []]),
        ]);
        $handler = new BrowserRelayHandler(['projectMode' => 'connected', 'projectToken' => 'server-owned', 'forwardTransport' => $transport,
            'durableWrite' => false, 'evidenceCapabilities' => self::PROOF]);
        $response = $handler->handle($this->request([$this->exception()]));
        self::assertSame(202, $response->status); self::assertCount(2, $transport->calls);
        self::assertSame($transport->calls[0]['events'][0]['event_id'], $transport->calls[1]['events'][0]['event_id']);
        self::assertArrayNotHasKey('exception_evidence', $transport->calls[1]['events'][0]['payload']);
        self::assertNotNull($response->body); self::assertArrayHasKey('evidence_capabilities', $response->body);
        self::assertSame([], $response->body['evidence_capabilities']['features'] ?? null);
    }
}
