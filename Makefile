.PHONY: help up down restart reset logs wp verify lint test cs cs-fix dist shell

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
	docker compose run --rm wpcli wp --path=/var/www/html $(ARGS)

verify: ## Run Python static & structural verification checks
	python3 tests/test-plugin-verification.py

test: ## Run PHPUnit in Docker (ARGS="--filter foo", PHP_VERSION=7.4, WP_VERSION=6.5)
	docker compose --profile test build tests
	docker compose --profile test run --rm tests $(ARGS)

cs: ## Check PHP against WordPress Coding Standards (needs: composer install)
	vendor/bin/phpcs

cs-fix: ## Auto-fix WordPress Coding Standards violations
	vendor/bin/phpcbf || [ $$? -eq 1 ]

dist: ## Build the installable plugin zip from HEAD (dist/wp-ai-gatekeeper.zip)
	mkdir -p dist
	git archive --format=zip --prefix=wp-ai-gatekeeper/ -o dist/wp-ai-gatekeeper.zip HEAD
	@echo "Built dist/wp-ai-gatekeeper.zip"

lint: ## Run PHP syntax linter inside the WordPress Docker container
	docker compose exec wordpress sh -c 'find /var/www/html/wp-content/plugins/wp-ai-gatekeeper -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 php -l'

shell: ## Open a bash shell inside the WordPress container
	docker compose exec wordpress bash
