# Bauplan — z77 2.0.0 (business-suite release)

**Status:** `[WAITING]` — prepared 2026-11-19, nothing executed. Waits for the owner's own server
installation of the suite (owner 2026-10-22, [`roadmap.md`](roadmap.md) row «Release 2.0.0»).
**Date:** 2026-11-19
**Pattern:** [`public-release-bauplan.md`](public-release-bauplan.md) (the 1.0.0 checklist).
**Upgrade notes for 1.6 projects:** [`upgrade-1.6-to-2.0.md`](upgrade-1.6-to-2.0.md).

Goal: publish the business suite on Packagist as **2.0.0** — the web stack (`kernel`,
`module-frontend`, `module-backend`, `module-dms`, `z77/docs`) moves from 1.x to 2.0.0 with it,
because it carries breaking changes since `1.6.0` (see the upgrade notes).

How to use this list: work top to bottom, tick each step, run its check before the next. A step
marked **[OWNER]** is done by hand by the owner (GitHub, Packagist, servers, decisions); a step
marked **[AI]** can be done by an assistant in the monorepo. Line numbers are as of commit
`204c415` (2026-11-19) — re-check them with the given `grep` before editing, the handbook and the
READMEs are being edited in parallel.

---

## Baseline (2026-11-19)

| Fact | Value |
|---|---|
| Last tag | `1.6.0` (2026-09-11); 423 commits since, 220 of them touch the four published packages |
| Split targets (`split.yml`) | `kernel`, `module-frontend`, `module-backend`, `module-dms`, `docs` |
| On Packagist | `z77/kernel`, `z77/module-frontend`, `z77/module-backend`, `z77/module-dms`, `z77/docs`, `z77/skeleton` |
| Constraints | every `z77/*` require is `^1.0`; every `composer.json` carries `branch-alias dev-main → 1.0.x-dev` |
| Why `^1.0` stays until the tag | live projects install the suite through `path` repositories as `1.0.x-dev`; a `^2.0` on `main` before they switch breaks their next `composer update` |
| PHP | web stack `>=8.2`; every business module and `persistence-doctrine` `>=8.4` |
| Published skeleton | `z77-ch/z77-skeleton` (`z77/skeleton`, tags up to `1.0.3`), requires the web stack with `^1.0` — a separate repo, not split |

---

## Phase 0 — Preconditions (gate)

- [ ] **0.1 [OWNER] The suite runs on the owner's own server.** Installed the way a 2.0 user will
      do it as far as possible (business modules registered, one `z77-db migrate`, first steps of
      `business-suite-setup.md` §4 entered, a real invoice, receipt and bank statement booked).
      **Check:** date and short result noted in the roadmap row «Release 2.0.0».
- [ ] **0.2 [OWNER] Decide the package set of 2.0.0** (see «Open owner decisions» D1–D3). The
      list fixes every later step: `split.yml` entries, repos, Packagist, README paragraphs.
- [ ] **0.3 [AI → OWNER review] Handbook coverage (CLAUDE.md publish rule).** Nothing is published
      before `docs/01-handbook/` covers it. Per package that goes public, confirm:

      | Package | Handbook | Topic |
      |---|---|---|
      | `persistence-doctrine` | `business-suite-setup.md` (packages, `z77-db`), `installer.md` | `persistence-doctrine.md` |
      | `module-vat`, `-contact`, `-mandator`, `-financial`, `-article`, `-debtor`, `-order`, `-receipt` | `business-suite-setup.md` §1–§4, `bank-setup.md` | one topic each |
      | `module-mailing` | `business-suite-setup.md` §1 | `mailing.md` |
      | `module-creditor` (D1) | `business-suite-setup.md` (row «im Aufbau», §4) | `creditor.md` |
      | `module-member`, `module-api`, `kibot` (D2) | `optional-modules.md` | `member.md`, `api.md`, `kibot.md` |

      **Check:** `npm run docs:check` green (it lints `docs/01-handbook/` since 2026-11-19); each
      README's «Enabling it in a project» matches the handbook; no row says «in progress» for a
      package that ships.
