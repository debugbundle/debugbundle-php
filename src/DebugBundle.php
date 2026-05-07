<?php

declare(strict_types=1);

namespace DebugBundle;

final class DebugBundle
{
    private static ?DebugBundleSdk $sdk = null;

    /** @param array<string, mixed> $config */
    public static function init(array $config): void
    {
        self::sdk()->init($config);
    }

    /** @param array<string, mixed>|null $context */
    public static function captureException(\Throwable $error, ?array $context = null): void
    {
        self::sdk()->captureException($error, $context);
    }

    /** @param array<string, mixed>|null $context */
    public static function captureError(\Throwable $error, ?array $context = null): void
    {
        self::sdk()->captureError($error, $context);
    }

    /** @param array<string, mixed>|null $context */
    public static function captureLog(string $message, string $level = 'warning', ?array $context = null): void
    {
        self::sdk()->captureLog($message, $level, $context);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $response
     * @param array<string, mixed>|null $context
     */
    public static function captureRequest(array $request, ?array $response = null, ?array $context = null): void
    {
        self::sdk()->captureRequest($request, $response, $context);
    }

    /** @param array<string, mixed>|null $context */
    public static function captureMessage(string $message, ?string $level = null, ?array $context = null): void
    {
        self::sdk()->captureMessage($message, $level, $context);
    }

    public static function setContext(string $key, mixed $value): void
    {
        self::sdk()->setContext($key, $value);
    }

    public static function flush(): void
    {
        self::sdk()->flush();
    }

    /** @return 'healthy'|'degraded'|'disconnected' */
    public static function getStatus(): string
    {
        return self::sdk()->getStatus();
    }

    public static function getLastEventAt(): ?float
    {
        return self::sdk()->getLastEventAt();
    }

    /** @param array<string, mixed>|null $opts */
    public static function probe(string $label, mixed $data, ?array $opts = null): void
    {
        self::sdk()->probe($label, $data, $opts);
    }

    public static function captureErrors(): void
    {
        self::sdk()->captureErrors();
    }

    public static function captureExceptions(): void
    {
        self::sdk()->captureExceptions();
    }

    public static function captureShutdown(): void
    {
        self::sdk()->captureShutdown();
    }

    private static function sdk(): DebugBundleSdk
    {
        if (self::$sdk === null) {
            self::$sdk = new DebugBundleSdk();
        }

        return self::$sdk;
    }
}