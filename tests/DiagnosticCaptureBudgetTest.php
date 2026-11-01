<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DiagnosticCaptureBudget;
use PHPUnit\Framework\TestCase;

final class DiagnosticCaptureBudgetTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(int $failure = 1, int $attempt = 100): array { return ['version' => 1, 'kind' => 'recovery', 'failure_ref' => self::id($failure),
        'scope' => ['kind' => 'operation', 'operation_id' => self::id(999)], 'attempt_id' => self::id($attempt), 'state' => 'attempt_started']; }
    private static function id(int $n): string { return '00000000-0000-4000-8000-' . str_pad((string) $n, 12, '0', STR_PAD_LEFT); }
    public function testBoundsCollectedObservationsEvenAfterAckReleasesBytes(): void
    {
        $budget = new DiagnosticCaptureBudget();
        for ($n = 0; $n < 20; $n++) self::assertTrue($budget->reserve(self::id(200 + $n), $this->payload(1, 100 + $n % 5), 500, $n));
        $budget->release(array_map(self::id(...), range(200, 219)));
        self::assertFalse($budget->reserve(self::id(300), $this->payload(), 500, 100));
        self::assertTrue($budget->reserve(self::id(301), $this->payload(2), 500, 100));
        self::assertLessThanOrEqual(65536, $budget->accountedBytes());
    }
    public function testRetainsOfflineOwnershipAndDoesNotReleaseCollidingIdentity(): void
    {
        $budget = new DiagnosticCaptureBudget();
        self::assertTrue($budget->reserve(self::id(200), $this->payload(), 1000, 0));
        self::assertTrue($budget->reserve(self::id(201), $this->payload(2), 1000, 1));
        self::assertFalse($budget->replace(self::id(201), self::id(200), $this->payload(2), 1000, 2));
        self::assertTrue($budget->eligible(self::id(200), $this->payload()));
        self::assertTrue($budget->registerFailure($this->payload(), self::id(1), 3));
        self::assertFalse($budget->registerFailure($this->payload(), self::id(2), 4));
        self::assertFalse($budget->eligible(self::id(200), $this->payload()));
        self::assertFalse($budget->reserve(self::id(202), $this->payload(), 1000, 300001));
        $budget->release([self::id(200)]);
        self::assertTrue($budget->registerFailure($this->payload(), self::id(3), 300002));
    }
}
