SHELL := /bin/sh

PHP ?= php
COMPOSER := $(PHP) composer.phar
PACKAGE_VERSION := $(shell awk -F"'" '/SDK_VERSION = / { print $$2; exit }' src/DebugBundleSdk.php)
SMOKE_DIST_DIR := smoke/dist
SMOKE_ARTIFACT := $(SMOKE_DIST_DIR)/debugbundle-sdk-php-smoke.zip
COMPOSER_IMAGE ?= composer:2
DOCKER_RUN = docker run --rm -v "$(CURDIR):/app" -w /app $(COMPOSER_IMAGE)

.PHONY: test-docker check-docker
test-docker:
	$(DOCKER_RUN) composer test -- $(TEST_ARGS)

check-docker:
	$(DOCKER_RUN) sh -c 'composer validate --strict && composer test && composer typecheck'

.PHONY: coverage-docker
coverage-docker:
	docker build -q -f smoke/Dockerfile.coverage -t debugbundle-php-coverage:local .
	docker run --rm -e XDEBUG_MODE=coverage -v "$(CURDIR):/app" -w /app debugbundle-php-coverage:local sh -c 'vendor/bin/phpunit --configuration phpunit.xml.dist --coverage-clover coverage.xml && php scripts/check_coverage.php coverage.xml'

.PHONY: smoke smoke-artifact

smoke:
	rm -rf "$(SMOKE_DIST_DIR)"
	mkdir -p "$(SMOKE_DIST_DIR)"
	git archive --format=zip --output "$(SMOKE_ARTIFACT)" HEAD
	$(MAKE) smoke-artifact

smoke-artifact:
	$(PHP) smoke/run_app_driven_smoke.php --artifact "$(SMOKE_ARTIFACT)" --version "$(PACKAGE_VERSION)"

.PHONY: smoke-worktree
smoke-worktree:
	mkdir -p "$(SMOKE_DIST_DIR)"
	rm -f "$(SMOKE_DIST_DIR)/debugbundle-sdk-php-worktree.zip"
	$(DOCKER_RUN) sh -c 'zip -q -r smoke/dist/debugbundle-sdk-php-worktree.zip . -x ".git/*" "vendor/*" "smoke/dist/*" ".phpunit.cache/*" "coverage.xml" "composer.phar" "composer-setup.php"'
	$(DOCKER_RUN) php smoke/run_app_driven_smoke.php --artifact smoke/dist/debugbundle-sdk-php-worktree.zip --version "$(PACKAGE_VERSION)"

.PHONY: smoke-fpm
smoke-fpm:
	sh smoke/run_fpm_smoke.sh
