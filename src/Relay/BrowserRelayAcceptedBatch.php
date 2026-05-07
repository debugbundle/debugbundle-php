<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

final class BrowserRelayAcceptedBatch
{
    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $events,
        public readonly array $headers,
        public readonly ?string $ipAddress,
        public readonly string $receivedAt,
    ) {
    }
}