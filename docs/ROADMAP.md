# Roadmap

Open work for WP AI Gatekeeper, highest priority first. When a task is finished it is
**removed** from this file (git history keeps the record). Every fix ships with a PHPUnit
regression test; see [how-to-contribute.md](how-to-contribute.md) for running the suite.

Larger features that are planned but not yet scheduled are listed under
[Planned Features](#planned-features) at the end.

## Release Process

### Release by merging a "REL: v1.2.3" commit
Today a release takes four manual steps (bump version, push a tag, `make release`, upload
three files and publish). Goal: merging a commit titled `REL: v1.2.3` into `main` releases
version 1.2.3, **without** moving the signing key into CI (an offline key is what stops a
compromised GitHub account from shipping code to sites; see "Releasing" in
`docs/how-to-contribute.md`).

Proposed design:
- `make prepare-release VERSION=1.2.3` (local, maintainer): bumps the header and
  `WP_AI_GATEKEEPER_VERSION`, builds the plugin zip **reproducibly**, signs it with the offline
  key, stores the signature as `release-signatures/v1.2.3.sig` (excluded from the zip via
  `export-ignore`, so adding it does not change the zip), and commits `REL: v1.2.3`.
- On merge to `main`, CI recognizes the `REL:` commit, checks the title matches the header
  version, rebuilds the zip the same way, and **verifies the committed signature against its
  own build** with the keys in `includes/release-keys.php`. Only if that passes does it tag
  `v1.2.3` and publish the release with the zip, `.sig`, and `update.json`. Any mismatch fails
  the job and nothing is published.
- Reproducible zip: `git archive` embeds the commit ID and uses commit timestamps, so build
  from the tree with fixed timestamps, sorted entries, and a fixed compression setting (e.g. a
  small script), and add a CI test that two builds are byte-identical.
- Done when: a test release `REL: v1.0.1` published end to end by merging, and a tampered or
  missing signature in a `REL:` commit is shown to block publishing.

Considerations:
- **Full automation conflicts with the supply-chain protection.** A release that needs nothing
  but a merge would need the signing key in GitHub. Then anyone who compromises the repository
  (account, token, or a malicious workflow change) could sign and ship code to every site. The
  design above keeps one local step (`make prepare-release`) so the key never leaves the
  maintainer's machine; the rest (tagging, building, publishing, uploading) is automated.
- **Result for the maintainer:** one local command plus merging the `REL:` commit, instead of
  four manual steps.
- **Main technical risk: reproducibility.** The zip CI publishes must be byte-identical to the
  one the maintainer signed. Differences in git, zip, or zlib versions between the
  maintainer's machine and CI can break this. CI catches it (the signature will not verify, so
  nothing is published), and the manual flow remains as a fallback until the build is proven
  reproducible.
- **Protect `main`:** with releases triggered by merges, require pull request review for `main`
  so a `REL:` commit cannot be pushed directly. The signature still protects sites even
  without this, but a blocked release is better than a confusing failed one.

Rejected alternative: keeping the signing key as a GitHub secret (even in a protected
environment) would make releases one click, but anyone with sufficient access to the
repository could then sign arbitrary code.

## Planned Features

### Publish on wordpress.org
Long term, distribute through the wordpress.org plugin directory for discoverability and its
review process. Needs a `readme.txt` in the directory format, a review (which may require
renaming: plugin names starting with "WP" are often rejected), and SVN publishing. Directory
plugins must not update themselves from elsewhere, so the self-hosted updater
(`includes/class-updater.php`, `Update URI` header) must be removed in the version published
there, with a one-time migration note for existing users.

### Direct Claude (Anthropic) Integration Inside WordPress
Currently, external AI agents authenticate *into* WordPress using `WP AI Gatekeeper` API keys or Application Passwords. A planned next milestone is allowing site administrators to store an **Anthropic (Claude) API Key** directly inside **AI Gatekeeper → Settings** so WordPress itself can invoke Claude on demand while remaining governed by the exact same permission boundaries:

- **Encrypted Claude API Key Storage:** Store an Anthropic API key (`sk-ant-...`) in WordPress settings (with support for defining `WP_AI_GATEKEEPER_CLAUDE_API_KEY` via environment variable or `wp-config.php`) and model selector (e.g., Claude Sonnet / Opus) plus custom system prompt / brand style guidelines.
- **One-Click Editorial Actions in the Block Editor:** Allow editors to highlight a block or article in Gutenberg and click *"Ask Claude to Suggest Improvements"* (proofreading, tone alignment, SEO meta/excerpt generation, or fact-check annotations), which automatically creates PR-style suggestions in the `WP AI Gatekeeper` queue.
- **Automatic AI Replies in Suggestion Threads:** When a human editor posts a follow-up question or revision request on an open suggestion thread in WP Admin, WordPress can automatically trigger a background Action Scheduler / WP-Cron webhook to the Claude API with the article context and thread history so Claude updates its `suggested_text` and replies in the thread automatically—no external orchestrator required.
