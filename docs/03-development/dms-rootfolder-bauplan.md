# dms-rootfolder-bauplan.md — `area`-Label → Root-Ordner-Scope (ADR-020)

**Status:** **FERTIG (2026-07-02)** — alle RF-Phasen in EINEM Durchgang gebaut (User-Entscheid:
System musste zwischendurch nicht lauffähig bleiben, kein `area`-Scaffolding). `area` ist komplett
raus (Entities/Repos/Services/Traits/Route), Scope = Baum, Lese-Gates in der Domäne (RF-4a =
R-authz-2 fertig), Drive = Option (b). Verifiziert: `php -l` sauber, CLI-Smoke **27/27** (rootFolder
get-or-create/Key-Validierung/Locks, resolve über Root-Slug, Root-Grant→Subtree-ACL, Materialisierung
inkl. Wipe-Guard, RF-4a-Denials), curl gegen `php -S` (public 200+Bytes statisch, protected/sealed/
Miss → 404, Drive unauth → 302, Home 200). Skeleton-DMS-Daten neu geseedet (`_seed_drive_demo.php`,
Root `Ablage` key=`backend`). **Offen: manueller Admin-Klick-Test im Browser.**
Bindend: [`../02-decisions/adr-020-dms-scope-by-root-folder-not-area-label.md`](../02-decisions/adr-020-dms-scope-by-root-folder-not-area-label.md).
Baut auf: [`dms-authz-bauplan.md`](dms-authz-bauplan.md) (R-authz-1 fertig; **R-authz-2 = in RF-4a
erledigt**), [`dms-extraction-bauplan.md`](dms-extraction-bauplan.md) (`module-dms` self-contained).
Topic-Doc: [`../topics/documents.md`](../topics/documents.md).
**Datum:** 2026-07-01 (gebaut 2026-07-02)

## Wiederaufnahme (Stand 2026-07-02)

**Stand:** Planning fertig, **ADR-020 `[APPROVED]`**, ADR-017-Revisionsnotiz gesetzt, **noch kein
Code** an diesem Umbau. Vorgeschichte abgeschlossen + verifiziert: DMS-Extraktion nach
`module-dms` (Phase A + B, [`dms-extraction-bauplan.md`](dms-extraction-bauplan.md)) und domänen-erzwungene
Autorisierung R-authz-1 ([`dms-authz-bauplan.md`](dms-authz-bauplan.md), live bestätigt).
**Schwachstellen-Review des Plans durchgeführt (2026-07-02)** — Befunde S1–S7 unten eingearbeitet
(v. a. S1: Lese-Gates gehören in die Domäne, nicht als UI-Filter; neuer Block RF-4a).

**Gebaut 2026-07-02 (gleiche Session, alle Phasen):** s. Status oben. **Nächster Schritt:**
manueller Admin-Klick-Test im Browser (Drive über Roots, Lock-Meldungen der Modul-Roots,
Upload/Move gegen die neuen Gates).

**Entschieden (User, 2026-07-02):**
- **RF-4-Scope = Option (b):** der Drive zeigt **alle für den Principal zugänglichen Roots**
  (`effectiveRight >= read`; Super-User/Admin-Bypass = alle). `driveArea()` entfällt **ersatzlos** —
  kein Host-Label mehr (Option (a) hätte das Area-Label auf Host-Ebene reintroduziert).
- **Root-Rename (S4):** **modul-deklarierte Roots (`key != null`) werden `system = true`** angelegt und
  sind **rename- UND delete-geschützt** (der Root-Slug ist das oberste Segment ALLER public URLs des
  Bereichs; der `key` ist die Modul-Identität). Menschlich angelegte Roots (`key = null`) dürfen
  renamen — der URL-Bruch ist dort bewusstes, dokumentiertes Verhalten.

---

> **Ziel (ADR-020):** `area` (flaches Label auf jeder Entity) fällt **ganz weg**. Der **Baum ist die
> einzige Quelle**: Root-Ordner sind die Partitionen (echte Entities, Owner/ACL), Zugriff ist ACL
> (grant auf Root → Subtree), Module adressieren ihren Root über einen stabilen `key`. **Keine
> Datenmigration** (skeleton ephemer). Der Umbau ist inkrementell — jeder RF-Pausepunkt lauffähig; das
> `area`-Feld wird bis **RF-3** als Scaffolding mitgeschleppt (damit das System zwischendurch läuft) und
> dort **entfernt** — der Endzustand ist sauber, kein Zweit-Feld.

---

## Betroffene Fläche (Ist)

