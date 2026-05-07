<?php

declare(strict_types=1);

namespace DebugBundle\Logging;

use DebugBundle\DebugBundleSdk;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class DebugBundleHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly DebugBundleSdk $sdk,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;
        if ($record->extra !== []) {
            $context = [...$context, ...$record->extra];
        }

        $this->sdk->captureLog($record->message, strtolower($record->level->getName()), $context);
    }
}