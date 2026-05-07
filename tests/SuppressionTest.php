<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\Suppression;
use PHPUnit\Framework\TestCase;

final class SuppressionTest extends TestCase
{
    public function testAggregatesDuplicatesAfterInitialBudgetIsExhausted(): void
    {
        $suppression = new Suppression();

        self::assertTrue($suppression->shouldCapture('duplicate-key', 0.0));
        self::assertTrue($suppression->shouldCapture('duplicate-key', 1.0));
        self::assertTrue($suppression->shouldCapture('duplicate-key', 2.0));
        self::assertFalse($suppression->shouldCapture('duplicate-key', 3.0));
        self::assertFalse($suppression->shouldCapture('duplicate-key', 4.0));

        $aggregates = $suppression->drainAggregates(5.0);

        self::assertCount(1, $aggregates);
        self::assertSame('error_suppressed', $aggregates[0]['event_type']);
        self::assertSame(2, $aggregates[0]['payload']['suppressed_count']);
        self::assertSame('1970-01-01T00:00:00Z', $aggregates[0]['payload']['first_seen']);
        self::assertSame('1970-01-01T00:00:04Z', $aggregates[0]['payload']['last_seen']);
    }

    public function testLoopSuppressionEmitsCheckpointAggregatesOnlyAfterInterval(): void
    {
        $suppression = new Suppression();

        for ($index = 0; $index < 11; $index++) {
            $suppression->shouldCapture('loop-key', 0.1 * $index);
        }

        $firstAggregate = $suppression->drainAggregates(2.0);
        self::assertCount(1, $firstAggregate);
        self::assertSame(8, $firstAggregate[0]['payload']['suppressed_count']);

        self::assertFalse($suppression->shouldCapture('loop-key', 3.0));
        self::assertSame([], $suppression->drainAggregates(10.0));

        $checkpointAggregate = $suppression->drainAggregates(35.0);
        self::assertCount(1, $checkpointAggregate);
        self::assertSame(1, $checkpointAggregate[0]['payload']['suppressed_count']);
    }

    public function testLoopSuppressionResetsAfterIdlePeriod(): void
    {
        $suppression = new Suppression();

        for ($index = 0; $index < 11; $index++) {
            $suppression->shouldCapture('idle-reset', 0.1 * $index);
        }

        self::assertFalse($suppression->shouldCapture('idle-reset', 2.0));
        self::assertTrue($suppression->shouldCapture('idle-reset', 70.0));
        self::assertTrue($suppression->shouldCapture('idle-reset', 101.0));
    }
}