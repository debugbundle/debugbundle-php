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

    public function testComposerMetadataAndCiWorkflowAgreeOnPhp84SupportFloor(): void
    {
        $composerPath = dirname(__DIR__) . '/composer.json';
        $workflowPath = dirname(__DIR__) . '/.github/workflows/ci.yml';

        self::assertFileExists($composerPath);
        self::assertFileExists($workflowPath);

        $composer = (string) file_get_contents($composerPath);
        $workflow = (string) file_get_contents($workflowPath);

        self::assertStringContainsString('"php": ">=8.4"', $composer);
        self::assertStringContainsString("- '8.4'", $workflow);
        self::assertStringNotContainsString("- '8.1'", $workflow);
        self::assertStringNotContainsString("- '8.2'", $workflow);
        self::assertStringNotContainsString("- '8.3'", $workflow);
        self::assertStringContainsString("php-version: '8.4'", $workflow);
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
        self::assertStringContainsString('https://packagist.org/api/update-package', $workflow);
        self::assertStringContainsString('composer require debugbundle/sdk-php:${RELEASE_VERSION}', $workflow);
    }
}