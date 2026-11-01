<?php

declare(strict_types=1);

namespace DebugBundle;

/**
 * Structured snapshots use native final accessors; no argument values, object
 * graphs or custom formatters enter them. Legacy native text has a separately
 * guarded compatibility path. No raw Throwable survives into the SDK queue.
 *
 * @phpstan-type Frame array{function: ?string, file: ?string, line: ?int, column: ?int, classification: string}
 * @phpstan-type Node array{id: int, name: string, message: string, stack: ?string, trace_origin: string, parsing_status: string, frames: list<Frame>, relationships: list<array{kind: string, target_id: int}>}
 * @phpstan-type Graph array{version: int, runtime: string, root_id: int, nodes: non-empty-list<Node>, truncated: bool, unavailable_reason: ?string}
 */
final class ExceptionEvidence
{
    /** @return Graph */
    public static function snapshot(\Throwable $error, bool $fatal = false, ?int $budgetBytes = null, bool $captureTrace = false): array
    {
        $maximum = min($fatal ? 4096 : 32768, max(512, $budgetBytes ?? 32768));
        $graph = self::emptyGraph();
        /** @var list<\Throwable> $sources */
        $sources = [];
        /** @var array<int, bool> $seen */
        $seen = [];
        $current = $error;
        while ($current !== null) {
            if (isset($seen[spl_object_id($current)]) || count($sources) >= ($fatal ? 2 : 5)) {
                $graph['truncated'] = true;
                break;
            }
            $seen[spl_object_id($current)] = true;
            $id = count($sources);
            $node = self::node($id, self::text($current::class, 256, $graph['truncated']),
                self::text($current->getMessage(), $fatal ? 512 : 2048, $graph['truncated']));
            if ($id === 0 && $captureTrace) $node['trace_origin'] = 'capture';
            $previousGraph = $graph;
            if ($id === 0) $graph['nodes'][0] = $node;
            else $graph['nodes'][] = $node;
            if ($id > 0 && isset($graph['nodes'][$id - 1])) {
                $graph['nodes'][$id - 1]['relationships'][] = ['kind' => 'cause', 'target_id' => $id];
            }
            if (!self::fits($graph, $maximum)) {
                if ($id > 0) {
                    $graph = $previousGraph;
                    $graph['truncated'] = true;
                    break;
                }
                $graph['nodes'][0]['name'] = self::text($node['name'], 64, $graph['truncated']);
                $graph['nodes'][0]['message'] = self::text($node['message'], 128, $graph['truncated']);
                if (!self::fits($graph, $maximum)) {
                    $graph['nodes'][0]['name'] = 'Error';
                    $graph['nodes'][0]['message'] = 'Evidence budget exceeded';
                    $graph['truncated'] = true;
                }
            }
            $sources[] = $current;
            $current = $current->getPrevious();
        }
        foreach ($sources as $id => $source) {
            if (!isset($graph['nodes'][$id])) continue;
            $location = self::frame(['file' => $source->getFile(), 'line' => $source->getLine()], $graph['truncated']);
            if ($location !== null) self::appendFrame($graph, $id, $location, $maximum);
            $trace = $source->getTrace();
            $limit = $fatal ? 8 : 32;
            foreach ($trace as $native) {
                if (!isset($graph['nodes'][$id])) break;
                if (count($graph['nodes'][$id]['frames']) >= $limit) {
                    $node = $graph['nodes'][$id];
                    $node['parsing_status'] = 'truncated';
                    $graph['nodes'][$id] = $node;
                    $graph['truncated'] = true;
                    break;
                }
                $frame = self::frame($native, $graph['truncated']);
                if ($frame !== null && !self::appendFrame($graph, $id, $frame, $maximum)) break;
            }
            if (isset($graph['nodes'][$id]) && $graph['nodes'][$id]['parsing_status'] !== 'truncated') {
                $node = $graph['nodes'][$id];
                $node['parsing_status'] = $node['frames'] === [] ? 'unavailable' : 'parsed';
                $graph['nodes'][$id] = $node;
            }
        }
        return self::finish($graph);
    }

