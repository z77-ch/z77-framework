# dms-r4-delivery-bauplan.md — R4 strukturelle Auslieferung (Resume-Sheet)

**Status:** IM BAU. Sub-Phasen R4a → R4b → R4c, jede ein dokumentierter Pausepunkt.
Aufsetz-Dokument: eine kalte Session kann damit weiterbauen, ohne neu zu recherchieren.
**Datum:** 2026-06-23
**Master-Plan:** [`dms-umbauplan.md`](dms-umbauplan.md) (R4-Abschnitt verweist hierher)
**Bindender Entscheid:** [`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md) §8 (Media delivery)

---

## Ausgangslage (was R1–R3 bereitstellen)

- **R1:** `Document`/`Folder` haben `ownerId, active, slug, deliveryMode`; ACL-Store. `SaveService`
  setzt Document-`slug` (via `slugify`). **Folder-`slug` wird NICHT befüllt** → R4a holt das nach.
- **R2:** `AclService` (`effectiveRight`, `hasAccess`, `canRead`, `create`) — APCu-gecacht. `canRead` =
  `active`-Kette UND `read`. Der Output-Controller (R4c) ruft `canRead()` **vor** jeder Byte-Auflösung.
- **R3:** Reserved-Route-Tier. `/media` ist eine Reserved-Route in `frontendConfig`
  (`/media` → `frontend/media/media/serve`), gematcht vor Alias/Nav/Convention UND vor dem
  Fetch-Kurzschluss. R4c biegt diese Route auf `module-dms`/`OutputController` um.

## Bindende Entscheidungen für R4

- **Auslieferung = portabler PHP-Range-Stream** (`FileResponse`). Web-server-Beschleunigung
  (`X-Sendfile`/`X-Accel`/LiteSpeed) ist **verworfen** (2026-06-23, ADR-017 Rejected Alternatives) —
  nicht portabel (cyon), PHP-Stream genügt. `FileResponse` hat keinen `delivery`-Schalter mehr.
- **Variante in der URL = Dateinamen-Suffix** (Entscheid 2026-06-23, ADR §8): `…/foto.jpg` = Original,
  `…/foto.m.jpg` = Variante `m`. NICHT als Pfadsegment. Resolver prüft, ob das Stück vor der Extension
  ein bekannter Variantenname ist.
- **URL strukturell:** `/media/{area}/{ordner-slug…}/{datei}` — slug-basierter Folder-Walk, kein
  `publicPath`-Lookup mehr (`publicPath` wird in R5 entfernt). Kein Query-Param für Variante.
- **`deliveryMode`-Branching (ADR §3):** `public` = statisch vom Webserver (kein PHP, keine ACL);
  `protected`/`sealed` = PHP + ACL aus `data/blobs`. Materialisierung der public-Kopien nach
  `public/media` + Apache-`!-f`-Switch = **R5/Deployment**, NICHT R4. In R4 liefert der
  `OutputController` alles per PHP aus `data/blobs`; sobald R5 materialisiert, fängt der Webserver
  public-Dateien davor ab. R4 ist damit unabhängig von R5 baubar.

## Sub-Phasen

### R4a — Folder-slug-Befüllung (vorgezogen aus R6)  ✅ FERTIG (2026-06-23)
**Warum:** Der Resolver (R4b) walkt slug-basiert durch die Ordner; ohne Folder-slugs unmöglich.
`folders.json` ist aktuell leer → kein Backfill nötig (ADR: keine Migration, Skeleton ephemer).
- `Naming::toSlug(string): string` (shared) — `str_replace('_','-', toSnakeCase($v))`. `SaveService::slugify`
  nutzt es (Extension vorher droppen), `FolderController` nutzt es direkt. Nicht duplizieren.
- `FolderController::edit()`: `slug` bei Create UND Rename setzen, server-kontrolliert (kein `#[Clean]`).
  **Eindeutigkeit pro Parent:** unter Geschwistern (gleiche `area` + gleiche `parentId`, self
  ausgenommen) per `-2`, `-3`… eindeutig machen. Leerer Transform → Fallback `ordner`.
- **Done R4a:** `Naming::toSlug`-Smoke grün (Umlaute/Spaces/Sonderzeichen/leer), `php -l` sauber,
  Doku nachgeführt, `docs:check` grün. Live-Create-mit-slug = derselbe offene Admin-UI-Check wie bisher.

### R4b — `DocumentService::resolve(string $area, array $segments): ?array`  ✅ FERTIG (2026-06-23)
Reine Logik in `shared`, isoliert per CLI-Smoke testbar (kein Package nötig). Rückgabe
`['document'=>Document, 'variant'=>string]` | `null`. Slugs haben nie einen Punkt → Dateiname
splittet eindeutig: 2 Teile = `<slug>.<ext>` (Original), 3 Teile = `<slug>.<variant>.<ext>`.
Variante wird gegen `$doc->getVariants()` validiert (unbekannt → null). **Eindeutigkeits-Entscheid:**
bei !=1 lebenden Treffern (slug+ext im Zielordner) → `null` — nie falsche Bytes ausliefern. Doc-slug-
Eindeutigkeit pro Ordner ist Save-seitig (R5-Härtung, `SaveService` dedupt heute nicht). Soft-deleted
löst nicht auf. KEIN deliveryMode/ACL/active-Gate (das ist R4c). Smoke 12/12 grün (mehrstufiger Walk,
Variant-Suffix, Original, Root-Doc, alle Miss-Fälle).
1. Letztes Segment = Dateiname → Variante abspalten: ist das Stück vor der Extension ein bekannter
   Variantenname (`s|m|l|xl|admin.s`, aus `ImageProfileRegistry`/`variants`-Map)? Dann Variante +
   Basis-Dateiname; sonst Original.
2. Vordere Segmente = Ordner-slug-Kette ab Area-Root: über `FolderRepository::findByArea($area)` +
   `TreeService` walken, je Ebene Kind mit passendem `slug` suchen. Kein Treffer → `null`.
3. Im Zielordner Dokument per `slug` (+ `ext`) suchen. Rückgabe: `Document` + aufgelöste Variante.
   (Dokument-slug-Eindeutigkeit im Ordner: hier behandeln/annehmen — SaveService dedupt heute nicht;
   offener Punkt, in R4b entscheiden.)
- **Done R4b:** Smoke grün (Walk mehrstufig, Variante-Suffix, Original, Miss → null), `php -l`, Doku.

### R4c — Package `module-dms` + `OutputController`  ✅ FERTIG (2026-06-23)
**Gebaut:** Package `packages/module-dms` (composer.json PSR-4 `Z77\Module\Dms\`, README, `dmsConfig`).
`OutputController::serveAction` (GUEST): erste slug = area, Rest = struktureller Pfad → `resolve()` →
`effectiveDeliveryMode()` (neu in `DocumentService`: own ?? nächster Ancestor ?? `protected`, sealed-cap)
→ Branch: `public` offen (self-active-Check; volle Ancestor-active-Kette + Materialisierung = R5),
`protected`/`sealed` → `AclService::canRead()` (READ + active-Kette) **vor** Byte; jeder Miss/Deny → 404.
Bytes via PHP-Range-`FileResponse` (`serve()` hat jetzt optionalen `cacheControl`-Override: public=immutable,
protected=private,no-cache). Reserved-Route `/media` von `frontendConfig` nach `dmsConfig`
(`dms/media/output/serve`); `MediaController` + `media`-Gruppe (module-frontend) gelöscht.
`skeleton/composer.json`: `z77/module-dms` (require + path-repo + override-autoload `Z77\Module\Dms\`) —
Installer entdeckt das Modul automatisch (PSR-4 `Z77\Module\*`) und regeneriert `moduleManager.inc.php`
beim `composer install` (vom Entwickler ausgeführt). `php -l` aller neuen Dateien sauber.
**Offen:** Live-HTTP-Test nach dem composer-Lauf des Entwicklers; danach Deploy-Hinweis APCu/Page-Cache leeren.

Ursprüngliche Planung:
- Neues Package `module-dms` (Struktur analog `module-frontend`); in `moduleManager`-Config + PSR-4
  registrieren.
- `OutputController` (GUEST): `resolve(area, segments)` → Dokument | 404. `deliveryMode`-Branching:
  `public` → liefern ohne ACL; `protected`/`sealed` → `Principal` aus Session → `AclService::canRead()`
  + `active`-Gate **vor** Byte-Auflösung → `FileResponse` (PHP-Range) | **404** (Existenz nie leaken).
- Reserved-Route `/media` von `frontend/media/media/serve` auf `module-dms`/`OutputController` umbiegen
  (in der jeweiligen Modul-Config). `MediaController` (module-frontend) + die `media`-Gruppe ablösen.
- Cache-Header: public = `ETag` (aus `checksum`) + `Cache-Control: public, immutable`; privat/autorisiert
  = `private, no-cache`.
- **Done R4c:** Routing-Smoke (Page+Fetch) grün, `php -l`, `routing.md`/`documents.md` nachgeführt,
  `docs:check` grün. Deploy-Hinweis: APCu/Page-Cache leeren.

## Stolpersteine

- `FolderController` persistiert Folder **direkt** via `em()->persist()` (kein SaveService) → slug dort
  setzen. Folder-slug-Population ist damit controller-seitig (R6-Surface-Rework verschiebt das ggf.).
- `MediaController` bleibt bis R4c live (System lauffähig). Erst R4c löst ihn + die `media`-Gruppe ab.
- Reserved-Route zeigt bis R4c auf `frontend/media/media/serve` (R3-Stand) — erst R4c biegt um.
- Variant-Namen kommen aus `ImageProfileRegistry` (area-scoped) + der `admin`-Fixprofil-Logik; der
  Resolver darf NICHT hartkodieren.

## see also
- [`dms-umbauplan.md`](dms-umbauplan.md) — Master (R1–R3 ✅, R4 hierher, R5–R7)
- [`dms-r2-acl-bauplan.md`](dms-r2-acl-bauplan.md) — `AclService` (R4c konsumiert `canRead`)
- [`../topics/documents.md`](../topics/documents.md) + [`../topics/routing.md`](../topics/routing.md)
