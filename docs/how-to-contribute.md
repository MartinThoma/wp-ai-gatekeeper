# How to Contribute

Open work is tracked in [ROADMAP.md](ROADMAP.md). When you finish a task, remove it from the
roadmap in the same change, and add a PHPUnit regression test for it.

## Local Development Environment

You only need **Docker** with **Docker Compose** and `make`.

```bash
make up
```

This starts:
- **MariaDB 10.11**
- **WordPress** (`wordpress:latest`) at `http://localhost:8080`, with this checkout mounted as
  the `wp-ai-gatekeeper` plugin (edits apply immediately). The site runs with
  `WP_ENVIRONMENT_TYPE` set to `staging`, so Application Passwords work over plain HTTP.
- **WP-CLI** that installs WordPress on first start and activates the plugin. Activation creates
  the restricted `ai-assistant` service account.

Log in at `http://localhost:8080/wp-admin` with `admin` / `admin`, then create an API key under
**AI Gatekeeper → API Keys & Settings**. There is no sample content: create a post first if
you want something to send suggestions to.

Instead of an API key, you can also create an Application Password for the `ai-assistant`
user under **Users → Edit user** and use HTTP Basic Auth.

## `make` Commands

```bash
make help                    # Show all available commands
make up                      # Start WordPress + MariaDB and activate the plugin
make down                    # Stop containers, including the test DB (keeps volumes)
make restart                 # Recreate containers and re-run the WP-CLI setup
make reset                   # Wipe volumes and start from a clean WordPress install
make logs                    # Tail container logs
make wp ARGS="plugin list"   # Run any WP-CLI command against the dev site
make shell                   # Open a shell in the WordPress container
make test                    # Run the PHPUnit integration suite in Docker
make coverage                # Same, with code coverage (coverage/index.html)
make cs                      # Check WordPress Coding Standards
make cs-fix                  # Auto-fix what phpcbf can
make verify                  # Run the fast structural checks (Python 3)
make lint                    # php -l every file inside the WordPress container
make dist                    # Build dist/wp-ai-gatekeeper.zip from HEAD
```

## Testing & Continuous Integration

- **PHPUnit integration tests (Docker only, no local PHP needed):**
  ```bash
  make test                                   # PHP 8.3 + latest WordPress
  make test ARGS="--filter merge"             # run a subset
  make test PHP_VERSION=7.4 WP_VERSION=6.5    # oldest supported combination
  ```
  The first run builds a test image (PHP, `mysqli`, Composer dev dependencies, and the
  matching WordPress test suite) and starts a throwaway MariaDB on tmpfs. The plugin is
  mounted read-only, so tests never write into the working tree. `make down` stops the test DB.
  `make coverage` runs the suite with PCOV and prints a summary; open `coverage/index.html`
  for line-by-line detail. Code that runs while the plugin loads (the main plugin file,
  hook registration) shows as uncovered because it executes before the tests start.
  Tests live in `tests/phpunit/`; extend `WP_AI_Gatekeeper_TestCase` for the shared editor,
  AI user, API key, and REST helpers.
- **Coding standards (WordPress Coding Standards + PHP 7.4 compatibility):**
  ```bash
  composer install        # once; needs PHP with the xml and mbstring extensions
  pre-commit install      # once; runs phpcbf + phpcs on staged PHP files
  make cs                 # check everything
  make cs-fix             # auto-fix what phpcbf can
  ```
  Rules live in `phpcs.xml.dist`; CI runs the same `phpcs` check.
- **Fast structural verification (Python 3):**
  ```bash
  make verify
  ```
- **GitHub Actions CI (`.github/workflows/ci.yml`)** runs on every pull request and push to `main`:
  1. Static syntax & structural verification (`tests/test-plugin-verification.py` + `php -l`)
  2. WordPress Coding Standards (`phpcs`)
  3. The PHPUnit suite against MariaDB across PHP 7.4–8.3 and WordPress 6.5 / latest

## Releasing

1. Bump `Version:` in the `wp-ai-gatekeeper.php` header (and `WP_AI_GATEKEEPER_VERSION`).
2. Commit, then tag and push: `git tag v1.2.3 && git push origin v1.2.3`.
3. `.github/workflows/release.yml` checks that the tag matches the header version, builds the
   zip with `make dist`, and publishes it as a GitHub release. The install links in the README
   always point at the latest release.

The zip only contains runtime files; development files are excluded via `.gitattributes`
(`export-ignore`). Check what ships with `make dist && unzip -l dist/wp-ai-gatekeeper.zip`.
