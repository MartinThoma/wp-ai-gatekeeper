# WP AI Gatekeeper (`wp-ai-gatekeeper`)

**Enterprise-grade guardrails and GitHub Pull-Request-style editorial suggestions for AI agents in WordPress.**

`WP AI Gatekeeper` lets you connect AI agents (Claude, GPT, custom pipelines, or MCP servers) to your WordPress site with strict, kernel-level permission boundaries and a human-in-the-loop review workflow modeled after GitHub Pull Request suggestions.

---

## Permission Matrix

| Capability / Action | AI Gatekeeper Agent (`ai_contributor`) | Human Editor / Admin |
| :--- | :---: | :---: |
| **Read all articles** (`publish` & `draft`, any author) | Allowed | Allowed |
| **Create new drafts** (forced to `draft` status) | Allowed | Allowed |
| **Edit own drafts** (while still `draft`) | Allowed | Allowed |
| **Upload media** (images, attachments) | Allowed | Allowed |
| **Propose inline PR-style suggestions on any article** | Allowed | Allowed |
| **Add explanatory comments & reply in suggestion threads** | Allowed | Allowed |
| **Update an open suggestion based on human feedback** | Allowed | Allowed |
| **Edit human-authored drafts or published posts directly** | **Blocked** | Allowed |
| **Publish, schedule, or unpublish articles** | **Blocked** | Allowed |
| **Create, edit, or moderate WordPress blog comments** | **Blocked** | Allowed |
| **Merge or reject a suggestion** | **Blocked** | Allowed |
| **Modify or comment on a merged suggestion** | **Locked (409 Conflict)** | **Locked (409 Conflict)** |

---

## Quick Start (Local Docker Environment)

You only need **Docker** and **Docker Compose** (plus `make`).

### 1. Start the Local Stack

```bash
make up
```

This spins up:
- **MySQL 8.0** database
- **WordPress (PHP 8.2)** at `http://localhost:8080` with `define( 'WP_ENVIRONMENT_TYPE', 'staging' );` configured so WordPress Application Passwords work out of the box
- **WP-CLI Bootstrapper** that automatically installs WordPress, activates `wp-ai-gatekeeper`, creates the restricted `ai_gatekeeper_bot` user, and seeds a sample published article (`#10`) for testing

### 2. Log into WordPress Admin

- **URL:** `http://localhost:8080/wp-admin`
- **Username:** `admin`
- **Password:** `admin`

### 3. Generate an AI API Key (or Application Password)

1. In WP Admin, go to **AI Gatekeeper → API Keys & Settings**.
2. Enter a label (e.g., `Editorial Assistant Bot`) and click **Generate API Key**.
3. Copy the `wpai_...` key shown once at the top of the screen.

*(Alternatively, because `WP_ENVIRONMENT_TYPE` is set to `'staging'` and the plugin enables Application Passwords in staging environments, you can also generate a standard WordPress Application Password for the `ai_gatekeeper_bot` user under **Users → Profile**).*

---

## Common `Makefile` Commands

```bash
make help      # Show all available commands
make up        # Start WordPress + MySQL and auto-configure WP AI Gatekeeper
make down      # Stop containers (preserves database volume)
make restart   # Restart containers and re-run wp-cli activation/setup
make reset     # Wipe volumes and rebuild a clean WordPress instance from scratch
make logs      # Tail container logs
make verify    # Run static & structural verification tests
make wp CMD="plugin list"  # Run any WP-CLI command inside the container
```

---

## Authentication

AI agents can authenticate using any of the following methods:

1. **Custom Header (Recommended):**
   ```http
   X-WP-AI-Key: wpai_your_generated_key_here
   ```
2. **Bearer Token:**
   ```http
   Authorization: Bearer wpai_your_generated_key_here
   ```
3. **WordPress Application Password (HTTP Basic Auth):**
   Authenticate as the `ai_gatekeeper_bot` user (or any user assigned the `ai_contributor` role).

---

## REST API & PR-Style Suggestion Workflow

All custom endpoints live under `/wp-json/ai-gatekeeper/v1`, alongside standard `/wp-json/wp/v2` endpoints for reading posts, managing own drafts, and uploading media.

### 1. Read All Articles (Published & Drafts)

```bash
curl -s http://localhost:8080/wp-json/ai-gatekeeper/v1/posts?status=any \
  -H "X-WP-AI-Key: $WPAI_KEY"
```

### 2. Create a New Draft & Upload Media