    /** Supplied fatal metadata only; never enable fatal_error_backtraces.
     * @param array<string, mixed> $error
     * @return Graph
     */
    public static function fromFatal(array $error, ?int $budgetBytes = null): array
    {
        $maximum = min(4096, max(512, $budgetBytes ?? 4096));
        $graph = self::emptyGraph();
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'fatal error';
        $graph['nodes'][0] = self::node(0, 'ErrorException', self::text($message, 512, $graph['truncated']));
        $graph['nodes'][0]['trace_origin'] = 'native_fatal';
        if (!self::fits($graph, $maximum)) {
            $graph['nodes'][0]['message'] = self::text($message, 128, $graph['truncated']);
            if (!self::fits($graph, $maximum)) $graph['nodes'][0]['message'] = 'fatal error';
            $graph['truncated'] = true;
        }
        $location = self::frame(['file' => $error['file'] ?? null, 'line' => $error['line'] ?? null], $graph['truncated']);
        if ($location !== null && !self::appendFrame($graph, 0, $location, $maximum)) {
            $graph['nodes'][0]['message'] = self::text($message, 128, $graph['truncated']);
            $location['file'] = $location['file'] === null ? null : self::text($location['file'], 256, $graph['truncated']);
            self::appendFrame($graph, 0, $location, $maximum);
        }
        $supplied = is_array($error['trace'] ?? null);
        if ($supplied) {
            foreach ($error['trace'] as $native) {
                if (count($graph['nodes'][0]['frames']) >= 8) { $graph['truncated'] = true; break; }
                $frame = self::frame($native, $graph['truncated']);
                if ($frame !== null && !self::appendFrame($graph, 0, $frame, $maximum)) break;
            }
        }
        $graph['nodes'][0]['parsing_status'] = $graph['truncated'] ? 'truncated' : ($supplied ? 'parsed' : 'partial');
        if (!$supplied) $graph['unavailable_reason'] = PHP_VERSION_ID < 80500 ? 'unsupported' : 'unknown';
        if ($graph['nodes'][0]['frames'] === []) {
            $graph['nodes'][0]['trace_origin'] = 'unavailable';
            $graph['nodes'][0]['parsing_status'] = $graph['truncated'] ? 'truncated' : 'unavailable';
        }
        return self::finish($graph);
    }

    /** Keep ordinary native text without virtual formatting, with an argument-
     * free bounded fallback. The new graph retains native locations separately.
     * @param Graph|null $snapshot
     */
    public static function legacyStack(\Throwable $error, ?array $snapshot = null): string
    {
        $native = self::nativeLegacyStack($error);
        if ($native !== null) return $native;
        $graph = $snapshot ?? self::snapshot($error);
        $blocks = [];
        foreach (array_reverse($graph['nodes']) as $node) {
            $location = $node['frames'][0] ?? null;
            $block = $node['name'] . ': ' . $node['message'];
            if ($location !== null && $location['file'] !== null) $block .= ' in ' . $location['file'] . ':' . ($location['line'] ?? 0);
            $block .= "\nStack trace:";
            foreach (array_slice($node['frames'], 1) as $index => $frame) {
                $block .= "\n#" . $index . ' ' . ($frame['file'] ?? '[internal function]') . '(' . ($frame['line'] ?? 0) . '): ' . ($frame['function'] ?? '{main}') . '()';
            }
            $blocks[] = $block;
        }
        $truncated = false;
        return self::clip(implode("\n\nNext ", $blocks), 16384, $truncated);
    }

