<?php

declare(strict_types=1);

namespace DebugBundle\Transport;

final class TransportResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?int $retryAfterMs,
        public readonly mixed $body = null,
    ) {
    }
}