- [ ] **0.4 [OWNER] The anonymisation pending is closed.** `docs/topics/packaging.md` `## pending`
      still asks for «a FRESH public repository with one initial commit» before the public GitHub
      release — but the monorepo `z77-ch/z77-framework` is already public and holds the full
      history since the 2026-07-15 squash, including commit subjects that name client projects.
      Decide: accept the public history (then close the pending item), or rewrite/re-create
      before 2.0.0. **Check:** the pending item is resolved or rewritten with the decision.
- [ ] **0.5 [AI] Full test run on the release candidate.** `php tests/run.php all` on a clean
      `composer install` of the monorepo. **Check:** summary table green, pasted into the
      release commit's description or the roadmap row.

---

## Phase 1 — Code and manifests (one commit, «release 2.0.0 manifests»)

Everything in this phase lands in ONE commit, pushed right before the tag (Phase 4). Between this
commit and the owner's projects switching their constraints (step 5.4), those projects cannot run
`composer update` — keep the gap short.

### 1.1 [AI] Branch aliases `1.0.x-dev` → `2.0.x-dev`

Every `composer.json` with a branch alias (so `^2.0` resolves from `main` between releases, the
rule of `packaging.md` → `## rules`):

| File | Line |
|---|---|
| `composer.json` (monorepo aggregator) | 46 |
| `docs/composer.json` | 18 |
| `packages/kernel/composer.json` | 35 |
| `packages/kibot/composer.json` | 26 |
| `packages/module-api/composer.json` | 23 |
| `packages/module-article/composer.json` | 23 |
| `packages/module-backend/composer.json` | 23 |
| `packages/module-contact/composer.json` | 23 |
| `packages/module-creditor/composer.json` | 23 |
| `packages/module-debtor/composer.json` | 23 |
| `packages/module-dms/composer.json` | 26 |
| `packages/module-financial/composer.json` | 23 |
| `packages/module-frontend/composer.json` | 23 |
| `packages/module-mailing/composer.json` | 23 |
| `packages/module-mandator/composer.json` | 23 |
| `packages/module-member/composer.json` | 23 |
| `packages/module-order/composer.json` | 23 |
| `packages/module-receipt/composer.json` | 23 |
| `packages/module-vat/composer.json` | 23 |
| `packages/persistence-doctrine/composer.json` | 26 |

### 1.2 [AI] Version constraints `^1.0` → `^2.0`

Every `z77/*` require (all packages move together; a package left out of 2.0.0 by D1/D2 still
changes, otherwise it no longer installs next to a 2.x kernel):

| File | Lines (`z77/…` requires) |
|---|---|
| `composer.json` | 25–40 (16 lines: kernel, module-frontend, -backend, -dms, -vat, -contact, -mandator, -financial, -debtor, -article, -order, -mailing, -receipt, -creditor, persistence-doctrine, kibot) |
| `packages/module-api/composer.json` | 28 |
| `packages/module-article/composer.json` | 28–31 |
| `packages/module-backend/composer.json` | 28 |
| `packages/module-contact/composer.json` | 28–29 |
| `packages/module-creditor/composer.json` | 28–35 |
| `packages/module-debtor/composer.json` | 28–32 |
| `packages/module-dms/composer.json` | 32 |
| `packages/module-financial/composer.json` | 28–31 |
| `packages/module-frontend/composer.json` | 28 |
| `packages/module-mailing/composer.json` | 28–29 |
| `packages/module-mandator/composer.json` | 28–29 |
| `packages/module-member/composer.json` | 28 |
| `packages/module-order/composer.json` | 28–34 |
| `packages/module-receipt/composer.json` | 28–33 |
| `packages/module-vat/composer.json` | 28 |
| `packages/persistence-doctrine/composer.json` | 32 |

