<?php

declare(strict_types=1);

namespace DebugBundle;

/** Closed, bounded normalized-JSON contracts shared by capture/hooks/relays.
 * No object enumeration, string coercion, reflection or user callbacks.
 */
final class EvidenceWire
{
    public const FEATURES = ['exception_evidence_v1', 'resource_observations_v1', 'recovery_v1', 'evidence_provenance_v1'];

    public static function discoveryUrl(string $url): string
    {
        $parts = explode('#', $url, 2); $base = $parts[0]; $fragment = $parts[1] ?? null;
        $count = 0;
        $base = (string) preg_replace('/([?&])debugbundle_evidence=[^&]*/', '$1debugbundle_evidence=1', $base, -1, $count);
        if ($count === 0) $base .= (str_contains($base, '?') ? '&' : '?') . 'debugbundle_evidence=1';
        return $base . ($fragment === null ? '' : '#' . $fragment);
    }

    /** @return array{version: int, features: list<string>} */
    public static function descriptor(mixed $value): array
    {
        $empty = ['version' => 1, 'features' => []];
        if (!self::closed($value, ['version', 'features']) || !self::integer($value['version'], 1, 1)
            || !is_array($value['features']) || !array_is_list($value['features']) || count($value['features']) > 4) return $empty;
        foreach ($value['features'] as $feature) if (!is_string($feature) || !in_array($feature, self::FEATURES, true)) return $empty;
        if (count(array_unique($value['features'])) !== count($value['features'])) return $empty;
        return ['version' => 1, 'features' => array_values(array_intersect(self::FEATURES, $value['features']))];
    }

