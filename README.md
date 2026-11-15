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

## Installation

Requires **WordPress 6.0+**, **PHP 7.4+**, and an administrator account that may install
plugins. Some managed hosts block plugin uploads; use WP-CLI or the host's own plugin panel
there.

### 1. Install and activate the plugin

**From the WordPress admin (no command line needed):**

1. Download **[wp-ai-gatekeeper.zip](https://github.com/MartinThoma/wp-ai-gatekeeper/releases/latest/download/wp-ai-gatekeeper.zip)** from the latest release.
   On the release page, use the **wp-ai-gatekeeper.zip** asset, not GitHub's
   "Source code (zip)": that one unpacks into a version-numbered folder and is not the signed
   release build.
2. In WP Admin, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, and click **Install Now**.
3. Click **Activate**.

**Or with WP-CLI (one command):**

```bash
wp plugin install https://github.com/MartinThoma/wp-ai-gatekeeper/releases/latest/download/wp-ai-gatekeeper.zip --activate
```

Activation creates the restricted **AI Gatekeeper Agent** role, an `ai-assistant` service
account for your AI agents (`ai-assistant-2` if that username is already taken; existing users
are never changed), and two database tables that store suggestions and their discussions.

### 2. Create an API key for your AI agent

1. In WP Admin, go to **AI Gatekeeper → API Keys & Settings**.
2. Enter a label (e.g. `Editorial Assistant Bot`) and click **Generate API Key**.
3. Copy the `wpai_...` key. It is shown only once; if you lose it, revoke it and create a new one.

### 3. Check the connection

```bash
export SITE="https://your-site.example"
export WPAI_KEY="wpai_your_generated_key_here"

curl -s "$SITE/wp-json/ai-gatekeeper/v1/posts?per_page=1" -H "X-WP-AI-Key: $WPAI_KEY"
```

A JSON response with an `items` list means your agent is connected. A `401` means the key is
wrong or revoked. If your site uses plain permalinks (`?p=123`), use
`$SITE/?rest_route=/ai-gatekeeper/v1/posts` instead of `$SITE/wp-json/ai-gatekeeper/v1/posts`.

Suggestions from your agent show up under **AI Gatekeeper → Suggestions Queue** and in the
post editor, where editors can comment, merge, or reject them.

### 4. Optional: automatic updates

In **Plugins**, click **Enable auto-updates** in the plugin's row to install new releases
automatically. Only releases signed with the project's release key are installed (see
[Updates](#updates)).

*Want to try it without touching a real site? `make up` starts a local WordPress with the plugin
in Docker; see [docs/how-to-contribute.md](docs/how-to-contribute.md). That site already runs
the plugin from your checkout, so don't install the zip there.*

---

## Updates

When a new version is released, WordPress shows it like any other plugin update: on the
**Plugins** page and under **Dashboard → Updates**, with one-click updates and the usual
**Enable auto-updates** link. **View details** shows the changelog.

How it works and why it is safe:

- The plugin checks this repository's latest GitHub release about twice a day. That request
  goes to `github.com` (it reveals your site's IP address to GitHub, like any download from it).
  WordPress never asks wordpress.org about this plugin, so a different plugin with the same name
  there can never be installed as an "update".
- Every release is signed with a key that is kept offline, not on GitHub. Your site checks the
  signature before installing and **refuses unsigned or modified packages**, also for
  auto-updates and WP-CLI. Even someone with full access to the GitHub repository cannot push
  code to your site.
- After an update, the plugin re-applies the AI role's permissions, so security fixes to what
  the AI may do take effect without reactivating.

---

## Authentication

AI agents can authenticate using any of the following methods:

1. **Custom Header (Recommended):**
   ```http
   X-WP-AI-Key: wpai_your_generated_key_here
   ```
2. **Bearer Token** (some Apache setups strip the `Authorization` header; use the custom header then):
   ```http
   Authorization: Bearer wpai_your_generated_key_here
   ```
3. **WordPress Application Password (HTTP Basic Auth):**
   Authenticate as the `ai-assistant` user (or any user assigned the `ai_contributor` role).
   WordPress requires HTTPS for Application Passwords; on `local`, `development`, and `staging`
   sites the plugin also allows them over HTTP.

---

## REST API & PR-Style Suggestion Workflow

All custom endpoints live under `/wp-json/ai-gatekeeper/v1`, alongside standard `/wp-json/wp/v2` endpoints for reading posts, managing own drafts, and uploading media. The examples use the `$SITE` and `$WPAI_KEY` variables from [Installation](#3-check-the-connection).

### 1. Read All Articles (Published & Drafts)

```bash
curl -s "$SITE/wp-json/ai-gatekeeper/v1/posts?status=all" \
  -H "X-WP-AI-Key: $WPAI_KEY"
```

`status` accepts `all` (default), `draft`, or `publish`.

### 2. Create a New Draft & Upload Media

```bash
# Upload an image via standard WP REST API
curl -s -X POST "$SITE/wp-json/wp/v2/media" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Disposition: attachment; filename=diagram.png" \
  -H "Content-Type: image/png" \
  --data-binary @diagram.png

# Create a new draft (status is automatically enforced as 'draft')
curl -s -X POST "$SITE/wp-json/wp/v2/posts" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Zero-Trust Architecture Overview",
    "content": "<!-- wp:paragraph --><p>First draft written by AI.</p><!-- /wp:paragraph -->",
    "status": "draft"
  }'
```

### 3. Propose a GitHub PR-Style Suggestion on an Existing Article

Replace `123` with the ID of an existing post (from step 1). `original_text` must appear verbatim in the post.

```bash
curl -s -X POST "$SITE/wp-json/ai-gatekeeper/v1/posts/123/suggestions" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "target_field": "post_content",
    "original_text": "legacy password policies",
    "suggested_text": "FIDO2 hardware-backed passkey policies",
    "comment": "Updated this sentence to align with our Q3 security compliance policy."
  }'
```

> **Markup in AI suggestions:** merging runs as the human reviewer, so the plugin filters AI
> suggestions itself, the same way WordPress filters a Contributor's edits. Disallowed HTML
> (e.g. `<script>`, `onclick=`, `javascript:` links) is stripped from `suggested_text`, and
> suggestions that would change text *inside* an HTML tag, attribute, or `<script>`/`<style>`
> block are rejected with `422 suggestion_unsafe_markup`; suggest a replacement for the whole
> element instead. Plain-text edits work anywhere, and trusted markup elsewhere in the post
> is left untouched.

### 4. Dialogue Between Human Editor and AI Agent

- **Human Editor** reviews the visual word-level diff inside:
  - **WP Admin → AI Gatekeeper → Suggestions Queue**
  - The **AI Gatekeeper Suggestions (PR Review)** meta box at the bottom of the Post Editor
  - The **AI Gatekeeper (PR Review)** sidebar panel inside the Gutenberg Block Editor
- Suggestions and their discussions are editorial, not public: a person sees them only on
  articles they can edit (the same people who can comment, merge, or reject). AI agents see
  suggestions on every article they can read.
- The human leaves a comment: *"Can we also mention phishing resistance here?"*
- **AI Agent** polls or fetches the suggestion thread:

```bash
curl -s "$SITE/wp-json/ai-gatekeeper/v1/suggestions/1" \
  -H "X-WP-AI-Key: $WPAI_KEY"
```

- **AI Agent** revises its suggestion and replies in a single request:

```bash
curl -s -X PATCH "$SITE/wp-json/ai-gatekeeper/v1/suggestions/1" \
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

### 6. Publishing an AI Draft: Who Is the Author?

When a human publishes a draft the AI wrote, **the person who publishes it becomes the author**:
they took editorial responsibility. For scheduled posts, the person who scheduled the post
becomes the author when it goes live. The AI is still recorded as the drafter: a **Drafted by
AI** column in **Posts → All Posts**, plus `is_ai_authored: true` and `drafted_by` in the
`/ai-gatekeeper/v1/posts` API. It does not appear on the public site.

This is on by default; turn it off under **AI Gatekeeper → API Keys & Settings → Authorship** to
keep the AI as the author.

---

## Contributing & Roadmap

- **[docs/how-to-contribute.md](docs/how-to-contribute.md):** local Docker environment, testing, coding standards, CI, and releases.
- **[docs/ROADMAP.md](docs/ROADMAP.md):** open tasks and planned features.
