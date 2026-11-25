<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\DebugBundleSdk;
use DebugBundle\TelemetryPrivacy;
use DebugBundle\Tests\Support\FakeTransport;
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
        foreach ($fixture['exact_key_cases'] as $case) {
            $additional = array_fill_keys($case['additional_keys'] ?? [], true);
            $exact = array_fill_keys($case['exact_keys'] ?? [], true);
            $protected = TelemetryPrivacy::protect($case['input'], $additional, $exact);
            self::assertSame($case['expected'], $protected, $case['id']);
            self::assertSame($protected, TelemetryPrivacy::protect($protected, $additional, $exact), $case['id'] . ' idempotent');
        }
    }

    public function testExactFieldsRespectCombinedKeyBoundsAndRejectBlankOrOversizedKeys(): void
    {
        $halfBroad = array_fill_keys(array_map(static fn (int $i): string => "broad_{$i}", range(0, 63)), true);
        $halfExact = array_fill_keys(array_map(static fn (int $i): string => "exact_{$i}", range(0, 63)), true);
        self::assertSame(['safe' => 1], TelemetryPrivacy::protect(['safe' => 1], $halfBroad, $halfExact));

        try {
            TelemetryPrivacy::protect(['safe' => 1], $halfBroad, $halfExact + ['exact_64' => true]);
            self::fail('Expected combined > 128 keys to throw');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('unsafe_input', $error->getMessage());
        }

        try {
            TelemetryPrivacy::protect(['safe' => 1], [], ['   ' => true]);
            self::fail('Expected whitespace exact key to throw');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('unsafe_input', $error->getMessage());
        }
    }

    public function testSdkRedactExactFieldsMasksPrivateFieldWhilePreservingCompoundSiblings(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'billing',
            'environment' => 'production',
            'redactExactFields' => ['tag'],
        ]);

        $sdk->setContext('payout', ['tag' => 'SYNTHETIC_SECRET', 'tag_name' => 'script']);
        $sdk->captureLog('payout failed tag=SYNTHETIC_SECRET tag_name=script', 'error', [
            'tag' => 'SYNTHETIC_LOG_SECRET',
            'tag_name' => 'script',
            'url' => 'https://example.test/api?tag=SYNTHETIC_QUERY&tag_name=script',
        ]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        $event = $transport->calls[0]['events'][0];
        self::assertSame('payout failed tag=[REDACTED] tag_name=script', $event['payload']['message']);
        self::assertSame('[REDACTED]', $event['payload']['attributes']['tag']);
        self::assertSame('script', $event['payload']['attributes']['tag_name']);
        self::assertSame('https://example.test/api?tag=%5BREDACTED%5D&tag_name=script', $event['payload']['attributes']['url']);
        self::assertSame('[REDACTED]', $event['context']['payout']['tag']);
        self::assertSame('script', $event['context']['payout']['tag_name']);
        $sdk->reset();
    }

    public function testSdkBroadRedactFieldsPreservesValidatedProtocolEvidenceWhileRedactingApplicationFields(): void
    {
        $transport = new FakeTransport();
        $sdk = new DebugBundleSdk($transport);
        $failureRef = '11111111-1111-4111-8111-111111111111';
        $operationId = '22222222-2222-4222-8222-222222222222';
        $sdk->init([
            'projectToken' => 'dbundle_proj_test',
            'service' => 'billing',
            'environment' => 'production',
            'redactFields' => ['tag', 'reference', 'reason', 'file'],
            'evidenceCapabilities' => [
                'version' => 1,
                'features' => ['exception_evidence_v1', 'recovery_v1'],
            ],
        ]);

        $sdk->captureException(new \RuntimeException('boom reason=SYNTHETIC_REASON'), [
            'failureRef' => $failureRef,
            'evidenceScope' => ['kind' => 'operation', 'operation_id' => $operationId],
            'payout' => [
                'tag' => 'SYNTHETIC_TAG',
                'reference' => 'SYNTHETIC_REF',
                'reason' => 'SYNTHETIC_REASON',
                'file' => 'SYNTHETIC_FILE',
            ],
        ]);
        $sdk->flush();

        self::assertCount(1, $transport->calls);
        $event = $transport->calls[0]['events'][0];
        self::assertSame('backend_exception', $event['event_type']);
        self::assertSame([
            'version' => 1,
            'failure_ref' => $failureRef,
            'scope' => ['kind' => 'operation', 'operation_id' => $operationId],
        ], $event['context']['evidence_reference']);
        self::assertSame([
            'tag' => '[REDACTED]',
            'reference' => '[REDACTED]',
            'reason' => '[REDACTED]',
            'file' => '[REDACTED]',
        ], $event['context']['payout']);
        self::assertIsArray($event['payload']['exception_evidence']);
        self::assertNull($event['payload']['exception_evidence']['unavailable_reason']);
        self::assertNotEmpty($event['payload']['exception_evidence']['nodes'][0]['frames']);
        self::assertNotSame('[REDACTED]', $event['payload']['exception_evidence']['nodes'][0]['frames'][0]['file']);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        foreach (['SYNTHETIC_TAG', 'SYNTHETIC_REF', 'SYNTHETIC_REASON', 'SYNTHETIC_FILE'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        $sdk->reset();
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
