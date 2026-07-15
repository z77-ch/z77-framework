# media-url-helper-bauplan.md — `mediaUrl()` Template-Helper + DMS-scoped Resolve-Cache

**Status:** FERTIG + live-bestätigt (2026-07-12) — Steps 1–5 gebaut, CLI-Smokes grün, im Projekt
dem Referenzprojekt live verifiziert (Logo rendert mit `?v=`-Token; Bildtexte speichern über die Drive-Edit-Maske). Das erste echte Projekt braucht DMS-verwaltete
Bilder (Logo etc.) im Template. Entscheid: alle Frontend-Bilder laufen über das DMS (zentral
tauschbar durch den Kunden). Zwei Bausteine: (1) globaler `mediaUrl()`-Helper für die Ergonomie,
(2) DMS-scoped Per-Request/APCu-Cache für die Auflösungskosten, weil das Frontend viele Bilder pro
Seitenaufbau auflöst.
Topic-Doc: [`../topics/documents.md`](../topics/documents.md), [`../topics/cache.md`](../topics/cache.md),
[`../topics/view-layer.md`](../topics/view-layer.md).

## Ausgangslage (verifiziert 2026-07-12)

- Kein semantischer String-Key im DMS (kein `getFileById('header-logo')`). Adressierung: numerische
  `id` (`get`) oder struktureller Slug-Pfad (`resolve(['front','imgs','logo.png'])`).
- Öffentliche `/media`-URLs MÜSSEN via `DocumentService::publicUrl($doc,$variant)` gebaut werden
  (documents.md-Regel) — hand-gebaut fehlt der `?v=<checksum>`-Token → Stale-Cache nach Austausch.
- Layering: `Helper.php` + `DI` liegen in `kernel/core`; `DocumentService` in `module-dms`. Ein
  `mediaUrl()` in der core-`Helper.php` bräche die Regel (core → module-dms). → Helper lebt in module-dms.
- DI ist ein String-Container (`DI::getDocumentService()` → `get('DocumentService')`), aber
  `DocumentService` ist NICHT registriert — überall via statischer Factory `DocumentService::create()`.
  → Helper ruft `create()` und memoized die Instanz.
- Cache-Lage: PageCache (Frontend `enabled=true, ttl=86400`) deckt den Normalfall — auf HIT läuft
  `mediaUrl()` gar nicht. `invalidatesCache: true` auf `Document`/`Folder` leert PageCache + APCu bei
  jedem DMS-Write → `?v=`-Token + frisches Rendering, kein Stale. **Aber:** coarse Invalidierung leert
  bei JEDEM DMS-Write den ganzen PageCache → auf DMS-intensivem, kundengepflegtem Frontend häufige
  Misses. Und die Repos memoizen NICHT: `CollectionStore::all()` → `FileStorage::load()` liest+decodet
  `folders.json` (2×/`resolve`) + `documents.json` (1×) pro Bild, ungecacht. → N Bilder = N×3 File-I/O
  pro Miss-Render.
- APCu für `resolve()` bringt NICHT nichts (frühere Fehleinschätzung korrigiert): hilft intra-request
  (2.–N. Auflösung im selben Render) UND allen PHP-ausführenden Requests (Fetch-Mode/Query-String =
  PageCache-BYPASS, Backend-Drive = Cache disabled → immer BYPASS). Nur der Frontend-Page-HIT braucht
  es nicht (kein PHP).
- Präzedenzfall im Code: `AclService` cacht Folders + ACEs bereits in APCu via `DataCache`
  (`cachePersist: true`, R2b). `AclService::folderIndex()` ist aber reduziert (kein `slug`) und privat
  → nicht direkt wiederverwendbar. → eigener Index in `DocumentService`, gleiches Muster.

## Design-Entscheide

1. **Name `mediaUrl()`** — parallel zur `/media`-Route.
2. **`null` bei Fehltreffer** — Template guarded per `if`, kein stilles Broken-Image.
3. **Helper in `module-dms`**, registriert via composer `autoload.files` (`src/helpers.php`). Existiert
   nur, wenn module-dms installiert ist. Sauberes Layering.
4. **Cache DMS-scoped in `DocumentService`**, NICHT generisch auf `CollectionStore`/`FileStorage`
   (das würde jede File-Entity betreffen — zu breites Risiko). Muster `AclService` (reduzierte Arrays
   in APCu, keine Entities serialisieren).
5. **Doctrine (Punkt 2 der Diskussion) NICHT jetzt.** Ist der dokumentierte Skalierungs-Fahrplan
   (persistence-architecture, `#[Entity('doctrine')]`, ARCH-A001 ~5k-Grenze). Das `mediaUrl()`/
   `resolve()`-Design überlebt den Treiberwechsel unverändert (driver-abstrahiert) → keine Wegwerfarbeit.
   Roadmap-Trigger festhalten, sonst nichts.
