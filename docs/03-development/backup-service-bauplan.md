# Bauplan — BackupService (data / db / full) + Backend-Sektion «Service»

**Status:** `[DONE]` — released 1.1.0, 2026-07-16
**Date:** 2026-07-16

Ziel: Installation-weiter Backup-Dienst mit drei Typen — `data` (nur `data/`),
`db` (nur falls eine Datenbank konfiguriert ist), `full` (ganzes Projekt) —
bedienbar im Backend (neue Gruppe `service`, neue Topbar-Sektion «Service») und
per Cronjob (CLI). History pro Typ über Verzeichnis-Scan.

## Owner-Entscheide (2026-07-16)

| Frage | Entscheid |
|---|---|
| Ablageort | `{projekt}/backup/{data\|db\|full}/` — ausserhalb `public/`, Retention konfigurierbar (Default 10 pro Typ) |
| Format | ZIP (`ZipArchive`, neues Requirement `ext-zip` im kernel) |
| Cron | CLI-Script (erster CLI-Einstiegspunkt des Frameworks) |
| Berechtigung | nur `AuthRole::SUPER_USER` |
| Restore | bewusst NICHT in v1 (nur erstellen / auflisten / downloaden / löschen) |
| Bauplan-Ort | `docs/03-development/` (dieses Dokument) |

## Architektur

### Kernel (`Z77\Shared\Backup\` — installations-weit, HTTP-unabhängig)

| Baustein | Zweck |
|---|---|
| `BackupType` (enum) | `data` / `db` / `full` — Typ trägt Quellen-Definition + Verzeichnisname |
| `BackupService` | Orchestrierung: `run(BackupType, trigger)` → Archiv bauen, `meta.json` schreiben, Retention anwenden. Wirft `\RuntimeException` bei Fehlern (Installer-Muster) |
| `ZipArchiver` | rekursives Packen mit Exclude-Liste (`ZipArchive`) |
| `BackupHistory` | `scan(BackupType): BackupEntry[]` — liest `backup/{type}/*.zip` + `*.meta.json`, sortiert neuste zuerst. **Dateisystem = Wahrheit** (ADR-025-Philosophie), keine zentrale History-Datei |
| `DbDumperInterface` + `MysqlDumper` | DB-Dump als Adapter. v1: `mysqldump` via `exec` (cyon-tauglich). Kein DB-Config → Typ `db` ist «nicht konfiguriert» |

### Ablage & Meta

```text
backup/
├── data/2026-07-16_1430_data.zip
├── data/2026-07-16_1430_data.meta.json   ← trigger (manual|cron), duration, status, Dateizahl
├── db/…
└── full/…
```

- Verzeichnis liegt im Projekt-Root (nicht unter `htmlRoot`) → nie web-erreichbar.
- `meta.json` pro Backup (kein zentrales Log, nichts kann driften); Grösse/Zeitpunkt
  kommen aus dem Dateisystem selbst.

### Config — `config/backup.inc.php` (seed-once, wie auth/i18n)

```php
return [
    'dir'       => 'backup',            // relativ zum Projekt-Root
    'retention' => ['data' => 10, 'db' => 10, 'full' => 5],  // 0 = unbegrenzt
    'fullExcludes' => ['vendor', 'node_modules', 'backup', 'lib/cache'],
    'database'  => null,                // oder ['driver'=>'mysql','host'=>…,'name'=>…,'user'=>…,'pass'=>…]
];
```

- Seed-once: Entwickler passt Retention/DB an, Update überschreibt nie (INST-CONFIG-001-Klasse).
- `database = null` (Default — Framework ist file-basiert): Typ `db` erscheint im UI
  ausgegraut «keine Datenbank konfiguriert», CLI meldet es und beendet mit Exit 0.
- Installer: `writeBackupConfig()` (seed-once) ergänzen; `backup/` legt der Service lazy an.

### Backup-Typen

| Typ | Quelle | Bemerkung |
|---|---|---|
| `data` | `data/` komplett | inkl. `loginUsers.json` → Ablage nie public, Download nur SUPER_USER |
| `db` | `mysqldump` → `.sql` im ZIP | nur wenn `database` konfiguriert; Fehler wenn `exec`/`mysqldump` fehlt (klare Meldung) |
| `full` | Projekt-Root minus `fullExcludes` | `vendor/`/`node_modules/` sind via `composer.lock` regenerierbar; `backup/` selbst zwingend excluded (Rekursion) |

### CLI — erster CLI-Einstiegspunkt (→ eigener Mini-ADR)

- `packages/kernel/bin/z77-backup` (PHP-Script, shebang) + kernel `composer.json`
  `"bin": ["bin/z77-backup"]` → Projekte rufen `php vendor/bin/z77-backup {data|db|full}` auf.
- Eigener **CLI-Bootstrap**: lädt nur Autoload + Config (kein Router/Request/Session);
  Arbeitsverzeichnis = Projekt-Root (via `getcwd()` oder `--project=` Option).
- Kein HTTP-Auth-Kontext: Berechtigung = Filesystem/Cron-User (dokumentieren; die
  Rolle `AuthRole::CRON_JOB` bleibt HTTP-seitig ungenutzt — wir gehen NICHT über eine URL).
