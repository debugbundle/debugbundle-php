<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\TelemetryPrivacy;
use PHPUnit\Framework\TestCase;

final class TelemetryPrivacyTest extends TestCase
{
    public function testProtocolIdentityValuesAreChecked(): void
    {
        self::assertTrue(TelemetryPrivacy::hasSafeEventIdentity(['correlation' => ['session_id' => 'session-123']]));
        self::assertFalse(TelemetryPrivacy::hasSafeEventIdentity(['correlation' => ['trace_id' => 'dbundle_proj_SYNTHETIC_SECRET']]));
        self::assertFalse(TelemetryPrivacy::hasSafeEventIdentity(['sdk_version' => 'password=SYNTHETIC_SECRET']));
    }

    public function testSharedPrivacyCorpus(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/privacy-conformance.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('telemetry-privacy-v1', $fixture['policy']);
        foreach ($fixture['cases'] as $case) {
            self::assertSame($case['expected'], TelemetryPrivacy::protect($case['input']), $case['id']);
        }
    }

    public function testCustomFieldsAreAdditiveAndRepeatedPassesAreStable(): void
    {
        $input = ['password' => 'SYNTHETIC_SECRET', 'tenant_pin_code' => 123, 'status' => 503];
        $protected = TelemetryPrivacy::protect($input, ['tenant_pin_code' => true]);
        self::assertSame(['password' => '[REDACTED]', 'tenant_pin_code' => '[REDACTED]', 'status' => 503], $protected);
        self::assertSame($protected, TelemetryPrivacy::protect($protected, ['tenant_pin_code' => true]));
        self::assertSame('SYNTHETIC_SECRET', $input['password']);
    }

    public function testCyclicInputIsBounded(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;
        $protected = TelemetryPrivacy::protect($recursive);
        self::assertLessThan(1024, strlen(json_encode($protected, JSON_THROW_ON_ERROR)));
        self::assertStringContainsString('[REDACTED]', json_encode($protected, JSON_THROW_ON_ERROR));
    }
}