`area` (~40 Stellen `DocumentService`, plus `AclService`-Index, `SaveService`/`SaveRequest`,
`FolderService`, `Drive`-Trait, `resolve()`, `rebuildMaterialization`, `/media/<area>`-Reserved-Route in
`dmsConfig`/`OutputController`, `ImageProfileRegistry` area-scoped). Root-Ebene ist heute `null`
(Option A). `findByArea(area)` auf beiden Repos.

## Phasen (geplant inkrementell; real in EINEM Durchgang gebaut, 2026-07-02 — die
## Checkboxen unten gelten als ✅, die Texte bleiben als Bau-Referenz)

### RF-1 — Root-Ordner-`key` + `rootFolder(key)` ✅ (2026-07-02)
- [ ] `Folder`: `+key` (nullable, eindeutig **unter den Roots**; server-kontrolliert). Menschlich
  angelegte Roots dürfen `key=null` lassen; modul-deklarierte setzen ihn.
- [ ] **`key`-Härtung (S2):** `key` ist **ausschliesslich eine Code-Konstante des Moduls** — er darf
  NIE aus Request-Input stammen (sonst Root-Squatting: Angreifer besetzt einen künftigen Modul-Key
  vor und das Modul findet später den fremden Root inkl. dessen ACL/Modus). Kein `#[Clean]`, kein
  Setter-Pfad über Drive-Formulare; Format-Validierung im Setter (Slug-Charset `[a-z0-9-]`, nicht leer).
- [ ] `DocumentService::rootFolder(string $key, ?string $name = null): Folder` — **get-or-create**
  (System-Pfad, **ungegated** wie `saveGenerated`): fehlt der Root, wird ein Top-Level-Folder
  (`parentId=null`, `key`, `slug` aus `name`/`key`, System-Owner bis Super-User grant't,
  **`system = true`** — Entscheid S4: modul-deklarierte Roots sind rename- + delete-geschützt,
  der Root-Slug ist das oberste Segment aller public URLs) angelegt. Key-Eindeutigkeit erzwingen.
- [ ] **Key-Eindeutigkeit unter dem File-Driver (S3, TOCTOU):** der JSON-Store hat keinen Unique-Index
  und keine Transaktionen (ARCH-A003) — zwei parallele get-or-create können ein Duplikat erzeugen.
  Auflösung MUSS deterministisch sein (Lookup sortiert, **kleinste id gewinnt**); `rootFolder` prüft
  nach dem Flush defensiv und gibt den Gewinner zurück. Kein harter Uniqueness-Anspruch, aber
  deterministisches Verhalten + dokumentierter Caveat.
- [ ] **Rename-/Delete-Schutz für System-Roots:** `FolderService::rename` + `delete` blocken
  `system = true`-Roots (heute guarded `blockReason` nur delete; rename-Guard ergänzen — nur für Roots
  mit `system`, normale System-Ordner in der Tiefe bleiben wie bisher nur delete-geschützt).
- **Additiv:** `area` bleibt vorhanden + genutzt → System läuft. Module/Tests **können** ab hier per Root
  adressieren, nichts zwingt es.
- **Verify:** Wegwerf-Smoke `rootFolder('financial')` legt an / findet wieder / ist `system`;
  Key-Kollision → deterministisch derselbe Root; ungültiges Key-Format abgewiesen; rename auf
  System-Root blockiert.

### RF-2 — Scope/Queries/Save/Delivery vom `area` auf den Baum ✅ (2026-07-02)
- [ ] `AclService`-Folder-Index: von **pro-area** auf **baumweit** (alle Folder, Parent-Ketten) umstellen
  (Invalidierung wie gehabt). Root-Auflösung = Walk bis `parentId=null`.
- [ ] `listByArea(area)` → `listByRoot(int $rootId)` (Subtree-Docs via Folder-Index); `listByFolder`
  bleibt (schon folder-basiert).
- [ ] `SaveService`/`SaveRequest`: `area` nicht mehr fordern; die Lage = `folderId`. (`area` transitional
  abgeleitet gesetzt, bis RF-3.)
- [ ] `resolve(area, segments)` → `resolve(segments)`: erstes Segment = **Root-Slug** (Walk über Roots
  nach Slug), Rest = Folder-Slug-Kette. `/media/<root-slug>/…`.
- [ ] `rebuildMaterialization(area)` → pro **Root** (Pfad-Top = Root-Slug); `OutputController`/`dmsConfig`
  Reserved-Route auf Root-Slug.