`packages/kernel/composer.json` and `packages/kibot/composer.json` require no `z77/*` package.
`skeleton/composer.json` (monorepo dev project) uses `*` — no change.

**Check (1.1 + 1.2):**

```bash
git grep -n -e '"\^1\.0"' -e '1\.0\.x-dev' -- '*composer.json'     # → no output
composer validate --no-check-publish                                 # root
for f in packages/*/composer.json docs/composer.json; do composer validate --no-check-publish "$f"; done
composer update --dry-run                                            # resolves with the path repos
```

### 1.3 [AI] The installer's docs offer

`packages/kernel/core/src/Installer/Install.php:2132` requires `z77/docs:^1.0` when it installs the
docs for an interactive run → `^2.0` (comment at line 2103 likewise). Same text in
`docs/topics/installer.md:356`. **Check:** `git grep -n "docs:\^1" -- packages docs` → no output;
`php tests/run.php installer`.

### 1.4 [AI] Package manifests of newly published packages (only for D2 packages)

`packages/kibot/composer.json` has no `homepage` and no `support` block (every other package has
both). If kibot is published: add them in the same shape (`support.source` =
`https://github.com/z77-ch/kibot`). **Check:** `composer validate packages/kibot/composer.json`.

### 1.5 [OWNER decision D3, then AI] Declared PHP extensions

Code of the four web-stack packages uses extensions that no `composer.json` declares (found
2026-11-19, see the upgrade notes «Requirements»): `openssl` (`Z77\Shared\Auth\TotpVault`,
backend 2FA), `iconv` (`Z77\Shared\Pdf\PdfDocument`), `simplexml`/`libxml`
(`Z77\Shared\Payment\Camt\CamtParser`). All three are in a default PHP build. If D3 = declare:
add `"ext-openssl": "*"`, `"ext-iconv": "*"`, `"ext-simplexml": "*"` to
`packages/kernel/composer.json` `require` (after line 42). **Check:** `composer validate`.

---

## Phase 2 — Split workflow (`.github/workflows/split.yml`, same commit)

### 2.1 [AI] Matrix entries

Insert after the `module-dms` entry (line 148) and before `docs` (line 149). Full candidate set —
drop the lines of a package that D1/D2 keeps out:

```yaml
          - package: persistence-doctrine
            directory: packages/persistence-doctrine
          - package: module-vat
            directory: packages/module-vat
          - package: module-contact
            directory: packages/module-contact
          - package: module-mandator
            directory: packages/module-mandator
          - package: module-financial
            directory: packages/module-financial
          - package: module-article
            directory: packages/module-article
          - package: module-debtor
            directory: packages/module-debtor
          - package: module-order
            directory: packages/module-order
          - package: module-receipt
            directory: packages/module-receipt
          - package: module-mailing
            directory: packages/module-mailing
          # D1 — only if module-creditor ships in 2.0.0:
          - package: module-creditor
            directory: packages/module-creditor
          # D2 — only for the optional packages that ship:
          - package: module-member
            directory: packages/module-member
          - package: module-api
            directory: packages/module-api
          - package: kibot
            directory: packages/kibot
```

The `repository_name` of the action is `${{ matrix.package }}`, so each `package` value is the
GitHub repo name `z77-ch/<package>` — the same name every `composer.json` already gives in
`support.source`. Also update the header comment (line 117, «the 4 code packages under packages/
plus docs/») to the new count.

**Dependency closure** (a published package must not require an unpublished one — Packagist
cannot resolve it): `module-order` needs `-article` and `-debtor`; `-receipt` needs `-financial`
and `-dms`; `-creditor` needs `-receipt` and `-contact`; every database module needs
`persistence-doctrine`. `module-receipt`'s phone capture needs `module-api` (README «Phone»), but
not as a Composer require.

**Check:** `git diff .github/workflows/split.yml` shows only the added entries and the comment;
the YAML parses (`python -c "import yaml,sys;yaml.safe_load(open('.github/workflows/split.yml'))"`
or the GitHub Actions editor); every `package` value equals the `support.source` repo name of its
`composer.json`.

