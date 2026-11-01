<?php

declare(strict_types=1);

namespace DebugBundle;

/** Only normalized technical metadata belongs to an active operation. */
final class OperationState
{
    public bool $active = true;
    /** @param array<string, string> $correlation
     * @param list<RemoteProbeDirective> $directives
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly ?string $name,
        public readonly int $started,
        public readonly int|float|null $limit,
        public readonly array $correlation,
        public readonly array $directives,
    ) {}
}

