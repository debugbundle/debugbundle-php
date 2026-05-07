<?php

declare(strict_types=1);

const MINIMUM_PERCENT = 80.0;

$coveragePath = $argv[1] ?? 'coverage.xml';
if (!is_file($coveragePath)) {
    fwrite(STDERR, "Coverage file not found: {$coveragePath}\n");
    exit(1);
}

$xml = simplexml_load_file($coveragePath);
if ($xml === false) {
    fwrite(STDERR, "Coverage file is not valid XML: {$coveragePath}\n");
    exit(1);
}

$offenders = [];
$fileNodes = $xml->xpath('//file');
if ($fileNodes === false) {
    fwrite(STDERR, "Coverage file is missing file nodes: {$coveragePath}\n");
    exit(1);
}

foreach ($fileNodes as $fileNode) {
    $name = (string) ($fileNode['name'] ?? '');
    if ($name === '' || strpos($name, 'src/') === false) {
        continue;
    }

    $metricsNodes = $fileNode->xpath('./metrics');
    if ($metricsNodes === false || $metricsNodes === []) {
        continue;
    }

    $metrics = $metricsNodes[0];
    $statments = (int) ($metrics['statements'] ?? 0);
    $coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
    if ($statments === 0) {
        continue;
    }

    $percent = ($coveredStatements / $statments) * 100;
    if ($percent < MINIMUM_PERCENT) {
        $relativeName = preg_replace('~^.*?/src/~', 'src/', $name) ?: $name;
        $offenders[] = [$relativeName, $percent];
    }
}

if ($offenders !== []) {
    fwrite(STDOUT, sprintf("Per-file coverage check failed. Minimum required: %.0f%%\n", MINIMUM_PERCENT));
    foreach ($offenders as [$filePath, $percent]) {
        fwrite(STDOUT, sprintf("- %s: %.2f%%\n", $filePath, $percent));
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Per-file coverage check passed for 'src/' at >= %.0f%%.\n", MINIMUM_PERCENT));
exit(0);