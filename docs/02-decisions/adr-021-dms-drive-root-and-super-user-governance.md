# ADR-021 — DMS drive root and SUPER_USER governance

**Status:** `[APPROVED]`
**Date:** 2026-07-03

> **Revision 2026-07-03 (gleicher Tag, mit User entschieden):** der Partition-`key` ist neu auch
> **im UI setzbar** — ausschliesslich im kombinierten Edit-Modal des Drive, via
> `FolderService::setKey()`: nur SUPER_USER, nur auf menschlich verwalteten Partitionen (eine
> `system`-Partition trägt die Modul-Identität und bleibt code-only), eindeutig erzwungen,
> Slug-Charset, `drive` reserviert. Die S2-Schranke bleibt unangetastet: die `?key=`-Auflösung
> eines REQUESTS ist weiterhin find-only und erzeugt nie eine Partition. Damit ist die frühere
> Regel «kein UI-Setter für den Key» (ADR-020-Ära) revidiert — das Risiko lag im Request-Input
> und in beliebigen Admins, nicht im bewussten Akt des Governors.

---

## Context

[ADR-020](adr-020-dms-scope-by-root-folder-not-area-label.md) machte die obersten Ordner
(`parent_id = null`) zu den Partitionen — echte, besitz-/ACL-fähige Entities, kein Knoten darüber.
Zwei Punkte blieben dabei offen bzw. inkonsistent (Review 2026-07-03,
[`../03-development/dms-drive-root-bauplan.md`](../03-development/dms-drive-root-bauplan.md)):

- **Kein Anker über den Partitionen.** Die „Baumspitze" ist virtuell: kein Ort für einen globalen
  Grant, ein Sonderfall-Gate (`requireAdmin` bei `parentId === null`) statt einer normalen
  Ressourcen-Prüfung, und `folders.json` ohne kanonischen Ausgangszustand (kein Default-Seed).
- **„Super-User" stand in Doku/ADR, der Code prüfte ADMIN.** `Principal::isAdmin()` liess jede
  Rolle ab Level 80 durch — der ACL-Bypass und die Partition-Gates galten faktisch für `admin`,
  obwohl ADR-020 Rule 3 „nur Super-User" sagte. Der Installer legte `roles: ['admin']` an.

## Decision

**Ein einziger, obligatorischer Drive-Root über den Partitionen + echte SUPER_USER-Governance.**

### Rule 1 — genau EIN Drive-Root

