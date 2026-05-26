<?php

declare(strict_types=1);

namespace DebugBundle\Tests;

use PHPUnit\Framework\TestCase;

final class RepositoryMetadataTest extends TestCase
{
    public function testStandaloneRepositoryIncludesRequiredGovernanceFiles(): void
    {
        $repoRoot = dirname(__DIR__);

        foreach ([
            'README.md',
            'LICENSE',
            'CHANGELOG.md',
            'SECURITY.md',
            'CONTRIBUTING.md',
            'CODE_OF_CONDUCT.md',
            '.github/ISSUE_TEMPLATE/bug_report.yml',
            '.github/ISSUE_TEMPLATE/feature_request.yml',
            '.github/PULL_REQUEST_TEMPLATE.md',
        ] as $relativePath) {
            self::assertFileExists($repoRoot . '/' . $relativePath);
        }
    }

    public function testComposerMetadataAndCiWorkflowAgreeOnPhp82SupportFloor(): void
    {
        $composerPath = dirname(__DIR__) . '/composer.json';
        $workflowPath = dirname(__DIR__) . '/.github/workflows/ci.yml';

        self::assertFileExists($composerPath);
        self::assertFileExists($workflowPath);

        $composer = (string) file_get_contents($composerPath);
        $workflow = (string) file_get_contents($workflowPath);

        self::assertStringContainsString('"php": ">=8.2"', $composer);
        self::assertStringContainsString("- '8.2'", $workflow);
        self::assertStringContainsString("- '8.3'", $workflow);
        self::assertStringContainsString("- '8.4'", $workflow);
        self::assertStringNotContainsString("- '8.1'", $workflow);
        self::assertStringContainsString("php-version: '8.2'", $workflow);
    }

    public function testStandaloneCiWorkflowExistsWithExpectedPhpSdkValidationSteps(): void
    {
        $workflowPath = dirname(__DIR__) . '/.github/workflows/ci.yml';
        self::assertFileExists($workflowPath);

        $workflow = (string) file_get_contents($workflowPath);
        self::assertStringContainsString('actions/checkout@v4', $workflow);
        self::assertStringContainsString('shivammathur/setup-php@v2', $workflow);
        self::assertStringContainsString('composer validate --strict', $workflow);
        self::assertStringContainsString('composer install --no-interaction --prefer-dist', $workflow);
        self::assertStringContainsString('composer test', $workflow);
        self::assertStringContainsString('composer typecheck', $workflow);
    }

    public function testStandaloneChangelogAndSecurityPolicyAreLaunchReady(): void
    {
        $changelogPath = dirname(__DIR__) . '/CHANGELOG.md';
        $securityPath = dirname(__DIR__) . '/SECURITY.md';

        self::assertFileExists($changelogPath);
        self::assertFileExists($securityPath);

        $changelog = (string) file_get_contents($changelogPath);
        $security = (string) file_get_contents($securityPath);

        self::assertStringContainsString('## [Unreleased]', $changelog);
        self::assertStringContainsString('## [0.1.0] - 2026-05-07', $changelog);
        self::assertStringContainsString('https://github.com/debugbundle/debugbundle-php/security/advisories/new', $security);
    }

    public function testStandaloneCiWorkflowEnforcesPerFileCoverage(): void
    {
        $workflowPath = dirname(__DIR__) . '/.github/workflows/ci.yml';
        $coverageScriptPath = dirname(__DIR__) . '/scripts/check_coverage.php';

        self::assertFileExists($workflowPath);
        self::assertFileExists($coverageScriptPath);

        $workflow = (string) file_get_contents($workflowPath);
        self::assertStringContainsString('coverage: xdebug', $workflow);
        self::assertStringContainsString('--coverage-clover coverage.xml', $workflow);
        self::assertStringContainsString('php scripts/check_coverage.php coverage.xml', $workflow);

        $coverageScript = (string) file_get_contents($coverageScriptPath);
        self::assertStringContainsString('MINIMUM_PERCENT = 80.0', $coverageScript);
        self::assertStringContainsString("'src/'", $coverageScript);
    }

    public function testReleaseWorkflowCoversPhpPackagePublication(): void
    {
        $workflowPath = dirname(__DIR__) . '/.github/workflows/release.yml';
        self::assertFileExists($workflowPath);

        $workflow = (string) file_get_contents($workflowPath);
        self::assertStringContainsString('- "v*"', $workflow);
        self::assertStringContainsString('composer validate --strict', $workflow);
        self::assertStringContainsString('composer install --no-interaction --prefer-dist', $workflow);
        self::assertStringContainsString('composer test', $workflow);
        self::assertStringContainsString('composer typecheck', $workflow);
        self::assertStringContainsString('make smoke', $workflow);
        self::assertStringContainsString('https://packagist.org/api/update-package', $workflow);
        self::assertStringContainsString('php smoke/run_app_driven_smoke.php --package debugbundle/sdk-php:${RELEASE_VERSION}', $workflow);
    }

    public function testStandaloneSmokeHarnessExistsAndIsWiredIntoWorkflows(): void
    {
        $repoRoot = dirname(__DIR__);
        $makefilePath = $repoRoot . '/Makefile';
        $smokeRunnerPath = $repoRoot . '/smoke/run_app_driven_smoke.php';
        $ciWorkflowPath = $repoRoot . '/.github/workflows/ci.yml';
        $releaseWorkflowPath = $repoRoot . '/.github/workflows/release.yml';

        self::assertFileExists($makefilePath);
        self::assertFileExists($smokeRunnerPath);
        self::assertFileExists($ciWorkflowPath);
        self::assertFileExists($releaseWorkflowPath);

        $makefile = (string) file_get_contents($makefilePath);
        $ciWorkflow = (string) file_get_contents($ciWorkflowPath);
        $releaseWorkflow = (string) file_get_contents($releaseWorkflowPath);

        self::assertStringContainsString('.PHONY: smoke', $makefile);
        self::assertStringContainsString('archive --format=zip', $makefile);
        self::assertStringContainsString('smoke/run_app_driven_smoke.php --artifact', $makefile);

        self::assertStringContainsString('make smoke', $ciWorkflow);
        self::assertStringContainsString('make smoke', $releaseWorkflow);
        self::assertStringContainsString('php smoke/run_app_driven_smoke.php --package debugbundle/sdk-php:${RELEASE_VERSION}', $releaseWorkflow);
    }

    public function testReadmeCoversPhpReleaseDocumentationGates(): void
    {
        $readmePath = dirname(__DIR__) . '/README.md';
        self::assertFileExists($readmePath);

        $readme = (string) file_get_contents($readmePath);

        self::assertStringContainsString('## Configuration Reference', $readme);
        self::assertStringContainsString('Configuration sources and precedence:', $readme);
        self::assertStringContainsString('Capture-policy fields are server-owned', $readme);
        self::assertStringContainsString('## Install Examples by Mode', $readme);
        self::assertStringContainsString('## Runtime and Framework Support', $readme);
        self::assertStringContainsString('## Dependency Alignment', $readme);
        self::assertStringContainsString('## Service Naming', $readme);
        self::assertStringContainsString('## Safe Startup and Status', $readme);
        self::assertStringContainsString('## First-Event Verification', $readme);
        self::assertStringContainsString('make smoke', $readme);
        self::assertStringContainsString('same-origin', $readme);
        self::assertStringContainsString('allowed origins', $readme);
        self::assertStringContainsString('rate limiting', $readme);
        self::assertStringContainsString('credential isolation', $readme);
        self::assertStringContainsString('missing token', $readme);
    }
}