<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use DebugBundle\BeforeSend;
use PHPUnit\Framework\TestCase;

final class BeforeSendTest extends TestCase
{
    public function testAcceptsCanonicalUuidVersionsAndRejectsMalformedEnvelopeReplacements(): void
    {
        $original = self::event('log_event', ['level' => 'error', 'message' => 'original', 'attributes' => []]);
        $valid = $original; $valid['event_id'] = '00000000-0000-7000-8000-000000000001';
        self::assertSame($valid, BeforeSend::apply($original, static fn (): array => $valid));
        foreach ([['occurred_at' => 'tomorrow'], ['correlation' => ['trace_id' => ['unsafe']]],
            ['project_id' => 'not-a-uuid'], ['service' => ['name' => 'test', 'environment' => 'test', 'unexpected' => true]]] as $fields) {
            $invalid = array_replace($original, $fields);
            self::assertSame($original, BeforeSend::apply($original, static fn (): array => $invalid));
        }
    }
    public function testRejectsIncompleteNestedEnvelopeAndOwnsNativeObjectsWithoutApplicationMagic(): void
    {
        $original = self::event('log_event', ['level' => 'error', 'message' => 'private original', 'attributes' => []]);
        $invalid = self::event('backend_exception', ['name' => 'Error', 'message' => 'safe', 'stack' => 'trace',
            'handled' => true, 'request' => [], 'response' => [], 'runtime' => []]);
        self::assertSame($original, BeforeSend::apply($original, static fn (): array => $invalid));
        $replacement = $original;
        $replacement['payload']['message'] = 'safe';
        $replacement['context'] = ['nested' => (object) ['value' => 'owned']];
        $accepted = BeforeSend::apply($original, static fn (): array => $replacement);
        $replacement['context']['nested']->value = 'later mutation';
        self::assertNotNull($accepted);
        self::assertSame('owned', $accepted['context']['nested']->value);
        $foreign = new class {
            public static int $calls = 0;
            public function __clone() { ++self::$calls; throw new \LogicException('must not clone'); }
            public function __serialize(): array { ++self::$calls; throw new \LogicException('must not serialize'); }
        };
        $replacement['context'] = ['foreign' => $foreign];
        self::assertSame($original, BeforeSend::apply($original, static fn (): array => $replacement));
        self::assertSame(0, $foreign::$calls);
    }

    public function testWithholdsValidationBudgetExhaustionWithoutRestoringRemovedContent(): void
    {
        $original = self::event('log_event', ['level' => 'error', 'message' => 'private original', 'attributes' => []]);
        $replacement = $original;
        $replacement['payload']['message'] = 'safe';
        $replacement['context'] = ['detail' => str_repeat('x', 9 * 1024 * 1024)];
        self::assertTrue(BeforeSend::apply($original, static fn (): array => $replacement) === null);
    }

