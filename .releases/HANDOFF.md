# Handoff — set up `.releases/` in a project

For an AI assistant or a developer who is told: *«read `.releases/` in the
framework and apply it here».* Work through the steps in order; stop and ask
where a step says so. The framework copy lives at
`<framework>/.releases/` (the repository `z77-ch/z77-framework`, on the
maintainer machines `Z:\z77\z77-ch-framework-1.0.0`).

## 0. Read first

- `RULES.md` — the ten rules. They are not negotiable in this handoff.
- `docs/01-handbook/release-structure.md` in the framework — the server
  layout and the switch mechanics (`ln -sfn` + OPcache reset, cache clear,
  rollback).

## 1. Copy the directory

Copy the whole `.releases/` directory from the framework into
`<project>/.releases/` — every file except `target.json` (that one is per
project). Copy verbatim — `RULES.md`, `check.php`, `lib.php`, the
`vendor-*` scripts and the wrappers are never edited per project. If the
project already has a `.releases/`, diff first and update everything except
`target.json` to the newer framework version.

The project's old `vendor-deploy.bat` / `vendor-dev.bat` in the project
root are superseded by `.releases/vendor-deploy.bat` / `vendor-dev.bat`
(which read the package list from `composer.json`). Tell the developer; do
not delete them yourself.

## 2. Create `target.json`

`cp target.example.json target.json`, then fill in:

| key | value |
|---|---|
| `host` | the SSH alias from `~/.ssh/config` (e.g. `cyon-z77`) — never a password, never a raw hostname with credentials |
| `root` | absolute server path of the project directory, the one that contains `current`, `next`, `releases/`, `shared/` |
| `release_name` | keep the default unless the owner approved another scheme |
| `link_target` | `public` when the hoster's document root is `<root>/current` (the door points at `releases/<name>/public`); `release` when the document root is `<root>/current/public`. Read it off the hoster's panel or off `ls -l <root>/current` — do not guess, rule 3 |
| `hosts` | the hostname behind each door (`next.example.ch`, `example.ch`) — `switch.php` probes them from outside |
| `shared` | the default list PLUS every project-specific runtime store (key directories such as `.propbase`, `.emonitor`, `data/<something>` that is not in git) |
| `release_dirs` | keep the default; add only what the project really ships |

Ask the developer for `root` and for the project-specific `shared` entries if
they are not obvious from the code (`grep` for `.`-prefixed store names in
`config/` and `override/`).

## 2b. The deny file in the project root

With `link_target: public` copy `htaccess-deny` to `<project>/.htaccess`
(verbatim, committed). Apache never reads it in the correct layout; it is
the 403 that answers the day a door is bent at `releases/<name>` instead of
`releases/<name>/public`. With `link_target: release` do NOT — there the
release root is inside Apache's directory walk and the file would close the
whole site; `check.php` says so.

## 3. `.vscode/sftp.json` from the template

If the project has no `.vscode/sftp.json`, copy `sftp.example.json` there
and set `remotePath` to `<root>/releases/<name>`. Add every project-specific
`shared` entry from `target.json` to the ignore list as `<name>/**`.

If the file exists, **do not edit it** — the developer maintains it. Step 4
will tell them what differs.

## 4. Make sure `.releases/` is committed

`.releases/` contains no secret. Check `.gitignore` — it must NOT match
`.releases/`. `.vscode/` stays ignored (it holds `sftp.json` with the deploy
path).

## 5. Run the check

```sh
php .releases/check.php
```

Report every violation to the developer, verbatim. **Do not edit
`.vscode/sftp.json` yourself.** Typical findings on an existing project:

- `uploadOnSave` not `false` → rule 8
- `remotePath` on the old flat layout (`apps/z77-…`) or on `current` → the
  project has not been migrated yet; migration is a manual one-time step
  (rule 10), not part of this handoff — say so and stop.
- ignore list lacks `data/**`, `logs/**`, `lib/**`, `backup/**`,
  `public/media/**`, `public/storage/**` → rule 6; list the exact entries to
  add.
- `vendor/**` ignored → since this setup vendor is uploaded from real copies;
  the entry must go.
- `vendor/z77/kernel is a link` / `build.json missing` → the developer runs
  `php .releases/vendor-deploy.php` before uploading, `vendor-dev.php`
  afterwards. Between deploys this finding is normal — the check is meant
  for the moment right before an upload.
- `link_target must be ...` / `hosts.next missing` → an older `target.json`;
  add the keys from `target.example.json`, values off the hoster's panel.
- `<project>/.htaccess missing` → step 2b.

## 6. Mention it in the project's `CLAUDE.md`

If the project has a `CLAUDE.md`, add one bullet under its «must not» list
pointing at `.releases/RULES.md` (the framework's own `CLAUDE.md` has the
wording — copy that bullet).

## 7. Done when

- `.releases/` exists with every framework file plus `target.json`, tracked
  by git
- `.vscode/sftp.json` exists (from the template or the developer's own)
- `php .releases/check.php` prints `OK` right before a deploy, or the
  developer has the list of violations and decided what to do
- nothing on the server was touched — this handoff is local only
