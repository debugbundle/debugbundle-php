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

.PHONY: smoke smoke-artifact

smoke:
	rm -rf "$(SMOKE_DIST_DIR)"
	mkdir -p "$(SMOKE_DIST_DIR)"
	git archive --format=zip --output "$(SMOKE_ARTIFACT)" HEAD
	$(MAKE) smoke-artifact

smoke-artifact:
	$(PHP) smoke/run_app_driven_smoke.php --artifact "$(SMOKE_ARTIFACT)" --version "$(PACKAGE_VERSION)"