    /** @return array<string, mixed>|null */
    public static function reference(mixed $value): ?array
    {
        if (!self::closed($value, ['version', 'failure_ref', 'scope']) || !self::integer($value['version'], 1, 1)
            || !self::uuid($value['failure_ref'], true) || !self::scope($value['scope'])) return null;
        return $value;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $defaultScope
     * @return array<string, mixed>|null
     */
    public static function captureReference(array $context, ?array $defaultScope = null): ?array
    {
        return self::reference(['version' => 1, 'failure_ref' => $context['failureRef'] ?? null, 'scope' => $context['evidenceScope'] ?? $defaultScope]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $defaultScope
     * @return array<string, mixed>|null
     */
    public static function recovery(array $input, ?array $defaultScope = null): ?array
    {
        $reference = self::captureReference($input, $defaultScope); if ($reference === null) return null;
        $payload = $reference + ['kind' => 'recovery', 'attempt_id' => $input['attemptId'] ?? null, 'state' => $input['state'] ?? null];
        foreach (['operation' => 'operation', 'statusCode' => 'status_code', 'durationMs' => 'duration_ms'] as $local => $wire)
            if (array_key_exists($local, $input)) $payload[$wire] = $input[$local];
        return self::diagnostic($payload) ? $payload : null;
    }

    public static function diagnostic(mixed $value): bool
    {
        if (!is_array($value)) return false;
        $reference = ['version' => $value['version'] ?? null, 'failure_ref' => $value['failure_ref'] ?? null, 'scope' => $value['scope'] ?? null];
        if (self::reference($reference) === null) return false;
        if (($value['kind'] ?? null) === 'recovery') return self::closed($value, ['version', 'kind', 'failure_ref', 'scope', 'attempt_id', 'state'], ['operation', 'status_code', 'duration_ms'])
            && self::uuid($value['attempt_id'], true) && in_array($value['state'], ['attempt_started', 'attempt_completed', 'attempt_failed', 'recovery_observed', 'failure_persisted'], true)
            && (!array_key_exists('operation', $value) || self::text($value['operation'], 64))
            && (!array_key_exists('status_code', $value) || self::integer($value['status_code'], 100, 599))
            && (!array_key_exists('duration_ms', $value) || self::number($value['duration_ms']));
        return ($value['kind'] ?? null) === 'resource_observation'
            && self::closed($value, ['version', 'kind', 'failure_ref', 'scope', 'source_event_id', 'observations'])
            && self::uuid($value['source_event_id']) && self::resources($value['observations']);
    }

    public static function execution(mixed $value): bool
    {
        return self::closed($value, ['version', 'kind', 'name', 'elapsed_ms', 'limit_ms']) && self::integer($value['version'], 1, 1)
            && in_array($value['kind'], ['request', 'command', 'job', 'unknown'], true)
            && ($value['name'] === null || self::text($value['name'], 128))
            && ($value['elapsed_ms'] === null || self::number($value['elapsed_ms']))
            && ($value['limit_ms'] === null || self::number($value['limit_ms']));
    }

    public static function graph(mixed $value): bool
    {
        if (!self::closed($value, ['version', 'runtime', 'root_id', 'nodes', 'truncated', 'unavailable_reason'])
            || !self::integer($value['version'], 1, 1) || !self::integer($value['root_id'], 0, 7)
            || !in_array($value['runtime'], ['node', 'browser', 'python', 'php', 'jvm', 'go', 'ruby', 'dotnet', 'android', 'swift', 'react_native', 'unknown'], true)
            || !is_bool($value['truncated']) || !in_array($value['unavailable_reason'], [null, 'unknown', 'unsupported', 'unsafe', 'budget_exceeded'], true)
            || !is_array($value['nodes']) || !array_is_list($value['nodes']) || count($value['nodes']) < 1 || count($value['nodes']) > 8) return false;
        $nodes = [];
        foreach ($value['nodes'] as $node) {
            if (!self::closed($node, ['id', 'name', 'message', 'stack', 'trace_origin', 'parsing_status', 'frames', 'relationships'])
                || !self::integer($node['id'], 0, 7) || isset($nodes[(int) $node['id']])
                || !self::text($node['name'], 256) || !self::text($node['message'], 2048)
                || ($node['stack'] !== null && !self::text($node['stack'], 16384))
                || !in_array($node['trace_origin'], ['throw', 'native_fatal', 'capture', 'runtime_reported', 'unavailable', 'unknown'], true)
                || !in_array($node['parsing_status'], ['parsed', 'partial', 'unparsed', 'unavailable', 'truncated'], true)
                || !is_array($node['frames']) || !array_is_list($node['frames']) || count($node['frames']) > 32
                || !is_array($node['relationships']) || !array_is_list($node['relationships']) || count($node['relationships']) > 5) return false;
            foreach ($node['frames'] as $frame) if (!self::closed($frame, ['function', 'file', 'line', 'column', 'classification'])
                || ($frame['function'] !== null && !self::text($frame['function'], 256))
                || ($frame['file'] !== null && !self::text($frame['file'], 2048))
                || ($frame['line'] !== null && !self::integer($frame['line'], 1))
                || ($frame['column'] !== null && !self::integer($frame['column'], 0))
                || !in_array($frame['classification'], ['application', 'framework', 'sdk', 'unknown'], true)) return false;
            $edges = []; $targets = []; $causal = 0;
            foreach ($node['relationships'] as $edge) {
                if (!self::closed($edge, ['kind', 'target_id']) || !self::integer($edge['target_id'], 0, 7)
                    || !in_array($edge['kind'], ['cause', 'context', 'aggregate', 'suppressed'], true)) return false;
                $identity = $edge['kind'] . ':' . (int) $edge['target_id'];
                if (isset($edges[$identity])) return false;
                $edges[$identity] = true;
                if (in_array($edge['kind'], ['cause', 'context'], true) && ++$causal > 1) return false;
                $targets[] = (int) $edge['target_id'];
            }
            $nodes[(int) $node['id']] = $targets;
        }
        $visited = [];
        return self::visit((int) $value['root_id'], $nodes, [], $visited, 0) && count($visited) === count($nodes) && self::bytes($value) <= 32768;
    }

    /**
     * @param array<int, list<int>> $nodes
     * @param array<int, bool> $path
     * @param array<int, bool> $visited
     */
    private static function visit(int $id, array $nodes, array $path, array &$visited, int $depth): bool
    {
        if ($depth > 4 || isset($path[$id]) || !isset($nodes[$id])) return false;
        $path[$id] = true; $visited[$id] = true;
        foreach ($nodes[$id] as $target) if (!self::visit($target, $nodes, $path, $visited, $depth + 1)) return false;
        return true;
    }

    public static function resources(mixed $value): bool
    {
        if (!self::closed($value, ['version', 'items']) || !self::integer($value['version'], 1, 1)
            || !is_array($value['items']) || !array_is_list($value['items']) || count($value['items']) < 1 || count($value['items']) > 5) return false;
        foreach ($value['items'] as $item) {
            if (!is_array($item) || !self::timestamp($item['observed_at'] ?? null) || !array_key_exists('source_url', $item) || ($item['source_url'] !== null && !self::url($item['source_url']))) return false;
            if (($item['kind'] ?? null) === 'timing') {
                if (!self::closed($item, ['kind', 'observed_at', 'source_url', 'availability', 'response_status', 'duration_ms', 'transfer_size_bytes'])
                    || !in_array($item['availability'], ['observed', 'unsupported', 'no_match', 'withheld', 'ambiguous', 'unknown'], true)
                    || ($item['response_status'] !== null && !self::integer($item['response_status'], 100, 599))
                    || ($item['duration_ms'] !== null && !self::number($item['duration_ms']))
                    || ($item['transfer_size_bytes'] !== null && !self::integer($item['transfer_size_bytes'], 0))) return false;
            } elseif (($item['kind'] ?? null) === 'csp') {
                if (!self::closed($item, ['kind', 'observed_at', 'source_url', 'effective_directive', 'disposition', 'document_status_code'])
                    || !is_string($item['effective_directive']) || preg_match('/^[a-z-]{1,64}$/D', $item['effective_directive']) !== 1
                    || !in_array($item['disposition'], ['enforce', 'report'], true)
                    || ($item['document_status_code'] !== null && !self::integer($item['document_status_code'], 100, 599))) return false;
            } else return false;
        }
        return self::bytes($value) <= 32768;
    }

    /** Historical arbitrary context remains open; only new payload fields opt in.
     * @param array<string, mixed> $event
     */
    public static function optionalEvidence(array $event): bool
    {
        $payload = $event['payload'] ?? null; if (!is_array($payload)) return false;
        if (($event['event_type'] ?? null) === 'diagnostic_context') {
            $correlation = is_array($event['correlation'] ?? null) ? $event['correlation'] : [];
            $session = $correlation['session_id'] ?? null;
            return self::diagnostic($payload) && (($payload['scope']['kind'] ?? null) !== 'page' || (is_string($session) && trim($session) !== ''));
        }
        $added = [];
        foreach (['exception_evidence' => 'graph', 'execution' => 'execution', 'resource_observations' => 'resources'] as $field => $validator) {
            if (!array_key_exists($field, $payload)) continue;
            $allowed = (($event['event_type'] ?? null) === 'backend_exception' && $field !== 'resource_observations')
                || (($event['event_type'] ?? null) === 'frontend_exception' && $field !== 'execution');
            if (!$allowed) return false;
            if (!self::$validator($payload[$field])) return false;
            $added[$field] = $payload[$field];
        }
        if ($added === []) return true;
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        $reference = self::reference($context['evidence_reference'] ?? null);
        if ($reference !== null) $added['evidence_reference'] = $reference;
        return self::bytes($added) <= 32768;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function withoutOptional(array $event): ?array
    {
        if (!in_array($event['event_type'] ?? null, ['backend_exception', 'frontend_exception'], true) || !is_array($event['payload'] ?? null)) return null;
        $changed = false;
        foreach (['exception_evidence', 'execution', 'resource_observations'] as $field) if (array_key_exists($field, $event['payload'])) { unset($event['payload'][$field]); $changed = true; }
        return $changed ? $event : null;
    }

    /**
     * @param array<string, mixed> $event
     * @param list<string> $features
     * @return array<string, mixed>|null
     */
    public static function supported(array $event, array $features): ?array
    {
        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) return null;
        if (($event['event_type'] ?? null) === 'diagnostic_context') return in_array(($payload['kind'] ?? null) === 'recovery' ? 'recovery_v1' : 'resource_observations_v1', $features, true) ? $event : null;
        if (!in_array('exception_evidence_v1', $features, true)) unset($payload['exception_evidence'], $payload['execution']);
        if (!in_array('resource_observations_v1', $features, true)) unset($payload['resource_observations']);
        $event['payload'] = $payload;
        if (!in_array('resource_observations_v1', $features, true) && !in_array('recovery_v1', $features, true)
            && is_array($event['context'] ?? null) && self::reference($event['context']['evidence_reference'] ?? null) !== null) unset($event['context']['evidence_reference']);
        return $event;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function closed(mixed $value, array $required, array $optional = []): bool
    {
        return is_array($value) && count($value) <= count($required) + count($optional)
            && array_diff($required, array_keys($value)) === [] && array_diff(array_keys($value), [...$required, ...$optional]) === [];
    }
    private static function scope(mixed $value): bool
    {
        if (!is_array($value)) return false;
        $field = ($value['kind'] ?? null) === 'page' ? 'page_id' : (($value['kind'] ?? null) === 'operation' ? 'operation_id' : null);
        return $field !== null && self::closed($value, ['kind', $field]) && self::uuid($value[$field], true);
    }
    public static function uuid(mixed $value, bool $fresh = false): bool
    {
        return is_string($value) && strlen($value) === 36 && preg_match($fresh
            ? '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD'
            : '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value) === 1;
    }
    private static function text(mixed $value, int $maximum): bool { return is_string($value) && strlen($value) <= $maximum && preg_match('//u', $value) === 1; }
    private static function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0; }
    private static function integer(mixed $value, int $minimum, ?int $maximum = null): bool { return self::number($value) && floor((float) $value) === (float) $value && $value >= $minimum && ($maximum === null || $value <= $maximum); }
    public static function timestamp(mixed $value): bool { return is_string($value) && strlen($value) <= 32768 && preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?Z$/D', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)); }
    private static function url(mixed $value): bool
    {
        if (!self::text($value, 2048) || $value === '' || str_starts_with($value, '//') || preg_match('/[\x00-\x20\x7f\\\\?#]/', $value) === 1
            || (!str_starts_with($value, '/') && preg_match('/^https?:\/\//i', $value) !== 1)) return false;
        $parts = parse_url($value);
        return is_array($parts) && ($parts['user'] ?? '') === '' && ($parts['pass'] ?? '') === '' && !isset($parts['query']) && !isset($parts['fragment']);
    }
    private static function bytes(mixed $value): int
    {
        return EvidenceJson::bytes($value);
    }
}
