<?php

declare(strict_types=1);

namespace DebugBundle;

/** Byte accounting for validated evidence; never serializes application objects. */
final class EvidenceJson
{
    public static function bytes(mixed $value): int
    {
        $remaining = 4096;
        try { return self::count($value, 0, $remaining); } catch (\Throwable) { return 32769; }
    }

    private static function count(mixed $value, int $depth, int &$remaining): int
    {
        if ($depth > 16 || --$remaining < 0) return 32769;
        if ($value === null) return 4;
        if (is_bool($value)) return $value ? 4 : 5;
        if (is_string($value)) return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
        if (is_int($value) || is_float($value)) return self::numberBytes($value);
        if (!is_array($value) || count($value) > 256) return 32769;
        $list = array_is_list($value);
        $bytes = 2 + max(0, count($value) - 1);
        foreach ($value as $key => $item) {
            if (!$list) $bytes += self::count((string) $key, $depth + 1, $remaining) + 1;
            $bytes += self::count($item, $depth + 1, $remaining);
        }
        return $bytes;
    }

    private static function numberBytes(int|float $value): int
    {
        if (is_int($value) && abs($value) <= 9007199254740991) return strlen((string) $value);
        $number = (float) $value;
        if (!is_finite($number)) return 32769;
        if ($number == 0) return 1;
        $negative = $number < 0;
        $number = abs($number);
        // Find the shortest round-tripping native representation without
        // changing the host's serialize_precision or locale configuration.
        $representation = '';
        for ($precision = 1; $precision <= 17; $precision++) {
            $representation = str_replace(',', '.', sprintf('%.' . $precision . 'g', $number));
            if ((float) $representation === $number) break;
        }
        $parts = explode('e', strtolower($representation), 2);
        $mantissa = $parts[0];
        $before = strlen(explode('.', $mantissa, 2)[0]);
        $raw = str_replace('.', '', $mantissa);
        $leading = strlen($raw) - strlen(ltrim($raw, '0'));
        $digits = rtrim(ltrim($raw, '0'), '0');
        $position = $before + (int) ($parts[1] ?? 0) - $leading;
        $power = $position - 1;
        if ($power >= -6 && $power <= 20) {
            $length = $position <= 0 ? 2 - $position + strlen($digits) : max($position, strlen($digits));
            if ($position > 0 && $position < strlen($digits)) $length++;
        } else {
            $length = strlen($digits) + (strlen($digits) > 1 ? 1 : 0) + 2 + strlen((string) abs($power));
        }
        return $length + ($negative ? 1 : 0);
    }
}
