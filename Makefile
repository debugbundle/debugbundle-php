SHELL := /bin/sh

PHP ?= php
COMPOSER := $(PHP) composer.phar
PACKAGE_VERSION := $(shell awk -F"'" '/SDK_VERSION = / { print $$2; exit }' src/DebugBundleSdk.php)
SMOKE_DIST_DIR := smoke/dist
SMOKE_ARTIFACT := $(SMOKE_DIST_DIR)/debugbundle-sdk-php-smoke.zip

.PHONY: smoke

smoke:
	rm -rf "$(SMOKE_DIST_DIR)"
	mkdir -p "$(SMOKE_DIST_DIR)"
	$(COMPOSER) archive --format=zip --dir "$(SMOKE_DIST_DIR)" --file debugbundle-sdk-php-smoke
	$(PHP) smoke/run_app_driven_smoke.php --artifact "$(SMOKE_ARTIFACT)" --version "$(PACKAGE_VERSION)"