    /** Preserve established native text for ordinary bounded traces. Call the
     * native base formatter directly only after rejecting virtual overrides and
     * oversized native inputs. Argument values never enter the new graph. */
    private static function nativeLegacyStack(\Throwable $error): ?string
    {
        $limit = ini_get('zend.exception_string_param_max_len');
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit > 64) return null;
        $current = $error; $seen = []; $estimate = 0;
        for ($depth = 0; $current !== null; $depth++) {
            if ($depth >= 8 || isset($seen[spl_object_id($current)])) return null;
            $seen[spl_object_id($current)] = true;
            if (strlen($current::class) > 256 || strlen($current->getMessage()) > 16384 || strlen($current->getFile()) > 2048) return null;
            $method = new \ReflectionMethod($current, '__toString');
            if (!in_array($method->getDeclaringClass()->getName(), ['Exception', 'Error'], true)) return null;
            $trace = $current->getTrace(); if (count($trace) > 128) return null;
            $estimate += strlen($current::class) + strlen($current->getMessage()) + strlen($current->getFile()) + 64;
            foreach ($trace as $frame) {
                $cost = self::legacyFrameCost($frame, (int) $limit);
                if ($cost === null) return null;
                $estimate += $cost;
                if ($estimate > 16384) return null;
            }
            $current = $current->getPrevious();
        }
        try {
            $method = new \ReflectionMethod($error instanceof \Exception ? \Exception::class : \Error::class, '__toString');
            $value = $method->invoke($error); return is_string($value) ? $value : null;
        } catch (\Throwable) { return null; }
    }

    private static function legacyFrameCost(mixed $frame, int $argumentLimit): ?int
    {
        if (!is_array($frame)) return null;
        $cost = 64;
        foreach (['file' => 2048, 'class' => 256, 'function' => 256] as $field => $maximum) {
            $value = $frame[$field] ?? '';
            if (!is_string($value) || strlen($value) > $maximum) return null;
            $cost += strlen($value);
        }
        $args = $frame['args'] ?? [];
        if (!is_array($args) || count($args) > 32) return null;
        foreach ($args as $arg) {
            if (is_object($arg)) {
                $name = $arg::class;
                if (strlen($name) > 256) return null;
                $cost += strlen($name) + 16;
            } elseif (is_string($arg)) $cost += min(strlen($arg), $argumentLimit) * 4 + 8;
            else $cost += 64;
        }
        return $cost;
    }

    /** @return Graph */
    private static function emptyGraph(): array
    {
        return ['version' => 1, 'runtime' => 'php', 'root_id' => 0, 'nodes' => [self::node(0, 'Error', 'Unknown error')], 'truncated' => false, 'unavailable_reason' => null];
    }

    /** @return Node */
    private static function node(int $id, string $name, string $message): array
    {
        return ['id' => $id, 'name' => $name, 'message' => $message, 'stack' => null, 'trace_origin' => 'runtime_reported',
            'parsing_status' => 'unavailable', 'frames' => [], 'relationships' => []];
    }

    /** @return Frame|null */
    private static function frame(mixed $native, bool &$truncated): ?array
    {
        if (!is_array($native)) return null;
        $file = is_string($native['file'] ?? null) && $native['file'] !== '' ? self::text($native['file'], 2048, $truncated) : null;
        if ($file !== null && preg_match('/^https?:\/\//i', $file) === 1) {
            $url = parse_url($file);
            $file = is_array($url) && isset($url['scheme'], $url['host'])
                ? $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '') . ($url['path'] ?? '') : null;
        }
        $function = is_string($native['function'] ?? null) ? self::text($native['function'], 256, $truncated) : null;
        if ($function !== null && is_string($native['class'] ?? null)) {
            $function = self::text($native['class'] . (in_array($native['type'] ?? null, ['::', '->'], true) ? $native['type'] : '::') . $function, 256, $truncated);
        }
        $line = is_int($native['line'] ?? null) && $native['line'] > 0 ? $native['line'] : null;
        if ($file === null && $function === null) return null;
        return ['function' => $function, 'file' => $file, 'line' => $line, 'column' => null,
            'classification' => $file === null ? 'unknown' : (str_contains($file, '/debugbundle-php/src/') ? 'sdk' : (str_contains($file, '/vendor/') ? 'framework' : 'application'))];
    }

    /**
     * @param Graph $graph
     * @param Frame $frame
     * @param-out Graph $graph
     */
    private static function appendFrame(array &$graph, int $id, array $frame, int $maximum): bool
    {
        if (!isset($graph['nodes'][$id])) return false;
        $node = $graph['nodes'][$id]; $node['frames'][] = $frame;
        $graph['nodes'][$id] = $node;
        if (self::fits($graph, $maximum)) return true;
        $node['frames'] = array_slice($node['frames'], 0, -1);
        $node['parsing_status'] = 'truncated';
        $graph['nodes'][$id] = $node; $graph['truncated'] = true; return false;
    }

    /** Reserve small structural space for final truncation/availability flags.
     * @param Graph $graph
     */
    private static function fits(array $graph, int $maximum): bool
    {
        return strlen(json_encode($graph, JSON_THROW_ON_ERROR)) <= $maximum - 64;
    }

    /**
     * @param Graph $graph
     * @return Graph
     */
    private static function finish(array $graph): array
    {
        if ($graph['truncated']) $graph['unavailable_reason'] = 'budget_exceeded';
        return $graph;
    }

    private static function text(string $value, int $maximum, bool &$truncated): string
    {
        $clipped = self::clip($value, $maximum, $truncated);
        try { $protected = TelemetryPrivacy::protect($clipped); }
        catch (\Throwable) { $truncated = true; return '[REDACTED]'; }
        return is_string($protected) ? self::clip($protected, $maximum, $truncated) : '[REDACTED]';
    }

    private static function clip(string $value, int $maximum, bool &$truncated): string
    {
        $result = substr($value, 0, $maximum);
        if (strlen($value) > $maximum) $truncated = true;
        for ($attempt = 0; $attempt < 4 && preg_match('//u', $result) !== 1; $attempt++) $result = substr($result, 0, -1);
        if (preg_match('//u', $result) !== 1) { $truncated = true; return ''; }
        if ($result !== $value) $truncated = true;
        return $result;
    }
}
