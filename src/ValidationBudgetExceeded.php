<?php

declare(strict_types=1);

namespace DebugBundle;

/** Distinguish exhausted validation from invalid output that permits fallback. */
final class ValidationBudgetExceeded extends \RuntimeException
{
}
