# Git Journal

A running, chronological log of commits and notable Git/infra actions — so nothing
gets forgotten between sessions. Newest entries first.

This is **not** the curated [changelog](README.md) (release-oriented, grouped by
category) and **not** the place for pendenzen/known issues (those live in
`docs/topics/{thema}.md`). This journal answers "what did we actually do, when, and
why" — including actions that leave no commit (repo creation, token changes, deploys).

**How to use:** after a commit or a notable infra action, add a bullet under the
current date. Record the commit hash, a one-line summary, and — when the context is
not obvious from the message — a short why / follow-up. Keep it terse.

---

## 2026-07-10

### Dev-environment docs for PC switch

- Added [`docs/01-handbook/dev-environment.md`](../01-handbook/dev-environment.md) —
  what the toolchain needs that the synced code does not carry (PHP+ext, Node, Composer,
  Dart Sass, Git/SSH, gh), GitHub/SSH access, directory-layout requirement for project
  path-repos, post-sync steps, CSS watch, npm checkers, gotchas.
- Added runnable `docs/01-handbook/verify-dev-env.sh` (Git Bash) — PASS/WARN/FAIL for
  every tool, PHP extension, SSH handshake, gh login, node_modules. Verified on the
  current machine: 24 PASS / 0 FAIL.
- Reference (old PC, known-good): PHP 8.4.20, Composer 2.9.7, Node 24.15.0, npm 11.12.1,
  Dart Sass 1.99.0 (local dev-dep), SSH ed25519 key, gh scopes repo/read:org/gist/admin:public_key.

### Monorepo cleanup — skeleton test install fixed

- `1a2b32a` — chore(skeleton): stop tracking composer.lock (stale after kernel merge).
  The committed `skeleton/composer.lock` still locked the pre-merge packages
  (`z77/core|shared|persistence`), so `composer install` failed. Untracked + gitignored
  it; the skeleton is a template/test-install on path repos to the evolving monorepo,
  so a committed lock always goes stale. Regenerate per install via `composer update`.
- Verified: fresh `composer update` in `skeleton/` resolves `z77/kernel` + 3 modules
  (junctioned path repos), installer runs, autoload OK. Interactive `composer install`
  admin prompt works once the stale `hiddeninput.exe` is cleared from the temp dir
  (Windows Symfony-Console hidden-input helper can leave a locked leftover).

### Kernel rollout (ADR-023) — completed

Split the merged `z77/kernel` foundation package live and consumed it end-to-end.
Full context: [`docs/topics/packaging.md`](../topics/packaging.md).

- `6ad2abb` — docs: fix kernel-structure leftovers and installer config-file count.
  Post-merge doc cleanup (stale `packages/{shared,persistence}` → `packages/kernel/*`,
  `vendor/z77/core` → `vendor/z77/kernel/core`, config-file count three → five).
- `0686e8e` — docs(packaging): kernel rollout complete — split repos live, old repos archived.
- `1dbb53d` — docs(packaging): record kernel split blocked on ACCESS_TOKEN repo access.

Notable infra actions (no commit):

- Created private repo `z77-ch/kernel` + bootstrapped its `main` (README) as a push
  target for the split action (an empty target repo breaks the split).
- **Blocker + fix:** the split's `ACCESS_TOKEN` (a fine-grained PAT, scoped
  to "Only select repositories") lacked write access to the newly created
  `z77-ch/kernel` → kernel split job failed with `403 Write access not granted`.
  Fixed by adding `z77-ch/kernel` to the token's repository access, then re-running the
  failed job. **Recurring gotcha:** every future new split target needs the same token
  grant (or must exist before the split runs).
- Split workflow green for all 4 targets (kernel + module-frontend/backend/dms).
- Archived (not deleted) the obsolete split repos `z77-ch/core`, `z77-ch/shared`,
  `z77-ch/persistence` — superseded by `z77-ch/kernel`.

Verification (local, no commit):

- Remote consume test: fresh project with `vcs` repos, `composer require z77/module-frontend:^1.0`
  resolved the whole graph from `dev-main`; all three kernel namespaces autoload.
- Installer test: fresh skeleton install via path repos — installer handles the three
  nested PSR-4 roots correctly (`vendor/z77/kernel/{core,shared,persistence}`), shared
  assets land in `public/assets/shared`, 5 config files generated, idempotent re-install.

### Open / deferred

- Packagist registration + making the repos public — deferred until `docs/01-handbook/`
  is complete and the publish decision is taken (publish philosophy). Tracked in
  [`packaging.md`](../topics/packaging.md) `## pending`.
- Observation (not yet ticketed): `provisionAdmin()` skips only when `loginUsers.json`
  exists, not when a `SETUP_TOKEN` exists — a non-interactive install followed by an
  interactive run re-prompts for the admin. Pre-existing, unrelated to the kernel merge.
