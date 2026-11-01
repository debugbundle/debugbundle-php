<?php

declare(strict_types=1);

namespace DebugBundle;

final class Redaction
{
    public const REDACTED_VALUE = '[REDACTED]';

    /** @var list<string> */
    public const DEFAULT_REDACT_FIELDS = [
        'authorization',
        'cookie',
        'credit_card',
        'password',
        'secret',
        'ssn',
        'token',
    ];

    /** @param array<string, bool> $redactFields */
    public static function redactValue(mixed $value, array $redactFields): mixed
    {
        $nodes = 0;
        $bytes = 0;
        return self::visit($value, $redactFields, 0, $nodes, $bytes);
    }

    /** @param array<string, bool> $redactFields */
    private static function visit(mixed $value, array $redactFields, int $depth, int &$nodes, int &$bytes): mixed
    {
        // Bound the compatibility key pass before the mandatory privacy visitor.
        // In particular, PHP arrays can contain references back to themselves.
        if (++$nodes > 4096 || $depth > 16 || $bytes > 262144) return self::REDACTED_VALUE;
        if (is_string($value)) {
            $bytes += strlen($value);
            return strlen($value) > 16384 || $bytes > 262144 ? self::REDACTED_VALUE : $value;
        }
        if (is_array($value)) {
            if (count($value) > 256) return [];
            $redacted = [];
            foreach ($value as $key => $nestedValue) {
                if (is_string($key) && isset($redactFields[strtolower($key)])) {
                    $redacted[$key] = self::REDACTED_VALUE;
                    continue;
                }

                $redacted[$key] = self::visit($nestedValue, $redactFields, $depth + 1, $nodes, $bytes);
            }

            return $redacted;
        }

        return $value;
    }
}
