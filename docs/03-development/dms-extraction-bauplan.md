# dms-extraction-bauplan.md — DMS-Vertikale nach `module-dms` extrahieren (ADR-019)

**Status:** FERTIG (2026-07-01) — Phase A (Domain-Logik) + Phase B (Drive-UI-Fragment: B-mech +
B-folderservice) abgeschlossen + verifiziert, B-mech live vom User bestätigt. DMS ist self-contained
(Logik + UI); die Hosts sind reine Konsumenten (3-Zeilen-Controller + 1-Zeilen-Config). Bindender
Entscheid: [`../02-decisions/adr-019-code-organization-package-by-domain.md`](../02-decisions/adr-019-code-organization-package-by-domain.md)
(„First concrete move": DMS-Vertikale). Vorgeschichte (Zugriffsmodell/Delivery, alles gültig):
[`dms-umbauplan.md`](dms-umbauplan.md). Topic-Doc (single source of truth, `## file map` nach jeder Phase nachziehen):
[`../topics/documents.md`](../topics/documents.md).
**Datum:** 2026-07-01

> **Grundsatz (ADR-019):** Organisiere **nach Domäne, nicht nach technischer Schicht**. Die DMS-Domäne
> lebt heute über vier `shared`-Layer-Ordner + `persistence/Blob` + die Drive-UI in `module-backend`
> verteilt. Sie wird als **eine** vertikale Scheibe in das bestehende Paket `module-dms`
> (`Z77\Module\Dms`) gezogen. Inkrementell, jeder Pausepunkt lauffähig. Kein Produktiv-Datenumzug
> (Skeleton ephemer; `#[Entity]`-Pfade sind explizit → JSON-Dateien bleiben unberührt).

---

## Vorprüfungen (alle erledigt 2026-07-01, Fakten die den Umzug tragen)

- **EM-Auto-Wiring schon ADR-019-konform.** `FileEntityManager::resolveSpecific`
  ([`../../packages/kernel/persistence/src/File/FileEntityManager.php`](../../packages/kernel/persistence/src/File/FileEntityManager.php) Z. 113–120)
  leitet den Repository-Namespace **generisch** aus dem `\Entities\`-Segment ab
  (`$ns` = alles vor `\Entities\`, dann `$ns.'\Repositories\'.$name.'Repository'`). Kein fixes
  `Z77\Shared\…`-Paar. `Z77\Module\Dms\Entities\Document → Z77\Module\Dms\Repositories\DocumentRepository`
  greift ohne Codeänderung. → Die in ADR-019 als Risiko markierte Stelle ist bereits erledigt.
- **Storage-Pfad namespace-unabhängig.** `#[Entity('file', 'documents/documents.json', …)]` u. a. tragen
  den Pfad **explizit**; `resolveStore($attr)` nutzt das Attribut, nicht den Klassen-Namespace. Umzug =
  keine Datenmigration.
- **`Blob` intern ungenutzt.** `BlobStorage`/`LocalBlobStorage` werden in `persistence` nur in ihren
  eigenen Dateien referenziert → verschiebbar, ohne `persistence` zu brechen.
- **Dependency-Richtung ist Konvention, nicht composer-erzwungen.** Host-Module (`module-backend`) und
  `module-dms` deklarieren als `require` nur `php`; die Pakete verdrahten sich über die Skeleton-
  Autoload/Path-Repos. „Kein Zyklus" heisst: `module-dms` `use`-t **keinen** Host-Namespace
  (`Z77\Module\Backend\*`). `composer.json` von `module-dms` braucht keine Änderung.
- **`getUploadedFiles()` hat genau einen Laufzeit-Aufrufer:** `DriveController` (DMS-UI, wandert in
  Phase B mit). `getUploadedFile()` (Singular) hat gar keinen.

## Abweichung von ADR-019 (dokumentiert, bewusst)

**`UploadedFile` wird NICHT verschoben.** ADR-019s Zielliste verortet es in
`module-dms/src/ValueObjects/`. Aber `core/Http/Request` **produziert** `UploadedFile`
(`getUploadedFiles(): list<UploadedFile>`, [`../../packages/kernel/core/src/Http/Request.php`](../../packages/kernel/core/src/Http/Request.php)
Z. 14/546) — läge die Klasse in `module-dms`, hinge `core` von `module-dms` ab (verbotener Zyklus).
`UploadedFile` ist zudem inhaltlich ein **domain-loses HTTP-Transport-VO** (nur `originalName/tmpPath/
size/clientMimeType/error` + `bytes()/sniffMime()/extension()`; alles DMS-Spezifische — Allowlist,
`DocumentKind`, Blob — liegt in `UploadService`/`SaveService`). Nach ADR-019 Rule 2/c ist es ein
Primitiv. → **Bleibt in `shared/ValueObjects/UploadedFile` (unverändert).** `shared/ValueObjects/`
löst sich damit nicht ganz auf; das ist die einzige Ausnahme. `Principal` hat das Thema nicht (nur
`AclService` nutzt es) und wandert regulär mit.

*Action:* Revisionsnotiz in ADR-019 ergänzen (diese eine Zeile der Zielliste ist überstimmt) und in
ADR-017 den Verweis auf ADR-019 (Verortungswechsel, Domänen-Entscheide unverändert).

*Optionaler Feinschliff (NICHT Teil dieser Extraktion):* `UploadedFile` später nach `Z77\Core\Http`
umziehen (wohnt beim Produzenten). Eigener, kleiner Schritt.

---

## Move-Set (Namespace-Umzug)

**A — `shared/src` → `module-dms/src`** (`Z77\Shared\{…}` → `Z77\Module\Dms\{…}`):

| von `shared/src/…` | nach `module-dms/src/…` | Klassen |
|---|---|---|
| `Entities/` | `Entities/` | `Document`, `Folder`, `AccessControlEntry` |
| `Repositories/` | `Repositories/` | `DocumentRepository`, `FolderRepository`, `AccessControlEntryRepository` |
| `Services/` | `Services/` | `DocumentService`, `SaveService`, `SaveRequest`, `UploadService`, `AclService` |
| `Documents/` | **`Images/`** (Rename, ADR-019) | `DocumentKind`, `ImageProcessor`, `GdImageProcessor`, `VariantSpec`, `ImageProfile`, `ImageProfileRegistry`, `ProcessedVariant` |
| `ValueObjects/` | `ValueObjects/` | `Principal` (**nur** — `UploadedFile` bleibt) |

**B — `persistence/src/Blob` → `module-dms/src/Blob`** (`Z77\Persistence\Blob\{…}` → `Z77\Module\Dms\Blob\{…}`):
`BlobStorage`, `LocalBlobStorage`.

**Bleibt liegen:** `shared/ValueObjects/UploadedFile` (s. o.).

---

## Phase A — Domain-Logik (mechanisch, self-contained, lauffähig)

Nur die Nicht-UI-Klassen. Hosts bleiben Konsumenten und ziehen nur den Namespace nach. **Kein**
Verhalten ändert sich.

- [x] **A1 — Dateien verschoben** (21 Dateien) gemäss Move-Set A + B; `namespace`-Zeilen angepasst
  (per Migrations-Skript, literale Ersetzung). `shared/Documents` + `persistence/Blob` leer → entfernt.
- [x] **A2 — Referenzen nachgezogen** (22 Dateien geändert, 287 gescannt). Gezielt pro Klasse für die
  gemischten Ordner (`Entities`/`Repositories`/`Services`/`ValueObjects` — LoginUser/AuthService/
  UploadedFile/UserPreferences blieben unberührt), pauschal für `Documents\`→`Images\` und
  `Persistence\Blob\`→`Dms\Blob\`. Grep bestätigt: kein Code-Rest alter FQN (nur der Plan-Doc nennt sie).
- [x] **A3 — image-profiles-Config:** No-Op. Es existiert noch keine `imageProfilesConfig.inc.php`
  (optional pro Modul); `ImageProfileRegistry::fromModules` iteriert alle Modulschlüssel ohne
  hartkodierten Namespace → greift unter neuem Ort unverändert.
- [x] **A4 — Autoload regeneriert:** `vendor/z77/*` sind Symlinks auf `packages/` → Umzug sofort live;
  `composer dump-autoload` im Skeleton neu gebaut. `moduleManager.inc.php` unberührt (Modul-Registrierung
  ändert sich nicht, nur Klassenorte).
- [x] **A5 — Verifiziert:** `php -l` aller bewegten/geänderten Dateien grün. Autoload-Smoke: alle 21
  neuen FQN (inkl. Interfaces `ImageProcessor`/`BlobStorage`) lösen auf, `UploadedFile`/`LoginUser`/
  `AuthService` bleiben, alte DMS-FQN weg. Live-Server: home 200, `/media`-Miss 404 (nicht 500 → volle
  Resolve-Kette läuft), backend/drive 302 (Login, nicht 500). `documents.md`/`mail.md` file map + entry
  nachgezogen, `npm run docs:check` grün (27/27). *Rest wie im ganzen Umbauplan:* auth-pflichtiges
  Drive-Rendern/Upload nicht curl-testbar (kein Admin-Login).

**Pausepunkt A = lauffähig:** Domäne liegt in `module-dms`, Drive-UI konsumiert sie noch aus
`module-backend` über den neuen Namespace.

---

## Phase B — Drive-UI-Fragment nach `module-dms` (entangled, Host-Rewiring)

Vervollständigt ADR-018 (eingebettetes `.dms`-Fragment). Höheres Risiko wegen Host-Config/Assets —
separater Schritt.

### Design-Entscheide (fixiert 2026-07-01)

- **Routing-Constraint (verifiziert):** `getNamespacePrefix($module)` = `Z77\Module\{Module}`, **fixe
  1:1-Bindung** URL-Modul ↔ Namespace. Ein Controller in `Z77\Module\Dms` ist nur über `/dms/...`
  erreichbar → kann NICHT gleichzeitig in `module-dms` liegen und als `/backend/...` geroutet werden.
- **Muster = Trait, NICHT abstrakte Basis.** `BackendAbstractController::html()` dekoriert das View-Model
  mit Host-Chrome (Palette/Theme/`navGroupSlug`/headerUser) → der Drive braucht seine Host-Basis. Eine
  `AbstractDriveController` in dms dürfte `BackendAbstractController` nicht erben (Zyklus) und PHP hat
  Single-Inheritance. Deshalb: **`module-dms` shipt einen `DriveControllerTrait`**; der Host-Controller
  `extends {Host}AbstractController` + `use DriveControllerTrait` + liefert `driveArea()`.
- **Ressourcen-Pinning:** der Trait löst Templates/Partials/JS/CSS fix über `self::DMS_NS =
  'Z77\Module\Dms'` auf (nicht `self::NAMESPACE` = Host). Host-Chrome kommt automatisch aus dem
  jeweiligen `html()`.
- **`driveArea()`-Semantik = heutiges `area`** (keine Modell-Änderung; die „Zugriffs-ID auf Folder+Kinder"
  ist ein separater späterer Schritt — mit User abgestimmt 2026-07-01).
- **Sub-Entscheide (User 2026-07-01):** (1) `DocumentController` `preview`/`download` ebenfalls als
  `DocumentDeliveryTrait` in dms. (2) Host-`driveControllerConfig` = 1-Zeilen-Delegation an ein
  dms-`DriveLayout::config()` (pinnt Skeleton + Body-Main-Template + `dms.css`/`drive.js` auf dms).

### Was nach `module-dms` wandert

- `src/Ui/DriveControllerTrait.php` — die ~1070 Zeilen Drive-Logik (alle Actions + `buildViewModel`/
  `panes`/`renderPane`/Upload/Folder-Ops/ACL/Mode/Trash/Hub).
- `src/Ui/DocumentDeliveryTrait.php` — `preview`/`download` (Byte-Delivery).
- `src/Ui/DriveLayout.php` — liefert das controllerConfig-Array (Skeleton/Main-Template/Assets, alles dms-NS).
- `res/view/templates/Documents/DriveController/*` — `listAction` + 4 Partials + Modals; die heute unter
  `DocumentController/`+`FolderController/` verstreuten Reuse-Modals reorganisiert als Drive-Modals.
- `res/assets/js/documents/{drive,upload}.js`.
- `src/Services/FolderService.php` — **NEU**, die 3-fach duplizierte Slug-/Guard-Logik konsolidiert.

### Was jeder Host behält (dünn)

- `DriveController` (3 Zeilen: `extends {Host}AbstractController`, `use DriveControllerTrait`, `driveArea()`).
- `DocumentController` (analog, `use DocumentDeliveryTrait`).
- `Ui/Config/Documents/driveControllerConfig.inc.php` — 1-Zeilen-Delegation an `DriveLayout::config()`.
- Eintrag in `{host}Config.inc.php` (Route-Gruppe `documents` + Rollen) — bleibt.
- Nav-Seed (`/backend/documents/drive`) — bleibt.

### Schritte (Sub-Phasen, je lauffähig)

- [x] **B-mech (2026-07-01, verhaltenserhaltend).** `DriveControllerTrait` + `DocumentDeliveryTrait` +
  `DriveLayout` nach `module-dms/src/Ui`; Templates (`res/view/templates/Documents/*`, Subpfade
  identisch) + JS (`res/assets/js/documents/*`) nach `module-dms`. Transforms: `self::AREA`→
  `$this->driveArea()` (34×), `self::NAMESPACE`→`self::DMS_NS` (16×), hardcodete `/backend/documents/*`
  → host-relativ via `groupBase()` (Controller) bzw. `$base` (Templates, 28× in 11 Files). **Host-URL-
  Threading:** Controller legt `base`/`tplNs` ins View-Model + jedes Modal-`html()`; `listAction` reicht
  `$base`+`$tplNs` an die 4 Sub-Panes (`$tplNs` nötig, weil `HtmlView` sonst im Host-NS auflöst).
  **`driveControllerConfig` (Host, 1-Zeilen-Delegation an `DriveLayout::config()`)** pinnt `body.main` auf
  das DMS-`listAction` — Pflicht, sonst sucht `initialize()` das Action-Template im Host-NS und wirft.
  Dünne Host-Controller (backend) `use` die Traits + `driveArea()='backend'`. Folder-Slug/Guard-Logik
  bleibt vorerst im Trait (→ B-folderservice). **Verifiziert:** `php -l` alle grün; Komposition-Smoke
  (Traits komponiert, alle Actions + `#[Fetch]` am Host sichtbar); **Offline-Render via echtem
  `Bootstrap::pullUp()`**: `body.main` + alle 16 Templates lösen im DMS-NS auf, `listAction` rendert
  (4600 B, alle 4 Panes via `tplNs`), **Breadcrumb mit `base=/financial/documents` → financial-URLs,
  0× `/backend/`-Leak** (host-agnostisch bewiesen); curl home 200 / drive 302 / preview 302 / media-404
  (kein Fatal); `dms.css`+`drive.js`/`upload.js` nach `public/assets/dms/` publiziert; `documents.md`
  file map + `docs:check` grün (27/27). **Live vom User bestätigt (2026-07-01)** nach frischem
  `composer install` (voller Reset: `public/`/`config/`/`data/` gelöscht → Installer re-publiziert Assets
  + neuer Admin): Drive rendert, Ordner-Anlegen/Upload/Panes/Modals funktionieren im Browser.
  **1 Bug beim Live-Test gefunden + gefixt:** `_tree.tpl.php` — die rekursive `$renderNodes`-Closure
  hatte `$base` nicht in `use(...)` (der Blanket-Replace machte aus dem URL-Literal ein `$base`-Ref);
  bei leerem Baum lief die Closure nie (Offline-Test grün), bei erstem Ordner → `undefined $base`.
  Fix: `$base` in `use(...)` ergänzt. Andere Closures ok (`_list::$fileUrl` nimmt `$base` als Param,
  `fn()` auto-capturen).
- [x] **B-folderservice (2026-07-01, Refactor).** Neuer `module-dms/src/Services/FolderService`
  (`add`/`rename`/`move`/`delete` + `blockReason` + Slug-Eindeutigkeit + Delete-/Cycle-Guards) — das
  Folder-Pendant zu `DocumentService`. Die Folder-Domänenlogik wandert aus dem UI-Trait in die Domäne:
  `DriveControllerTrait` behält nur Surface (CSRF/Modal/Flash/Pane-Refresh) und delegiert; die 4 privaten
  Helfer (`folderNameError`/`folderBlockReason`/`isDescendant`/`uniqueFolderSlug`) + der `Naming`-Import
  raus. Service wirft (`InvalidArgumentException`/`NotFoundException`/`RuntimeException`), Controller
  surfaced via `fetchError`. `rename`/`move` re-materialisieren (Slug/Parent → Descendant-`/media`),
  `add`/`delete` nicht. **Verhaltensgleich:** Slug nutzt weiter `Naming::toSlug` (gleiche Ausgabe wie das
  alte `uniqueFolderSlug`). *Nicht angefasst:* `SaveService::uniqueSlug` (Doc-Slug, pro Folder+Ext) bleibt
  im Doc-Save-Pfad — teilt nur das Muster, nicht den Code. **Verifiziert:** `php -l` grün, keine dangling
  refs; Round-Trip-Smoke gegen echte Daten (Bootstrap): add (Felder+Slug), Dup→`-2`, rename, move,
  Cycle-Guard, Delete-Guard (nicht-leer), Empty-Name-Guard — alle grün, Wegwerf-Ordner sauber entfernt;
  curl drive/folder-add 302 (kein Fatal). **Live vom User bestätigt (2026-07-01):** Ordner anlegen/
  umbenennen/verschieben/löschen im Drive funktionieren. **Kleine Verhaltensnuance (bewusst):** Move prüft
  jetzt CSRF vor Ordner-Existenz (vorher umgekehrt) — nur Fehler-Präzedenz bei doppelt-ungültigem Request.
- [ ] **Verifikation je Sub-Phase:** `php -l`, Autoload-Dump, curl `/backend/documents/drive/list` → 302
  (Login, nicht 500) + wenn eingeloggt Pane/Upload/Modals; `documents.md ## file map` + Regeln nachziehen,
  `docs:check` grün. Asset-Publish `drive.js`/`upload.js` aus dms.

**Pausepunkt B = lauffähig:** DMS ist self-contained (Logik + UI); Hosts sind reine Konsumenten.

---

## Betroffene Referenzen (Stand 2026-07-01, für A2)

DMS-Namespaces referenziert in ~22 Dateien. Nicht-DMS/Host-Konsumenten (nur Namespace nachziehen):
- `core/src/Http/Request.php` — nutzt **nur `UploadedFile`** (bleibt liegen → **keine** Änderung nötig).
- `module-backend/…/DriveController.php`, `DocumentController.php` + Templates `Documents/*` — Namespace
  nachziehen (Phase A), Dateien selbst wandern erst in Phase B.
- `skeleton/_seed_drive_demo.php` — Wegwerf-Seed; Namespace nachziehen oder entfernen.
- `module-dms/…/OutputController.php`, `VariantSpec` u. a. — intern, wandern mit.

Verbindliche Liste beim Start via
`Grep "Z77\\Shared\\(Entities|Repositories|Services|Documents|ValueObjects)\\…"` +
`"Z77\\Persistence\\Blob\\…"` neu ziehen (Blast-Radius kann sich bis dahin ändern).

## Offene Punkte

- **B1** (Fragment-Registrierung, s. o.) — vor Phase B klären.
- **DocumentController** `preview`/`download`: Host-Byte-Route oder `module-dms`?
- ADR-019-Revisionsnotiz (`UploadedFile`-Abweichung) + ADR-017-Verweis auf ADR-019 setzen.

## see also

- [`../02-decisions/adr-019-code-organization-package-by-domain.md`](../02-decisions/adr-019-code-organization-package-by-domain.md) — bindend, Move-Ziel + Regeln
- [`dms-umbauplan.md`](dms-umbauplan.md) — Zugriffsmodell/Delivery (alles gültig), R6c-Schuldnotiz (FolderService)
- [`../topics/documents.md`](../topics/documents.md) — Topic-Doc, `## file map` pro Phase nachziehen
- [`../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md`](../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md) — `.dms`-Fragment (Phase B)