- Ausgabe cron-freundlich (eine Zeile pro Lauf), Exit 0 = ok / 1 = Fehler.
- cyon: Cronjob-Eintrag `php /home/…/vendor/bin/z77-backup data` — in Doku aufnehmen.
- **ADR-028** (kurz): CLI-Entry via Composer-bin, ein Binary pro Aufgabe (kein
  generisches `console`-Framework — YAGNI), Bootstrap-Schnitt.

### Backend-UI — neue Gruppe `service`, Sektion «Service»

- **Navigation-Seed** (`navigation.default.json`): Level-0-Eintrag «Service»
  (Slot `backend-main`, nach Drive) + Kind «Backup» → `/backend/service/backup/list`.
  Nur Neu-Installationen erhalten den Seed automatisch (data ist seed-once) —
  **bestehende Projekte** legen die zwei Navigation-Einträge übers Backend an
  (Release-Notes + Doku-Hinweis).
- **`BackupController`** (`Ui/Controllers/Service/`, extends `BackendAbstractController`):

| Action | Art | Zweck |
|---|---|---|
| `listAction` | HTML | History-Ansicht: drei Abschnitte (data / db / full) als `be-list`; pro Abschnitt «Jetzt sichern»-Button; Typ db ggf. ausgegraut |
| `runAction` | POST/Fetch | startet Backup eines Typs; synchron (Full kann dauern → `set_time_limit(0)`), Erfolg = Flash + Liste refresh |
| `downloadAction` | GET | liefert ZIP via `$this->file()` (`FileResponse`, delivery=php — cyon hat kein X-Sendfile) |
| `confirmDeleteAction` / `removeAction` | Fetch | Backup-Datei (+ meta.json) löschen, Modal-Muster wie überall |
| `actionsAction` | Fetch | ⋮-Hub pro Zeile (LIST-ACTIONS-HUB-001) |

- `backendConfig.inc.php`: Gruppe `service` in `groupDefaults` (`'service' => 'backup'`),
  Controller-Block komplett `AuthRole::SUPER_USER` (controllerRole + alle Actions).
- Templates nach bestehendem Muster (`res/view/templates/Service/BackupController/…`),
  Styling mit vorhandenen `be-list`/`be-btn`-Komponenten — **kein neues CSS-File**,
  falls doch nötig: über `css-backend.md`-Konventionen.
- Kein neues JS — Fetch-Aktionen laufen über das bestehende `core.js`-Envelope-Muster.

## Sicherheit

- Backups enthalten Credentials-Hashes (`loginUsers.json`) und ggf. DB-Dumps →
  Ablage ausserhalb `htmlRoot`, Zugriff (UI + Download) ausschliesslich `SUPER_USER`.
- `meta.json` enthält keine Secrets (nur Zeit/Dauer/Trigger/Status/Zählwerte).
- CLI dokumentiert als «wer Shell-Zugriff hat, darf sichern» — kein Token-Umweg.
- `security.md` bekommt einen Abschnitt (Ablage, Rollen-Gate, Download-Weg).

## Doku-Pflichten (im gleichen Zug)

1. Neues Topic **`docs/topics/backup.md`** (STANDARD-konform; entry/file map/mental
   model/rules/known issues/pending) — single source of truth des Bereichs.
2. `backend.md`: Gruppen-Tabelle (`service`), Controller-Tabelle (`BackupController`).
3. `installer.md`: `writeBackupConfig()` in der execute()-Tabelle.
4. `security.md`: Backup-Abschnitt.
5. **ADR-028** CLI-Einstiegspunkt.
6. `npm run docs:check` grün.

## Umsetzungs-Phasen

- [x] **P1 Kernel:** `BackupType`, `ZipArchiver`, `BackupHistory`, `BackupService`,
      `DbDumperInterface`/`MysqlDumper`, `backup.default.inc.php` + Installer-Seed,
      kernel `ext-zip` Requirement
- [x] **P2 CLI:** `bin/z77-backup` + composer-bin + ADR-028
- [x] **P3 Backend-UI:** Gruppe `service` (Config), `BackupController` + Templates,
      Navigation-Seed «Service»/«Backup»
- [x] **P4 Doku:** topic `backup.md`, backend.md, installer.md, security.md, ADR-028; docs:check grün
- [x] **P5 Verifikation:** frisches create-project → Service-Sektion sichtbar (superUser),
      alle 3 Typen manuell (db = «nicht konfiguriert»-Pfad), CLI-Lauf, Retention greift,
      Download + Delete; danach Release **1.1.0** (neues Feature → minor)

## Bewusst NICHT in v1

- Restore (manuell entpacken; eigene, heikle Übung — später eigener Bauplan)
- Job-Queue/async (synchroner POST reicht; Full-Backups typischer Projekte sind klein)
- Backup-Verschlüsselung, Remote-Targets (S3/FTP) — erst bei Bedarf
- Generisches CLI-`console`-Framework — ein Binary pro Aufgabe (ADR-028)
