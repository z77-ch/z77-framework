# Checkliste — Voraussetzungen vor der Umsetzung

Vor Schritt A durchgehen. Jede Zeile hat einen Prüfbefehl und das erwartete Ergebnis.
Verzeichnis: `Z:\z77-ch-framework-1.0.0`

---

## Werkzeuge installiert

| Prüfen | Erwartet | OK |
|--------|----------|----|
| `git --version`      | git vorhanden          | [ ] |
| `composer --version` | Composer 2.x           | [ ] |
| `php --version`      | PHP >= 8.2             | [ ] |
| `gh --version`       | GitHub CLI vorhanden   | [ ] |
| `sass --version`     | für SCSS-Build (schon in devDependencies) | [ ] |

Fehlt etwas:
- Composer: https://getcomposer.org/download/
- gh CLI:   `winget install GitHub.cli`

---

## GitHub-Zugang

| Prüfen | Erwartet | OK |
|--------|----------|----|
| `gh auth status`                    | eingeloggt, Scopes inkl. `repo` | [ ] |
| `ssh -T git@github.com`             | "Hi <user>! You've successfully authenticated" | [ ] |
| Namespace/Org existiert (`z77`)     | Repo-Anlage möglich | [ ] |

SSH-Key fehlt:
```bash
ssh-keygen -t ed25519 -C "peter.ruepp@z77.ch"
gh ssh-key add ~/.ssh/id_ed25519.pub
```

Personal Access Token (für den Split-Workflow, Schritt B3):
- GitHub -> Settings -> Developer settings -> Tokens (classic) -> scope `repo`
- Wert bereithalten, wird morgen als Secret `ACCESS_TOKEN` gesetzt
- [ ] PAT erzeugt und notiert

---

## Entscheidungen bereithalten (morgen bestätigen)

| Frage | Vorschlag | Deine Wahl |
|-------|-----------|-----------|
| GitHub-Namespace | `z77` | ______ |
| Repos privat oder public | privat (bis Phase 2 / Docs fertig) | ______ |
| Split-Auth | PAT als Secret | ______ |

---

## Kein Datenverlust

| Prüfen | Erwartet | OK |
|--------|----------|----|
| Backup/Kopie des Framework-Ordners vorhanden | ja (vor `git init`) | [ ] |
| `packages/` Inhalt vollständig | 6 Ordner mit je `composer.json` | [ ] |

---

Wenn alle Haken gesetzt sind -> Start mit Schritt A in `PLAN-monorepo-split.md`.
