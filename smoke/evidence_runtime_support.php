<?php

declare(strict_types=1);

// The minimum-runtime lane intentionally needs no development dependencies.
function loadEvidenceRuntimeSdk(?string $autoload): void
{
    if ($autoload !== null) { require $autoload; return; }
    spl_autoload_register(static function (string $name): void {
        if (!str_starts_with($name, 'DebugBundle\\')) return;
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($name, strlen('DebugBundle\\'))) . '.php';
        if (is_file($path)) require $path;
    });
}

function evidenceRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
