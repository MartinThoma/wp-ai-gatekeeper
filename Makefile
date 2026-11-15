.PHONY: help up down restart reset logs wp verify lint test coverage cs cs-fix dist signing-key release shell

help: ## Show available Make commands
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Start WordPress + MariaDB in Docker and activate WP AI Gatekeeper
	docker compose up -d --remove-orphans

down: ## Stop Docker containers (including the test database)
	docker compose --profile test down

restart: ## Recreate containers and re-run WP-CLI setup/activation
	docker compose up -d --force-recreate --remove-orphans

reset: ## Stop containers, wipe database/WordPress volumes, and start fresh
	docker compose down -v --remove-orphans
	docker compose up -d

logs: ## Tail Docker container logs
	docker compose logs -f

wp: ## Run a WP-CLI command inside the container (e.g. make wp ARGS="plugin list")
	docker compose run --rm --entrypoint wp wpcli $(ARGS)

verify: ## Run Python static & structural verification checks
	python3 tests/test-plugin-verification.py

test: ## Run PHPUnit in Docker (ARGS="--filter foo", PHP_VERSION=7.4, WP_VERSION=6.5)
	docker compose --profile test build tests
	docker compose --profile test run --rm tests $(ARGS)

coverage: ## Run PHPUnit with coverage (HTML in coverage/); fails below the CI minimum
	docker compose --profile test build tests
	mkdir -p coverage
	docker compose --profile test run --rm -v "$(CURDIR)/coverage:/coverage" --entrypoint sh tests -c \
		'php -d pcov.enabled=1 -d pcov.directory=/app /opt/composer/vendor/bin/phpunit --coverage-text --coverage-html /coverage --coverage-clover /coverage/clover.xml $(ARGS); \
		status=$$?; chown -R $(shell id -u):$(shell id -g) /coverage; exit $$status'
	@echo "HTML report: coverage/index.html"
	python3 bin/check-coverage.py coverage/clover.xml

cs: ## Check PHP against WordPress Coding Standards (needs: composer install)
	vendor/bin/phpcs

cs-fix: ## Auto-fix WordPress Coding Standards violations
	vendor/bin/phpcbf || [ $$? -eq 1 ]

dist: ## Build the installable plugin zip from HEAD (dist/wp-ai-gatekeeper.zip)
	mkdir -p dist
	git archive --format=zip --prefix=wp-ai-gatekeeper/ -o dist/wp-ai-gatekeeper.zip HEAD
	@echo "Built dist/wp-ai-gatekeeper.zip"

SIGNING_KEY ?= $(HOME)/.config/wp-ai-gatekeeper/release-signing.key
RELEASE_FILES = dist/wp-ai-gatekeeper.zip dist/wp-ai-gatekeeper.zip.sig dist/update.json

signing-key: ## Create the release signing key (once per maintainer; keep it offline and backed up)
	php bin/release-tool.php keygen "$(SIGNING_KEY)"

release: ## Build + sign release files for the tagged HEAD into dist/ (see docs/how-to-contribute.md)
	@tag=$$(git describe --exact-match --tags HEAD 2>/dev/null) || { echo "HEAD is not tagged. Tag the release commit first: git tag vX.Y.Z"; exit 1; }; \
	version=$$(sed -n 's/^ \* Version: *//p' wp-ai-gatekeeper.php); \
	[ "$$tag" = "v$$version" ] || { echo "Tag $$tag does not match plugin Version $$version"; exit 1; }; \
	[ -z "$$(git status --porcelain)" ] || { echo "Working tree is not clean"; exit 1; }; \
	mkdir -p dist; \
	rm -f $(RELEASE_FILES) dist/changelog.txt; \
	git archive --format=zip --prefix=wp-ai-gatekeeper/ -o dist/wp-ai-gatekeeper.zip "$$tag"; \
	prev=$$(git describe --tags --abbrev=0 "$$tag^" 2>/dev/null || true); \
	git log --format='- %s' $${prev:+$$prev..}$$tag > dist/changelog.txt; \
	php bin/release-tool.php sign dist/wp-ai-gatekeeper.zip "$(SIGNING_KEY)" && \
	php bin/release-tool.php verify dist/wp-ai-gatekeeper.zip && \
	php bin/release-tool.php manifest "$$version" dist/changelog.txt dist/update.json && \
	rm -f dist/changelog.txt && \
	echo && echo "Upload these 3 files to the draft release $$tag on GitHub, then publish it:" && ls -1 $(RELEASE_FILES)

lint: ## Run PHP syntax linter inside the WordPress Docker container
	docker compose exec wordpress sh -c 'find /var/www/html/wp-content/plugins/wp-ai-gatekeeper -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 php -l'

shell: ## Open a bash shell inside the WordPress container
	docker compose exec wordpress bash
