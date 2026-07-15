# Bauplan — z77 v1.0.0 Public Release (Doku-Gate)

**Status:** `[DONE]`
**Date:** 2026-07-15

Ziel: Framework öffentlich machen, um es ersten Entwicklern zu präsentieren.
Gate (aus CLAUDE.md-Philosophie): `docs/01-handbook/` muss vollständig sein, und
das Repo muss die Public-Essentials (README, LICENSE) tragen. Was public ist,
muss verständlich sein.

Basis: Doku-Analyse vom 2026-07-15. Diese Datei ist die Abhak-Liste; nach jedem
erledigten Schritt hier den Haken setzen und (wo betroffen) `npm run docs:check`
grün halten.

> **✅ ERLEDIGT 2026-07-15 — Public-Release komplett.** Das z77 Framework ist öffentlich:
> Monorepo (`z77-ch/z77-framework`), die 4 Pakete (`kernel`, `module-frontend`,
> `module-backend`, `module-dms`) und das Skeleton (`z77-ch/z77-skeleton`) sind public auf
> GitHub und auf Packagist gelistet (`z77/*`, Version `1.0.0`), anonym erreichbar. **Beide
> Install-Wege verifiziert:** `composer create-project z77/skeleton my-project` (ohne git,
> end-to-end getestet: Startseite HTTP 200) und „Use this template" (mit git). History via
> Orphan-Squash bereinigt (kein Kundenname, keine Secrets). Alle Schritte #1–#13 erledigt.
> Restliches Cleanup (archivierte Repos, Backup-Refs, 1.0.1-Tag) außerhalb dieser Liste.

---

## Ist-Zustand (Kurzfassung)

