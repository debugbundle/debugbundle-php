<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

interface BrowserRelayRateLimitStore
{
    public function allow(?string $ipAddress, int $maxRequests, int $windowSeconds, int $now): bool;
}