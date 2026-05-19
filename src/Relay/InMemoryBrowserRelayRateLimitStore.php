<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

final class InMemoryBrowserRelayRateLimitStore implements BrowserRelayRateLimitStore
{
    /** @var array<string, list<int>> */
    private array $timestampsByKey = [];

    public function allow(?string $ipAddress, int $maxRequests, int $windowSeconds, int $now): bool
    {
        $key = $ipAddress ?? 'unknown';
        $windowStart = $now - $windowSeconds;
        $timestamps = array_values(array_filter(
            $this->timestampsByKey[$key] ?? [],
            static fn (int $timestamp): bool => $timestamp > $windowStart
        ));

        if (count($timestamps) >= $maxRequests) {
            $this->timestampsByKey[$key] = $timestamps;
            return false;
        }

        $timestamps[] = $now;
        $this->timestampsByKey[$key] = $timestamps;
        return true;
    }
}