```bash
# Upload an image via standard WP REST API
curl -s -X POST http://localhost:8080/wp-json/wp/v2/media \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Disposition: attachment; filename=diagram.png" \
  -H "Content-Type: image/png" \
  --data-binary @diagram.png

# Create a new draft (status is automatically enforced as 'draft')
curl -s -X POST http://localhost:8080/wp-json/wp/v2/posts \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Zero-Trust Architecture Overview",
    "content": "<!-- wp:paragraph --><p>First draft written by AI.</p><!-- /wp:paragraph -->",
    "status": "draft"
  }'
```

### 3. Propose a GitHub PR-Style Suggestion on an Existing Article

```bash
curl -s -X POST http://localhost:8080/wp-json/ai-gatekeeper/v1/posts/10/suggestions \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "target_field": "post_content",
    "original_text": "legacy password policies",
    "suggested_text": "FIDO2 hardware-backed passkey policies",
    "comment": "Updated this sentence to align with our Q3 security compliance policy."
  }'
```

### 4. Dialogue Between Human Editor and AI Agent

- **Human Editor** reviews the visual word-level diff inside:
  - **WP Admin → AI Gatekeeper → Suggestions Queue**
  - The **AI Gatekeeper Suggestions (PR Review)** meta box at the bottom of the Post Editor
  - The **AI Gatekeeper (PR Review)** sidebar panel inside the Gutenberg Block Editor
- The human leaves a comment: *"Can we also mention phishing resistance here?"*
- **AI Agent** polls or fetches the suggestion thread:

```bash
curl -s http://localhost:8080/wp-json/ai-gatekeeper/v1/suggestions/1 \
  -H "X-WP-AI-Key: $WPAI_KEY"
```

- **AI Agent** revises its suggestion and replies in a single request:

```bash
curl -s -X PATCH http://localhost:8080/wp-json/ai-gatekeeper/v1/suggestions/1 \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "suggested_text": "phishing-resistant FIDO2 passkey policies",
    "comment": "Added phishing-resistant per your feedback!"
  }'
```

### 5. Human Merges the Suggestion (Permanent Lock)

When the human clicks **Merge Suggestion** in WP Admin (or calls `POST /wp-json/ai-gatekeeper/v1/suggestions/1/merge`):
1. The exact text replacement is applied to the target article and a WordPress post revision is recorded.
2. The suggestion status transitions to `merged` (`is_locked: true`).
3. **Immutability Guarantee:** Any subsequent attempt by either the AI or a human to edit, comment on, merge, or reject that suggestion returns `409 Conflict` (`suggestion_already_merged`).

---

## Testing & Continuous Integration

- **Fast Structural & Security Verification (Python 3):**
  ```bash
  make verify
  ```
- **GitHub Actions CI (`.github/workflows/ci.yml`):**
  Automatically runs on every Pull Request and push to `main`:
  1. Static syntax & structural verification (`tests/test-plugin-verification.py` + `php -l`)
  2. Full WordPress + MySQL integration test suite via PHPUnit (`tests/phpunit/test-ai-gatekeeper.php`) across PHP 8.1 and 8.2.

---

## Roadmap

### Direct Claude (Anthropic) Integration Inside WordPress
Currently, external AI agents authenticate *into* WordPress using `WP AI Gatekeeper` API keys or Application Passwords. A planned next milestone is allowing site administrators to store an **Anthropic (Claude) API Key** directly inside **AI Gatekeeper → Settings** so WordPress itself can invoke Claude on demand while remaining governed by the exact same permission boundaries:

- **Encrypted Claude API Key Storage:** Store an Anthropic API key (`sk-ant-...`) in WordPress settings (with support for defining `WP_AI_GATEKEEPER_CLAUDE_API_KEY` via environment variable or `wp-config.php`) and model selector (e.g., Claude Sonnet / Opus) plus custom system prompt / brand style guidelines.
- **One-Click Editorial Actions in the Block Editor:** Allow editors to highlight a block or article in Gutenberg and click *"Ask Claude to Suggest Improvements"* (proofreading, tone alignment, SEO meta/excerpt generation, or fact-check annotations), which automatically creates PR-style suggestions in the `WP AI Gatekeeper` queue.
- **Automatic AI Replies in Suggestion Threads:** When a human editor posts a follow-up question or revision request on an open suggestion thread in WP Admin, WordPress can automatically trigger a background Action Scheduler / WP-Cron webhook to the Claude API with the article context and thread history so Claude updates its `suggested_text` and replies in the thread automatically—no external orchestrator required.
