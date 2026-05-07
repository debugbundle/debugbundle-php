<?php

declare(strict_types=1);

namespace DebugBundle\Tests\Support;

final class FakeConfigResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $statusCode,
        private readonly mixed $payload,
        public readonly array $headers = [],
    ) {
    }

    public function json(): mixed
    {
        return $this->payload;
    }
}