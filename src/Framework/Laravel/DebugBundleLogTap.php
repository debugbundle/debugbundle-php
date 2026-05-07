<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Laravel;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Logging\DebugBundleHandler;
use Monolog\Logger;

final class DebugBundleLogTap
{
    public function __construct(private readonly DebugBundleSdk $sdk)
    {
    }

    public function __invoke(Logger $logger): void
    {
        $logger->pushHandler(new DebugBundleHandler($this->sdk));
    }
}