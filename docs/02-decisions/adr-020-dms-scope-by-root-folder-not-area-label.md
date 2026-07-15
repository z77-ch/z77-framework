# ADR-020 — DMS scope by root folder, not by a flat `area` label

**Status:** `[APPROVED]` — Rule 1 partly superseded by ADR-021
**Date:** 2026-07-01

> **Revision 2026-07-03 (see [ADR-021](adr-021-dms-drive-root-and-super-user-governance.md)).**
> Rule 1 is revised: the hierarchy now has exactly ONE mandatory drive-root folder
> (`parentId = null`, `key = 'drive'`, system-owned) and the **partitions are its direct
> children** — no longer themselves `parent_id = null`. Everything else stands: partitions stay
> real, owned/ACL'd folders; module addressing via `key` (Rule 4), root-slug URLs (Rule 5 — the
> DRIVE root's slug is NOT a path segment), no `area` field (Rule 6). Where this ADR says
> "Root-Ordner", read "Partition" (child of the drive root). The "nur Super-User" language in
> Rule 3 is now literally enforced (`AuthRole::SUPER_USER`, no longer the ADMIN-level gate).

> **Revision 2026-07-13 (image profiles).** The "Image-Profile-Keying: `area` → Root-`key`"
> consequence below is refined: profiles are no longer module-owned config files aggregated per
> module key (`ImageProfileRegistry::fromModules()` is gone). ONE project-owned,
> **partition-namespaced** DMS config (`App/Config/imageProfilesConfig.inc.php` in the
> `Z77\Module\Dms` namespace, provided via the project's override tree —
> `override/z77/module/dms/...`) defines `partitionIdent => profileName => sizes`, where the
> partition ident is the root's **`key ?? slug`** (a keyless, human-created partition resolves by
> its slug — the `?key=` mount pattern). WHICH profile applies to an upload is a **folder
> assignment** (`Folder::$profile`, inherited down the chain like `deliveryMode`, set in the
> Drive's combined edit modal via the gated `FolderService::setProfile`), resolved at save time
> with the per-partition `default` profile as fallback. Rationale: the profiles are
> project-specific DMS management data consumed by the Drive upload — not module code; the
> per-partition namespace keeps this ADR's collision safety. As-built:
> [`../03-development/dms-folder-image-profiles-bauplan.md`](../03-development/dms-folder-image-profiles-bauplan.md).

---

## Context

`area` ist heute ein **flaches Partition-Label** auf jeder `Document`/`Folder`-Entity. Es isoliert die
Bereiche der konsumierenden Module (backend, financial, orders, …), bildet den obersten Segment des
Materialisierungs-Pfads (`public/media/<area>/…`) und der `/media/<area>/…`-Reserved-Route, und keyt die
Image-Profile. Der Area-Wurzelknoten selbst ist **kein Entity** (`folder_id = null`, Option A in
[`adr-017`](adr-017-document-management-ownership-acl-and-delivery.md) / `documents.md`) — ein Sonderfall,
der nicht verwaltbar ist (kein `deliveryMode`, keine ACL).

Zwei offene Anforderungen zeigten die Grenzen des flachen `area`-Modells:
- **Area-übergreifender Super-User-Zugriff:** ein Super-User soll alle Bereiche verwalten; mit flachem
  `area` bräuchte es einen expliziten Area-Umschalter.
- **Drive-Sichtbarkeit für Nicht-Admins:** ein Admin/Member soll nur „seinen" Bereich sehen — mit ACL,
  nicht mit einem Label.

Der ACL-Kern ([`adr-017`](adr-017-document-management-ownership-acl-and-delivery.md), R2) läuft bereits die
**Vorfahren-Kette** hoch: ein `grant(manage)` auf einen Ordner gilt für dessen **ganzen Subtree**. Diese
Maschine macht das flache `area`-Label überflüssig, wenn die oberste Ebene ein **echter Ordner** ist.

## Decision

**Scope über echte Root-Ordner + ACL, nicht über ein flaches `area`-Label.** Ein Baum; die obersten Ordner
sind die Partitionen und normale, besitz-/ACL-fähige Entities. **Der Baum ist die einzige Quelle der
Wahrheit** — das `area`-Feld wird **ganz entfernt** (kein denormalisiertes Zweit-Feld, das vom Baum
abdriften kann; Entscheid „sauber statt minimal-Churn", User 2026-07-01).

### Rule 1 — ein Baum, Root-Ordner sind die Partitionen
Die oberste Ebene (`parent_id = null`) sind **echte Folder-Entities** mit `ownerId`, `deliveryMode`, ACL —
kein `null`-Sonderfall mehr. Der „Bereich `financial`" **ist** ein Root-Ordner. Damit entfällt der
Option-A-„null-Wurzel"-Sonderfall aus ADR-017.

### Rule 2 — Zugriff ist ACL, Sichtbarkeit ist gescoped
`effectiveRight` (Vorfahren-Walk) liefert „grant auf Root → ganzer Subtree" **schon heute**. Der Drive
zeigt jedem **nur, was er `read`/`manage` darf** (Baum + Liste nach effektivem Recht gefiltert). Das ist
**R-authz-2** — und wird hier vom Zusatz zum **Kernmechanismus**. Super-User = Admin-Bypass → sieht alle
Roots. Damit **fällt der area-übergreifende Zugriff von selbst raus**; ein Area-Umschalter ist unnötig.

### Rule 3 — zwei Erzeugungs-Pfade für Root-Ordner
- **Mensch (Drive-UI):** nur **Super-User** legt Root-Ordner an (`requireAdmin` auf Top-Level, R-authz-1).
- **Modul (Code):** ein Modul **deklariert** seinen Root und `DocumentService::rootFolder($key)` legt ihn
  bei Bedarf an (**get-or-create**, idempotent, über den vertrauten **System-Pfad** — ungegated wie
  `saveGenerated`). Kein Fatal bei fehlendem Ordner, keine Deploy-Reihenfolge-Falle.

**Existenz vs. Zugriff sind getrennt:** das **Modul** besitzt die **Existenz** seines Roots (deklariert +
ensured); der **Super-User** besitzt den **Zugriff** (grant't den Root an den menschlichen Bereichs-Admin).

### Rule 4 — Modul-Adressierung über einen stabilen `key`
Ein Root-Ordner trägt einen **optionalen, eindeutigen `key`** (modul-deklarierte Roots setzen ihn; rein
menschlich verwaltete Bereiche brauchen keinen). Der `key` ist **was heute `area` war**, aber **nur auf
Roots**, nicht auf jeder Entity. Das Modul besitzt den `key` als **Code-Konstante** (seine Identität,
versioniert — nicht Config; Config nur als optionales späteres Remapping). `DocumentService::rootFolder($key)`
löst den Key → Root-Ordner (get-or-create).

### Rule 6 — kein `area`-Feld: der Baum ist die einzige Quelle
`Document`/`Folder` verlieren das `area`-Feld ganz. Der Bereich einer Entity **ist** ihre Position im Baum
(die Root, zu der ihre `parentId`/`folderId`-Kette führt) — **abgeleitet, nie gespeichert**. Scope/Queries
gehen über den Folder-Subtree (von der Root), nicht über `findByArea`; Root-Auflösung über den (gecachten)
Folder-Index (Parent-Ketten in-memory). Kein Zweit-Feld, das driften kann.

### Rule 5 — Materialisierung/URLs über den Root-Slug
Der oberste Pfad-Segment wird der **Root-Ordner-Slug** (statt des Area-Labels): `public/media/<root-slug>/
<folder-slugs>/<doc-slug>[.<variant>].<ext>`, Reserved-Route `/media/<root-slug>/…`. Konsistenter, weil der
Root jetzt ein echter Ordner mit Slug ist; der Key steht nicht mehr im Pfad.

## Zu klären im Bauplan (Detail-Entscheide, nicht ADR-blockierend)

- **Image-Profile-Keying:** `area` → Root-`key` (Profile werden pro Root aufgelöst, nicht pro Entity-Feld).
- **Query-Performance ohne `findByArea`:** „alle Docs unter Root X" = Subtree-Walk (Folder-Index in-memory
  gecached). Für das DMS-Volumen (klein, ADR-016) unkritisch; falls nötig, ein Subtree-Cache im Bauplan.
- **Migration:** keine — Framework pre-release, `skeleton/` ephemer; Seeds neu, Root-Ordner werden
  angelegt (Mensch/Modul), keine Datenmigration.
- **`rootFolder($key)`-Defaults:** Name/Owner eines auto-erzeugten Root (System-Owner bis Super-User
  grant't), Key-Eindeutigkeit erzwingen.

## Relationship to prior ADRs

- **ADR-017 — revidiert (Teil).** Das **Ownership-+-ACL-+-deliveryMode-Modell bleibt vollständig gültig**
  und wird zum Kern. Geändert: die oberste Ebene ist ein **echter Ordner** statt eines flachen `area`-Labels
  mit `null`-Wurzel (**Option A „keine Doks im Wurzelbereich" wird gegenstandslos** — der Root ist ein
  normaler Ordner). *Action:* Revisionsnotiz in ADR-017.
- **ADR-019 — unberührt.** DMS ist `module-dms`; dieser ADR ändert nur das Scope-Modell **innerhalb** des
  Moduls.
- **R-authz-1 (`Authz`, domänen-erzwungene Mutations-Gates)** — Voraussetzung; **R-authz-2 (Lese-Scoping)**
  wird durch diesen ADR zum **Kernmechanismus** statt Zusatz. Detail:
  [`../03-development/dms-authz-bauplan.md`](../03-development/dms-authz-bauplan.md).

## Consequences

- Eine einzige, einheitliche Struktur (Baum) — kein flaches Label + kein `null`-Sonderfall.
- Cross-area Super-User-Zugriff + nicht-Admin-Sichtbarkeit sind **dasselbe** Feature (ACL-Scoping), kein
  Zusatz-Umschalter.
- Module bleiben selbst-genügsam (deklarieren + ensuren ihren Root); Super-User steuert den menschlichen
  Zugriff per grant.
- Kosten: `area` ist tief verdrahtet (~40 Stellen `DocumentService`, ACL-Index, Save-Pfad, Materialisierung,
  `/media`-Route, Image-Profile) — der Umbau ist substanziell und braucht einen eigenen Bauplan (inkrementell,
  je lauffähig).

## Rejected Alternatives

| Option | Warum verworfen |
|---|---|
| Flaches `area` behalten + Super-User-**Area-Umschalter** (session-sticky) | Löst nur den Cross-Area-Zugriff, nicht die Sichtbarkeit; behält das flache Label, den `null`-Wurzel-Sonderfall und die Area-Auswahl. Der Root-Ordner-Ansatz unifiziert alles über die vorhandene ACL-Maschine. |
| `area` als **denormalisiertes** Feld behalten (= Root-Key auf jeder Entity) | Zweite Quelle der Wahrheit neben dem Baum → driftet (area sagt X, der Ordner hängt an Y) = künftige Inkonsistenz-Bugs. Weniger Churn, aber genau die Art halber Lösung, die später Probleme macht (User 2026-07-01). Der Baum ist die einzige Quelle. |
| Modul adressiert per **Config** statt Code-Key | Unnötige Indirektion — der Key ist die Identität des Moduls, gehört versioniert in den Code. Config nur als optionales Remapping. |
| Fehlender Root → **Fatal** (Root muss vorexistieren) | Deploy-Reihenfolge-Falle (Code vor Setup); ein Modul, das seinen eigenen Raum deklariert, rät nicht. Get-or-create ist robuster (mit User entschieden 2026-07-01). |
