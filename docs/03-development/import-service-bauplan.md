# Bauplan — ImportService (Daten-Übernahme nach ADR-032)

**Status:** `[DONE]` — Phasen 1–6 gebaut und verifiziert (2026-08-08); offen: Klick-Durchlauf
im Browser, Upload-Formular, v2 (Mapping + SQL-Reader) — Pendenzen in
[`../topics/import.md`](../topics/import.md)
**Date:** 2026-08-08
**ADR:** [ADR-032](../02-decisions/adr-032-data-import-identity-and-content-hash.md) — bindende Entscheide (1–15)
**Review:** [review-import-adr-032.md](review-import-adr-032.md) — IMP-R001…R021, alle 8 Owner-Entscheide

Ziel: ein Dienst, der Datensätze in eine laufende Installation **übernimmt** —
Framework-Defaults, die nach dem Seeding dazukamen (der «Jobs»-Fall), Daten aus
einem anderen z77-Projekt, später wdv-6.2.2-Bestände (Fakturierung) aus einem
SQL-Dump. Kern: deklarierte Identität × Inhalts-Hash → Plan mit fünf Ergebnissen
→ der Entwickler entscheidet, der Dienst schreibt durch die Validatoren.

> **Laufende Dokumentation:** [`../topics/import.md`](../topics/import.md). Dieses Dokument
> ist der Bauplan und bleibt als Entstehungsgeschichte stehen.
>
> **Bau-Protokoll (2026-08-08):** alle 6 Phasen an einem Tag, je einzeln committet und
> verifiziert. Phase 1: 11-Check-Smoke (Hydration, Eindeutigkeit, UTF-8). Phase 2: Skeleton
> seedet `folders.json` aus module-dms, Re-Install 0 Writes. Phase 3: 16-Check-Smoke gegen
> Skeleton- und zihlundsee-Daten — drei Runden, `login-user`-Rename als `unclear`+Vorschlag
> gefangen, Id-Kollisionen (zihlundsee 25/26/28) nie gepaart, Bijektivität hält. Phase 4:
> E2E-Apply im Skeleton — 16 applied/0 failed, «E-Mail» mit Parent 25 + sortKey 3, Folgeplan
> 31× skipped (volle Konvergenz), stale Fingerprints werfen. Phase 5: Wiring-Smoke (Registry,
> Job, Vendor-Discovery über FileFinder) + Dev-Server 302. Zwei Planner-Korrekturen aus den
> Smokes: blocked nur für Einfügungen (gematchte Einträge brauchen keine Ref-Auflösung) und
> Ref-Diff bei ungeklärtem Gegenstück = «unbekannt», nicht «verschieden». Bewusste
> v1-Scope-Kürzung: kein Upload-Formular (core.js postet JSON, kein Multipart) — die Inbox
> deckt den Fall; als Pendenz im Topic.

## Owner-Entscheide (2026-08-08, aus dem Review)

| Frage | Entscheid |
|---|---|
| Key-Übernahme bei manueller Zuordnung | Ja — Apply schreibt den Quell-`key` auf den zugeordneten Ziel-Datensatz |
| Near-Match-Heuristik | Eng: aufgelöster Parent + Name + Slot, nur unbeanspruchte Ziele |
| i18n-Kataloge | Nicht dieser Dienst — eigenes Feature im `TranslationCatalog` (TRANS-SEED-001) |
| DMS `folders.json` | Komplett ausser Scope (Blobs werden nie geseedet, Grundstruktur entsteht im DMS) |
| Quellen-Ablage | Staging-Inbox mit Snapshots (Hash + Index + Lock), backup-ausgeschlossen |
| Massendaten | Plan persistiert, Apply als ADR-031-Job möglich, Plan-UI gruppiert nach Ergebnis |
| Mappings (Fremdschemata) | Projekt-Code unter `override/` — Framework liefert nur Contract + Helfer |
| SQL-Dump-Reader | v2, bei der ersten echten Migration; v1 legt die Nähte |

## Der Kernmechanismus

Zwei Dimensionen pro Datensatz, fünf Ergebnisse:

```text
Identität (deklariert, geordnete Fallbacks, bijektiv)   → WELCHER Datensatz ist das?
Inhalts-Hash (normalisiert, Refs als Ziel-Identität)    → ist er noch IDENTISCH?

match + hash gleich        → skipped
match + hash verschieden   → changed   (Feld-Diff, Übernahme opt-in, Default Nein)
kein match, kein Near-Match→ new       (als Gruppe übernehmbar)
keine Identität bestimmbar → unclear   (Vorschlag + manuelle Zuordnung)
Ref-Ziel nicht aufgelöst   → blocked   (zeigt den blockierenden Datensatz)
```