---

## Phase 3 — GitHub repos and Packagist [OWNER, by hand]

Before the release commit is pushed (the first branch split needs the repos):

- [ ] **3.1 Create one public repo per new package** under `z77-ch` — name = the `package` value
      of 2.1 (e.g. `z77-ch/module-vat`). **With an initial commit on `main`** (GitHub «Add a
      README file» is enough): an empty repo breaks the split action (`packaging.md` → `## rules`,
      «src refspec main does not match any»). Settings like the existing split repos (description,
      topics; read-only role — issues point to the monorepo).
      **Check:** `gh repo view z77-ch/<name> --json visibility,defaultBranchRef` → `PUBLIC`, `main`.
- [ ] **3.2 Give the split token push rights to the new repos.** The secret `ACCESS_TOKEN` of the
      monorepo is a PAT; if it is fine-grained with selected repositories, add every new repo.
      **Check:** after the push of Phase 4, every matrix job of the branch run is green.
- [ ] **3.3 Packagist — after the first green branch split** (the repo then holds the package):
      submit `https://github.com/z77-ch/<name>` per new package on packagist.org (account that owns
      `z77/*`), enable auto-update (GitHub integration / webhook, like the existing five).
      **Check:** `https://packagist.org/packages/z77/<name>` exists, shows `dev-main` and the MIT
      licence, «auto-updated» is on.

---

## Phase 4 — Docs, changelog, tag

### 4.1 [AI] README publish-status paragraphs (coordinate with the README owner)

Rewrite from «not on Packagist / path repository» to «on Packagist, `composer require
z77/<name>`». As of `204c415` (re-check: `git grep -n -i "packagist\|path repositor\|\^1\.0" -- README.md 'packages/*/README.md' docs/README.md`):

| File:line | Today |
|---|---|
| `README.md:47` | «Web stack — published on Packagist (`^1.0`, current release line 1.x)» |
| `README.md:56` | «Monorepo only (consumed through `path` repositories until the business-suite release)» — split into published / still monorepo-only per D1/D2 |
| `README.md:84–88` | status table (web stack «1.x», member/api «not yet on Packagist», business suite «Published together as release 2.0.0 once …», creditor «In progress», kibot «Monorepo only») |
| `README.md:123–144` | Quickstart — add the suite path (`composer require z77/module-…` after `create-project`) |
| `docs/README.md:160` | «Projects consume these via Composer (path repository in development).» |
| `packages/persistence-doctrine/README.md:9`, `:84` | «not yet a split target or on Packagist» / «Publishing: not a split target» |
| `packages/module-vat/README.md:8` | «Not yet a split target or on Packagist» |
| `packages/module-contact/README.md:11` | same |
| `packages/module-mandator/README.md:9` | same |
| `packages/module-financial/README.md:13` | same |
| `packages/module-article/README.md:9` | same |
| `packages/module-debtor/README.md:10` | same |
| `packages/module-order/README.md:10` | same |
| `packages/module-receipt/README.md:14` | same |
| `packages/module-mailing/README.md:10`, `:54`, `:64` | same; «path repository while it is not on Packagist»; open item «Split repository and Packagist registration» |
| `packages/module-creditor/README.md:41` (D1) | «Not yet a split target or on Packagist» |
| `packages/module-member/README.md:10`, `:66`, `:77` (D2) | as mailing |
| `packages/module-api/README.md:8`, `:62`, `:72` (D2) | as mailing |
| `packages/kibot/README.md:171`, `:220` (D2) | «path repository like the other z77 packages»; «Publishing: not a split target» |

Version lines «`z77/kernel` ^1.0» in the requirements sections → `^2.0`:
`packages/module-backend/README.md:88`, `packages/module-dms/README.md:60`,
`packages/module-frontend/README.md:73`, `packages/module-mailing/README.md:48`,
`packages/module-member/README.md:61`, `packages/module-api/README.md:57`.

