# Umsetzungsplan — z77 Framework auf Git (konsolidiert, korrigiert)

Ersetzt die Annahmen aus `PLAN-monorepo-split.md` und `PLAN-checkliste-voraussetzungen.md`
dort, wo sie vom realen Ist-Zustand abweichen. Basis: Analyse vom 2026-07-09.

> **STATUS (2026-07-15): Schritte 0–D + Auto-Split sind LIVE (seit 2026-07-10).**
> Dieses Dokument ist ab hier weitgehend **Referenz/Historie**, kein offener TODO mehr.
> Der Rollout lief mit dem **kernel-Merge (ADR-023)**: statt 6 Package-Repos gibt es
> **4** — `kernel` (= core+shared+persistence, ein Paket/drei Namespaces),
> `module-frontend`, `module-backend`, `module-dms`. Der Auto-Split
> (`.github/workflows/split.yml`, `symplify/monorepo-split-github-action`) ist grün für
> alle 4 Targets; die alten Repos `core`/`shared`/`persistence` sind archiviert.
> `monorepo-builder` wurde **nicht** eingesetzt (der kernel-Merge kapselt die frühere
> Zirkularität paket-intern → kein Package-übergreifender Zyklus mehr). Nachweis:
> [`../04-changelog/git-journal.md`](../04-changelog/git-journal.md) (Eintrag 2026-07-10)
> und [`../topics/packaging.md`](../topics/packaging.md).
>
> **Einzig offen:** Phase 2 (Packagist-Listing + Repos auf public) — geführt im
> [`public-release-bauplan.md`](public-release-bauplan.md) als **#11** (public + Packagist)
> und **#13** (Orphan-Squash als Finalschritt). Die Schrittdetails unten sind auf den
> 4-Paket-Stand korrigiert und dienen als Referenz, wie der Split aufgesetzt ist.

