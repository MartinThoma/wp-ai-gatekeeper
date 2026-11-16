# API Guide

How an AI agent talks to WP AI Gatekeeper. All plugin endpoints live under
`/wp-json/ai-gatekeeper/v1`; the standard WordPress endpoints under `/wp-json/wp/v2` work too,
within the [AI's permissions](permissions.md).

The examples use two shell variables:

```bash
export SITE="https://your-site.example"
export WPAI_KEY="wpai_your_generated_key_here"   # AI Gatekeeper → API Keys & Settings
```

If your site uses plain permalinks (`?p=123`), replace `$SITE/wp-json/...` with
`$SITE/?rest_route=/...`.

- [Authentication](#authentication)
- [Endpoints](#endpoints)
- [Workflow](#workflow)
- [Rules the API enforces](#rules-the-api-enforces)
- [Errors](#errors)

## Authentication

Send the API key with every request, in either header:

```http
X-WP-AI-Key: wpai_your_generated_key_here
Authorization: Bearer wpai_your_generated_key_here
```

Prefer `X-WP-AI-Key`: some Apache setups strip the `Authorization` header. The key is only
accepted on REST API requests (`/wp-json/…` or `?rest_route=`); admin-ajax, admin-post,
XML-RPC, and normal pages ignore it.

Alternatively, authenticate as the `ai-assistant` user (or any user with the `ai_contributor`
role) with a WordPress **Application Password** over HTTP Basic Auth. WordPress only offers
Application Passwords over HTTPS, or on sites whose environment type is `local`; the API key
works either way.

## Endpoints

Paths are relative to `/wp-json/ai-gatekeeper/v1`.

| Method | Path | Who | Purpose |
| :--- | :--- | :--- | :--- |
| `GET` | `/posts` | AI, humans | List articles. `status`: `all` (default), `draft`, `publish`; also `search`, `author`, `page`, `per_page` (max 100). |
| `POST` | `/posts` | AI, humans | Create a draft (`title`, `content`, `excerpt`, `featured_media`, `categories`, `tags`). Always saved as `draft`. |
| `GET` | `/posts/{id}` | AI, humans | Read one article (`content_raw`, `content_rendered`, `excerpt`, `is_ai_authored`, …). |
| `PUT` | `/posts/{id}` | AI (own drafts), editors of the post | Edit a draft. The AI can only edit drafts it wrote, and they stay drafts. |
| `POST` | `/media` | AI, humans | Upload a file (raw body with `Content-Disposition`), like `/wp/v2/media`. |
| `GET` | `/posts/{id}/suggestions` | AI, editors of the post | Suggestions on one article. |
| `POST` | `/posts/{id}/suggestions` | AI, editors of the post | Propose a change: `original_text`, `suggested_text`, optional `target_field` (`post_content`, `post_title`, `post_excerpt`), `context_before`/`context_after`, `comment`. |
| `GET` | `/suggestions` | AI, editors | All suggestions you may see. `status`: `open` (default), `merged`, `rejected`, `all`; `needs_ai_reply=true` for threads waiting on the AI; `post_id`, `page`, `per_page`. |
| `GET` | `/suggestions/{id}` | AI, editors of the post | One suggestion with its diff and discussion. |
| `PATCH` | `/suggestions/{id}` | Its author, editors | Revise an open suggestion, optionally with a `comment`. |
| `GET` | `/suggestions/{id}/preview` | AI, editors of the post | The whole article rendered with the change highlighted. |
| `POST` | `/suggestions/{id}/comments` | AI, editors of the post | Reply in the discussion (`comment`). |
| `POST` | `/suggestions/{id}/merge` | Humans only | Apply the change to the article (optional `comment`). |
| `POST` | `/suggestions/{id}/reject` | Humans only | Close without applying (optional `comment`). |

## Workflow

### 1. Read articles

```bash
curl -s "$SITE/wp-json/ai-gatekeeper/v1/posts?status=all" -H "X-WP-AI-Key: $WPAI_KEY"
```

WordPress's own `/wp/v2/posts` works too, with its usual default of published posts only; pass
`?status=draft,pending,future` for the others. Pages can be read through `/wp/v2/pages` for
context, but suggestions and drafts only work on posts.

### 2. Write a new draft, with media

```bash
# Upload an image (standard WordPress endpoint)
curl -s -X POST "$SITE/wp-json/wp/v2/media" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Disposition: attachment; filename=diagram.png" \
  -H "Content-Type: image/png" \
  --data-binary @diagram.png

# Create a draft (the status is always forced to "draft")
curl -s -X POST "$SITE/wp-json/wp/v2/posts" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Zero-Trust Architecture Overview",
    "content": "<!-- wp:paragraph --><p>First draft written by AI.</p><!-- /wp:paragraph -->"
  }'
```

### 3. Suggest a change to an existing article

`original_text` must appear verbatim in the field. If it appears more than once, add
`context_before` / `context_after` to pick the right one.

```bash
curl -s -X POST "$SITE/wp-json/ai-gatekeeper/v1/posts/123/suggestions" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "original_text": "legacy password policies",
    "suggested_text": "FIDO2 hardware-backed passkey policies",
    "comment": "Aligns this sentence with our Q3 security compliance policy."
  }'
```

Editors now see the suggestion in the review queue and in the post editor.

### 4. Discuss and revise

Find threads where an editor replied and is waiting for you, read the discussion, then revise
the suggestion and answer in one request:

```bash
curl -s "$SITE/wp-json/ai-gatekeeper/v1/suggestions?needs_ai_reply=true" -H "X-WP-AI-Key: $WPAI_KEY"
curl -s "$SITE/wp-json/ai-gatekeeper/v1/suggestions/1" -H "X-WP-AI-Key: $WPAI_KEY"

curl -s -X PATCH "$SITE/wp-json/ai-gatekeeper/v1/suggestions/1" \
  -H "X-WP-AI-Key: $WPAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "suggested_text": "phishing-resistant FIDO2 passkey policies",
    "comment": "Added phishing resistance, as you asked."
  }'
```

To answer without changing the suggestion, `POST /suggestions/{id}/comments` with a `comment`.

### 5. A human merges or rejects

Only humans can merge or reject. Merging applies the change to the article and records a normal
WordPress revision. The suggestion becomes `merged` and is locked for good: editing,
commenting on, merging, or rejecting it afterwards returns `409 Conflict`.

### 6. Publishing an AI draft

Only humans can publish. By default, the person who publishes (or schedules) the draft becomes
its author; the AI stays recorded as `drafted_by` with `is_ai_authored: true`. See
[Authorship](administration.md#authorship).

## Rules the API enforces

- **Drafts only.** The AI can create drafts and edit its own drafts, never publish, schedule, or
  edit anything else. See [Permissions](permissions.md).
- **Markup in suggestions is filtered.** Merging runs as the human reviewer, so the plugin
  filters AI suggestions itself, the same way WordPress filters a Contributor's edits:
  disallowed HTML (`<script>`, `onclick=`, `javascript:` links, …) is removed from
  `suggested_text`. A suggestion that would change text *inside* an HTML tag, attribute, or
  `<script>`/`<style>` block is rejected; suggest a replacement for the whole element instead.
  Block comments (`<!-- wp:… -->`) are kept exactly as sent (only their attributes are
  filtered), so a suggestion may open or close a block whose other half lies outside
  `original_text`.
- **`suggested_text_filtered`.** Create and update responses include this flag. It is `true`
  when the stored `suggested_text` differs from what you sent; check it before relying on your
  exact text.
- **Password-protected posts.** Their text is only returned to users who can edit the post. Everyone
  else, including the AI, gets the title with empty `excerpt`, `content_raw`, and
  `content_rendered` and `"content_protected": true`. `search` does not match their content,
  and the AI cannot suggest changes to them or see their suggestions.
- **Stale editors.** An editor that was opened before a merge (another tab or device) cannot save
  its older copy over the merge: its saves are refused with `409 wpai_stale_editor`. API
  clients that do not come from the WordPress editor are not affected.

## Errors

Errors use the standard WordPress REST format: `{"code": "…", "message": "…", "data": {"status": …}}`.

| Status | Code | Meaning |
| :--- | :--- | :--- |
| 401 | `rest_ai_invalid_key` | The API key is wrong or revoked. |
| 403 | `rest_ai_review_forbidden` | AI agents cannot merge or reject. |
| 403 | `rest_ai_status_forbidden`, `rest_ai_cannot_edit_post` | The AI tried to publish, or to edit a post that is not its own draft. |
| 403 | `rest_forbidden`, `suggestion_update_forbidden`, `suggestion_comment_forbidden`, `merge_forbidden`, `reject_forbidden` | Not allowed to read or change this article or suggestion. |
| 404 | `post_not_found`, `suggestion_not_found` | No such article or suggestion. |
| 409 | `suggestion_already_merged`, `suggestion_not_open` | The suggestion is closed. |
| 409 | `suggestion_conflict` | The article changed so that the suggestion no longer applies. |
| 422 | `original_text_not_found` | `original_text` is not in the field (or is ambiguous without context). |
| 422 | `suggestion_unsafe_markup` | The change touches markup the AI may not change. |
