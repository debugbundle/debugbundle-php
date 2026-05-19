<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

final class RelayFileWriteResult
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $writtenFilePath = null,
    ) {
    }
}