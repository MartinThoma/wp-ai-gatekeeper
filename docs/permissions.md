# Permissions

What the AI agent may and may not do, and how the plugin enforces it.

## Permission matrix

| Action | AI agent (`ai_contributor`) | Human editor / admin |
| :--- | :---: | :---: |
| Read posts by any author: published, draft, pending, scheduled | Allowed | Allowed |
| Read other authors' **private** posts | **Blocked** | Allowed |
| Read the text of **password-protected** posts | **Blocked** | Only if they can edit the post |
| Read pages (read-only, via `/wp/v2/pages`) | Allowed | Allowed |
| Create new drafts (always saved as `draft`) | Allowed | Allowed |
| Edit its own drafts | Allowed | Allowed |
| Upload media | Allowed | Allowed |
| Suggest changes to any post it can read | Allowed | Allowed |
| Comment in suggestion discussions, revise open suggestions | Allowed | Allowed |
| Edit human-authored drafts or published posts directly | **Blocked** | Allowed |
| Publish, schedule, or unpublish | **Blocked** | Allowed |
| Create, edit, or moderate WordPress blog comments | **Blocked** | Allowed |
| Merge or reject a suggestion | **Blocked** | Allowed |
| Change or comment on a merged suggestion | **Locked** | **Locked** |

## Who sees suggestions

Suggestions and their discussions are editorial, not public. A person sees them only on posts
they can edit, the same people who can comment, merge, or reject; the review queue and its
menu badge count only those. The AI sees suggestions on every post it can read.

## How it is enforced

The rules are enforced inside WordPress, not just in the plugin's own endpoints, so they also
hold for the standard `/wp/v2` REST API, WP-CLI-style code paths, and other plugins:

- **A restricted role.** AI accounts get the `ai_contributor` role, without the capabilities
  to publish, edit others' posts, manage comments, pages, users, settings, or plugins. Those
  capabilities are also stripped at runtime, even if another plugin grants them.
- **Per-post checks.** WordPress's `read_post`, `edit_post`, and `delete_post` checks are
  narrowed for AI accounts: read as listed above, edit and delete only their own drafts and
  uploads.
- **Write boundary.** Any post the AI saves is forced to `draft`, through every code path.
- **REST guards.** Requests that try to publish, change another author's post, or touch
  comments, pages, settings, users, plugins, or themes are refused before they run.
- **Filtered suggestions.** Merges run as the human reviewer, so AI suggestions are filtered
  for unsafe markup first (see [API guide](api.md#rules-the-api-enforces)).
- **API keys only on REST.** The AI's key is ignored outside the REST API.

The settings page under **AI Gatekeeper → API Keys & Settings** summarizes the active policy.