- [ ] **Materialisierungs-Wipe-Guard (S5):** `rebuildMaterialization` macht `rrmdir` auf das
  Top-Segment-Verzeichnis. Ist der Root-Slug leer/korrupt, träfe `rrmdir('public/media/')` **alle**
  Bereiche. Guard: leerer Root-Slug → throw; das rrmdir-Ziel MUSS strikt `public/media/<slug>` mit
  nicht-leerem, sanitisiertem Slug sein — nie `public/media` selbst. (Latent existiert das heute schon
  mit `$area`; mit datengetriebenem Slug wird es real.)
- [ ] `Drive`-Trait + `FolderService`-Guards: Baum/Folder statt `area` (`driveArea()` s. RF-4).
  **Wichtig (S6): die `getArea() !== driveArea()`-Checks im Trait ERSETZEN, nicht streichen** — jede
  per-id angefragte Ressource (Doc/Folder) MUSS gegen „liegt unter einem für den Principal zugänglichen
  Root" geprüft werden, sonst öffnet der Wegfall des Area-Checks Cross-Root-ID-Probing. (Der
  vollwertige Lese-Gate kommt in RF-4a; transitional reicht Root-Zugehörigkeit + ADMIN-Mount.)
- **Ende RF-2:** `area` wird noch geschrieben, aber **nirgends mehr für Scope gelesen**.
- **Verify:** CLI-Smoke resolve/materialization über Root-Slug; `/media/<root-slug>/…` curl 200/404;
  Drive-Render (Bootstrap) über einen Root.

### RF-3 — `area`-Feld entfernen (sauber) ✅ (2026-07-02)
- [ ] `area` aus `Document`/`Folder` (Felder, Setter, `mapTo/FromArray`, `SaveRequest`) + **alle** Rest-
  Referenzen entfernen. Grep `area` = 0 im DMS-Code (ausser Root-`key`-Kontext).
- [ ] `ImageProfileRegistry`: Profile pro **Root-`key`** auflösen (statt area). Modul-Config bleibt, Key = Root.
  **Fallback (S7):** Dokumente unter einem Root **ohne** `key` (menschlich angelegt) haben keine
  Modul-Profile → es greift NUR das framework-fixe `admin`-Profil (Tool-Thumbnail). Explizit so
  festhalten, kein stiller `null`-Pfad.
- **Ende RF-3:** Baum ist die einzige Quelle. Kein `area`-Feld, kein Zweit-Zustand.
- **Verify:** `php -l` + Autoload; Round-Trip (save→resolve→serve→materialize) rein baum-/root-basiert;
  `docs:check`.

### RF-4 — Drive-Surface: ACL-Scoping + Root-Verwaltung (= R-authz-2) ✅ (2026-07-02; Klick-Test offen)

> **Vorbedingung ist RF-4a (Lese-Gates in der Domäne).** Sobald Nicht-Admins in den Drive kommen,
> fällt die Host-Mount-Rolle von ADMIN auf MEMBER — die grobe `AccessGuard`-Gate, die heute ALLE
> Lese-Pfade schützt, ist dann weg. UI-**Filterung** allein ist Präsentation und bypassbar (dieselbe
> Erkenntnis wie R-authz-1, [`dms-authz-bauplan.md`](dms-authz-bauplan.md)): Links verstecken ≠ die
> **angefragte** Ressource verweigern.

- [ ] **RF-4a — Lese-Gates in der Domäne (S1, deny-by-default, VOR jeder Mount-Rollen-Senkung):**
  - **Byte-Delivery** (`DocumentDeliveryTrait::preview/download`): heute NUR Host-`AccessGuard` +
    Area-Check, **kein `canRead`** — nach der Rollen-Senkung könnte ein Member per ID-Iteration jedes
    Dokument laden, auch `sealed`. Gate: `AclService::canRead` (effektives READ + Aktiv-Kette) in der
    Domäne (z. B. `DocumentService`-Lese-Pfad, den die Traits konsumieren), Denial = 404.
  - **Modal-GETs** (`edit`/`move`/`mode`/`acl`/`actions`-Hub): leaken Namen/Metadaten für beliebige
    IDs (die Mutation scheitert am Domain-Gate, der GET heute nicht). Gate: `effectiveRight >= read`
    auf der angefragten Ressource.
  - **`trashAction`**: listet heute alle gelöschten Docs des Bereichs → nach Recht scopen
    (`manage` auf dem jeweiligen Doc/Root; restore/purge sind schon gated).
  - **`paneAction`/`buildViewModel`**: ein `?folder=<id>` auf einen unzugänglichen Ordner MUSS
    verweigert werden (404/leerer Prompt), nicht nur der Link versteckt.
