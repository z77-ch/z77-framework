# ADR-030 — System Configuration

**Status:** `APPROVED`
**Date:** 2026-08-07

---

## Context

Settings that describe **this installation** — not the project's code, not a user's preference —
had no home. They accumulated wherever the first implementation happened to put them:

- `DEBUG` → a flag file, `data/framework/debug.flag`
- `SEO_NOINDEX` → a flag file, `data/framework/seo/noindex.flag`
- `canonicalBaseUrl` → introduced 2026-08-07 as a key in `bootstrap.inc.php`, fed from
  `composer.json` (`extra.core-bootstrap`)

Three mechanisms, two storage locations, no validation, nothing discoverable. And more are
coming — a maintenance mode, a login lock while a backup runs.

`canonicalBaseUrl` exposed why the `composer.json` route is wrong. `composer.json` is
committed, so staging and production share one value; `bootstrap.inc.php` is regenerated on
every `composer install`, so a value corrected on the server is silently lost at the next
update. An installation's own identity must be settable per installation and must survive an
update — and it must be readable from CLI, because a cron job that mails a link has no HTTP
request to derive a host from.

## Decision

**1. One home: `config/systemConfig.inc.php`.** Installation-level settings live in a single,
semantically named config file next to the other project config, seeded by the installer and
**seed-once** — written when absent, never overwritten, safe to edit by hand. Exactly the
policy `mail.inc.php`, `auth.inc.php`, `i18n.inc.php` and `backup.inc.php` already use.

`config/` is per installation: gitignored in the project template, and the `data` backup
archive contains only `data/`, so the file rides neither a repository push nor a data restore
between environments.

**2. Not in `composer.json`.** No installation-identity value goes into composer `extra`. The
one that did (`canonicalBaseUrl`) moves out with this ADR.

**3. Bootstrap loads it; loading tolerates, using fails.** `Bootstrap` reads the file and
publishes the values as constants, so both SAPIs — web request and a cron that boots the
framework — see the same thing. A missing or empty value MUST NOT abort the boot: a fatal
there takes down the backend too, i.e. the very surface an operator would use to fix it. The
error belongs at the point of use, where the value is actually needed.

**4. The failure policy is per key, not per file.**
- A key with no meaningful default (`canonicalBaseUrl` — every guessed host is wrong, and
  wrong invisibly) MUST throw when something tries to use it empty.
- A key with an obvious default (a future `maintenanceMode`, off) takes that default. Taking
  a site down after an update over a boolean nobody set is out of proportion.

**5. A missing value is visible in the backend.** The shell shows a persistent,
non-dismissible banner, the same device `SEO_NOINDEX` already uses. That is what makes a
restore from another environment, or a fresh install nobody configured, surface immediately
instead of at the first mail that goes out with a broken link.

**6. `DEBUG` and `SEO_NOINDEX` stay where they are — for now.** Both work, both are boolean,
both have a backend toggle. Migrating them means touching the installer, two toggles and two
read sites for no functional gain. They move when the third or fourth entry exists and the
pattern has settled. This is a decided deferral, not an oversight.

## Reasoning

- **Seed-once is the only policy that fits.** Regenerate-always loses the operator's value on
  update; a value only in `composer.json` cannot differ per environment. Seed-once is already
  the framework's answer for "developer-adjustable and update-safe", used four times.
- **Constants, not a service call.** `DEBUG` and `SEO_NOINDEX` set the precedent, and a
  constant is the one form available identically in a web request and in a CLI entry that
  boots the framework. It also keeps `Request` out of the business of answering questions
  about the installation.
- **Fail late, not early.** A site that never sends a mail has no reason to die over a URL it
  does not use, and the backend must stay reachable precisely when the configuration is
  broken. Failing at the point of use satisfies both, and it is still loud: the cron aborts
  rather than mailing an attacker-usable link.
- **Visibility beats prevention.** The file can still travel — a full-project archive carries
  it, and someone can copy a tree. Rather than trying to make that impossible, the banner
  makes it obvious.

## Consequences

- `canonicalBaseUrl` leaves `bootstrap.default.inc.php` and `composer.json` and becomes the
  first key of `systemConfig`. Installations that set it in composer `extra` must move it.
- A new installation boots fine with an unconfigured `canonicalBaseUrl` and shows the banner;
  the first attempt to build an absolute URL throws, and cron aborts instead of sending links
  that point nowhere.
- Every future installation-level setting has a home and does not become flag file number
  five. Adding one means: a key in `systemConfig.default.inc.php`, a default policy per
  point 4, and — if it can be wrong — a rule that drives the banner.
- The framework gains a fifth seed-once config file; `installer.md`'s overwrite table grows
  by one row.
- Per-user settings stay on `LoginUser` (ADR-022) and project/module settings stay in module
  config. `systemConfig` is not a general dumping ground — it holds what describes the
  installation.
- **Transient runtime state does not belong here.** A lock held while a backup runs has a
  lifetime of minutes and is written by a background process; putting it in a durable,
  restorable config file means a restore can resurrect a stale lock on a machine where
  nothing is running. Locks are a separate mechanism.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep `canonicalBaseUrl` in `composer.json` (`extra.core-bootstrap`) | Committed, so staging and production cannot differ; and it is the deploy artefact, not the installation |
| Put it in `bootstrap.inc.php` | Regenerated on every install — a value corrected on the server is silently lost |
| A flag/value file under `data/` | `data/` is what the `data` backup archive carries between environments, so the value rides a restore into the wrong environment, silently |
| A value in `override/systemConfig` | `override/` is the CE code tree and belongs in the project repository — same sharing problem as `composer.json` |
| A vhost environment variable (`SetEnv`) | Genuinely per environment, but a second configuration channel outside the framework's own config story, and invisible to the backend |
| Fatal at boot when a value is missing | Takes down the backend, i.e. the surface used to fix it — you lock yourself out exactly when you need the editor |
| Migrate `DEBUG` / `SEO_NOINDEX` in the same step | Touches the installer, two toggles and two read sites for no functional gain, while the pattern is one key old |