**Check:** the grep above lists only intended hits; `npm run docs:check` green.

### 4.2 [AI] `INSTALL-AI.md` — the pre-2.0.0 path goes

| Line(s) | Today | After |
|---|---|---|
| 67 | Git row: «needed for the pre-2.0.0 business-suite path, §4 box» | Git optional (template route only) |
| 104 | `module-creditor` «in progress, check its status first» | per D1 |
| 114–115 | question 5: «For the pre-2.0.0 suite path also: where the framework repository may be cloned» | drop the clone question |
| 139–159 | the box «Until release 2.0.0: the business suite is not on Packagist» (clone, path repositories) | remove; step 3 adds the modules with `composer require` |
| 191–193 | step 3: «Put the packages into `require` in the same edit (pre-2.0.0: with their path repositories, box above)» | `composer require z77/module-… ` after the psr-4 entries (or both in one `composer.json` edit + `composer update`) |
| 200–201 | step 4: «`composer update` when you changed `composer.json` in step 3 or the box» | without «or the box» |
| 303–304 | step 9: «Pre-2.0.0 suite projects get it through the `docs` path repository» | remove |

**Check:** `git grep -n -i "2\.0\.0\|path repositor\|git clone" -- INSTALL-AI.md` → no
pre-release wording left; the INSTALL-AI flow run once end to end in 5.2.

### 4.3 [AI] Handbook path-repository passages (coordinate with the handbook owner)

| File:line | Today |
|---|---|
| `docs/01-handbook/architecture.md:36–41` | «Four of them … published on Packagist; the others are consumed through path repositories until the business-suite release» |
| `docs/01-handbook/architecture.md:43–56` | package table, column «Packagist» (`no` for every suite package; creditor «in progress») |
| `docs/01-handbook/business-suite-setup.md:15–19` | prerequisite «stages 1–3 (path repositories, …)», «Until the business-suite release they are consumed through `path` repositories, not Packagist.» |
| `docs/01-handbook/business-suite-setup.md:35`, `:44–47` | creditor «im Aufbau» (D1) |
| `docs/01-handbook/new-project-manual.md:41–43` | prerequisite «A checkout of the framework monorepo … path repositories in `composer.json` are relative» |
| `docs/01-handbook/new-project-manual.md:49–71` | stage 1 step 2: `repositories` with one path repository per package, the dependency-graph list |
| `docs/01-handbook/new-project-manual.md:103`, `:181–186` | «The installer links the packages into `vendor/z77/`»; `vendor-deploy.php` turns links into copies — for a Packagist project `vendor/` holds copies already; decide whether the manual keeps the path-repository route as the maintainer's route next to the Packagist route (see D5) |
| `docs/01-handbook/optional-modules.md:8` | «(path repository while the packages are not on Packagist)» |
| `docs/01-handbook/create-module.md:92` | example `"z77/kernel": "^1.0"` |

**Check:** `git grep -n -i "path repositor\|until the business-suite\|\^1\.0" -- docs/01-handbook`
lists only deliberate hits; `npm run docs:check` green.

### 4.4 [AI] Topic and development docs

- `docs/topics/packaging.md` — lines 5, 33, 36, 38–39, 46–68 (`## packages` table «not split»),
  87, 96, 99 (`^1.0` examples), 124 (rule: branch alias `1.0.x-dev`), 127–128 (rules naming
  «the 5 split repos»), 153 (pending «which further packages become split targets» → resolved),
  154 (see 0.4). Date line 3. **Check:** `npm run docs:check` green.
- `docs/03-development/roadmap.md` row «Release 2.0.0» → `[DONE]` with date, link to this file.
- This file: status `[DONE]`, a closing note like the 1.0.0 one.

### 4.5 [AI] `CHANGELOG.md`

