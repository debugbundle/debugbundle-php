<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

use DebugBundle\Transport\HttpTransport;
use DebugBundle\EvidenceWire;
use DebugBundle\IngestionWire;
use DebugBundle\Transport\IngestionAcknowledgementDecision;
use DebugBundle\Transport\TransportInterface;

final class RelayForwardTransport
{
    private readonly RelayEvidencePath $evidence;
    public function __construct(private readonly TransportInterface $transport, ?RelayEvidencePath $evidence = null)
    {
        $this->evidence = $evidence ?? new RelayEvidencePath();
    }

    public static function fromEndpoint(string $endpoint, ?RelayEvidencePath $evidence = null): self
    {
        return new self(new HttpTransport($endpoint), $evidence);
    }

    /** @param array<string,mixed> $event
     * @return array<string,mixed>|null
     */
    public static function prepareEvent(array $event, string $token): ?array
    {
        try {
        $owned = [...$event, 'project_token' => $token];
        $reduced = ($event['event_type'] ?? null) === 'analytics_event'
            ? (IngestionWire::bytes($owned) <= IngestionWire::MAX_EVENT_BYTES ? $owned : null)
            : IngestionWire::fit($owned);
        if ($reduced !== null) unset($reduced['project_token']);
        return $reduced;
        } catch (\Throwable) { return null; }
    }

    /** @param list<array<string,mixed>> $events
     * @return array{bool,bool}
     */
    public function send(string $projectToken, array $events, bool $discover = false): array
    {
        [$configured, $success] = $this->sendVerified($projectToken, $events, $discover);
        return [$configured, $success];
    }

    /** @param list<array<string,mixed>> $events
     * @return array{bool,bool,bool} configured, accepted, complete canonical acceptance
     */
    public function sendVerified(string $projectToken, array $events, bool $discover = false): array
    {
        if ($projectToken === '') return [false, false, false];
        $deadline = hrtime(true) + 250_000_000;
        $complete = true;
        $features = EvidenceWire::FEATURES;
        try {
            foreach (IngestionWire::chunks($this->attachProjectToken($events, $projectToken)) as $original) {
                if (hrtime(true) >= $deadline) return [true, false, false];
                $batch = [];
                foreach ($original as $event) {
                    $prepared = $this->evidence->prepare($event);
                    if ($prepared === null) return [true, false, false];
                    $batch[] = $prepared;
                }
                [$success, $canonical] = $this->sendChunk($projectToken, $batch, $discover, $deadline);
                $features = array_values(array_intersect($features, $this->evidence->descriptor()['features']));
                $this->evidence->restrict($features);
                if (!$success || hrtime(true) >= $deadline) return [true, false, false];
                $complete = $complete && $canonical;
            }
            return [true, true, $complete];
        } catch (\Throwable) { return [true, false, false]; }
    }

    /** @param list<array<string,mixed>> $events
     * @return array{bool,bool}
     */
    private function sendChunk(string $projectToken, array $events, bool $discover, int $deadline): array
    {
        $request = ['project_token' => $projectToken, 'events' => $events];
        $required = $discover || $this->transport instanceof HttpTransport;
        if ($discover && $this->transport instanceof HttpTransport) $request['evidence_discovery'] = true;
        $response = $this->forward($request, $deadline);
        if ($response->statusCode < 200 || $response->statusCode >= 300) return [false, false];
        $ack = IngestionAcknowledgementDecision::decide($response->body, count($events), $required);
        $lost = $this->evidence->observe($response->body, $required, $ack->kind === 'acknowledged');
        if ($ack->kind === 'legacy') return [true, false];
        if ($ack->kind !== 'acknowledged') return [false, false];
        if ($ack->accepted === count($events)) return [true, true];
        if (!$lost) return [false, false];
        $retry = [];
        foreach ($ack->terminalErrors as $error) {
            $event = $events[$error['index']] ?? null;
            if ($error['reason'] !== 'invalid_event' || $event === null || ($event['event_type'] ?? null) !== 'frontend_exception') continue;
            $prepared = $this->evidence->prepare($event);
            if ($prepared !== null && $prepared !== $event) $retry[] = $prepared;
        }
        if ($retry === []) return [false, false];
        $request['events'] = $retry;
        $response = $this->forward($request, $deadline);
        if ($response->statusCode < 200 || $response->statusCode >= 300) return [false, false];
        $retryAck = IngestionAcknowledgementDecision::decide($response->body, count($retry), true);
        $this->evidence->observe($response->body, $required, $retryAck->kind === 'acknowledged');
        $complete = $retryAck->kind === 'acknowledged' && $ack->accepted + $retryAck->accepted === count($events);
        return [$complete, $complete];
    }

    /** @param array<string,mixed> $request */
    private function forward(array $request, int $deadline): \DebugBundle\Transport\TransportResponse
    {
        if (hrtime(true) >= $deadline) throw new \RuntimeException('relay forwarding deadline exhausted');
        return $this->transport instanceof HttpTransport
            ? $this->transport->sendUntil($request, $deadline) : $this->transport->send($request);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function attachProjectToken(array $events, string $projectToken): array
    {
        return array_map(
            static fn (array $event): array => [...$event, 'project_token' => $projectToken],
            $events,
        );
    }
}
