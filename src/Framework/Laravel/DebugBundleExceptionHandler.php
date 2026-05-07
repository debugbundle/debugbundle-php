<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Laravel;

use DebugBundle\DebugBundleSdk;
use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandlerContract;
use Throwable;

final class DebugBundleExceptionHandler implements LaravelExceptionHandlerContract
{
    public function __construct(
        private readonly DebugBundleSdk $sdk,
        private readonly LaravelExceptionHandlerContract $innerHandler,
    ) {
    }

    public function report(Throwable $e)
    {
        if ($this->innerHandler->shouldReport($e)) {
            $this->sdk->captureException($e);
        }

        $this->innerHandler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->innerHandler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->innerHandler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->innerHandler->renderForConsole($output, $e);
    }
}