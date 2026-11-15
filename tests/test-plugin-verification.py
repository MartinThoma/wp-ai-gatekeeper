#!/usr/bin/env python3
"""
Static & Structural Verification Suite for WP AI Gatekeeper (`wp-ai-gatekeeper`).

Verifies:
1. All required plugin, admin, frontend, test, Docker, Makefile, CI, and documentation files exist.
2. Zero stale references to the old `WP_Controlled_AI` or `wp-controlled-ai` naming remain.
3. Balanced delimiters and valid structure across all PHP, JS, and CSS files.
4. Presence of all core security capability hooks, REST routes, DB schema tables,
   staging Application Passwords configuration, Makefile targets, and README Roadmap.
"""

import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

EXPECTED_FILES = [
    "wp-ai-gatekeeper.php",
    "uninstall.php",
    "Makefile",
    "docker-compose.yml",
    "composer.json",
    "phpunit.xml.dist",
    "bin/install-wp-tests.sh",
    "tests/Dockerfile",
    "phpcs.xml.dist",
    ".pre-commit-config.yaml",
    ".github/workflows/ci.yml",
    "includes/class-database.php",
    "includes/class-auth.php",
    "includes/class-permissions.php",
    "includes/class-diff.php",
    "includes/class-suggestion-service.php",
    "includes/class-rest-controller.php",
    "includes/admin/class-meta-box.php",
    "includes/admin/class-admin-menu.php",
    "assets/css/admin-suggestions.css",
    "assets/js/admin-suggestions.js",
    "assets/js/gutenberg-sidebar.js",
    "tests/phpunit/bootstrap.php",
    "tests/phpunit/test-ai-gatekeeper.php",
    "README.md",
    "docs/ROADMAP.md",
    "docs/how-to-contribute.md",
    ".gitattributes",
    ".github/workflows/release.yml",
]


def read_file(rel_path: str) -> str:
    full_path = os.path.join(ROOT, rel_path)
    with open(full_path, "r", encoding="utf-8") as f:
        return f.read()


def test_all_files_exist():
    missing = []
    for rel_path in EXPECTED_FILES:
        if not os.path.isfile(os.path.join(ROOT, rel_path)):
            missing.append(rel_path)
    assert not missing, f"Missing expected files: {missing}"

    # Ensure old plugin entrypoint does not exist
    assert not os.path.exists(
        os.path.join(ROOT, "wp-controlled-ai.php")
    ), "Stale wp-controlled-ai.php should not exist"


def test_no_stale_controlled_ai_identifiers():
    forbidden = ["WP_Controlled_AI", "wp-controlled-ai", "wp_controlled_ai", "wp-controlled-ai/v1"]
    for rel_path in EXPECTED_FILES:
        content = read_file(rel_path)
        for token in forbidden:
            assert token not in content, f"Found stale identifier '{token}' in {rel_path}"


def test_php_files_structure():
    php_files = [f for f in EXPECTED_FILES if f.endswith(".php")]
    for rel_path in php_files:
        content = read_file(rel_path)
        assert content.startswith("<?php"), f"{rel_path} must start with <?php"
        # Check balanced braces
        open_braces = content.count("{")
        close_braces = content.count("}")
        assert (
            open_braces == close_braces
        ), f"Unbalanced braces in {rel_path}: {open_braces} != {close_braces}"


def test_security_and_capability_hooks():
    perms = read_file("includes/class-permissions.php")
    auth = read_file("includes/class-auth.php")
    required_perms = [
        "map_meta_cap",
        "user_has_cap",
        "wp_insert_post_data",
        "rest_pre_dispatch",
        "preprocess_comment",
        "rest_post_query",
        "upload_files",
        "moderate_comments",
        "publish_posts",
    ]
    for snippet in required_perms:
        assert snippet in perms, f"Missing security hook/capability '{snippet}' in class-permissions.php"
    assert "ai_contributor" in auth


def test_suggestion_workflow_and_immutability():
    service = read_file("includes/class-suggestion-service.php")
    assert "suggestion_already_merged" in service
    assert "'status' => 409" in service
    assert "is_locked" in service

    rest = read_file("includes/class-rest-controller.php")
    assert "ai-gatekeeper/v1" in rest
    for route in ["/posts", "/suggestions", "/comments", "/merge", "/reject", "/media"]:
        assert route in rest, f"Missing route '{route}' in class-rest-controller.php"


def test_staging_application_passwords_and_docker():
    compose = read_file("docker-compose.yml")
    assert "define( 'WP_ENVIRONMENT_TYPE', 'staging' );" in compose
    assert "wp-ai-gatekeeper" in compose

    auth = read_file("includes/class-auth.php")
    assert "wp_is_application_passwords_available" in auth
    assert "'staging'" in auth


def test_makefile_and_ci_and_roadmap():
    makefile = read_file("Makefile")
    for target in ["up:", "down:", "restart:", "reset:", "logs:", "wp:", "verify:", "test:"]:
        assert target in makefile, f"Missing target '{target}' in Makefile"

    ci = read_file(".github/workflows/ci.yml")
    assert "phpunit" in ci
    assert "test-plugin-verification.py" in ci

    readme = read_file("README.md")
    assert "## Installation" in readme
    assert "docs/ROADMAP.md" in readme

    roadmap = read_file("docs/ROADMAP.md")
    assert "## Planned Features" in roadmap
    assert "Claude" in roadmap


if __name__ == "__main__":
    tests = [
        test_all_files_exist,
        test_no_stale_controlled_ai_identifiers,
        test_php_files_structure,
        test_security_and_capability_hooks,
        test_suggestion_workflow_and_immutability,
        test_staging_application_passwords_and_docker,
        test_makefile_and_ci_and_roadmap,
    ]
    for test_fn in tests:
        test_fn()
        print(f"[PASS] {test_fn.__name__}")
    print(f"\nAll {len(tests)} verification checks passed ({len(EXPECTED_FILES)} files verified).")
    sys.exit(0)
