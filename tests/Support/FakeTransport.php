<?php

declare(strict_types=1);

namespace DebugBundle\Tests\Support;

use DebugBundle\Transport\TransportInterface;
use DebugBundle\Transport\TransportResponse;

final class FakeTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<TransportResponse> */
    private array $responses;

    /** @param list<TransportResponse>|null $responses */
    public function __construct(?array $responses = null)
    {
        $this->responses = $responses ?? [new TransportResponse(202, null)];
    }

    public function send(array $request): TransportResponse
    {
        $this->calls[] = $request;

        if (count($this->responses) === 1) {
            return $this->responses[0];
        }

        return array_shift($this->responses) ?? new TransportResponse(202, null);
    }
}