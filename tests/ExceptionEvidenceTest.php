<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\ExceptionEvidence;
use PHPUnit\Framework\TestCase;

final class ExceptionEvidenceTest extends TestCase
{
    public function testCapturesNativePreviousChainWithoutCustomFormatting(): void
    {
        $inner = new EvidenceUnsafeFormatter('inner');
        $outer = new \RuntimeException('outer', 0, $inner);
        EvidenceUnsafeFormatter::$calls = 0;
        $graph = ExceptionEvidence::snapshot($outer);
        self::assertSame(0, EvidenceUnsafeFormatter::$calls);
        self::assertSame('php', $graph['runtime']);
        self::assertSame([['kind' => 'cause', 'target_id' => 1]], $graph['nodes'][0]['relationships']);
        self::assertSame('inner', $graph['nodes'][1]['message']);
        self::assertSame('runtime_reported', $graph['nodes'][0]['trace_origin']);
        self::assertLessThanOrEqual(32768, strlen(json_encode($graph, JSON_THROW_ON_ERROR)));
    }

    public function testLegacyStackRetainsOuterAndPreviousIdentityWithoutCallingOverrides(): void
    {
        $error = new EvidenceUnsafeFormatter('outer', 0, new EvidenceUnsafeFormatter('inner'));
        EvidenceUnsafeFormatter::$calls = 0;
        $stack = ExceptionEvidence::legacyStack($error);
        self::assertStringContainsString('outer', $stack);
        self::assertStringContainsString('inner', $stack);
        self::assertSame(0, EvidenceUnsafeFormatter::$calls);
    }
    public function testPreservesExistingNativeTextForOrdinaryExceptionChains(): void
    {
        $error = new \RuntimeException('outer', 0, new \Exception('inner'));
        self::assertSame($error->__toString(), ExceptionEvidence::legacyStack($error));
    }

    public function testUsesActualFatalFileLineAndOnlySuppliedNativeFramesWithinCrashBudget(): void
    {
        $graph = ExceptionEvidence::fromFatal(['type' => E_ERROR, 'message' => 'native failure', 'file' => '/app/fatal.php', 'line' => 47]);
        self::assertSame('native_fatal', $graph['nodes'][0]['trace_origin']);
        self::assertSame('/app/fatal.php', $graph['nodes'][0]['frames'][0]['file']);
        self::assertSame(47, $graph['nodes'][0]['frames'][0]['line']);
        self::assertSame('partial', $graph['nodes'][0]['parsing_status']);
        $native = ExceptionEvidence::fromFatal(['type' => E_ERROR, 'message' => 'native failure', 'file' => '/app/fatal.php', 'line' => 47,
            'trace' => array_fill(0, 20, ['file' => '/app/caller.php', 'line' => 12, 'function' => 'run', 'args' => ['do-not-retain-secret'], 'object' => new EvidenceUnsafeFormatter('object')])]);
        self::assertLessThanOrEqual(8, count($native['nodes'][0]['frames']));
        self::assertLessThanOrEqual(4096, strlen(json_encode($native, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('do-not-retain-secret', json_encode($native, JSON_THROW_ON_ERROR));
        self::assertTrue($native['truncated']);
    }

    public function testBoundsEscapedUnicodeAndKeepsFatalLocationAheadOfLongControlText(): void
    {
        $graph = ExceptionEvidence::snapshot(new \Exception(str_repeat("\x01", 3000)), false, 512);
        self::assertLessThanOrEqual(512, strlen(json_encode($graph, JSON_THROW_ON_ERROR)));
        self::assertTrue($graph['truncated']);
        $fatal = ExceptionEvidence::fromFatal(['message' => str_repeat("\x01", 1000), 'file' => '/app/' . str_repeat('é', 900) . '.php', 'line' => 47]);
        self::assertSame(47, $fatal['nodes'][0]['frames'][0]['line']);
        self::assertLessThanOrEqual(4096, strlen(json_encode($fatal, JSON_THROW_ON_ERROR)));
        self::assertTrue($fatal['truncated']);
        self::assertSame(1, preg_match('//u', json_encode($fatal, JSON_THROW_ON_ERROR)));
    }
}

final class EvidenceUnsafeFormatter extends \Exception
{
    public static int $calls = 0;
    public function __toString(): string
    {
        self::$calls++;
        throw new \LogicException('custom formatter must not run');
    }
}