- `## [Unreleased]` (line 15) → `## [2.0.0] — YYYY-MM-DD`; drop the stale line 17 «Work of
  2026-11-13 to 2026-11-15.»
- Above its groups, three lines: first release with the business suite on Packagist; the list of
  newly published packages; «Upgrading a 1.6 project: `docs/03-development/upgrade-1.6-to-2.0.md`
  — the changelog starts 2026-11-15, the breaking changes before that date are listed there.»
- A new empty `## [Unreleased]` above it.
- Optional (Keep a Changelog): compare links at the bottom
  (`[2.0.0]: https://github.com/z77-ch/z77-framework/compare/1.6.0...2.0.0`).

**Check:** `grep -n "^## \[" CHANGELOG.md` → `[Unreleased]`, `[2.0.0] — <date>`.

### 4.6 [OWNER] Commit, push, tag — in this order

```bash
php tests/run.php all                         # 0.5, on the final tree
git add -A && git commit                      # Phases 1, 2, 4.1–4.5 (one release commit)
git push origin main                          # branch split → every split repo incl. the new ones
#   wait: Actions run «Packages Split» green for EVERY matrix job (gh run watch)
#   then Phase 3.3 (Packagist submit) for the new packages
git tag -a 2.0.0 -m "z77 2.0.0 — business suite"
git push origin 2.0.0                         # tag split → tag in every split repo
```

Push branch and tag separately; the workflow's `concurrency` group queues a branch run and a tag
run, it never cancels (PKG-004). **Check:** both runs green;
`for r in kernel module-frontend module-backend module-dms docs <new…>; do gh api repos/z77-ch/$r/tags --jq '.[0].name'; done`
→ `2.0.0` everywhere. A failed job: `gh run rerun <id> --failed`, then check the tags again.

---

## Phase 5 — After the tag

- [ ] **5.1 [OWNER] Skeleton `z77-ch/z77-skeleton`** (not split, hand-maintained —
      `packaging.md` rule «When changing the skeleton»): its `composer.json` lines 27–30
      (`z77/kernel`, `-frontend`, `-backend`, `-dms` at `^1.0`) → `^2.0`; README if it names 1.x;
      commit, tag `2.0.0` there. Mirror behaviour-relevant changes in the monorepo's
      `skeleton/composer.json` (it uses `*`, nothing to change today).
      **Check:** `https://packagist.org/packages/z77/skeleton` shows `2.0.0`.
- [ ] **5.2 [OWNER or AI with OK] Clean-folder verification** — outside every project, PHP 8.4,
      Composer 2, no `repositories` block anywhere:
      ```bash
      composer create-project z77/skeleton z77-check-web            # web stack only
      composer show -d z77-check-web "z77/*"                        # → 2.0.0 for all, from Packagist
      composer create-project z77/skeleton z77-check-suite
      #   add the psr-4 override entries of business-suite-setup.md §2, then:
      composer require -d z77-check-suite z77/module-order:^2.0 z77/module-receipt:^2.0 z77/module-mailing:^2.0
      composer require -d z77-check-suite --dev z77/docs:^2.0
      ```
      **Check:** every `z77/*` resolves to `2.0.0` without a path repository; the installer ends
      with `✓ Z77 Core installation complete`; `config/vendor/moduleManager.inc.php` lists every
      module; against a scratch MariaDB `php vendor/bin/z77-db migrate` then `status` → `New 0`;
      `composer dev` → `/`, `/login`, one screen per module open without error, `logs/` clean.
      Then delete both folders and the scratch database. Same run once through `INSTALL-AI.md`
      with an assistant (4.2 check).
- [ ] **5.3 Packagist pages.** Every `https://packagist.org/packages/z77/<name>` shows `2.0.0`,
      MIT, the README, the right `require` list, «auto-updated». **Check:** one push to `main`
      afterwards updates `dev-main` on all pages within minutes.
