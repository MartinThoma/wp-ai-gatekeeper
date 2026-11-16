# Administration

Running WP AI Gatekeeper on a site: reviewing suggestions, settings, updates, and removal.

- [Reviewing suggestions](#reviewing-suggestions)
- [Authorship](#authorship)
- [Updates](#updates)
- [Uninstalling](#uninstalling)

## Reviewing suggestions

Editors review AI suggestions in three places:

- **AI Gatekeeper → Suggestions Queue**: all open suggestions on posts you can edit. The menu
  badge shows how many are open.
- The **AI Gatekeeper Suggestions** box below the post in the editor.
- The **AI Gatekeeper** sidebar in the block editor (toolbar icon or ⋮ menu).

Each suggestion shows a word-level diff and its discussion. **Show in full article** displays
the whole post with the change highlighted in place. Type a reply to ask the AI for a revision
(**Comment**), or decide: **Merge** applies the change and records a normal WordPress revision;
**Reject** closes it. Both also post what you typed as a final comment.

Merging from inside the editor requires saving your own changes first, and the editor reloads
afterwards to show the merged text. An editor that was opened before a merge (another tab or
device) shows a warning and cannot save its older copy over the merge; reload it to continue.

## Authorship

When a human publishes a draft the AI wrote, **the person who publishes it becomes the author**:
they took editorial responsibility. For scheduled posts, the person who scheduled the post
becomes the author when it goes live. The AI stays recorded as the drafter: a **Drafted by AI**
column in **Posts → All Posts**, and `is_ai_authored` / `drafted_by` in the API. It does not
appear on the public site.

This is on by default; turn it off under **AI Gatekeeper → API Keys & Settings → Authorship** to
keep the AI as the author.

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

## Uninstalling

Deactivating changes nothing permanent. **Deleting** the plugin in WP Admin (or
`wp plugin uninstall wp-ai-gatekeeper --deactivate`):

- **Always removes** its settings, API keys, the AI role, the Application Passwords of AI
  accounts, and internal bookkeeping.
- **Keeps your articles as they are:** merged suggestions are ordinary post content and
  revisions. Published posts do not depend on the plugin.
- **Keeps** suggestions with their discussions and the "Drafted by AI" record on posts, unless
  you enable **AI Gatekeeper → API Keys & Settings → Uninstall → Also delete all suggestions…**
  before deleting the plugin.
- **Never deletes the AI service account** (`ai-assistant`), because it may be the author of
  posts. Without its role and passwords it can do nothing; delete it under **Users** if you no
  longer need it, and choose there who gets its posts.
