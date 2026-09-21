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

## 2026-09-21

### PHP 8.5 on the maintainer machine; `setAccessible()` removed

- Maintainer machine moved from PHP 8.4.20 to **8.5.10** (infra, no commit; procedure in
  `docs/_local/maintainer-machine-runbook.md`). All `tests/*.php` green.
- 8.5 deprecates `ReflectionProperty::setAccessible()` (no effect since 8.1). Removed from
  `ArrayMappable::mapToArray()` — fired on every entity serialisation — and from the debug
  helper `getAllPropertiesRecursive()`. Safe on the kernel's `php >=8.2`.

### P0 closed, P1 started; MariaDB on the maintainer machine

- ADR-039 to ADR-043 written and approved (P0 of the order/debtor/financial plan). ADR-039 got
  an independent review (Fable) before approval, and on the same day decisions 17/18: MariaDB
  10.6 minimum, `utf8mb4` / `utf8mb4_unicode_ci` throughout (plan Q8 had asked for it).
- P1: `Money` in the kernel (`shared/src/Money`, `tests/money.php`, topic `money.md`).
- Infra, no commit: MariaDB 10.6.28 installed locally for the Doctrine driver's tests
  (localhost only, scoped test user `z77test`), `pdo_mysql` enabled. Setup in
  `docs/_local/maintainer-machine-runbook.md`.

### Persistence access for the business modules decided (order/debtor/financial plan)

- Unified API via `UnifiedEntityManager`, two drivers (File, Doctrine), ledger reports on DBAL
  of the same driver, minimal transaction port. Owner requirement: Doctrine caches regenerate in
  DEBUG and go with «Cache leeren». Input for ADR 2 (P0).

## 2026-09-13

### module-member: ADR-038 built — the member is the person, memberships are the project's

- `MemberAccount` loses `tenantRef`, `tenantRole`, `suspendedAt`; `MemberGrant`,
  `MemberGrants`, `grants.json` and yesterday's `ZugaengeController` are gone (the area
  moves to the project with its templates). New `TenantChoice` + hooks `membershipHook`,
  `joinHook`, `areaVisibilityHook`; `InvitationFlow` keeps only the token's craft
  (`invite(inviter, ref, email)` checks no right, `redeem()` calls `joinHook`,
  `sendJoinActivated()` for the project). Login no longer refuses a paused account —
  pausing is a membership state at the project (MEM-016). Backend accounts list reads
  «creates / attaches» from the hook. `shell.js` pause switch posts to `data-url`
  (hand copy!). ⚠️ Ships ONLY together with the project side (axo3 S2): a framework
  without `tenantRef` and a project still reading it must never meet. zihlundsee: no
  hooks → no switcher, no invitations; its `accounts.json` drops the four keys on the
  next save of each account. Measured by the axo3 suites (b7, b10) — the framework
  carries no member tests of its own.

### ADR-038: the member module knows no project reference (decided, not built)

- `company`, `tenantRef`, `tenantRole`, `suspendedAt`-as-master-pause and `grants.json`
  will leave `module-member`; memberships `(account, role owner|agent, state)` live at
  the PROJECT's tenant, the module asks for them through a hook in the pattern of
  `tenantLabelHook`. The `Zugaenge` area built yesterday moves to the project with
  the domain; token mechanics stay. Why: the account was profile and tenant in one
  (Peter, 2026-09-13, axo3) — measured: 4 of 20 account fields belong to the tenant,
  the profile hook renames a tenant, the purge deletes people. Project ADR
  `konto-und-mandant` (axo3-core). Build order: project's open questions first.

### module-member: the flash band gets its close button

- The member flash partial was the only one of the three without
  `flash-msg__close` (backend and frontend always had it); `member.scss` had no
  style for it either. core.js wires any button that is there and auto-dismisses
  only success/info — an error is meant to stay. In the shell the band is
  `position: fixed` at the top, so a refusal sat permanently on top of the action
  cell with «Speichern». Found in the axo3 B4 acceptance on a duplicate slug
  (Peter, 2026-09-12). Closer is absolutely positioned right, with padding on both
  sides so the centred sentence stays centred — a flex sibling would push it off.
  ⚠️ `member.css` is a hand copy per installation (ADR-024): rebuild with
  `npm run build:member` and copy to `public/assets/member/css/`.

## 2026-09-12

### module-member: «Zugänge» becomes an area of the shell

- «Zugänge» (invite, withdraw, pause, remove) moves out of the profile into its own
  area `Main/ZugaengeController` — present only when the session's choice IS the home
  and the account is master (`InvitationFlow::managesHere()`); `addAreas()` drops the
  nav entry by the same predicate. The profile keeps Konto / 2FA / Geräte; its Konto
  dialog names the home it renames. `shell.js` posts the pause switch to the new
  route (hand copy per installation!). Why: with two references in the header the
  profile section listed the home's accounts under another reference's name
  (Peter, 2026-09-12, axo3). Handoff: `z77-axo3.ch/work/docs/handoff-framework-zugaenge-bereich-2026-09-12.md`.
  ⚠️ An installation on this build without a `zugaenge` nav entry has NO way to
  invite until the entry exists — it is data, added per machine.

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
