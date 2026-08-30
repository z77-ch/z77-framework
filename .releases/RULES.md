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
| `switch.php` | bends `next` or `current` at a release over ssh: link target per `target.link_target`, the `touch`, the deny files in `shared/`, then probes the door from outside |
| `htaccess-deny` | `Require all denied` — copied to `<project>/.htaccess` (link_target `public`) and by `switch.php` into every shared store; the alarm for a door bent at the wrong place |
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
   forgets the `touch` (then the old release keeps running behind the new
   link). The project root carries `htaccess-deny` as `.htaccess` for the day
   the hand wins anyway.
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
  2. set `remotePath` in `.vscode/sftp.json` to the new release name
  3. `php .releases/check.php` — must print `OK`
  4. upload (SFTP: Sync Local → Remote)
  5. `php .releases/vendor-dev.php`
  6. on the server: the signposts into `shared/` (handbook `release-structure.md`)
  7. `php .releases/switch.php <name> next` — test on the test door
  8. `php .releases/switch.php <name> current` — then «Cache leeren» in the backend
- **`CHECKLIST.md` covers what the sequence above does not move** — a
  regenerated `config/` file, the `shared/` stores, the `touch`, the backend
  cache clear. Read it on every release, not only on the first.
- Switch mechanics (`touch` + `ln -sfn`, page-cache clear, rollback) are in
  the framework handbook: `docs/01-handbook/release-structure.md`.

## Approved deviations

- none
