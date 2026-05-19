<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

use DebugBundle\Transport\HttpTransport;
use DebugBundle\Transport\TransportInterface;

final class RelayForwardTransport
{
    public function __construct(private readonly TransportInterface $transport)
    {
    }

    public static function fromEndpoint(string $endpoint): self
    {
        return new self(new HttpTransport($endpoint));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{bool, bool}
     */
    public function send(string $projectToken, array $events): array
    {
        if ($projectToken === '') {
            return [false, false];
        }

        try {
            $response = $this->transport->send([
                'project_token' => $projectToken,
                'events' => $this->attachProjectToken($events, $projectToken),
            ]);
        } catch (\Throwable) {
            return [true, false];
        }

        return [true, $response->statusCode >= 200 && $response->statusCode < 300];
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