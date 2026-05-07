<?php

declare(strict_types=1);

namespace DebugBundle\Tests\Support;

final class ManualClock
{
    public float $now = 1700000000.0;

    public function time(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}