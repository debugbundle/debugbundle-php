<?php

declare(strict_types=1);

namespace DebugBundle\Transport;

interface TransportInterface
{
    /** @param array<string, mixed> $request */
    public function send(array $request): TransportResponse;
}