6. **URL-Format = eine Quelle.** Der cached Pfad baut die URL NICHT von Hand — Assembly wird in eine
   private `buildPublicUrl(...)` gezogen, die `publicUrl(Document)` UND der cached Pfad nutzen
   (documents.md-Regel „nur via publicUrl" bleibt erfüllt).
7. **Delivery-Pfad unangetastet.** `OutputController` → `resolve()` (entity-basiert, gated, echte Bytes)
   bleibt wie er ist (security-kritisch). Der neue Index ist ein read-only URL-Bauer für Templates.
   Beide werden vom selben `invalidatesCache` zusammen gedroppt.

## Aufrufkette (Ziel)

```text
Template:  <img src="<?= e(mediaUrl('front/imgs/logo.png')) ?>">
  → mediaUrl($path,$variant)            module-dms/src/helpers.php (static $svc memo)
  → DocumentService::urlForPath()       String-Pfad → URL (NEU)
  → [Cache] publicPathIndex-Lookup O(1) statt folders.json/documents.json-Reads
  → buildPublicUrl(...)                 "/media/…?v=<checksum8>"  ODER null
```

## Voraussetzungen (Upload-Seite, nicht Code)

- Bild-Dokument deliveryMode **`public`** (sonst nicht unter `/media` materialisiert; `publicUrl`
  prüft das NICHT — baut blind).
- Logo mit **`showOriginal`** hochladen (kein GD-Reprocessing eines PNG durch JPEG q90).

## Schritte

### Schritt 1 — Helper + `urlForPath()` (naiv, ohne Cache) — **FERTIG (2026-07-12)**

Ziel: Logo erscheint, Interface steht. Cache kommt in Schritt 2 transparent dahinter.

- `packages/module-dms/src/helpers.php` — globale `mediaUrl(string $path, ?string $variant = null): ?string`,
  memoized `static $svc = DocumentService::create()`, `function_exists`-Guard. **gebaut.**
- `packages/module-dms/composer.json` — `autoload.files: ["src/helpers.php"]` ergänzt. **gebaut.**
- `DocumentService::urlForPath(string $path, ?string $variant = null): ?string` — `explode('/')` +
  Leerstring-Filter → `resolve()` → `publicUrl()`. Naiv (nutzt bestehende, ungecachte `resolve`). **gebaut.**
- **User-Aktion (offen):** `composer dump-autoload` im Projekt/Skeleton, damit die globale `mediaUrl()`
  via `autoload.files` gezogen wird (die `urlForPath`-Methode wirkt sofort via PSR-4).
- **Verify:** throwaway CLI-Smoke im Skeleton (`front/imgs/logo.txt` über `forModule`-Pfad geseedet) —
  **5/5 grün**: `urlForPath` hit → `/media/front/imgs/logo.txt?v=<8>`, miss → `null`, führende/nachlaufende
  Slashes toleriert, `mediaUrl()` delegiert identisch, `mediaUrl` miss → `null`. `php -l` sauber,
  composer.json valides JSON. Smoke + Seed-Daten (`data/documents`, `data/blobs`) wieder entfernt.

### Schritt 2 — DMS-scoped Resolve-Cache (Punkt 1) — **FERTIG (2026-07-12)**

Ziel: Miss-Render = 1× Folder-Index + 1× Doc-Index laden, dann O(1) pro Bild; cross-request via APCu.

**Gebaut:** `DocumentService` bekam `DataCache`-Dep (via `create()`: `DI::getCacheManager()->data()`) +
`CACHE_NS = 'DmsMediaUrl'`. Neu: `folderSlugIndex()` (reduziert `id→{parentId,slug}`, cached),
`publicPathIndex()` (`"relPath/slug.ext" → {relPath,slug,ext,checksum,variants}`, cached, Ambiguität wie
`resolve()` weggelassen), `slugPathForFolder()` (entity-freier Zwilling von `folderSlugPath`). URL-Assembly
in private `buildPublicUrl()` gezogen — EINZIGE Format-Quelle, genutzt von `publicUrl(Document)` UND
`urlForPath()`. `urlForPath()` nur noch Index-Lookup + `buildPublicUrl`. `variantExt(Document)` delegiert an
neue statische `variantExtFromMap()`. Invalidierung: keine neue — bestehendes `invalidatesCache` deckt es.
**Verify:** CLI-Smoke **5/5** — `urlForPath`↔`publicUrl` byte-identisch, Cache-Hit ignoriert rohen
`documents.json`-Edit (aus Cache bedient), Rebuild nach DMS-Write (neuer `?v=`), Miss → `null`. `php -l`
sauber. Smoke + Seed entfernt.

- `DocumentService` bekommt `DataCache` (via `create()`: `DI::getCacheManager()->data()`), eigene
  `CACHE_NS`.
- Cached, reduziert (kein Entity in APCu), `cachePersist: true`:
  - Folder-Index: `id → {parentId, slug}` + `driveRootId` (für den Slug-Pfad-Walk).
  - Public-Path-Index: `"<folderslugpath>/<slug>.<ext>" → {folderSlugPath, slug, ext, checksum,
    variants:{name:ext}}` über alle live Docs (Ambiguität = weglassen, wie `resolve()`).
- `buildPublicUrl(...)` refactor (Design-Entscheid 6); `urlForPath()` nutzt den Index + `buildPublicUrl`.
- Invalidierung: bereits gedeckt durch `invalidatesCache` auf `Document`/`Folder` → `clearAllApcu()`.
  Kein neuer Invalidierungs-Code nötig; verifizieren dass der Index nach einem Write neu gebaut wird.
- **Verify:** CLI-Smoke — 1. Aufruf baut Index (Files gelesen), 2. Aufruf reiner APCu-Hit; nach einem
  `saveGenerated`/`replace` ist der Index neu (neuer `?v=`); N-Bilder-Auflösung = 1 Folder-+1 Doc-Load.

### Schritt 3 — Doku — **FERTIG (2026-07-12)**

- `documents.md`: file map (`helpers.php`), zwei neue `## rules` (`mediaUrl()`-Nutzung + Layering;
  `publicPathIndex`/`buildPublicUrl` Cache-Regel), In-Progress-Pending auf Step-1/2-done aktualisiert. **erledigt.**
- `cache.md`: `## see also` → DMS-Resolve-Index als DataCache-Consumer (invalidatesCache deckt Invalidierung). **erledigt.**
- `view-layer.md`: `## see also` → `mediaUrl()` für DMS-Bilder. **erledigt.**
- `npm run docs:check` grün (28/28). **erledigt.**

### Schritt 4 — Projekt — **FERTIG + live-bestätigt (2026-07-12)**

- `composer dump-autoload` im Projekt → globale `mediaUrl()` aktiv. **erledigt.**
- Header-Override im `overwrite/` des Referenzprojekts (Snippet an die andere Claude-Code-Instanz übergeben). **erledigt.**
- Live-Browser-Check: Logo erscheint mit korrektem `?v=`-Token. **bestätigt (User).**
- `new-project-checklist.md` im Projekt: Phase 5b um `mediaImage()` + Verweis auf `documents.md` erweitert.

### Schritt 5 — alt/figcaption (Modell B) — **CODE FERTIG (2026-07-12); Browser-Klick offen**

Sprach-gekeyte `alt`/`caption` als dedizierte `Document`-Felder + Edit-Maske je Sprache + `mediaImage()`-Helper.

**Gebaut:** (1) Entity `Document` — Felder `alt`/`caption` (`array<lang,string>`) + `getAltMap()`/`getCaptionMap()`
+ Setter; Docblock nachgeführt. (2) `DocumentService::setImageText($id,$alt,$caption)` — gated wie `rename()`
(`authz->require(manage)`), `cleanTextMap()` (nur konfigurierte Sprachen, trim + Control-Char-Strip, leere
raus). (3) Consumer `imageForPath()` → `{url,alt,caption,width,height}` mit `localizeMap()` (aktuelle Sprache →
i18n-Default → leer, `t()`-Reihenfolge); `publicPathIndex` um alt/caption/width/height erweitert (O(1)).
(4) Edit-Maske: `editVm()` (`isImage`/`languages`/`altMap`/`captionMap`), `_edit.tpl.php` (je Sprache 1× Alt +
1× Bildunterschrift, `kind=image`, flache Feldnamen `alt_<lang>`/`caption_<lang>`), `applyEditSave()`
(Map-Reassembly, changed-only). (5) Global `mediaImage()` in `helpers.php`.
**Verify:** CLI-Smoke **10/10** — kind, Round-Trip, `imageForPath` lokalisiert, Dimensionen, `mediaImage`
Parität/Miss, aktuelle-Sprache-Pick, fr-only-unter-de → `''`, Cache-Rebuild. `php -l` sauber (5 Dateien).
Smoke + Seed entfernt.
**Offen:** gated `setImageText` + Modal-Verdrahtung braucht Live-Admin-Browser-Klick (CLI hat keine Session —
gleiche Grenze wie die anderen DMS-Admin-Flows; das Gate spiegelt das bewährte `rename()`-Gate).

### Schritt 4 — Projekt (User, optional/separat)

- Header-Override im `overwrite/` des Referenzprojekts: Text-Brand `z77` durch
  `<?php if ($logo = mediaUrl('front/imgs/logo.png')): ?><img …><?php endif; ?>` ersetzen.
  Gehört ins Projekt, NICHT in den Framework-Default-Header (kein projektspezifischer Media-Pfad im
  Framework).

## Offen / Risiken

- Index-Drift Delivery (`resolve`) ↔ URL-Bauer (`publicPathIndex`): beide aus denselben Quellen
  (folders + live docs) bauen, beide via `invalidatesCache` gedroppt. Bei Divergenz-Verdacht:
  Index-Build gegen `resolve()`-Semantik prüfen (Ambiguität, soft-deleted).
- Verifikation ist CLI-Smoke (kein Admin-Browser-Login hier) — manueller Browser-Klick bleibt offen,
  analog anderer DMS-Baupläne.
