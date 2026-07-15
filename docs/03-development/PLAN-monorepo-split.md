# Umsetzungsplan — Monorepo + Auto-Split (Composer-Distribution)

Ziel: Framework nicht mehr pro Projekt kopieren. Projekte ziehen es via Composer
aus zentralen GitHub-Repos. Entwickelt wird nur im Monorepo; ein Git-Tag splittet
automatisch in 6 read-only Package-Repos.

Phase 1 (dieser Plan): privat, ohne Veröffentlichung. Respektiert die Framework-
Philosophie (Packagist erst wenn `docs/01-handbook/` fertig ist).
Phase 2 (später): Packagist — dann entfällt der `repositories`-Block in Projekten.

---

## Verzeichnis-Übersicht (wo stehe ich wann)

| Schritt | Verzeichnis |
|---------|-------------|
| A–C (Aufbau) | `Z:\z77-ch-framework-1.0.0`  ← hier startest du morgen |
| D (Projekt)  | `Z:\z77-1.0.0-<project>.ch` ← eigene, zweite Session |

Eine Claude-Code-Session pro Ordner (nicht mischen).

---

## Voraussetzungen (einmalig prüfen)

- [ ] `git --version`, `composer --version`, `gh --version` vorhanden
- [ ] Bei GitHub eingeloggt: `gh auth status`
- [ ] SSH-Key bei GitHub hinterlegt (für privaten Composer-Zugriff der Projekte)
- [ ] GitHub-Organisation/Namespace festlegen (Annahme unten: `z77`)

---

## Schritt A — Monorepo GitHub-fähig machen
Verzeichnis: `Z:\z77-ch-framework-1.0.0`

### A1 — .gitignore anlegen
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

### A2 — Interne Package-Requires auf Versionen umstellen  ← kritischster Schritt
In jeder `packages/*/composer.json` müssen Abhängigkeiten zwischen den Modulen
als Versions-Constraint stehen, NICHT als `*` oder path. Sonst bricht später
`composer install` im Projekt.

Beispiel `packages/module-frontend/composer.json`:
```json
"require": {
    "php": ">=8.2",
    "z77/core": "^1.0",
    "z77/shared": "^1.0"
}
```
Prüfen für: core, shared, persistence, module-frontend, module-backend, module-dms.
(Wird morgen zusammen durchgegangen — Ist-Zustand pro Datei checken.)

### A3 — Git init + erster Push
```bash
git init
git add .
git commit -m "chore: initial monorepo import"
gh repo create z77/z77-framework --private --source=. --remote=origin
git push -u origin main
```

---

## Schritt B — Auto-Split einrichten
Verzeichnis: `Z:\z77-ch-framework-1.0.0`

### B1 — 6 leere Ziel-Repos anlegen (read-only Konsum-Repos)
```bash
for r in core shared persistence module-frontend module-backend module-dms; do
  gh repo create z77/z77-$r --private
done
```

### B2 — Versions-Sync-Tool
```bash
composer require --dev symplify/monorepo-builder
```
`monorepo-builder.php` im Root anlegen (packages-Pfad + Ziel-Version-Sync).
Zweck: hält die 6 Package-Versionen synchron und propagiert Tags.

### B3 — Split-Workflow `.github/workflows/split.yml`
Nutzt `symplify/monorepo-split-github-action`. Ein Job pro Package, getriggert
bei Push von Tags. Braucht ein Secret mit Push-Rechten auf die Ziel-Repos:
```bash
# Personal Access Token (classic, scope: repo) erzeugen, dann:
gh secret set ACCESS_TOKEN --body "<PAT>"
```
Matrix-Skizze (Details morgen):
```yaml
on:
  push:
    tags: ['*']
jobs:
  split:
    strategy:
      matrix:
        package: [core, shared, persistence, module-frontend, module-backend, module-dms]
    # -> pusht packages/${{ matrix.package }} nach z77/z77-${{ matrix.package }}
```

### B4 — Ersten Release testen
```bash
git tag 1.0.0
git push origin 1.0.0
```
Prüfen: Werden die 6 Ziel-Repos befüllt und tragen sie den Tag `1.0.0`?

---

## Schritt C — Skeleton auf Composer-Bezug umstellen
Verzeichnis: `Z:\z77-ch-framework-1.0.0\skeleton`

### C1 — composer.json: path -> vcs
Alt (path, nicht portabel):
```json
"repositories": [
    {"type": "path", "url": "../packages/core"}, ...
]
```
Neu (Phase 1, privat via SSH):
```json
"repositories": [
    {"type": "vcs", "url": "git@github.com:z77/z77-core.git"},
    {"type": "vcs", "url": "git@github.com:z77/z77-shared.git"},
    {"type": "vcs", "url": "git@github.com:z77/z77-persistence.git"},
    {"type": "vcs", "url": "git@github.com:z77/z77-module-frontend.git"},
    {"type": "vcs", "url": "git@github.com:z77/z77-module-backend.git"},
    {"type": "vcs", "url": "git@github.com:z77/z77-module-dms.git"}
]
```
Und `require`-Constraints `*` -> `^1.0`.

### C2 — Skeleton als GitHub-Template
```bash
gh repo create z77/z77-skeleton --private --template
```
Neue Projekte künftig: "Use this template" -> klonen -> `composer install`.

---

## Schritt D — Projekt aufsetzen
Verzeichnis: `Z:\z77-1.0.0-<project>.ch`  (zweite Session!)

1. Projekt aus dem Skeleton beziehen (Template klonen oder Skeleton-Inhalt übernehmen)
2. `composer.json`: `name` auf `z77/<project>` setzen
3. `composer install`
   -> Framework landet in `vendor/`. Nichts wird kopiert.
4. Lokaler Test: `composer dev` (startet `php -S localhost:8080 -t public`)

---

## Verifikation (Definition of Done für morgen)

- [ ] `git tag 1.0.0` splittet in alle 6 Ziel-Repos
- [ ] Frisches Verzeichnis + `composer install` aus Skeleton zieht Framework nach `vendor/`
- [ ] das Projekt startet lokal ohne dass `packages/` daneben liegt
- [ ] Kein Package-Code physisch im Projektordner (ausser `vendor/`, ge-gitignored)

---

## Phase 2 — später (nicht morgen)

Sobald `docs/01-handbook/` fertig: die 6 Ziel-Repos bei Packagist listen (MIT).
Dann in allen Projekten den `repositories`-Block (C1) entfernen — nur noch
`require`. Der Umstieg ist ein Einzeiler pro Projekt.

---

## Offene Punkte / morgen entscheiden

- GitHub-Namespace bestätigen (`z77` angenommen)
- Private vs. public Repos jetzt (Empfehlung: privat bis Phase 2)
- PAT vs. Deploy-Keys für den Split (Empfehlung: ein PAT als Secret, einfacher)
- Ist-Zustand der internen Requires (A2) — pro Datei prüfen
