# Release checklist

## The run, step by step

Every step prints something; read it before typing the next command. The
failure points behind these steps are explained in the second half of this
file — read those on every release too.

1. **Build the deployable `vendor/`** — `php .releases/vendor-deploy.php`
   You see: every path-repo package turned into a real copy, the build stamp.
   Nothing to decide — but from here until step 6, local framework edits do
   not reach the working trees.

2. **Upload the release** — `php .releases/deploy.php <name>`
   You see: the tar streaming into `releases/<name>/`, the signposts and deny
   files being set, and LAST a line about `config/fileFinder.inc.php`.
   Decide: if it reports the file DIFFERS, copy it up (the printed `scp`
   line) BEFORE any switch — a release without it dies on the first call
   into a new namespace.

3. **Bend the test door** — `php .releases/switch.php <name> next`
   You see: the link read back and verified, the OPcache reset,
   `X-Z77-Release` naming the release, the outside probes all `ok`.
   Decide: on any STOP or FAIL, stop — the script prints the rollback line.

4. **Test on `next.<domain>` — by hand, in the browser.**
   Request pages that are demonstrably NEW in this release (a changed
   template, a new route) — a static file proves nothing, Apache always
   follows the link. If rendered output looks old, clear the page cache
   there first («Cache leeren»).
   Decide: only when the new behaviour shows on the test door, go live.

5. **Bend production** — `php .releases/switch.php <name> current`
   Then: backend «Cache leeren» on the production hostname (the page cache
   spans releases), and read `X-Z77-Release` off the production domain once
   yourself (`curl -sI https://<domain>/ | grep X-Z77-Release`).

6. **Back to development** — `php .releases/vendor-dev.php`
   You see: the copies turned back into links at the working trees.
   Forgetting this fails silently: you edit a framework package and nothing
   happens.

Rollback, any time: `php .releases/switch.php <previous> current` — the same
script with the old name, no upload involved.

## What an upload does not carry

The deploy sequence in `RULES.md` moves code. These five things it does not,
and each one fails in a way that does not look like its cause. Work through
them on every release; they are the same every time.

1. **A regenerated `config/` file goes up by hand.** `config/` lives in
   `shared/` and is excluded from every upload, so `composer install` can
   rewrite `config/fileFinder.inc.php` — a new package adds a namespace
   there — and the upload will not take it. `deploy.php` compares the two
   and prints the `scp` line when they differ; the copy is still yours.
   *Symptom if skipped:* the site runs, the first call into the new package
   dies with «Namespace 'X\Y\' has no registered sourcePaths».

2. **Every store named in `target.shared` must exist before the first write,
   and each one carries the deny file.** The release carries a symlink; the
   symlink needs a target. Code that creates its own directory (`mkdir`)
   cannot do it *through* a dangling link — it gets «File exists» and stops.
   `mkdir -p shared/<name>` costs nothing when the directory is already there.
   The `.htaccess` inside each store (`Require all denied`) is written by
   `deploy.php` and `switch.php` when missing; it is what stands between a
   door bent at the wrong place and the credentials becoming URLs. **Never
   into a store served through `public/`**: `public/media` IS
   `shared/media` — the link is the directory — so a deny file there denies
   every image, on every door at once, because `shared/` is common to all
   releases. Measured 2026-08-30 on axo3.ch; both scripts now remove it.
   *Symptom if skipped:* a form reports success and stores nothing, or a
   credential file never appears — or, without the deny file, nothing at all
   until someone requests `/.propbase/tenant-3.json`.

3. **Bend a door with `switch.php`, never by hand — `next` and `current`
   both, and the rollback too.**
   ```
   php .releases/switch.php <name> next
   ... test ...
   php .releases/switch.php <name> current
   ```
   Two things a hand-typed `ln` forgets, and the script cannot: the `/public`
   at the end of the link (per `target.link_target`; forgotten, the release
   root with all its signposts is the document root) and the **OPcache
   reset**. OPcache binds the door path (`next/index.php`) to ONE compiled
   copy and never re-resolves the symlink (`opcache.revalidate_path=0`, no
   TTL) — bending the link changes nothing it looks at, and a `touch` on
   the new release's file is never looked at either (only the OLD bound
   file's mtime is checked). Measured on cyon 2026-08-30, mechanism proven
   2026-08-31: the door on release N served PHP from release N-1 for an
   hour, hits climbing, while Apache served N's static files (a versioned
   CSS built by N-1 → 404 on N). Since 2026-08-31 `index.php` is a
   runtime-resolving trampoline that self-heals such a switch within
   ~2 min; the reset makes it immediate and clears the account-wide OPcache
   (all sites of the account — they recompile once, harmless). The script drops a
   one-shot reset file into the release, calls it once (it deletes itself),
   then reads **`X-Z77-Release`** off `/` until it names the release — the
   header every HtmlResponse carries since 2026-08-30, the proof a human
   gets with `curl -I`. Then it **probes the door from outside**:
   `/composer.json`, `/vendor/z77/build.json` and every top-level shared
   store must give 403 or 404. A failed probe prints the rollback line.
   By hand, should the script be unavailable:
   ```
   curl -sI https://<host>/ | grep X-Z77-Release          # the release name
   curl -sI https://<host>/composer.json | head -1        # 403 or 404
   ```
   *Symptom if skipped:* the worst kind — the site works. Static files come
   from the new release, every rendered page from the old one. On `next` it is
   worse still: the test door then certifies a release that never ran, and the
   switch to `current` carries the untested code to production. Mechanism and
   the rollback caveat: `release-structure.md`.

4. **Clear the cache in the backend after the switch.** FileFinder and
   ConfigManager store the resolved vendor path per namespace with a very long
   TTL. A new namespace and every newly shadowing override stay invisible
   until that pool is dropped. Each hostname has its own pool — clear it on
   each one you serve.
   *Symptom if skipped:* «class not found» for a class whose file is plainly
   there.

5. **Restore the development `vendor/` when the upload is done.**
   `php .releases/vendor-dev.php` turns the real copies back into links at the
   working trees.
   *Symptom if skipped:* you edit a framework package and nothing happens —
   locally you are running the deploy copy.

An installation that shares its framework packages with a second installation
on the same host needs points 4 and 5 there too, plus whatever hand-copied
assets it keeps. That belongs in the project's own notes, not here.
