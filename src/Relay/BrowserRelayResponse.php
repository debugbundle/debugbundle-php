<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

final class BrowserRelayResponse
{
    /**
     * @param array{accepted:int,rejected:int,errors:list<string>}|null $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body = null,
        public readonly array $headers = [],
    ) {
    }
}