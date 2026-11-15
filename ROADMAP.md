# Roadmap

Open work for WP AI Gatekeeper, highest priority first. When a task is finished it is
**removed** from this file (git history keeps the record). Every fix ships with a PHPUnit
regression test; run the suite with `make test` (see README → Testing).

Product features that are planned but not yet scheduled are listed in the README's
"Roadmap" section.

## High

### 1. Sanitize AI suggestion text before it can be merged
The AI's own drafts pass through `wp_kses_post`, but suggestion text is stored raw
(`includes/class-suggestion-service.php`, `create_suggestion` / `update_suggestion`). On merge,
`wp_update_post` applies kses based on the *merging* user's capabilities, so an Editor or Admin
(who has `unfiltered_html`) merges AI-supplied `<script>` / `onerror=` markup verbatim. The
diff shows it escaped, which is easy to miss in a long change.
- Sanitize `suggested_text` on create and update: `wp_kses_post` for `post_content` /
  `post_excerpt`, `sanitize_text_field` for `post_title`.
- Done when: a test merges an AI suggestion containing `<script>` as an administrator and the
  post contains no script.

## Medium

### 2. Suggestion list leaks content from unreadable posts
`list_suggestions_permissions_check` (`includes/class-rest-controller.php`) only requires
`edit_posts`, so a Contributor can call `GET /ai-gatekeeper/v1/suggestions?status=all` and read
suggestion text for private posts and other authors' drafts. The single-suggestion endpoint
already checks `read_post`; the list does not.
- Filter list results to posts the caller can `read_post` (or restrict the global queue to
  `edit_others_posts`), and keep `total`/`counts` consistent with the filtered result.
- Done when: a test shows a Contributor cannot see suggestions on another author's draft.

### 3. Editor sync after merge can revert or corrupt the merge
After a merge, `syncEditorContentOnMerge` (`assets/js/admin-suggestions.js`) and
`handleMergedContent` (`assets/js/gutenberg-sidebar.js`) replace the *first* occurrence of
`original_text` in the open editor, while the server uses `context_before`/`context_after` to
pick the occurrence. If they differ, or the text isn't found, the next Save overwrites the
merged post with stale editor content.
- Use `updated_post` from the merge response to set the editor field (or reload the post /
  prompt the user) instead of client-side string replacement.

### 4. Activation can demote an existing administrator
`ensure_default_ai_user` (`includes/class-auth.php`) finds an existing `ai-assistant` login and
calls `set_role()`, which strips all other roles, including `administrator`.
- Never re-role an existing user; create a uniquely named AI user instead (or refuse with a
  notice).
- Done when: a test with a pre-existing `ai-assistant` administrator keeps its admin role.

### 5. Password-protected posts leak via the plugin REST API
`read_post` allows them and `format_post` (`includes/class-rest-controller.php`) returns
`content_raw` / `content_rendered`, so any logged-in Subscriber can read protected content.
- Withhold content for password-protected posts unless the caller can `edit_post`.

## Low

### 6. Admin menu builds up to 100 full suggestions on every admin page load
`register_menus` (`includes/admin/class-admin-menu.php`) calls `get_suggestions` (diffs, comment
threads, 5 count queries) just to show a badge, for every user on every admin page. The
`per_page => 200` is clamped to 100, so the badge also caps at 100.
- Use `WP_AI_Gatekeeper_Database::count_suggestions( array( 'status' => 'open' ) )`.

### 7. Application Passwords are force-enabled for all users in staging
`allow_application_passwords_in_staging` (`includes/class-auth.php`) enables Application
Passwords for *every* user over plain HTTP on staging/local/development sites and overrides
plugins that deliberately disable them. Many "staging" sites are publicly reachable.
- Limit to `local`/`development`, or to AI users only, or make it an opt-in setting.
  Update `tests/test-plugin-verification.py` and the README to match.

### 8. `allow_ai_read_drafts_in_wp_v2` is dead code
Core's `/wp/v2/posts` sets `status` to `publish` by default, so the `empty()` check in
`includes/class-permissions.php` never passes and the filter never changes the query.
- Decide the intended behavior (AI sees drafts by default?) and implement it via
  `$request->get_param( 'status' )` against the default, or remove the filter.

### 9. Read policy is inconsistent with what is enforced and documented
- The settings page says the AI can read "posts/pages", but `filter_map_meta_cap` denies pages.
- `read_post` also lets the AI read other authors' `private` and `future` posts, which the
  README's permission matrix does not mention.
- Decide the policy, then align code, settings page, and README.

### 10. AI key authentication applies to every request, not only REST
`determine_current_user` accepts the key on admin-ajax, admin-post, and XML-RPC requests too.
Capabilities are restricted, but this widens the attack surface unnecessarily.
- Only honor the AI key for REST requests (e.g. check the request URI against
  `rest_get_url_prefix()`), and add a test that admin-ajax ignores it.

### 11. README is out of date
- The AI user is `ai-assistant`, not `ai_gatekeeper_bot`.
- The Docker stack uses MariaDB 10.11 and `wordpress:latest`, not "MySQL 8.0" / "PHP 8.2".
- Nothing seeds sample post `#10`.
- `make wp CMD="..."` should be `make wp ARGS="..."`.
- `?status=any` returns 400; allowed values are `all`, `draft`, `publish`.

### 12. Cleanup
- `docker-compose.yml`: remove the leftover `wp plugin deactivate wp-controlled-ai` line (and
  the matching exception in `tests/test-plugin-verification.py`).
- `uninstall.php`: nothing can set `wp_ai_gatekeeper_delete_data_on_uninstall`, so tables are
  never dropped; the `ai-assistant` user is left behind with no role. Add the setting (or
  document a WP-CLI way) and decide what happens to the AI user.
- `render_settings_page` creates the AI user as a side effect of a GET; move that to an
  explicit action.
