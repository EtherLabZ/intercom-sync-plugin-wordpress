# =============================================================================
# Makefile — Developer convenience commands for Intercom WooCommerce Sync.
# Run `make help` to see all available targets.
# =============================================================================

SHELL := /bin/bash
.DEFAULT_GOAL := help

# Read the version from the plugin header (used in help output).
VERSION := $(shell grep -m1 "Version:" intercom-woo-sync.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

# ---------------------------------------------------------------------------
# Help
# ---------------------------------------------------------------------------

.PHONY: help
help: ## Show this help message.
	@echo ""
	@echo "  Intercom WooCommerce Sync — v$(VERSION)"
	@echo ""
	@echo "  Usage: make <target>"
	@echo ""
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""

# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

.PHONY: build
build: ## Build the production ZIP → dist/intercom-woo-sync-<version>.zip
	@bash bin/build.sh

.PHONY: version
version: ## Print the current plugin version.
	@echo "$(VERSION)"

# ---------------------------------------------------------------------------
# Release
# ---------------------------------------------------------------------------

.PHONY: release
release: ## Build ZIP and create a DRAFT GitHub release (requires gh CLI).
	@bash bin/release.sh --draft

.PHONY: release-publish
release-publish: ## Build ZIP and publish a GitHub release immediately.
	@bash bin/release.sh --publish

.PHONY: release-pre
release-pre: ## Build ZIP and create a DRAFT pre-release.
	@bash bin/release.sh --draft --pre-release

# ---------------------------------------------------------------------------
# Quality
# ---------------------------------------------------------------------------

.PHONY: lint
lint: ## Run PHP_CodeSniffer.
	@./vendor/bin/phpcs

.PHONY: lint-fix
lint-fix: ## Auto-fix PHP_CodeSniffer violations.
	@./vendor/bin/phpcbf

.PHONY: test
test: ## Run PHPUnit.
	@./vendor/bin/phpunit

.PHONY: check
check: lint test ## Run lint + tests together (used in CI).

# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

.PHONY: install
install: ## Install Composer dev dependencies.
	@composer install --no-interaction

.PHONY: clean
clean: ## Remove build artifacts (dist/).
	@rm -rf dist/
	@echo "Cleaned dist/"