- [ ] Baum/Liste im Drive nach `effectiveRight` (`read`/`manage`) filtern → Nicht-Admin sieht nur seine
  zugänglichen Roots+Subtrees; **Super-User sieht alle Roots** (Bypass). Damit ist der area-übergreifende
  Zugriff erledigt. (Filterung = UX-Schicht ÜBER den RF-4a-Gates, nie deren Ersatz.)
- [x] **Design-Entscheid GEFÄLLT (User, 2026-07-02): Option (b)** — der Drive zeigt **alle** für den
  Principal zugänglichen Roots („Meine Bereiche"-Einstieg); `driveArea()` entfällt **ersatzlos**.
  Begründung: (a) — Host fix auf einen Root-`key` — hätte das Area-Label auf Host-Ebene reintroduziert;
  (b) ist selbst-scopend über die ACL, und der Super-User-Cross-Bereich-Zugriff fällt raus wie von
  ADR-020 versprochen.
- [ ] Super-User legt Roots im Drive an (Top-Level = `requireAdmin`, R-authz-1 vorhanden) + grant't sie.
  Modul-Roots (`system`) sind im Drive rename-/delete-gesperrt (RF-1); Modus/ACL bleiben verwaltbar.
- **Verify:** Live — Super-User sieht alle Roots, ein granted-Admin nur seinen; ein Member OHNE grant:
  Baum leer, direkter `?folder=`/`?id=`-Zugriff + `preview`/`download` fremder Ressourcen → 404
  (ID-Probing-Test); curl/Bootstrap-Render.

### RF-5 — Aufräumen + Doku ✅ (2026-07-02)
- [x] `documents.md` file map + Regeln (area→Root/`key`, Scoping), ADR-Querverweise; `docs:check` grün.
- [x] `dms-authz-bauplan.md` R-authz-2 als „in RF-4a erledigt" markiert.

## Offene Detail-Entscheide (im Verlauf)
- ~~**RF-4 (a) vs (b)**~~ → **entschieden: (b)** „alle zugänglichen Roots" (User, 2026-07-02; s. Wiederaufnahme-Block).
- **RF-1:** `rootFolder`-Default-Name/Owner (System-Owner-Konvention). Key-Format ist fixiert
  (Slug-Charset, S2); offen bleibt nur die Owner-Konvention (`ownerId = null` bis Super-User grant't?).
- **RF-2:** Subtree-Query-Perf (Folder-Index gecached; falls nötig Subtree-Cache) — für DMS-Volumen unkritisch.
- **RF-4a:** genaue Verortung der Lese-Gates (eigene `DocumentService`-Lese-Methoden mit Gate vs.
  `Authz`-Aufruf in den bestehenden Read-Pfaden) — beim Bau entscheiden; Kriterium: un-umgehbar von
  jedem Host aus (wie R-authz-1), OHNE den `OutputController`-Pfad doppelt zu gaten (der gated schon
  selbst via `canRead`).

## Schwachstellen-Review 2026-07-02 (eingearbeitet)

| ID | Schwere | Befund | Verortung |
|---|---|---|---|
| S1 | kritisch | Lese-Schutz war als UI-Filter geplant; Byte-Delivery (`preview`/`download`) hätte nach der Mount-Rollen-Senkung KEIN `canRead` — ID-Iteration auf `sealed` möglich | RF-4a (neu) |
| S2 | hoch | `rootFolder($key)` ungegated → `key` MUSS Code-Konstante sein, nie Request-Input (Root-Squatting) | RF-1 |
| S3 | mittel | Key-Eindeutigkeit im File-Driver nicht erzwingbar (TOCTOU) → deterministische Auflösung (kleinste id) | RF-1 |
| S4 | mittel | Root-Rename re-slugt → alle public URLs des Bereichs brechen → Modul-Roots `system=true`, rename-/delete-gesperrt (Entscheid User) | RF-1 |
| S5 | mittel | `rebuildMaterialization`-`rrmdir` mit leerem Root-Slug träfe `public/media/` komplett → Guard | RF-2 |
| S6 | klein | Trait-Area-Checks ersetzen (Root-Zugehörigkeit), nicht streichen — sonst Cross-Root-ID-Probing | RF-2 |
| S7 | klein | Image-Profile für Roots ohne `key`: expliziter Fallback = nur `admin`-Profil | RF-3 |

## see also
- [`../02-decisions/adr-020-dms-scope-by-root-folder-not-area-label.md`](../02-decisions/adr-020-dms-scope-by-root-folder-not-area-label.md) — Modell (bindend)
- [`dms-authz-bauplan.md`](dms-authz-bauplan.md) — R-authz-1 (Gates, Vorbedingung), R-authz-2 (→ RF-4)
- [`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md) — Ownership/ACL/deliveryMode (Kern bleibt)
