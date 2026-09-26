<?php

declare(strict_types=1);

namespace DebugBundle\Transport;

/** Bounds untrusted retry hints before conversion or deadline arithmetic. */
final class RetryAfter
{
    public static function bounded(?int $milliseconds): int
    {
        return $milliseconds === null ? 1000 : min(300000, max(0, $milliseconds));
    }

    public static function parse(string $value): ?int
    {
        if (is_numeric($value)) {
            $seconds = (float) $value;
            return is_finite($seconds) ? (int) round(min(300, max(0, $seconds)) * 1000) : null;
        }
        // strtotime accepts relative phrases; Retry-After permits HTTP dates only.
        foreach (['!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T', '!D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('GMT'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            // RFC 9110 uses a rolling fifty-year window for obsolete two-digit years.
            if (str_contains($format, 'y') && $date > new \DateTimeImmutable('+50 years')) {
                $date = $date->modify('-100 years');
            }
            return (int) (min(300, max(0, $date->getTimestamp() - time())) * 1000);
        }
        return null;
    }
}