Die numerische `id` ist nie Identität — sie ist Verweisziel innerhalb der
Quelldatei und wird beim Apply über die Id-Map umgeschrieben (zihlundsee-Beweis:
lokale id 28 = «Gut zu wissen», Default id 28 = «Jobs»).

## Neue Bausteine

Namespace `Z77\Shared\Import` (Fundament-Muster wie `Z77\Shared\Tree`), Attribute
neben den bestehenden in `Z77\Shared\Attributes`:

| Baustein | Zweck |
|---|---|
| `Attributes\ImportIdentity` | geordnete Identitätsregeln an der Entity-Klasse; Regeln dürfen `ImportRef`-Felder nennen (aufgelöste Ziel-Identität) |
| `Attributes\ImportRef` | Feld-Attribut: FK auf Ziel-Entity-Klasse; `resolveBy: map \| identity` (Id-Map = Default, Natural Key für Mehrphasen-Migration) |
| `Import\ImportDescriptor` | liest die Attribute einer Entity-Klasse: Identitätsregeln, Ref-Felder, Hash-Feldmenge (alles ausser `id`, `sort_key`, Ref-Rohwerte) |
| `Import\ImportSource` (Interface) | die Reader-Naht: liefert normalisierte Datensatz-Arrays je Ziel-Entity-Typ — der Kern sieht nie ein Rohformat |
| `Import\Source\JsonEntitySource` | v1-Reader: natives Entity-JSON (`*.default.json`, Export aus anderem z77-Projekt, Upload) |
| `Import\ImportStaging` | Snapshot-Ablage: `inbox/` sichten, Snapshot mit Hash + `index.json` + Lock anlegen/löschen (PropBase-Muster) |
| `Import\ImportHasher` | normalisierter Hash: `mapFromArray → mapToArray → id/sort_key/Refs raus → Refs als Ziel-Identität rein → Keys rekursiv sortieren → hash` |
| `Import\ImportMatcher` | Identitäts-Klassifikation (bijektiv, IMP-R001) + Near-Match-Pass über die `new`-Menge (IMP-R003) |
| `Import\ImportPlanner` | berechnet den `ImportPlan` in Abhängigkeitsreihenfolge (toposortiert über die `ImportRef`-Zielklassen — keine manuelle Liste) |
| `Import\ImportPlan` / `ImportPlanEntry` | persistierter Plan: Einträge mit Ergebnis, Diff, Zuordnung, Entscheidung; Datei-Fingerprint für den Staleness-Guard (IMP-R011) |
| `Import\ImportApplier` | topologisches Apply: Id-Map, Ref-Patching, Ziel-seitige `id`/`sortKey`-Vergabe, Key-Übernahme, Schreiben durch die bestehenden Validatoren |
| `Import\ImportService` | Fassade: Quelle → Plan → (Neuberechnung nach Entscheidung) → Apply |
| `ImportApplyJob` | ADR-031-Job für grosse Pläne; kleine Pläne laufen im Request |
| v2: `Import\ImportMapping` (Interface) + `Import\Source\SqlDumpSource` | Fremdschema-Mapping (Projekt-Code) + mysqldump-INSERT-Reader mit deklariertem Quell-Encoding |

Registrierung module-agnostisch über die Modul-Config (Muster `jobs`/`reservedRoutes`):

```php
'importEntities' => [Navigation::class, NavigationAlias::class, MetaData::class],
```

`ModuleManager::getImportEntities()` aggregiert; die Verarbeitungsreihenfolge wird
aus den `ImportRef`-Deklarationen abgeleitet (NavigationAlias → Navigation,
MetaData → Navigation ⇒ Navigation zuerst), nicht von Hand gepflegt.

## Entity-Deklarationen (v1)

```php
#[ImportIdentity(['key'], ['module', 'group', 'controller', 'action'], ['parentId', 'ref'])]
class Navigation
{
    #[ImportRef(Navigation::class)] private ?int $parentId = null;
    #[ImportRef(Navigation::class)] private ?int $ref      = null;
}

#[ImportIdentity(['path'])]
class NavigationAlias
{
    #[ImportRef(Navigation::class)] private ?int $navigationId = null;
}

#[ImportIdentity(['navigationId', 'language'])]
class MetaData
{
    #[ImportRef(Navigation::class)] private ?int $navigationId = null;
}
```