Die Hierarchie hat genau einen obersten Ordner („Drive"): `parentId = null`, `ownerId = null`
(= z77 core / System), `key = 'drive'` (Code-Konstante), `system = true` (rename/move/delete-
gesperrt), `active = true` (unveränderbar), `deliveryMode = null` (unveränderbar — nie `sealed`,
sonst könnte keine Partition je `public` sein; der Vererbungs-Fallback bleibt `protected`).
Die **Partitionen sind seine direkten Kinder** — sonst ändert sich an ADR-020 nichts (echte
Entities, ACL, `key` auf Modul-Partitionen, Slug-URLs).

Existenz ist doppelt gesichert: ein `folders.default.json`-Seed in `module-dms` (Installer,
write-once) UND lazy get-or-create im Code (`driveRoot()`), das zusätzlich verwaiste
Top-Level-Ordner als Kinder adoptiert (Single-Root-Invariante, selbstheilend).

### Rule 2 — der Drive-Root ist unsichtbar für URLs, sichtbar für ACL

Der Root-Slug erscheint **nicht** in `/media`-URLs oder Materialisierungs-Pfaden — erstes Segment
bleibt der Partition-Slug (D1). Dokumente direkt im Drive-Root sind **verboten** (der Root
organisiert Partitionen, er speichert nicht). Für die ACL ist der Root ein normaler Anker: ein
Grant auf dem Root wirkt über die Vorfahren-Kette auf das gesamte DMS.

### Rule 3 — SUPER_USER ist der DMS-Governor

- Der **ACL-Bypass** gilt nur noch für `SUPER_USER` (Level 100), nicht mehr ab `ADMIN` (D3a).
  Ein `admin` ist ein normaler, per Grant verwalteter Principal.
- **SUPER_USER-only** sind: Grants/Revokes auf dem Drive-Root, Anlegen/Verschieben/Umbenennen/
  Löschen von Partitionen, Grants/Revokes auf Partitionen (D5). Unterhalb einer Partition gilt
  normale ACL — wer `manage` hat (vom SUPER_USER grant'et), delegiert selbst weiter
  („Existenz vs. Zugriff", ADR-020 Rule 3).
- Der **Installer** provisioniert den ersten Account mit `roles: ['superUser']` (interaktiv wie
  über das Setup-Token); der Username bleibt `admin` (kosmetisch, kein Rollenname).

### Rule 4 — Modul-Schreibziel per Config, Schreiben nie oberhalb

Das per ADR-020 vorgesehene **optionale Remapping** wird konkret: `dmsConfig['moduleFolders']`
mappt Modul-`key` → Ziel-Partition-`key` (kein Eintrag = der eigene Key). Der Modul-Key bleibt
Code-Konstante (S2). Modul-Schreibpfade laufen über ein **subtree-gebundenes Handle**
(`DocumentService::forModule($moduleKey)`): Unterordner unterhalb des Ziels dürfen erzeugt
werden, jeder Write ausserhalb/oberhalb des Ziel-Subtrees wirft — erzwungen in der Domäne,
nicht im Aufrufer (gleiche Begründung wie R-authz-1).

### Rule 5 — Folder-`key` ist hart eindeutig (soweit der Driver kann)

Der key-setzende Pfad prüft vor dem Persist auf einen bestehenden anderen Ordner mit demselben
Key und wirft. Der File-Driver kann keine harte Garantie geben (kein Unique-Index, ARCH-A003) —
der deterministische S3-Fallback (kleinste id gewinnt) bleibt als Lese-Sicherung.

## Relationship to prior ADRs

- **ADR-020 — revidiert (Teil).** Rule 1 („oberste Ordner SIND die Partitionen, nichts darüber")
  wird ersetzt: Partitionen sind jetzt Kinder des Drive-Root. Rules 2–6 (ACL-Scoping, zwei
  Erzeugungs-Pfade, `key`-Adressierung, Root-Slug-URLs, kein `area`-Feld) bleiben vollständig
  gültig — „Root" heisst dort nun „Partition". *Action:* Revisionsnotiz in ADR-020.
- **ADR-017 — revidiert (Teil).** Der Admin-Bypass wird zum Super-User-Bypass; das Ownership-
  +-ACL-+-deliveryMode-Modell bleibt unverändert. *Action:* Revisionsnotiz in ADR-017.

## Consequences

- Ein globaler Grant-Anker (Root) + ein klarer Governor (SUPER_USER) — der `parentId === null`-
  Sonderfall verschwindet aus den Gates.
- Bestehende `admin`-User verlieren den impliziten Vollzugriff — gewollt; der Skeleton-Dev-User
  wird auf `superUser` umgestellt.
- Ketten-Walks (`rootIdOf`, `rootKeyOf`, `resolve`, Materialisierung, Tree-UI) stoppen neu am
  Drive-Root statt bei `parentId === null` — substanzieller, aber mechanischer Umbau
  ([Bauplan](../03-development/dms-drive-root-bauplan.md) U1–U6).
- Keine Daten-Migration (Framework pre-release, `skeleton/` ephemer) — Re-Seed + Adoption.

## Rejected Alternatives

| Option | Warum verworfen |
|---|---|
| Root-Slug in `/media`-URLs führen | Konstantes Segment ohne Information; alle materialisierten Pfade und publizierten URLs würden wandern. |
| ADMIN behält Bypass, nur Root-Akte SUPER_USER-only (D3b) | Zwei „Super"-Rollen nebeneinander; R3 („nur SUPER_USER teilt dort zu") bliebe halb wahr — genau die Ambiguität, die dieser ADR beseitigt. |
| Jeder Grant im DMS nur durch SUPER_USER | SUPER_USER wird Flaschenhals für jede Ordnerfreigabe; Delegation unterhalb der Partition ist der Sinn des ACL-Modells (D5, mit User entschieden 2026-07-03). |
| Dokumente direkt im Drive-Root erlauben | Hätten keine öffentliche URL (Root-Slug ist kein Pfadsegment) und machten die Materialisierung sonderfällig; der Root ist Organisation, nicht Ablage. |
| Seed in `packages/kernel/core/data` (ursprüngliche Anforderung) | Koppelt core an ein Modul-Datenformat; der Installer deployt `*.default.json` aus jedem Framework-Package identisch — der Seed gehört zum Besitzer der Domäne (`module-dms`, ADR-019). Mit User entschieden 2026-07-03 (D2). |
