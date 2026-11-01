<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\Redaction;
use PHPUnit\Framework\TestCase;

final class RedactionBoundsTest extends TestCase
{
    public function testBoundsDeepInputBeforeMandatoryPrivacyWithoutChangingOrdinaryCorrelation(): void
    {
        $value = ['session_id' => 'synthetic-session', 'password' => 'withheld'];
        for ($n = 0; $n < 40; $n++) $value = ['nested' => $value];
        $result = Redaction::redactValue($value, ['password' => true]);
        self::assertLessThan(256, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
        self::assertStringContainsString('[REDACTED]', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(['session_id' => 'synthetic-session', 'password' => '[REDACTED]'],
            Redaction::redactValue(['session_id' => 'synthetic-session', 'password' => 'withheld'], ['password' => true]));
    }
    public function testBoundsSelfReferencingArraysWithoutInvokingObjects(): void
    {
        $value = []; $value['cycle'] = &$value;
        $result = Redaction::redactValue($value, []);
        self::assertLessThan(256, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
    }
}