## `Navigation::key` (NAV-KEY-001, Phase 1)

Gleiche Klasse wie `Folder::key` (ADR-020): server-controlled, Code-Konstante,
`null` für alles im Backend Erstellte, im Edit-Formular nur lesbar.

- Entity: `?string $key`, kein `#[Clean]`-Request-Pfad (S2-Muster), `mapFrom/ToArray` tragen `key` (fehlender Key → `null`, vorwärts-/rückwärtskompatibel).
- `NavigationValidator::validateKey()`: eindeutig unter allen Einträgen mit Key; nie aus dem Request übernommen (Edit-POST erzwingt den Bestandswert server-seitig, wie `parentId`).
- `navigation.default.json`: Keys für die framework-eigenen Backend-Einträge und Container — `webseiten`(1), `stammdaten`(2), `drive`(23), `service`(25), `login`(8), `logout`(9) sowie die Backend-Kinder (`navigation`, `benutzer`, `inhalte`, `metadaten`, `nav-alias`, `uebersetzungen`, `dokumente`, `backup`, `email`, `jobs`). Die Frontend-Starterseiten (Home/About/…) bekommen **keinen** Key — Starter-Content gehört dem Kunden, das 4-Tupel bleibt deren Fallback-Identität.

## Staging + Plan-Ablage

```text
data/framework/import/
  inbox/            ← Entwickler legt Dateien ab (FTP/Explorer); Screen listet, nimmt nie Pfade an
  snapshots/        ← übernommene Quellen: {ts}_{hash}.json + index.json (Hash, Herkunft, Status)
  plans/            ← persistierte Pläne inkl. Entscheidungen + Datei-Fingerprints
  import.lock       ← ein Import-Vorgang zur Zeit (kurze Sperre via FileStorage::withExclusiveLock)
```

- `data/framework/import` wandert in die `DATA_EXCLUDES` des Backups (BACKUP-JOBS-001-Muster) — transienter Zustand darf keinen Restore in eine andere Umgebung reiten.
- Ablage unter `data/framework/` (nicht `data/import/`) — Konsistenz mit `framework/jobs`; Framework-Zustand liegt unter `framework/`.
- Uploads werden beim Eintreffen gestagt; Snapshots werden bei Apply/Verwurf gelöscht.

## Ablauf

```text
Quelle wählen (Vendor-Defaults | Upload | Inbox)
  → Snapshot anlegen (Hash, index.json)
  → Plan berechnen:  je Entity-Typ in Abhängigkeitsreihenfolge
       hydrieren (mapFromArray + #[Clean])          → strukturell kaputte Sätze: Plan-Eintrag mit Fehler
       Identität klassifizieren (bijektiv)          → match | kein match | unbestimmbar
       Near-Match-Pass über die new-Menge           → Downgrade auf unclear mit Vorschlag
       Hash vergleichen (normalisiert)              → skipped | changed
       Ref-Ziele prüfen                             → blocked, wenn Ziel unclear/abgelehnt
  → Preview: Gruppen (new bulk | changed einzeln, Default Nein | unclear zuordnen | blocked)
       jede Entscheidung → Plan-Neuberechnung (Zuordnung kann Nachgelagertes entblocken)
  → Apply (Request bei kleinen Plänen, ADR-031-Job bei grossen):
       Fingerprint prüfen → bei Abweichung neu rechnen statt schreiben
       topologisch einfügen: id ziel-seitig, sortKey = TreeService::nextSortKey der Ziel-Gruppe
       Refs über Id-Map patchen (resolveBy: map) bzw. über Ziel-Identität (resolveBy: identity)
       Key-Übernahme bei manuell zugeordneten Einträgen
       jeder Satz durch den bestehenden Validator; Ablehnung = Plan-Eintrag, kein Abbruch
  → Snapshot + Plan aufräumen
```

## Backend-UI

`ImportController`, Gruppe `service` (`/backend/service/import/*`), SUPER_USER —
analog `BackupController`/`JobController`. Screens: Quellenliste (Vendor-Defaults
je Modul, Inbox-Inhalt, Upload), Plan-Ansicht (Gruppen, Diff-Anzeige für
`changed`, Zuordnungs-Picker für `unclear`, Blocker-Anzeige), Apply mit
Ergebnis-Protokoll. Fetch-POST + Entity-CSRF wie überall.

