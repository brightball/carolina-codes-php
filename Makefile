# Quality gates for carolina-codes-php (raw PHP, no Composer app bootstrap).
# Tooling lives under tools/ (psalm, php-cs-fixer). App sources are the root *.php files.
GITLEAKS_MISE := $(HOME)/.local/share/mise/installs/gitleaks/8.30.1
export PATH := $(GITLEAKS_MISE):$(HOME)/.local/bin:$(PATH)

COMPOSER ?= composer
PHP ?= php
GITLEAKS ?= gitleaks
PSALM := $(CURDIR)/tools/vendor/bin/psalm
PHP_CS_FIXER := $(CURDIR)/tools/vendor/bin/php-cs-fixer

.PHONY: tools test sast audit secrets lint fmt check hooks

tools:
	$(COMPOSER) install --working-dir=$(CURDIR)/tools --no-interaction --prefer-dist

test:
	$(PHP) test.php

sast: tools
	$(PSALM) --config=$(CURDIR)/psalm.xml --taint-analysis --no-progress --no-cache

audit:
	@test ! -f $(CURDIR)/composer.json
	$(COMPOSER) audit --working-dir=$(CURDIR)/tools --locked

secrets:
	$(GITLEAKS) detect --source $(CURDIR) --verbose --redact

lint: tools
	cd $(CURDIR)/tools && $(PHP_CS_FIXER) fix --config=$(CURDIR)/.php-cs-fixer.php --dry-run --diff --ansi

fmt: tools
	cd $(CURDIR)/tools && $(PHP_CS_FIXER) fix --config=$(CURDIR)/.php-cs-fixer.php --ansi

check: test sast audit secrets lint

hooks:
	pre-commit install
	git config core.hooksPath .githooks
