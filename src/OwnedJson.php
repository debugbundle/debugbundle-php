<?php

declare(strict_types=1);

namespace DebugBundle;

/** Copy native JSON without clone, magic properties or application serializers. */
final class OwnedJson
{
    public static function copy(mixed $value): mixed
    {
        $nodes = 4096;
        $bytes = 8 * 1024 * 1024;
        return self::visit($value, 0, $nodes, $bytes);
    }

    private static function visit(mixed $value, int $depth, int &$nodes, int &$bytes): mixed
    {
        if (--$nodes < 0 || $depth > 16 || $bytes < 0) throw new ValidationBudgetExceeded();
        if (is_string($value)) {
            $bytes -= strlen($value) + 2;
            if ($bytes < 0) throw new ValidationBudgetExceeded();
            if (preg_match('//u', $value) !== 1) throw new \InvalidArgumentException('non-JSON string');
            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            $bytes -= 24;
            return $value;
        }
        $object = is_object($value) && get_class($value) === \stdClass::class;
        if (!is_array($value) && !$object) throw new \InvalidArgumentException('non-native JSON value');
        $items = (array) $value;
        if (count($items) * 2 > $nodes) throw new ValidationBudgetExceeded();
        $bytes -= 2 + 2 * count($items);
        $result = [];
        foreach ($items as $key => $child) {
            if (is_string($key)) self::visit($key, $depth + 1, $nodes, $bytes);
            $result[$key] = self::visit($child, $depth + 1, $nodes, $bytes);
        }
        return $object ? (object) $result : $result;
    }
}
