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
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $nestedValue) {
                if (is_string($key) && isset($redactFields[strtolower($key)])) {
                    $redacted[$key] = self::REDACTED_VALUE;
                    continue;
                }

                $redacted[$key] = self::redactValue($nestedValue, $redactFields);
            }

            return $redacted;
        }

        return $value;
    }
}