- [ ] **5.4 [OWNER] Live projects.** Each project of the owner that installs through `path`
      repositories with `^1.0`: change its `z77/*` requires to `^2.0` (they keep their path
      repositories, which now report `2.0.x-dev`), then `composer update`, read
      `upgrade-1.6-to-2.0.md`, run `php .releases/check.php` (it names every override that lacks a
      key the framework gained). Projects on Packagist with `^1.0` (web stack) stay on 1.6.0 until
      they are upgraded deliberately. **Check:** per project `composer show "z77/*"` → `2.0.x-dev`
      or `2.0.0`; the project's harnesses/smoke test green.
- [ ] **5.5 Build stamp on a Packagist project.** `.releases/vendor-deploy.php` and
      `tools/build-stamp.php` collect their sources from path repositories; a project that takes
      the framework from Packagist has none, so the backend footer shows «unbekannt» /
      «Entwicklung» instead of a version. **Check:** what the footer shows in the 5.2 project; if
      it is wrong, record it in `packaging.md` `## known issues` (do not fix in this release).

---

## Order

```
0 Preconditions (server install, package set, handbook, anonymisation, tests)
→ 3.1/3.2 repos + token (owner)
→ 1 + 2 + 4.1–4.5 in ONE commit (manifests, installer, split.yml, READMEs, INSTALL-AI, handbook, changelog)
→ push main → branch split green → 3.3 Packagist submit
→ tag 2.0.0 → tag split green
→ 5.1 skeleton → 5.2 clean-folder check → 5.3 Packagist pages → 5.4 live projects → 5.5 stamp
```

Why the manifests go in one commit with the tag right after it: `main` with `^2.0` /
`2.0.x-dev` breaks every project that still requires `^1.0` through a path repository (owner
decision 2026-10-22: constraints stay `^1.0` until the tag).

---

## Open owner decisions

- **D1 — `module-creditor` in 2.0.0 or not.**
  - *In:* it is published as built («im Aufbau» must then be gone from READMEs, `INSTALL-AI.md:104`,
    `business-suite-setup.md:35/44–47`, `architecture.md` table, or it ships labelled
    experimental); posting into the books (stage 1c) and the recognition wiring must be finished
    or explicitly scoped out; its migrations and its config (`creditorAi` in kibot's config) become
    public contract — later changes follow semver; it pulls `module-receipt`, `-financial`,
    `-contact`, `-dms` (all published anyway).
  - *Out:* no split entry, no repo, no Packagist; it stays a `path`-repository package, but its
    constraints still move to `^2.0` (step 1.2); `INSTALL-AI.md` §3 and `business-suite-setup.md`
    must say «not on Packagist, monorepo only» instead of «in progress»; a later 2.x minor
    publishes it without a breaking change.
- **D2 — `module-member`, `module-api`, `kibot`.** The roadmap names the suite modules only. Not
  publishing `module-api` leaves `module-receipt`'s phone capture without a public gateway;
  `kibot` is `suggest`ed by `z77/kernel` and `z77/module-frontend` (published) and used by the
  creditor AI step. Each one in: split entry, repo, Packagist, README rewrite (4.1), and
  `optional-modules.md` drops «path repository while …».
- **D3 — declare `ext-openssl`, `ext-iconv`, `ext-simplexml` in `packages/kernel/composer.json`**
  (step 1.5) — a new hard requirement vs. today's implicit one.
- **D4 — the anonymisation pending** (step 0.4).
- **D5 — the new-project manual's route.** Keep the path-repository route (maintainer, live
  projects with a monorepo checkout) beside the Packagist route, or switch the manual to Packagist
  and move the path-repository route to `dev-environment.md`.

## Not covered by this plan

- The content of `docs/01-handbook/` beyond the publish-status passages (another work stream).
- A business-suite skeleton (`create-project` with the suite preset) — not planned; the suite is
  added with `composer require`.
- Whether the release needs a `UPGRADE.md` at the repository root (the upgrade notes live in
  `docs/03-development/` today; `z77/docs` ships them to every project).
