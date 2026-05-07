# Contributing

## Development Workflow

1. Create a feature branch from `main`.
2. Implement using Red/Green TDD.
3. Install dependencies: `composer install --no-interaction --prefer-dist`.
4. Run local checks:
   - `composer validate --strict`
   - `composer test`
   - `composer typecheck`
   - `vendor/bin/phpunit --coverage-clover coverage.xml`
   - `php scripts/check_coverage.php coverage.xml`
5. Update docs when behavior or public integration guidance changes.
6. Open a pull request with validation evidence.

## Rules

- Keep the SDK fail-closed around validation and fail-open toward host applications.
- Do not add backwards-compatibility shims during pre-production.
- Keep framework adapters thin over the shared SDK core.