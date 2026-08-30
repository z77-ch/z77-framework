# What an upload does not carry

The deploy sequence in `RULES.md` moves code. These five things it does not,
and each one fails in a way that does not look like its cause. Work through
them on every release; they are the same every time.

1. **A regenerated `config/` file goes up by hand.** `config/` lives in
   `shared/` and is on the SFTP ignore list, so `composer install` can rewrite
   `config/fileFinder.inc.php` — a new package adds a namespace there — and
   the upload will not take it. Copy it into `shared/config/` yourself.
   *Symptom if skipped:* the site runs, the first call into the new package
   dies with «Namespace 'X\Y\' has no registered sourcePaths».

2. **Every store named in `target.shared` must exist before the first write.**
   The release carries a symlink; the symlink needs a target. Code that
   creates its own directory (`mkdir`) cannot do it *through* a dangling
   link — it gets «File exists» and stops. `mkdir -p shared/<name>` costs
   nothing when the directory is already there.
   *Symptom if skipped:* a form reports success and stores nothing, or a
   credential file never appears.

3. **`touch` the entry point on every switch.** OPcache keys the compiled
   `index.php` on the *unresolved* path (`current/public/index.php`), and
   `index.php` carries the same mtime in every release — so bending the
   symlink changes nothing PHP can see, and the old bytecode keeps running
   with the old release's paths baked in.
   ```
   touch releases/<name>/public/index.php && ln -sfn releases/<name> current
   ```
   *Symptom if skipped:* the worst kind — the site works. Static files come
   from the new release, every rendered page from the old one. Mechanism,
   rollback caveat and the reset-file fallback: `release-structure.md`.

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
