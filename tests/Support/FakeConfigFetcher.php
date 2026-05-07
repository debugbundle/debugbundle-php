<?php

declare(strict_types=1);

namespace DebugBundle\Tests\Support;

final class FakeConfigFetcher
{
    /** @var list<array{url: string, request: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<FakeConfigResponse> */
    private array $responses;

    /** @param list<FakeConfigResponse> $responses */
    public function __construct(array $responses = [], private readonly ?\Exception $error = null)
    {
        $this->responses = $responses;
    }

    /** @param array<string, mixed> $request */
    public function __invoke(string $url, array $request): FakeConfigResponse
    {
        $this->calls[] = ['url' => $url, 'request' => $request];

        if ($this->error !== null) {
            throw $this->error;
        }

        if (count($this->responses) === 1) {
            return $this->responses[0];
        }

        return array_shift($this->responses) ?? new FakeConfigResponse(304, null, []);
    }
}