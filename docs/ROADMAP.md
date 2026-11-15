# Roadmap

Open work for WP AI Gatekeeper, highest priority first. When a task is finished it is
**removed** from this file (git history keeps the record). Every fix ships with a PHPUnit
regression test; see [how-to-contribute.md](how-to-contribute.md) for running the suite.

Larger features that are planned but not yet scheduled are listed under
[Planned Features](#planned-features) at the end.

## Medium

### 1. Password-protected posts leak via the plugin REST API
`read_post` allows them and `format_post` (`includes/class-rest-controller.php`) returns
`content_raw` / `content_rendered`, so any logged-in Subscriber can read protected content.
- Withhold content for password-protected posts unless the caller can `edit_post`.

### 2. A stale editor in another tab can still undo a merge
Merging from inside the editor is now safe (it refuses while there are unsaved changes and
reloads afterwards). But if the same article is open in another tab or window, or by the same
user on another device, that editor does not know about the merge, and saving it writes the
older content back. WordPress's post lock only warns when a *different* user is editing.
- Options: have the plugin's scripts poll (or use Heartbeat) and warn "This article changed
  since you opened it"; or reject REST saves whose base is older than the last merge (the block
  editor sends no base version, so this needs a hidden field or meta).

## Low

### 3. Admin menu builds up to 100 full suggestions on every admin page load
`register_menus` (`includes/admin/class-admin-menu.php`) calls `get_suggestions` (diffs, comment
threads, 5 count queries) just to show a badge, for every user on every admin page. The
`per_page => 200` is clamped to 100, so the badge also caps at 100.
- Use `WP_AI_Gatekeeper_Database::count_suggestions( array( 'status' => 'open' ) )`.

### 4. Application Passwords are force-enabled for all users in staging
`allow_application_passwords_in_staging` (`includes/class-auth.php`) enables Application
Passwords for *every* user over plain HTTP on staging/local/development sites and overrides
plugins that deliberately disable them. Many "staging" sites are publicly reachable.
- Limit to `local`/`development`, or to AI users only, or make it an opt-in setting.
  Update `tests/test-plugin-verification.py` and the README to match.

### 5. `allow_ai_read_drafts_in_wp_v2` is dead code
Core's `/wp/v2/posts` sets `status` to `publish` by default, so the `empty()` check in
`includes/class-permissions.php` never passes and the filter never changes the query.
- Decide the intended behavior (AI sees drafts by default?) and implement it via
  `$request->get_param( 'status' )` against the default, or remove the filter.

### 6. Read policy is inconsistent with what is enforced and documented
- The settings page says the AI can read "posts/pages", but `filter_map_meta_cap` denies pages.
- `read_post` also lets the AI read other authors' `private` and `future` posts, which the
  README's permission matrix does not mention.
- Decide the policy, then align code, settings page, and README.

### 7. AI key authentication applies to every request, not only REST
`determine_current_user` accepts the key on admin-ajax, admin-post, and XML-RPC requests too.
Capabilities are restricted, but this widens the attack surface unnecessarily.
- Only honor the AI key for REST requests (e.g. check the request URI against
  `rest_get_url_prefix()`), and add a test that admin-ajax ignores it.

### 8. Cleanup
- `uninstall.php`: nothing can set `wp_ai_gatekeeper_delete_data_on_uninstall`, so tables are
  never dropped; the `ai-assistant` user is left behind with no role. Add the setting (or
  document a WP-CLI way) and decide what happens to the AI user.
- Post meta `_wpai_drafted_by` / `_wpai_scheduled_by` stays after uninstall. Keeping the
  "drafted by AI" record is probably right; decide and document it.
- Unused code (found via `make coverage`): `WP_AI_Gatekeeper_Diff::compute_line_diff()`,
  `WP_AI_Gatekeeper_Auth::is_human_reviewer()`, and `WP_AI_Gatekeeper_Auth::get_api_keys()`.
  Remove them, or use them.
- `render_settings_page` creates the AI user as a side effect of a GET; move that to an
  explicit action.

## Planned Features

### Direct Claude (Anthropic) Integration Inside WordPress
Currently, external AI agents authenticate *into* WordPress using `WP AI Gatekeeper` API keys or Application Passwords. A planned next milestone is allowing site administrators to store an **Anthropic (Claude) API Key** directly inside **AI Gatekeeper → Settings** so WordPress itself can invoke Claude on demand while remaining governed by the exact same permission boundaries:

- **Encrypted Claude API Key Storage:** Store an Anthropic API key (`sk-ant-...`) in WordPress settings (with support for defining `WP_AI_GATEKEEPER_CLAUDE_API_KEY` via environment variable or `wp-config.php`) and model selector (e.g., Claude Sonnet / Opus) plus custom system prompt / brand style guidelines.
- **One-Click Editorial Actions in the Block Editor:** Allow editors to highlight a block or article in Gutenberg and click *"Ask Claude to Suggest Improvements"* (proofreading, tone alignment, SEO meta/excerpt generation, or fact-check annotations), which automatically creates PR-style suggestions in the `WP AI Gatekeeper` queue.
- **Automatic AI Replies in Suggestion Threads:** When a human editor posts a follow-up question or revision request on an open suggestion thread in WP Admin, WordPress can automatically trigger a background Action Scheduler / WP-Cron webhook to the Claude API with the article context and thread history so Claude updates its `suggested_text` and replies in the thread automatically—no external orchestrator required.
