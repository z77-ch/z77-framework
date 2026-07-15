# ADR-026 — Opt-in interactive asset deploy on update (amends ADR-024 §3 / ADR-025)

**Status:** `[APPROVED]`
**Date:** 2026-07-15
**Amends:** ADR-024 §3 ("No overwrite, no force command"), ADR-025 (read-only report)

---

## Context

ADR-024 made `public/` developer-owned and ADR-025 added a read-only drift report on update:
after `composer update` the installer prints which framework assets in `vendor` differ from the
deployed `public/` copies (`~ changed` / `+ new`), but writes nothing. The developer then deploys
the wanted files **by hand** (`cp vendor/z77/.../res/assets/... public/...`).

In practice that hand-copy is repetitive: a framework update or a new module can bring several new
asset files, and copying each one manually — for every file, every project — is exactly the busywork
the tooling should remove. The owner asked to turn the report into a per-file prompt: for each drifted
file, ask "deploy into `public/`?", defaulting to **No**.

This reverses ADR-024 §3 / ADR-025's "the installer must never write into an existing `public/`" —
consciously, after weighing the INST-ASSET-002 risk (see Reasoning).

## Decision

**On an update (`public/` present) the installer offers an opt-in, per-file deploy of drifted
framework assets into `public/`. Interactive runs only; every prompt defaults to No.**

1. **Interactive-only.** The deploy prompt runs solely when `io->isInteractive()`. In a
   non-interactive context (CI, deploy, `--no-interaction`) the drift output stays the ADR-025
   read-only report and **nothing is written to `public/`**. This preserves the property that
   automated `composer install` on a server never clobbers deployed assets.
2. **Default No.** Each file is a separate `askConfirmation(…, false)`. A blind Enter, or any
   "yes to all" reflex, deploys nothing — the developer must actively type `y` per file.
3. **`+ new` is risk-free; deploy plainly.** The file is absent in `public/`, so copying it in
   destroys nothing. Prompt: `Deploy into public/? [y/N]`.
4. **`~ changed` is the footgun; warn before asking.** The deployed copy may be the developer's own
   edit or a **build artefact** (compiled CSS/JS from `override/…/scss`). Overwriting it with the
   framework version can wipe their work — the exact INST-ASSET-002 incident. The prompt is preceded
   by a loud warning ("this may be YOUR edit or a compiled artefact — your changes are lost"), then
   `Overwrite public/ file? [y/N]`.
5. **Still no blanket force.** There is no "yes to all" flag, no `debug`-driven auto-copy, no
   `publish-assets` command that writes without asking. The only way a file reaches `public/` is an
   explicit per-file `y`. Implementation: `promptAssetDeploy()` + `deployAsset()` in `Install.php`.

## Reasoning

- The INST-ASSET-002 incident was an **automatic, unattended** overwrite (`debug=true` → always copy).
  An explicit per-file `y` from the developer who owns `public/` is a different act: they are told
  what the file is (`+ new` vs `~ changed`), warned when it is risky, and default is No. Consent, not
  surprise.
- The non-interactive guard keeps every automated path (the dangerous ones — deploy, CI) exactly as
  safe as ADR-024/025 left them. The change only affects a human sitting at the prompt.
- The `+ new` case carries no risk at all and is the most common source of hand-copy busywork (a new
  module's assets), so most of the convenience win comes with zero exposure.
- For `~ changed`, the warning + default-No make the dangerous case opt-in and eyes-open. The residual
  risk (developer types `y` on a file that was actually their build artefact) is accepted by the owner
  as the price for not hand-copying — and is recoverable the same way the original incident was (rebuild
  from `override/…/scss`).

## Consequences

- Interactive `composer update` on an existing project now offers to deploy each drifted asset; the
  developer accepts the ones they want with `y`, skips the rest with Enter.
- Non-interactive `composer install/update` (server, CI) behaves exactly as under ADR-024/025: a
  read-only drift report, `public/` untouched.
- ADR-024 §3 and ADR-025's "never writes into `public/`" no longer hold verbatim for the interactive
  case — they are amended by this ADR. The ownership model itself (public is the developer's) is
  unchanged: the installer only ever writes what the developer explicitly confirms.
- A developer-customized `~ changed` file still re-appears on every update (ADR-025 limitation intact);
  now it also prompts each time. The default-No + warning keep that safe but it can get repetitive —
  the deferred manifest/version scheme (ADR-025) remains the eventual upgrade if the noise grows.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep read-only report only (ADR-025 as-is) | The owner wanted the hand-copy busywork removed; a per-file prompt with default No does that without reintroducing an unattended overwrite. |
| "Yes to all" / `--publish-all` flag | Re-creates the INST-ASSET-002 blast radius (one keystroke overwrites every developer asset). Per-file consent is the whole safety mechanism. |
| Also deploy in non-interactive runs | The unattended-overwrite footgun. CI/deploy must stay read-only. |
| Auto-deploy `+ new`, prompt only `~ changed` | Even a `+ new` file is a developer-visible change to `public/`; keeping both behind an explicit `y` is consistent and costs one keystroke. |

## See also

- [`adr-024-asset-ownership-and-first-install-seed.md`](adr-024-asset-ownership-and-first-install-seed.md) — ownership + first-install seed (this ADR amends §3)
- [`adr-025-asset-drift-report-on-update.md`](adr-025-asset-drift-report-on-update.md) — the read-only report this ADR turns into an opt-in deploy
- [`../topics/installer.md`](../topics/installer.md) — INST-ASSET-DEPLOY-001, implementation