**Ready (kein Handlungsbedarf):** `create-page.md` (vollständiger „erste Seite"-Guide),
`installer.md`, `conventions.md`, `css-conventions.md`, `templates.md`,
`dev-environment.md`, 27 ADRs, 30+ Topic-Docs, Frontend-Templates (`IndexController`
als sauberes Referenzbeispiel).

**Lücke:** Genau die erste Kontaktfläche für fremde Entwickler fehlt oder ist Stub.

---

## Phase 1 — Public-Blocker (P0)

- [x] **1. `LICENSE` (MIT) im Root.** Erledigt 2026-07-15 — MIT, Copyright
      Peter Ruepp (z77), 2026. Macht die überall behauptete MIT-Aussage wirksam.
- [x] **2. Root-`README.md`.** Erledigt 2026-07-15 — Tagline, Intro, Philosophie-Notiz,
      Highlights, Prerequisites (laufen vs. Styles), Quickstart (Skeleton-Template →
      composer install → composer dev), Doku-Tabelle, Architektur-Absatz, Lizenz.
      Deckt P1-#8 (Quickstart) mit ab. Owner-Freigabe: Skeleton-Quickstart + Ton bestätigt.
- [x] **3. `docs/01-handbook/architecture.md` füllen.** Erledigt 2026-07-15 —
      navigierende Karte (Overview, Packages, Module/URL-Grouping, Request-Flow,
      Persistenz, CE-Prinzip, „Where things live"); verlinkt in Topics/ADRs statt
      zu duplizieren. Owner-Review bestätigt (Ton + Vollständigkeit).
- [x] **4. `docs/01-handbook/create-module.md` füllen.** Erledigt 2026-07-15 —
      zwei Modul-Ausprägungen (view-area wie frontend/backend, headless wie dms);
      nur App-Config zwingend. Owner-Korrektur eingearbeitet (Modul ≠ zwingend
      view-area) und Logik gegen dms-Code verifiziert.
- [x] **5. `docs/01-handbook/onboarding.md` → „Where is what?" + FAQ-Start füllen.**
      Erledigt 2026-07-15 — „Where is what?" nach zwei Blickwinkeln (Monorepo vs.
      Projekt), 7 Tag-1-FAQ-Einträge. Read-first-Liste korrigiert (create-page vor
      create-module).

### Nachtrag zu #2 — dev-environment.md public-tauglich (Option 2)

- [x] **dev-environment.md gesplittet.** Erledigt 2026-07-15 — schlanke öffentliche
      Fassung (Tools/Extensions/CSS-Build/Checker/Gotchas); der persönliche
      Maintainer-Runbook (persönliche Zugangsdaten, Laufwerk-Layout, Sync-Topologie,
      Zwei-PC-Workflow, Projekt-Copy) wandert nach `docs/_local/maintainer-machine-runbook.md`
      (gitignored `/docs/_local/`, extern synchronisiert, nie public).

## Phase 2 — Aufräumen & Feinschliff (P1)

- [x] **6. `CLAUDE.md`-Entscheid.** Erledigt 2026-07-15 — Owner-Entscheid: **public
      lassen + füllen**. „Key Rules" (9 Regeln) und „What you must NOT do" aus
      conventions.md/architecture.md abgeleitet, jede Regel mit Link auf owning Doc/ADR.
      Zeigt die AI-first-/Open-Source-Philosophie transparent.
- [x] **7. Root-Cleanup.** Erledigt 2026-07-15 — die vier getrackten Notiz-Dateien per
      `git mv` nach `docs/03-development/` (`review.md` → `docs-system-review.md`,
      sprechend im review-`*.md`-Ordner). `git-spick-zettel.md` (untracked, persönlicher
      Git-Lernzettel) nach `docs/_local/` (gitignored). Root trägt jetzt nur noch
      `CLAUDE.md` + `README.md`. Veralteter Root-Verweis in `css-backend-list-review.md`
      nachgezogen. `docs:check` grün.
- [x] **8. Quickstart verifizieren.** Erledigt 2026-07-15 — Skeleton in ein frisches
      Verzeichnis kopiert (ohne `vendor/`/`composer.lock`), `composer install` lief
      sauber (4 Pakete via path-repo verlinkt, `Install::run` komplett, Exit 0),
      `php -S … -t public` gestartet: Startseite **HTTP 200** (gerendertes „Home |
      Muster AG"), versioniertes CSS-Asset **HTTP 200**. Quickstart end-to-end lauffähig.
      Test-Verzeichnis + Junctions rückstandsfrei entfernt.
- [x] **9. Root-`composer.json`** ergänzen. Erledigt 2026-07-15 — Monorepo-Aggregator:
      `type: project`, `path`-Repository auf `packages/*` (symlink), `require` der vier
      Pakete (`^1.0`, via `branch-alias` `dev-main → 1.0.x-dev`), MIT, Autor/Stability
      konsistent mit den Paket-Manifesten. `composer validate` grün. Kein `install`
      ausgeführt (kein `vendor/`/`composer.lock` erzeugt).

- [x] **12. Git-History-Audit (BLOCKER vor public) — erledigt 2026-07-15
      (Audit + Anonymisierung; Squash siehe #13).**
      **Audit-Befund:** keine echten Secrets in der gesamten History (kein Token/Key/
      Klartext-Passwort/Hash; `.vscode/sftp.json` nie getrackt). Sensibel war (a) internes
      Maintainer-Wissen (alte `dev-environment.md`: Sync-Topologie, Zugangsdaten,
      Laufwerk-Layout; PAT-Name) und (b) **der reale Kundenname des Referenzprojekts**, der
      tief im aktuellen Stand steckte (~13 Dateien, u. a. `slider.md` mit projektspezifischem
      CSS-Prefix). **Erledigt:** aktueller HEAD anonymisiert (Kundenname → „reference project" /
      `<project>`; CSS-Prefix → neutral `sl-`; PAT-Name entfernt; Bauplan-Meta generalisiert).
      Verifiziert: `git grep` 0 Treffer, `docs:check` grün (Commit `66a929c`, gepusht).
      **Offen:** Orphan-Squash (neuer Initial-Commit aus dem sauberen Stand, alte History
      verwerfen, force-push) — verwirft die History-Tiefe, die die alten Strings noch
      trägt. Owner-Entscheid: **erst als letzter Schritt vor #11** ausführen; Repo bleibt
      bis dahin privat, daher kein Leak.

## Phase 3 — Go-Public (mechanisch)

Deckt `PLAN-git-rollout.md` „Phase 2 — Packagist" ab: Ziel-Repos + Skeleton auf public,
Packagist-Listing (MIT), `repositories`-Block in Projekten entfernen.

- [x] **10. Rollout-Plan aktualisieren.** Erledigt 2026-07-15 — `PLAN-git-rollout.md` auf
      den realen Stand gebracht: **4 Pakete statt 6** (`kernel` + 3 Module), Abhängigkeits-
      matrix neu (Module → `kernel`), `monorepo-builder` als verworfen dokumentiert (kernel-
      Merge kapselt die Zirkularität), Split-Workflow-Fakten korrigiert (`symplify/monorepo-
      split-github-action@v2.4.5`, 4-Target-Matrix). **Wichtiger Befund:** die Schritte 0–D
      **+ Auto-Split sind bereits seit 2026-07-10 LIVE** (git-journal); der Plan ist damit
      Referenz/Historie, nicht mehr auszuführen. Einzig real offener Rollout-Teil = Phase 2
      (Packagist + public) = #11.
- [x] **11. Repos auf public + Packagist-Listing.** Erledigt 2026-07-15 — 4 Pakete public
      + auf Packagist (`z77/kernel`, `z77/module-*`, Version `1.0.0`), Webhooks aktiv,
      `composer require z77/module-frontend:^1.0` end-to-end von Packagist verifiziert.
      Skeleton (`z77-ch/z77-skeleton`) public + GitHub-Template + auf Packagist als
      `z77/skeleton` → `composer create-project z77/skeleton` (git-los) getestet. Paket-
      READMEs auf das Skeleton-Template verwiesen, „not ready for production" entfernt.
      Monorepo als letzter Schritt public. (Projekt-`repositories`-Block-Entfernung
      entfällt — die realen Projekte werden ohnehin nicht über dieses Repo verwaltet.)
- [x] **13. Orphan-Squash — erledigt 2026-07-15.** Neuer Initial-Commit `09da7c5` aus dem
      sauberen Stand, alte 58-Commit-History verworfen, `push --force main` → branch-Split
      grün. Danach `tag 1.0.0` → tag-Split → alle 4 Paket-Repos getaggt. Reihenfolge
      korrigiert (Squash VOR Tag/Split/Public). Lokaler Backup-Ref `pre-squash-backup`
      als Sicherheitsnetz gesetzt (Cleanup separat).

---

## Reihenfolge

```
Phase 1/2 → #12 Anonymisierung → #10 (Rollout-Plan) → Gate schliessen →
#13 Orphan-Squash (Monorepo PRIVAT) → tag 1.0.0 + Split → Pakete public →
Packagist → Skeleton-Template → Monorepo public
```

Warum Squash zuerst: es existiert kein `1.0.0`-Tag; `tag 1.0.0` triggert den Split, der
die Paket-Repos befüllt. Sitzt der Tag auf noch-nicht-gesquashter History, zeigt er nach
dem Squash auf einen verworfenen Commit. Also: erst Squash, dann taggen/splitten/public.

**Kritischer Pfad zum „präsentieren":** #1 + #2 + #3 (LICENSE, README, architecture) —
damit ist das Repo einem Entwickler zeigbar; der Rest folgt.

## Offene Entscheide

- ~~**#6 CLAUDE.md** — public lassen (füllen) oder aus öffentlichem Repo nehmen?~~
  Entschieden 2026-07-15: public lassen + gefüllt.
