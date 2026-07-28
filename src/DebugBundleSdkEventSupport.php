<?php

declare(strict_types=1);

namespace DebugBundle;

trait DebugBundleSdkEventSupport
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function baseEvent(string $eventType, array $payload, array $context = []): array
    {
        $mergedContext = array_merge($this->context, $this->redactArray($context));
        $event = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => $this->uuidV4(),
            'event_type' => $eventType,
            'occurred_at' => $this->isoNow(),
            'sdk_name' => self::SDK_NAME,
            'sdk_version' => self::SDK_VERSION,
            'service' => [
                'name' => $this->service,
                'runtime' => 'php',
                'framework' => null,
                'environment' => $this->environment,
            ],
            'correlation' => $this->buildCorrelation($mergedContext),
            'payload' => $payload,
        ];

        $eventContext = $this->eventContext($mergedContext);
        if ($eventContext !== []) {
            $event['context'] = $eventContext;
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function eventContext(array $context): array
    {
        unset(
            $context['request'],
            $context['response'],
            $context['correlation'],
            $context['request_id'],
            $context['trace_id'],
            $context['session_id'],
            $context['user_id_hash']
        );

        return $context;
    }

    /** @return array<string, mixed> */
    private function buildRequestPayload(mixed $request): array
    {
        if (!is_array($request)) {
            return [
                'method' => 'UNKNOWN',
                'path' => '/',
                'headers' => [],
                'query' => [],
            ];
        }

        return [
            'method' => (string) ($request['method'] ?? 'GET'),
            'path' => (string) ($request['path'] ?? '/'),
            'headers' => $this->normalizeMap(is_array($request['headers'] ?? null) ? $request['headers'] : []),
            'query' => $this->normalizeMap(is_array($request['query'] ?? null) ? $request['query'] : []),
            'body' => is_array($request['body'] ?? null) ? $request['body'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function buildResponsePayload(mixed $response): array
    {
        if (!is_array($response)) {
            return [
                'status_code' => 0,
            ];
        }

        return [
            'status_code' => isset($response['status_code']) ? (int) $response['status_code'] : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function buildRuntimePayload(): array
    {
        $pid = getmypid();
        $cwd = getcwd();
        $hostname = gethostname();

        return [
            'version' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY,
            'arch' => php_uname('m') ?: null,
            'pid' => is_int($pid) ? $pid : null,
            'cwd' => is_string($cwd) ? $cwd : null,
            'uptime_sec' => max(0.0, (hrtime(true) - $this->processStartedAtNs) / 1_000_000_000),
            'hostname' => $hostname !== false ? $hostname : null,
            'memory' => [
                'rss' => null,
                'heap_total' => memory_get_usage(true),
                'heap_used' => memory_get_usage(false),
                'external' => null,
                'peak' => memory_get_peak_usage(true),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string|null>
     */
    private function buildCorrelation(array $context = []): array
    {
        return [
            'request_id' => $this->readContextString('request_id', $context) ?? ($this->requestCorrelation['request_id'] ?? null),
            'trace_id' => $this->readContextString('trace_id', $context) ?? ($this->requestCorrelation['trace_id'] ?? null),
            'session_id' => $this->readContextString('session_id', $context),
            'user_id_hash' => $this->readContextString('user_id_hash', $context),
        ];
    }

    /** @return array<string, mixed>|null */
    private function buildProbeData(): ?array
    {
        if ($this->probeBuffers === []) {
            return null;
        }

        $items = [];
        foreach ($this->probeBuffers as $entries) {
            foreach ($entries as $entry) {
                $items[] = $entry;
            }
        }

        return [
            'version' => 1,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param list<RemoteProbeDirective> $directives
     */
    private function emitProbeEvents(string $label, array $value, array $directives): void
    {
        foreach ($directives as $directive) {
            $event = $this->applyBeforeSend($this->baseEvent('probe_event', [
                'label' => $label,
                'data' => $value,
                'activation_id' => $directive->id,
                'probe_label_pattern' => $directive->labelPattern,
            ]));
            if ($event !== null && $this->capturePolicy->captureProbeEvents === 'standalone_when_activated') {
                $this->enqueueEvent($event);
            }
        }
    }

    /** @param list<string>|mixed $customFields */
    /** @return array<string, bool> */
    private function buildRedactFields(mixed $customFields): array
    {
        $fields = [];
        foreach (Redaction::DEFAULT_REDACT_FIELDS as $field) {
            $fields[$field] = true;
        }

        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                if (is_string($field) && $field !== '') {
                    $fields[strtolower($field)] = true;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function redactArray(array $value): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = Redaction::redactValue($value, $this->redactFields);
        return $redacted;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>|object
     */
    private function normalizeMap(array $value): array|object
    {
        if ($value === []) {
            return new \stdClass();
        }

        return $value;
    }

    private function stringifyThrowable(\Throwable $error): string
    {
        return $error->__toString();
    }
}
