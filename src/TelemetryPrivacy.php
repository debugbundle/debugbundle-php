<?php

declare(strict_types=1);

namespace DebugBundle;

/** Mandatory, bounded protection for application-owned telemetry fields. */
final class TelemetryPrivacy
{
    private const MAX_BYTES = 262144;
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const KEYS = [
        'authorization', 'proxyauthorization', 'cookie', 'setcookie', 'creditcard',
        'password', 'passwd', 'secret', 'ssn', 'token', 'apikey', 'clientsecret',
        'accesstoken', 'refreshtoken', 'privatekey', 'sessionid', 'bearer',
        'cardnumber', 'cvv', 'cvc', 'pin', 'expiry', 'phone', 'otp', 'verificationcode',
    ];

    /** @param array<string, bool> $additionalFields */
    public static function protect(mixed $value, array $additionalFields = []): mixed
    {
        if (count($additionalFields) > 128) {
            throw new \InvalidArgumentException('unsafe_input');
        }
        $keys = array_fill_keys(self::KEYS, true);
        foreach (array_keys($additionalFields) as $field) {
            if ($field === '' || strlen($field) > 64) {
                throw new \InvalidArgumentException('unsafe_input');
            }
            $keys[self::canonical($field)] = true;
        }

        $nodes = 0;
        $bytes = 0;
        $result = self::visit($value, $keys, array_keys($additionalFields), 0, $nodes, $bytes, true);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('budget_exceeded');
        }
        return $result;
    }

    /** @param array<string, mixed> $event
     *  @param array<string, bool> $extra
     */
    public static function hasSafeEventIdentity(array $event, array $extra = []): bool
    {
        $correlation = $event['correlation'] ?? [];
        if (!is_array($correlation) || count($correlation) > 8) {
            return false;
        }
        $values = array_values($correlation);
        foreach (['schema_version', 'sdk_name', 'sdk_version'] as $field) {
            $values[] = $event[$field] ?? null;
        }
        foreach ($values as $value) {
            if ($value !== null && (!is_string($value) || self::protect($value, $extra) !== $value)) {
                return false;
            }
        }
        return true;
    }

    private static function canonical(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /** @param array<string, bool> $keys
     *  @param list<string> $extra
     */
    private static function visit(mixed $value, array $keys, array $extra, int $depth, int &$nodes, int &$bytes, bool $structured): mixed
    {
        if (++$nodes > 4096) {
            throw new \InvalidArgumentException('budget_exceeded');
        }
        if ($depth > 16) {
            return self::REDACTED;
        }
        if (is_string($value)) {
            if (strlen($value) > 16384) {
                return self::REDACTED;
            }
            if (preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('unsafe_input');
            }
            $bytes += strlen($value);
            self::checkBudget($bytes);
            if ($structured && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                try {
                    $parsed = json_decode($value, true, 17, JSON_THROW_ON_ERROR);
                    if (is_array($parsed)) {
                        $cleaned = self::visit($parsed, $keys, $extra, 0, $nodes, $bytes, false);
                        return json_encode($cleaned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                } catch (\JsonException) {
                    // Recognizable credentials in malformed structured data are withheld below.
                }
            }
            $cleaned = self::scrubText($value, $keys, $extra);
            if ($cleaned === $value && (str_starts_with($value, '{') || str_starts_with($value, '['))
                && preg_match('/(?:password|token|secret|authorization|cookie)["\']?\s*[:=]/i', $value) === 1) {
                return self::REDACTED;
            }
            return $cleaned;
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $items = (array) $value;
            if (count($items) > 256) {
                return self::REDACTED;
            }
            $result = [];
            foreach ($items as $key => $nested) {
                if (is_string($key)) {
                    if (strlen($key) > 128) {
                        continue;
                    }
                    $bytes += strlen($key);
                    self::checkBudget($bytes);
                    if (self::scrubText($key, $keys, $extra) !== $key) {
                        continue;
                    }
                    if (self::sensitiveKey($key, $keys)) {
                        $result[$key] = self::REDACTED;
                        continue;
                    }
                }
                $result[$key] = self::visit($nested, $keys, $extra, $depth + 1, $nodes, $bytes, $structured);
            }
            return $value instanceof \stdClass ? (object) $result : $result;
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new \InvalidArgumentException('unsafe_input');
    }

    private static function checkBudget(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('budget_exceeded');
        }
    }

    /** @param array<string, bool> $keys */
    private static function sensitiveKey(string $key, array $keys): bool
    {
        $segments = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key)));
        if ($segments === false) {
            return false;
        }
        foreach ($segments as $index => $segment) {
            $combined = '';
            for ($next = $index; $next < count($segments); ++$next) {
                $combined .= $segments[$next];
                if (isset($keys[$combined])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, bool> $keys
     *  @param list<string> $extra
     */
    private static function scrubText(string $text, array $keys, array $extra, bool $scanUrls = true): string
    {
        if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $text) === 1
            && preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $text) !== 1) {
            return self::REDACTED;
        }
        if (preg_match('/(?:password|token|secret|authorization|cookie)%3[ad]/i', $text) === 1) {
            $text = rawurldecode($text);
        }
        $text = (string) preg_replace('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', self::REDACTED, $text);
        $text = (string) preg_replace('/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*:\s*[^\r\n]*/i', '$1: [REDACTED]', $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]{6,}/i', '$1 [REDACTED]', $text);
        $text = (string) preg_replace('/\bdbundle_(?:proj|mem|probe|agent)_[A-Za-z0-9_-]+\b/', self::REDACTED, $text);
        $labels = 'authorization|cookie|credit_card|card_number|password|passwd|secret|ssn|token|api_key|client_secret|access_token|refresh_token|private_key|accessToken|refreshToken|privateKey|clientSecret|session_id|cvv|cvc|pin|expiry|phone|otp|verification_code';
        $text = (string) preg_replace('/\b(' . $labels . ')\b(["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)/i', '$1$2[REDACTED]', $text);
        foreach ($extra as $field) {
            $text = (string) preg_replace('/\b(' . preg_quote($field, '/') . ')\b(["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)/i', '$1$2[REDACTED]', $text);
        }
        $text = (string) preg_replace_callback('/(?<![A-Za-z0-9_-])(?:[0-9][ -]?){12,18}[0-9](?![A-Za-z0-9_-])/', static function (array $match): string {
            $digits = preg_replace('/[^0-9]/', '', $match[0]);
            if ($digits === null || strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^(\d)\1+$/', $digits) === 1) {
                return $match[0];
            }
            $sum = 0;
            for ($index = strlen($digits) - 1; $index >= 0; --$index) {
                $digit = (int) $digits[$index];
                if ((strlen($digits) - $index) % 2 === 0) {
                    $digit *= 2;
                    if ($digit > 9) {
                        $digit -= 9;
                    }
                }
                $sum += $digit;
            }
            return $sum % 10 === 0 ? self::REDACTED : $match[0];
        }, $text);
        if (!$scanUrls) {
            return $text;
        }
        return (string) preg_replace_callback('/\bhttps?:\/\/[^\s<>"\']+/i', static function (array $match) use ($keys, $extra): string {
            $raw = rtrim($match[0], ').,;');
            $suffix = substr($match[0], strlen($raw));
            $parts = parse_url($raw);
            if ($parts === false || !isset($parts['host'])) {
                return self::REDACTED;
            }
            $url = ($parts['scheme'] ?? 'https') . '://';
            if (isset($parts['user']) || isset($parts['pass'])) {
                $url .= 'REDACTED@';
            }
            $url .= $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/');
            if (isset($parts['query'])) {
                $pairs = [];
                foreach (explode('&', $parts['query']) as $pair) {
                    [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                    $key = urldecode($name);
                    $decoded = urldecode($value);
                    if (strlen($key) > 128 || self::scrubText($key, $keys, $extra, false) !== $key) {
                        continue;
                    }
                    $pairs[] = rawurlencode($key) . '=' . rawurlencode(self::sensitiveKey($key, $keys) || self::scrubText($decoded, $keys, $extra, false) !== $decoded ? self::REDACTED : $decoded);
                }
                $url .= '?' . implode('&', $pairs);
            }
            return $url . $suffix;
        }, $text);
    }
}
