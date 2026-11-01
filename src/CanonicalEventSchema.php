<?php

declare(strict_types=1);

namespace DebugBundle;

/** Closed interpreter for the vendored accepting Draft 7 contract. No remote refs. */
final class CanonicalEventSchema
{
    private const KEYWORDS = ['$schema', '$id', '$comment', '$ref', 'type', 'anyOf', 'const', 'enum', 'properties',
        'required', 'additionalProperties', 'items', 'minimum', 'maximum', 'exclusiveMinimum', 'minLength',
        'maxLength', 'minItems', 'maxItems', 'format', 'pattern'];
    private static ?self $instance = null;
    /** @var array<string, mixed> */
    private array $root;

    /** @param array<string, mixed> $schema */
    public function __construct(array $schema)
    {
        $this->root = $schema;
        $this->compile($schema);
    }

    private static function engine(): self
    {
        if (self::$instance === null) {
            $schema = json_decode((string) file_get_contents(__DIR__ . '/event-envelope.schema.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($schema)) throw new \InvalidArgumentException('missing schema');
            self::$instance = new self($schema);
        }
        return self::$instance;
    }

    public static function valid(mixed $value): bool
    {
        try { return self::checked($value); }
        catch (\Throwable) { return false; }
    }

    /** Hook validation distinguishes exhausted work from invalid fallback. */
    public static function checked(mixed $value): bool
    {
        $engine = self::engine();
        $steps = 100000;
        return $engine->check($engine->root, $value, $steps, 0);
    }

    /** PHP empty-map arrays are an API idiom; encode their object shape at the wire boundary. */
    public static function project(mixed $value, bool $wire = false): mixed
    {
        $engine = self::engine();
        return $engine->normalize($engine->root, $value, $wire);
    }

    /** First-capture fatal events already use SDK-owned canonical map shapes.
     * Do not allocate a schema interpreter in an exhausted-memory shutdown path.
     * Ordinary capture/hooks load it before retaining application replacements.
     * @param array<string, mixed> $event
     */
    public static function wireEvent(array $event): mixed
    {
        return self::$instance === null ? $event : self::$instance->normalize(self::$instance->root, $event, true);
    }

    private function compile(mixed $node): void
    {
        if (is_bool($node)) return;
        if (!is_array($node) || array_diff(array_keys($node), self::KEYWORDS) !== []) throw new \InvalidArgumentException('unsupported schema keyword');
        foreach ((array) ($node['type'] ?? []) as $type) {
            if (!in_array($type, ['null', 'boolean', 'number', 'integer', 'string', 'array', 'object'], true)) throw new \InvalidArgumentException('unsupported schema type');
        }
        if (!in_array($node['format'] ?? null, [null, 'uuid', 'date-time'], true)) throw new \InvalidArgumentException('unsupported schema format');
        if (isset($node['$ref'])) $this->resolve($node['$ref']);
        foreach ((array) ($node['properties'] ?? []) as $child) $this->compile($child);
        foreach ((array) ($node['anyOf'] ?? []) as $child) $this->compile($child);
        foreach (['items', 'additionalProperties'] as $key) if (array_key_exists($key, $node)) $this->compile($node[$key]);
    }

    private function resolve(mixed $reference): mixed
    {
        if (!is_string($reference) || !str_starts_with($reference, '#/')) throw new \InvalidArgumentException('external schema ref');
        $node = $this->root;
        foreach (explode('/', substr($reference, 2)) as $part) {
            if (!is_array($node)) throw new \InvalidArgumentException('invalid schema ref');
            $key = str_replace(['~1', '~0'], ['/', '~'], $part);
            $node = $node[$key] ?? throw new \InvalidArgumentException('missing schema ref');
        }
        return $node;
    }

    private function check(mixed $node, mixed $value, int &$steps, int $depth): bool
    {
        if (--$steps < 0 || $depth > 64) throw new ValidationBudgetExceeded();
        if (is_bool($node)) return $node;
        if (!is_array($node)) return false;
        if (isset($node['$ref'])) return $this->check($this->resolve($node['$ref']), $value, $steps, $depth + 1);
        if (isset($node['anyOf'])) {
            $matched = false;
            foreach ((array) $node['anyOf'] as $child) if ($this->check($child, $value, $steps, $depth + 1)) { $matched = true; break; }
            if (!$matched) return false;
        }
        if (isset($node['type'])) {
            $matched = false;
            foreach ((array) $node['type'] as $type) if ($this->type($value, $type)) { $matched = true; break; }
            if (!$matched) return false;
        }
        if (array_key_exists('const', $node) && !self::same($value, $node['const'])) return false;
        if (isset($node['enum'])) {
            $matched = false;
            foreach ((array) $node['enum'] as $choice) if (self::same($value, $choice)) { $matched = true; break; }
            if (!$matched) return false;
        }
        if (is_array($value) || $value instanceof \stdClass) {
            if (($node['type'] ?? null) === 'array') {
                if (count((array) $value) < ($node['minItems'] ?? 0) || count((array) $value) > ($node['maxItems'] ?? PHP_INT_MAX)) return false;
                if (isset($node['items'])) foreach ((array) $value as $child) if (!$this->check($node['items'], $child, $steps, $depth + 1)) return false;
            } else {
                $items = (array) $value;
                foreach ((array) ($node['required'] ?? []) as $key) if (!array_key_exists($key, $items)) return false;
                $properties = (array) ($node['properties'] ?? []);
                foreach ($items as $key => $child) if (!$this->check($properties[$key] ?? ($node['additionalProperties'] ?? true), $child, $steps, $depth + 1)) return false;
            }
        }
        if (is_string($value)) {
            $length = preg_match_all('/./us', $value) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
            if ($length < ($node['minLength'] ?? 0) || $length > ($node['maxLength'] ?? PHP_INT_MAX)) return false;
            if (isset($node['pattern']) && (!is_string($node['pattern']) || preg_match('~' . str_replace('~', '\\~', $node['pattern']) . '~u', $value) !== 1)) return false;
            if (($node['format'] ?? null) === 'uuid' && !EvidenceWire::uuid($value)) return false;
            if (($node['format'] ?? null) === 'date-time' && !EvidenceWire::timestamp($value)) return false;
        }
        if (is_int($value) || is_float($value)) {
            if ((isset($node['minimum']) && $value < $node['minimum']) || (isset($node['maximum']) && $value > $node['maximum']) || (isset($node['exclusiveMinimum']) && $value <= $node['exclusiveMinimum'])) return false;
        }
        return true;
    }

    private function type(mixed $value, mixed $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'integer' => (is_int($value) || is_float($value)) && is_finite((float) $value) && floor((float) $value) === (float) $value,
            'array' => is_array($value) && array_is_list($value),
            'object' => (is_object($value) && get_class($value) === \stdClass::class) || (is_array($value) && ($value === [] || !array_is_list($value))),
            default => false,
        };
    }

    private static function same(mixed $value, mixed $choice): bool
    {
        return $value === $choice || ((is_int($value) || is_float($value)) && (is_int($choice) || is_float($choice)) && $value == $choice);
    }

    private function normalize(mixed $node, mixed $value, bool $wire): mixed
    {
        if (!is_array($node)) return $value;
        if (isset($node['$ref'])) return $this->normalize($this->resolve($node['$ref']), $value, $wire);
        foreach ((array) ($node['anyOf'] ?? []) as $child) {
            $steps = 100000;
            if ($this->check($child, $value, $steps, 0)) return $this->normalize($child, $value, $wire);
        }
        if (($node['type'] ?? null) === 'array' && is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->normalize($node['items'] ?? [], $child, $wire), $value);
        }
        if (($node['type'] ?? null) !== 'object' || (!is_array($value) && !$value instanceof \stdClass)) return $value;
        $result = [];
        $properties = (array) ($node['properties'] ?? []);
        foreach ((array) $value as $key => $child) $result[$key] = $this->normalize($properties[$key] ?? ($node['additionalProperties'] ?? []), $child, $wire);
        return $wire ? (object) $result : (isset($node['properties']) ? $result : ($value instanceof \stdClass ? (object) $result : $result));
    }
}