    public function testCompleteHookBoundaryMatchesAcceptingEvidenceCorpus(): void
    {
        /** @var array{events:list<array{id:string,valid:bool,event:array<string,mixed>}>} $corpus */
        $corpus = json_decode((string) file_get_contents(__DIR__ . '/fixtures/evidence-wire-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $original = self::event('log_event', ['level' => 'error', 'message' => 'safe original', 'attributes' => []]);
        foreach ($corpus['events'] as $fixture) {
            $candidate = $fixture['event'];
            $candidate['sdk_version'] = 'hook-accepted';
            $result = BeforeSend::apply($original, static fn (): array => $candidate);
            self::assertNotNull($result);
            self::assertSame($fixture['valid'], $result['sdk_version'] === 'hook-accepted', $fixture['id']);
        }
    }

    public function testNullHookReturnsOriginalEventWithoutMutation(): void
    {
        $event = self::event('log_event', [
            'level' => 'error',
            'message' => 'checkout failed',
            'attributes' => [],
        ]);

        self::assertSame($event, BeforeSend::apply($event, null));
    }

    public function testValidContractEventShapesAreAccepted(): void
    {
        $events = [
            self::event('backend_exception', [
                'name' => 'RuntimeException',
                'message' => 'checkout failed',
                'stack' => 'trace',
                'handled' => true,
                'request' => ['method' => 'GET', 'path' => '/', 'query' => [], 'headers' => []],
                'response' => ['status_code' => 500],
                'runtime' => ['version' => '8.2'],
                'probe_data' => ['version' => 1, 'items' => []],
            ]),
            self::event('request_event', [
                'method' => 'GET',
                'path' => '/checkout',
                'query' => [],
                'headers' => [],
                'response_status' => 200,
                'duration_ms' => 12.5,
                'response_headers' => [],
            ]),
            self::event('log_event', [
                'level' => 'warning',
                'message' => 'slow checkout',
                'attributes' => [],
            ]),
            self::event('frontend_breadcrumb', [
                'breadcrumb_type' => 'route_change',
                'data' => [],
            ]),
            self::event('frontend_exception', [
                'name' => 'TypeError',
                'message' => 'render failed',
                'stack' => 'trace',
                'browser' => ['name' => 'unknown', 'version' => 'unknown'],
                'breadcrumbs' => [],
                'probe_data' => ['version' => 1, 'items' => []],
            ]),
            self::event('deploy_metadata', [
                'commit_sha' => 'abc123',
                'version' => '1.3.0',
                'branch' => 'main',
                'environment' => 'production',
                'deployed_at' => '2026-07-28T17:00:00Z',
            ]),
            self::event('error_suppressed', [
                'fingerprint' => 'fingerprint',
                'suppressed_count' => 0,
                'window_seconds' => 60,
                'first_seen' => '2026-07-28T17:00:00Z',
                'last_seen' => '2026-07-28T17:01:00Z',
            ]),
            self::event('probe_event', [
                'label' => 'checkout.total',
                'data' => ['value' => 42],
                'activation_id' => null,
                'probe_label_pattern' => 'checkout.*',
            ]),
            self::event('probe_event', [
                'label' => 'checkout.total',
                'data' => ['value' => 42],
                'activation_id' => '123e4567-e89b-42d3-a456-426614174000',
                'probe_label_pattern' => 'checkout.*',
            ]),
        ];

        foreach ($events as $event) {
            $event['context'] = ['accepted' => true];
            self::assertSame($event, BeforeSend::apply($event, static fn (array $candidate): array => $candidate));
        }
    }

    public function testInvalidRootAndEnvelopeShapesFallBackToOriginalEvent(): void
    {
        $base = self::event('log_event', [
            'level' => 'error',
            'message' => 'checkout failed',
            'attributes' => [],
        ]);
        $invalidEvents = [];

        $invalidEvents[] = [...$base, 'unexpected' => true];
        foreach (['schema_version', 'event_id', 'event_type', 'occurred_at', 'sdk_name', 'sdk_version'] as $field) {
            $invalidEvents[] = [...$base, $field => ''];
        }
        $invalidEvents[] = [...$base, 'event_id' => 'not-a-uuid'];
        $invalidEvents[] = [...$base, 'service' => null];
        $invalidEvents[] = [...$base, 'service' => ['name' => '', 'environment' => 'production']];
        $invalidEvents[] = [...$base, 'service' => ['name' => 'checkout-api', 'environment' => '']];
        $invalidEvents[] = [...$base, 'payload' => null];
        $invalidEvents[] = [...$base, 'event_type' => 'unknown_event'];
        $invalidEvents[] = [...$base, 'payload' => [...$base['payload'], 'unexpected' => true]];
        $invalidEvents[] = [...$base, 'payload' => ['level' => 'error', 'message' => 'checkout failed']];

        foreach ($invalidEvents as $invalid) {
            self::assertSame(
                $base,
                BeforeSend::apply($base, static fn (array $candidate): array => $invalid),
            );
        }
    }

    public function testInvalidPayloadShapesFallBackToOriginalEvent(): void
    {
        $base = self::event('log_event', [
            'level' => 'error',
            'message' => 'original',
            'attributes' => [],
        ]);
        $invalidEvents = [
            self::event('backend_exception', [
                'name' => '',
                'message' => 'failed',
                'stack' => 'trace',
                'handled' => 'yes',
                'request' => null,
                'response' => [],
                'runtime' => [],
                'probe_data' => 'invalid',
            ]),
            self::event('request_event', [
                'method' => '',
                'path' => '/checkout',
                'query' => null,
                'headers' => [],
                'response_status' => -1,
                'duration_ms' => -1,
                'response_headers' => 'invalid',
            ]),
            self::event('log_event', [
                'level' => '',
                'message' => 'failed',
                'attributes' => null,
            ]),
            self::event('frontend_breadcrumb', [
                'breadcrumb_type' => '',
                'data' => null,
            ]),
            self::event('frontend_exception', [
                'name' => 'TypeError',
                'message' => '',
                'stack' => 'trace',
                'breadcrumbs' => 'invalid',
                'probe_data' => 'invalid',
            ]),
            self::event('deploy_metadata', [
                'commit_sha' => '',
                'version' => '1.3.0',
                'branch' => 'main',
                'environment' => 'production',
                'deployed_at' => 'not-a-timestamp',
            ]),
            self::event('error_suppressed', [
                'fingerprint' => '',
                'suppressed_count' => -1,
                'window_seconds' => 0,
                'first_seen' => 'not-a-timestamp',
                'last_seen' => 'not-a-timestamp',
            ]),
            self::event('probe_event', [
                'label' => '',
                'data' => 'invalid',
                'activation_id' => 'not-a-uuid',
                'probe_label_pattern' => '',
            ]),
        ];

        foreach ($invalidEvents as $invalid) {
            self::assertSame(
                $base,
                BeforeSend::apply($base, static fn (array $candidate): array => $invalid),
            );
        }
    }

    public function testHookReceivesACloneAndHookFailuresAreContained(): void
    {
        $nestedObject = new \stdClass();
        $nestedObject->value = 'original';
        $event = self::event('log_event', [
            'level' => 'error',
            'message' => 'checkout failed',
            'attributes' => [],
        ]);
        $event['context'] = ['object' => $nestedObject];

        $result = BeforeSend::apply($event, static function (array $candidate): array {
            $candidate['context']['object']->value = 'changed';
            return $candidate;
        });

        self::assertSame('original', $nestedObject->value);
        self::assertNotNull($result);
        self::assertSame('changed', $result['context']['object']->value);
        self::assertSame($event, BeforeSend::apply($event, static function (): never {
            throw new \RuntimeException('hook failed');
        }));
        self::assertNull(BeforeSend::apply($event, static fn (): null => null));
        self::assertSame($event, BeforeSend::apply($event, static fn (): string => 'invalid'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function event(string $eventType, array $payload): array
    {
        return [
            'schema_version' => '1.0',
            'event_id' => '123e4567-e89b-42d3-a456-426614174000',
            'event_type' => $eventType,
            'project_token' => 'dbundle_proj_test',
            'sdk_name' => 'debugbundle-php',
            'sdk_version' => '1.3.0',
            'service' => [
                'name' => 'checkout-api',
                'environment' => 'production',
            ],
            'occurred_at' => '2026-07-28T17:00:00Z',
            'correlation' => [],
            'context' => [],
            'payload' => $payload,
        ];
    }
}