Deckt die vier Projektziele ab:
- a) Weiterentwicklung → im Monorepo
- b) erste Projekte → Skeleton + Composer (Schritt C/D)
- c) Packagist → Phase 2 (am Ende)
- d) Update-Automatismus → SemVer-Lockstep + `composer update` (Abschnitt „Versionierung")

---

## Getroffene Entscheidungen (fix)

| Frage | Entscheidung |
|-------|--------------|
| GitHub-Org / Repo-Namen | **`z77-ch`**, Repo-Namen bar: `kernel`, `module-frontend`, `module-backend`, `module-dms` (4 Pakete nach ADR-023). Deckt sich mit `support.source` in den `composer.json`. |
| Composer-/Packagist-Vendor | `z77/…` (unverändert, z. B. `z77/kernel`) |
| Monorepo-Repo | `z77-ch/z77-framework` (privat) |
| Sichtbarkeit | **privat** bis Phase 2 (Handbook fertig) |
| Update-Strategie | **SemVer im Lockstep**, Projekte updaten mit `composer update z77/*`. `monorepo-builder` verworfen — nach dem kernel-Merge kein Package-Zyklus mehr, Versions-Sync über gemeinsames Taggen im Monorepo. |
| Split-Auth | ein PAT als Secret `ACCESS_TOKEN` (Schreibrechte auf die Ziel-Repos) |

---

## Kritische Befunde aus der Analyse (weshalb dieser Plan vom alten abweicht)

1. **`gh` fehlt.** GitHub CLI ist nicht installiert → Schritt 0.
2. **A2 war falsch beschrieben.** Die Packages deklarieren aktuell *gar keine* internen
   Requires (nur `php >=8.2`). Es geht nicht um `*` → `^1.0`, sondern ums *Eintragen*
   der real existierenden Abhängigkeiten (Matrix unten).
3. **Zirkuläre Kopplung** core ↔ shared ↔ persistence — durch ADR-023 in **ein** Paket
   (`kernel`, drei Namespaces) zusammengeführt. Damit ist der Zyklus paket-intern
   gekapselt; auf Package-Ebene bleibt ein sauberer DAG (3 Module → `kernel`).
   `monorepo-builder` wurde deshalb **nicht** gebraucht (im ursprünglichen Plan noch als
   Pflicht vorgesehen).
4. **CSS-Assets müssen vor dem Tag gebaut werden** (`npm run build`), sonst fehlen sie
   in den Split-Repos.
5. **Namespace-Konflikt** aufgelöst: `z77-ch/<pkg>` (nicht `z77/z77-<pkg>`).
6. **Override-Mechanik** des Skeletons muss nach dem Umbau weiter funktionieren → Verifikation.

### Reale Abhängigkeitsmatrix (nach ADR-023, Grundlage für A2)

| Package | direkte interne Requires |
|---|---|
| kernel | — (die Foundation; core/shared/persistence sind interne Namespaces) |
| module-frontend | kernel |
| module-backend | kernel |
| module-dms | kernel |

Keine Modul-zu-Modul-Abhängigkeiten; jedes Modul hängt genau an `z77/kernel`
(alle `Z77\Module\*`-Referenzen sind Selbstbezüge).

---

## Schritt 0 — Werkzeuge & Zugang
Verzeichnis: `Z:\z77-ch-framework-1.0.0`

- [ ] `winget install GitHub.cli`  (danach neue Shell öffnen)
- [ ] `gh auth login`  → GitHub, HTTPS oder SSH, Scopes inkl. `repo`
- [ ] `gh auth status`  → eingeloggt
- [ ] `ssh -T git@github.com`  → „Hi <user>! …"  (SSH-Key für privaten Composer-Zugriff)
- [ ] Org `z77-ch` existiert / anlegen (GitHub → New organization)
- [ ] PAT (classic, scope `repo`) erzeugen, Wert bereithalten (für B3)
- [ ] Backup des Framework-Ordners anlegen (vor `git init`)

Bereits vorhanden: git 2.54, composer 2.9.7, php 8.4.20, sass (npm).

---

## Schritt A — Monorepo GitHub-fähig machen
Verzeichnis: `Z:\z77-ch-framework-1.0.0`

### A1 — `.gitignore`
```
/vendor/
/node_modules/
/skeleton/vendor/
/data/
/logs/
/temp/
*.zip
.env
```

### A2 — Interne Requires eintragen  ← kritischster Schritt
Pro `packages/*/composer.json` den `require`-Block gemäss Matrix ergänzen. Nach ADR-023
hat `kernel` **keine** internen Requires; jedes Modul verlangt genau `z77/kernel`:

`packages/module-backend/composer.json` (analog frontend/dms):
```json
"require": {
    "php": ">=8.2",
    "z77/kernel": "^1.0"
}
```
Für alle 3 Module. `minimum-stability: dev` / `prefer-stable: true` bleiben, damit
`^1.0` vor dem ersten stabilen Tag über den `branch-alias` (`dev-main` → `1.0.x-dev`)
auflösbar ist.

### A3 — CSS bauen (nicht vergessen)
```
npm install
npm run build
```
Erzeugt die kompilierten CSS in `packages/module-*/res/assets/css`. Diese Dateien werden
mitcommittet, sonst fehlen im Projekt die Styles.

### A4 — docs:check grün halten
```
npm run docs:check
```

### A5 — Git init + erster Push
```bash
git init
git add .
git commit -m "chore: initial monorepo import"
gh repo create z77-ch/z77-framework --private --source=. --remote=origin
git push -u origin main
```

---

## Schritt B — Auto-Split einrichten
Verzeichnis: `Z:\z77-ch-framework-1.0.0`

### B1 — 4 leere Ziel-Repos (read-only Konsum-Repos)
```bash
for r in kernel module-frontend module-backend module-dms; do
  gh repo create z77-ch/$r --private
done
```
> **Gotcha (belegt):** Jedes neue Split-Target muss existieren **und** im `ACCESS_TOKEN`
> Schreibrechte haben, bevor der Split läuft — sonst `403 Write access not granted`.

### B2 — Versions-Sync (Lockstep) — **`monorepo-builder` verworfen**
Ursprünglich als Pflicht vorgesehen (`symplify/monorepo-builder`), um die 6 Package-
Versionen wegen der Zirkularität identisch zu halten. Nach dem kernel-Merge (ADR-023)
gibt es keinen Package-Zyklus mehr; die 4 Pakete werden schlicht **gemeinsam im Monorepo
getaggt**, der Split propagiert denselben Tag in alle Ziel-Repos. Kein Extra-Tool nötig.

### B3 — Split-Workflow `.github/workflows/split.yml` (live)
Nutzt `symplify/monorepo-split-github-action@v2.4.5`, ein Job pro Paket. Trigger:
**Branch-Push** (splittet History) **und Tag-Push** (splittet + propagiert den Tag).
Repo-Mapping: `packages/<package>` → `z77-ch/<package>` (bare Namen, kein Präfix).
```bash
gh secret set ACCESS_TOKEN --body "<PAT>"   # PAT mit Schreibrechten auf die Ziel-Repos
```
```yaml
strategy:
  matrix:
    package: [kernel, module-frontend, module-backend, module-dms]
# -> pusht packages/${{ matrix.package }} nach z77-ch/${{ matrix.package }}
# repository_organization: z77-ch, token: ${{ secrets.ACCESS_TOKEN }}
```

### B4 — Ersten Release testen
```bash
git tag 1.0.0
git push origin 1.0.0
```
Prüfen: Werden alle 4 Ziel-Repos befüllt und tragen sie Tag `1.0.0` inkl. der
kompilierten CSS-Assets?

---

## Schritt C — Skeleton auf Composer-Bezug umstellen
Verzeichnis: `Z:\z77-ch-framework-1.0.0\skeleton`

### C1 — `composer.json`: path → vcs, Constraints `*` → `^1.0`
Für den Deployment-Bezug (statt der lokalen `path`-Repos im Dev) auf 4 `vcs`-Repos:
```json
"repositories": [
    {"type": "vcs", "url": "git@github.com:z77-ch/kernel.git"},
    {"type": "vcs", "url": "git@github.com:z77-ch/module-frontend.git"},
    {"type": "vcs", "url": "git@github.com:z77-ch/module-backend.git"},
    {"type": "vcs", "url": "git@github.com:z77-ch/module-dms.git"}
]
```
`require`: `z77/kernel` + die drei Module von `*` auf `^1.0`. `autoload`-Override-Block
(override/z77/…) **unverändert lassen** — das ist die projektspezifische Override-Mechanik.
Ab Phase 2 (Packagist) entfällt der `repositories`-Block ganz.

### C2 — Skeleton als GitHub-Template
```bash
gh repo create z77-ch/z77-skeleton --private --template
```
Neue Projekte: „Use this template" → klonen → `composer install`.

---

## Schritt D — Projekt aufsetzen (Verifikation am realen Projekt)
Verzeichnis: `Z:\z77-1.0.0-<project>.ch`  ← **eigene, zweite Claude-Session**

1. Projekt aus dem Skeleton-Template beziehen
2. `composer.json`: `name` → `z77/<project>`
3. `composer install`  → Framework landet in `vendor/`, nichts wird kopiert
4. `composer dev`  → `php -S localhost:8080 -t public`
5. Override testen: eine Klasse in `override/z77/core/src/…` überschreiben, prüfen dass sie greift

---

## Verifikation (Definition of Done)

- [ ] `git tag <v>` splittet in alle 4 Ziel-Repos, inkl. CSS-Assets
- [ ] Frisches Verzeichnis + `composer install` aus Skeleton zieht Framework nach `vendor/`
- [ ] das Projekt startet lokal ohne dass `packages/` daneben liegt
- [ ] Kein Package-Code physisch im Projektordner (ausser `vendor/`, ge-gitignored)
- [ ] Override-Mechanik funktioniert (Punkt D5)
- [ ] `composer why-not`/`composer install` meldet keine ungelösten internen Requires

---

## Versionierung & Update-Automatismus (Ziel d)

- **Lockstep-SemVer:** alle 4 Packages tragen immer dieselbe Version (gemeinsames Taggen
  im Monorepo; kein `monorepo-builder`).
- **Release = Tag im Monorepo** → Split propagiert den Tag in alle 4 Repos.
- **Breaking Change → Major-Bump** (2.0.0). Projekte pinnen `^1.0` und bleiben stabil,
  bis sie bewusst den Constraint anheben.
- **Projekt-Update:** `composer update z77/*` (zieht neueste kompatible Version nach vendor/).
- **Changelog** im Monorepo (`CHANGELOG.md`), pro Release gepflegt — Basis für die
  Update-Entscheidung im Projekt.
- Optional später: `.z77-version`-Marker im Projekt + kleiner `composer`-Script, der bei
  `post-update-cmd` Migrationsschritte des Frameworks ausführt (erst nach Projekt-Erfahrung entscheiden).

---

## Phase 2 — Packagist (erst wenn `docs/01-handbook/` fertig) — **einzig offener Teil**

Entspricht `public-release-bauplan.md` #11 (+ #13 Orphan-Squash als Finalschritt).

1. 4 Ziel-Repos + Skeleton auf public umstellen
2. Bei Packagist listen (MIT)
3. In allen Projekten den `repositories`-Block (C1) entfernen — nur noch `require`.
   Der Umstieg ist ein Einzeiler pro Projekt.

---

## Reihenfolge / Abhängigkeiten der Schritte

```
0 (Werkzeuge) → A (Monorepo) → B (Split) → C (Skeleton) → D (Projekt-Test)
                                    ↑ B4 muss grün sein, bevor C sinnvoll testbar ist
```
Nicht mischen: A–C in der Framework-Session, D in einer separaten Session für das Projekt.