Der eigene Navigationseintrag («Import» unter «Service», Key `import`) ist selbst
seed-once — bestehende Projekte legen ihn einmal von Hand an; **danach holt der
Import künftige Einträge selbst nach.** Das Feature beendet seine eigene
Fussnoten-Klasse.

## Bauphasen

| Phase | Inhalt | Verifikation |
|---|---|---|
| 1 | **NAV-KEY-001**: Entity + Validator + Default-Keys + Formular read-only; Daten-Migration nicht nötig (fehlender Key → `null`) | `php -l`, Validator-Smoke (Duplikat-Key abgelehnt), Backend-Edit unverändert speicherbar |
| 2 | **INST-SEED-001**: `writeDataFiles()` läuft über alle installierten Framework-Pakete (Walk aus `buildPaths()` wiederverwendet); Discovery-Helfer für Phase 5 extrahiert | Skeleton-Neuinstallation seedet `folders.json` aus module-dms; Re-Install überschreibt nichts |
| 3 | **Import-Kern** ohne UI: Attribute, Descriptor, Hasher, Matcher (bijektiv + Near-Match), Planner (topo), `JsonEntitySource`; Id-Map; CLI-Smoke-Skript gegen Skeleton-Daten | Default vs. Skeleton-Runtime: «E-Mail» wird `new`, Rest `skipped`; zihlundsee-Kopie: id-Kollisionen korrekt getrennt, Container `unclear` |
| 4 | **Staging + Plan + Apply**: `ImportStaging`, persistierte Pläne, Fingerprint, `ImportApplier` (Ref-Patching, Key-Übernahme, Validator-Schreibweg), `ImportApplyJob` registriert; Backup-`DATA_EXCLUDES` + `fullExcludes` ergänzt | Apply legt «E-Mail» mit korrektem Parent/sortKey an; erneuter Plan: alles `skipped`; Backup-Archiv enthält `framework/import` nicht |
| 5 | **Backend-Screen**: `ImportController` + Templates + Fetch-Kommandos, Quellen (Defaults/Inbox/Upload), Gruppen-UI, Diff, Zuordnung inkl. Key-Übernahme, Navigationseintrag | Klick-Durchlauf im Skeleton: Service-Sektion nachziehen, `changed` ablehnen/übernehmen, `unclear` zuordnen |
| 6 | **Doku**: `docs/topics/import.md` (erst jetzt — Linter prüft `SOURCE=`-Pfade), Pendenzen in navigation/installer/jobs/backup auflösen bzw. umhängen, `npm run docs:check` grün | docs:check |
| v2 | `ImportMapping`-Contract + `SqlDumpSource` (mysqldump-INSERTs, Quell-Encoding latin1→UTF-8), Natural-Key-Auflösung im Feldeinsatz — bei der ersten echten wdv-Migration | gegen echten Dump + Ziel-Entities (Order-Modul) |

## Offene Risiken

- **Near-Match-Präzision:** die enge Heuristik (Parent + Name + Slot) kann Umbenennungen auf BEIDEN Seiten nicht fangen (Framework benennt um UND Kunde hat umbenannt) — solche Fälle erscheinen als `new`; das Restrisiko trägt die Durchsicht der `new`-Gruppe. Bewusst akzeptiert, Verbreiterung später billig.
- **Plan-Grösse im Speicher:** v1-Ziele sind klein (≤ Hunderte Sätze). Für wdv-Mengen (Zehntausende) muss der Planner streamen oder chunken — Naht dafür ist der `ImportSource`-Iterator; vor v2 prüfen, nicht vorbauen.
- **`key`-Kollision mit Projekt-Keys:** Projekte dürfen eigene Keys vergeben (gleicher Namensraum). Kollisionsrisiko klein, aber real — Konvention dokumentieren (Framework-Keys = einfache Wörter, Projekt-Keys mit Präfix empfohlen), Validator meldet das Duplikat ohnehin.
- **Gleichzeitige Backend-Arbeit:** der Fingerprint fängt Änderungen zwischen Plan und Apply; innerhalb des Apply schützt die kurze Dateisperre. Ein zweiter paralleler Import-Vorgang wird über `import.lock` abgewiesen.
- **Upload-Limits des Hostings:** grosse Dumps kommen per FTP in die Inbox, nicht als Upload — der Screen muss das sichtbar sagen, sonst rennt jemand ins `upload_max_filesize`.
