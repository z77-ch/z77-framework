# Release rules — binding for every z77 project

This directory is the single place where a project states its server release
target and the rules every deploy tool (script, SFTP client, AI assistant)
must respect. The master copy lives in the framework repository; a new project
copies the whole directory, fills `target.json`, and commits it.

**Deviations require the explicit, personal approval of the framework owner.**
Not a comment, not a commit message — an approval recorded here in this file.

## Files

| file | purpose |
|---|---|
| `RULES.md` | these rules — copied verbatim, never edited per project |
| `target.json` | this project's server target (committed; contains no secret, only the SSH alias and paths) |
| `target.example.json` | template for `target.json` |
| `check.php` | verifies `target.json`, `.vscode/sftp.json` and the local `vendor/` state against the rules — warns, never uploads |
| `sftp.example.json` | template for `.vscode/sftp.json` (gitignored; the developer copies and maintains it) |
| `deploy.php` | uploads ONE release over ssh (tar stream, every shared name excluded), sets the signposts into `shared/`, compares `config/fileFinder.inc.php` — refuses an existing or running release |
| `switch.php` | bends `next` or `current` at a release over ssh: link target per `target.link_target`, the OPcache reset, the deny files in `shared/`, then probes the door from outside |
| `htaccess-deny` | `Require all denied` — copied to `<project>/.htaccess` (link_target `public`) and by `deploy.php`/`switch.php` into every shared store NOT served through `public/`; the alarm for a door bent at the wrong place |
| `vendor-deploy.php` / `.bat` | builds a deployable `vendor/`: real copies of every path-repo package (list read from `composer.json`), production autoload, build stamp |
| `vendor-dev.php` / `.bat` | restores the development `vendor/` (links, dev deps, stamp removed) |
| `lib.php` | helpers shared by the scripts |
| `CHECKLIST.md` | the five things an upload does not carry — worked through on every release |
| `HANDOFF.md` | step list for setting a project up |

## The rules

1. **The project root on the server is the outer boundary.** `target.root`
   names it (e.g. `/home/<account>/public_html/example.ch`). Anything above
   or beside it is out of bounds: never listed, copied, written or deleted.
   The only permitted operation above the root is path resolution
   (`realpath`, existence check) needed to verify the boundary itself.
2. **Exactly four entries at the root:** `current`, `next`, `releases/`,
   `shared/`. Nothing else is created there.
3. **`current` and `next` are symlinks into `releases/`** — never anywhere
   else. `current` is production, `next` the test door. WHERE inside a
   release they point is ONE convention per project, recorded in
   `target.link_target`: `public` — the door points at
   `releases/<name>/public` and the hoster's document root is `<root>/current`
   (resp. `next`); `release` — the door points at `releases/<name>` and the
   document root is `<root>/current/public`. Never mixed, never a third form.
   **A door is bent with `switch.php`, not with a hand-typed `ln`**: the hand
   forgets the `/public` (with `public` that makes the release root — vendor/,
   composer.json, every signpost into shared/ — the document root) and it
   forgets the OPcache reset (OPcache binds the door path `next/index.php` to
   ONE compiled copy and never re-resolves the symlink —
   `opcache.revalidate_path=0`, no TTL; the old release keeps running and a
   `touch` on the new file is never looked at; proven 2026-08-31). The
   `index.php` trampoline (runtime `realpath(DOCUMENT_ROOT)`) self-heals such
   a switch within ~2 minutes; the reset makes it immediate — and clears the
   account-wide OPcache shared by ALL sites of the hosting account (they
   recompile once, harmless). The script proves the switch by reading
   `X-Z77-Release` off the door. The project root carries `htaccess-deny` as
   `.htaccess` for the day the hand wins anyway.
4. **`releases/<name>/` is the installation root.** It holds pure code —
   `vendor/`, `public/`, `override/`, `cron/`, `composer.json`,
   `composer.lock` — plus the symlinks into `shared/`. The name matches
   `target.release_name` (default: `YYYY-MM-DD`, optional `-N` for a second
   release on the same day).
5. **`shared/` holds everything that comes into being at runtime** and is
   never part of an upload: `data/`, `config/`, `logs/`, `lib/`, `backup/`,
   `media/`, `storage/`, and every key/credential store (`.propbase/`,
   `.emonitor/`, …). Listed in `target.shared`.
6. **An upload never contains a shared name.** A local `data/` or `config/`
   in the artifact would replace the symlink with a real directory and fork
   the state. The SFTP ignore list must exclude every entry of
   `target.shared` (as `<name>/**`).
7. **An upload targets exactly one release directory** —
   `<root>/releases/<name>` — never `current`, `next`, `shared` or the root.
8. **`uploadOnSave` is always `false`.** A save must never reach the server.
9. **Pruning never removes the target of `current` or `next`**, and keeps at
   least one additional release as the rollback.
10. **Migration of an existing installation into this layout is a manual,
    one-time step** (data move into `shared/`), never performed by a deploy
    tool.

## Operating

- The developer maintains `.vscode/sftp.json` by hand (it is gitignored —
  it carries the deploy path). It starts as a copy of `sftp.example.json`;
  per deploy only `remotePath` changes (the release name).
- **`vendor/` is part of the upload** — it must NOT be in the ignore list.
  On the developer machine the framework packages are links at the working
  trees; a link cannot be uploaded. `vendor-deploy.php` replaces every
  path-repo link with a real copy and stamps it; `check.php` refuses a
  vendor that still holds links, lacks the stamp, or carries dev dependencies.
- Deploy sequence, every time:
  1. `php .releases/vendor-deploy.php`
  2. `php .releases/deploy.php <name>` — upload + signposts; it prints whether
     `shared/config/fileFinder.inc.php` differs from the local one (CHECKLIST 1)
  3. `php .releases/switch.php <name> next` — test on the test door, «Cache leeren» there
  4. `php .releases/switch.php <name> current` — «Cache leeren» in the backend
  5. `php .releases/vendor-dev.php`

  The SFTP client is the fallback when ssh is not available: then
  `remotePath` in `.vscode/sftp.json` names the new release,
  `php .releases/check.php` must print `OK` before the upload, and the
  signposts are set by hand (handbook `release-structure.md`).
- **`CHECKLIST.md` covers what the sequence above does not move** — a
  regenerated `config/` file, the `shared/` stores, the backend
  cache clear. Read it on every release, not only on the first.
- Switch mechanics (`ln -sfn` + OPcache reset, page-cache clear, rollback) are in
  the framework handbook: `docs/01-handbook/release-structure.md`.

## Approved deviations

- none
