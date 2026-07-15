# dokumentenverwaltung-bauplan.md — DMS (Folder, Document, Upload/Save, Cross-Modul-Service)

**Status:** IN UMSETZUNG. OPEN-1…8 entschieden (ADR-016). **Phasen 1–6 fertig** (Kern, Metadaten, Speicher-Pfad, Fassade+Auslieferung, Backend-UI+Routen, Verschicken/Mailer) — alle `php -l` grün, Byte- + Mail-Pfad e2e per CLI-Smoke grün (Mail gegen Fake-SMTP-Loopback), HTTP-Routing per curl geprüft, `docs:check` grün. **⏸ PAUSE 2026-06-15. Nächster Schritt: Phase 7 (Modul-Integrationsbeispiel Fakturen).** Detaillierter Stand pro Datei: Topic-Docs [`../topics/documents.md`](../topics/documents.md) + [`../topics/mail.md`](../topics/mail.md). Bindender Entscheid: [`../02-decisions/adr-016-document-management-storage-and-delivery.md`](../02-decisions/adr-016-document-management-storage-and-delivery.md).
**Datum:** 2026-06-10 (Entscheide + Phasen 1–4: 2026-06-15; Phase 5: 2026-06-15; Phase 6: 2026-06-15)

> **Resume-Hinweis:** Vor Phase 7 → Topic-Docs `documents.md` + `mail.md` lesen (file map = aktueller Code-Stand, `## pending` = nächste Schritte). Offene Härtungen: `SaveService` Orphan-Cleanup bei Blob-Fehler; Installer-Seeding von `config/mail.inc.php`. Offene manuelle Checks: authentifizierter Upload über die Backend-UI; echtes SMTP-Relay konfigurieren + Dokument verschicken (beide Stacks sind e2e bzw. gegen Fake-SMTP geprüft, nur die Live-Wege mit Login/Relay noch nicht).
**Scope:** Eine Dokumentenverwaltung als Framework-Fundament für Projektmodule: Dateien im Browser hochladen, aus jedem Modul speichern (auch generierte, z.B. Faktura-PDFs), in einer Ordnerstruktur organisieren, typisieren (folder/pdf/image/json/html/text/word/excel …), verwalten (Ordner + Dokumente: add/move/delete) und einem Modul als Service bereitstellen (anzeigen/löschen/verschieben/verschicken).

> Quelle der Vorgaben: User-Briefing 2026-06-10 (7 Bausteine) + Sichtbarkeits-Entscheid OPEN-7 (2026-06-15). Diese Doku ist der Arbeitsstand. Volle Begründungen → ADR-016.

---

## Kern-Entscheidungen (mit User abgestimmt)

1. **Zwei getrennte Welten: Metadaten ≠ Blobs.**
   - Metadaten (Ordnerbaum, Dokument-Records) → Persistenz-Layer (`#[Entity]`).
   - Blobs (die Bytes) → **neuer** `BlobStorage`, getrennt vom Metadaten-Treiber.
   - `FileStorage` (JSON-only) wird **nicht** für Bytes missbraucht.
   - Logisches Verschieben/Umbenennen = nur Metadaten, nie Datei-Move.

2. **Metadaten: File-first, strikt Doctrine-ready.**
   - Volumen mittel (~3'000–10'000 Records kumuliert) → kreuzt die File-O(n)-Grenze (ARCH-A001, ~5k) **absehbar**.
   - Folge: Doctrine-Switch ist ein **geplanter Meilenstein**, kein „falls mal". Doctrine-Treiber muss dafür zuerst gebaut werden (existiert noch nicht). Switch dann = nur `#[Entity]`-Attribut + ggf. ORM-Mapping-Attribute.
   - Entities ab Tag 1 Doctrine-tauglich designen (server-kontrollierte Felder, keine File-Eigenheiten).

3. **Mail: jetzt mitdenken, bauen in Phase 6.** „Verschicken" ist Teil des Designs, aber nicht des Kerns. Mail-Fähigkeit existiert im Framework noch nicht (nur `EmailFilter` fürs Cleaning).

4. **Speicherort ≠ Sichtbarkeit (OPEN-7).** Alle Bytes — privat wie öffentlich — liegen ausschliesslich im `BlobStorage` ausserhalb `public/`. „Öffentlich" ist reine Metadaten-Eigenschaft, keine zweite physische Ablage. Auslieferung immer über `DocumentService::serve()`.

---

## Entschiedene Punkte (OPEN-1…7)

Volle Begründungen + verworfene Alternativen → ADR-016. Hier nur das Ergebnis.

### OPEN-1 — Blob-Layout  ✅ B (ID-basiert)

`data/blobs/<shard>/<documentId>.<ext>`. Move/Rename = nur Metadaten, kein Datei-Move, kein Refcount, **kein User-Input im Blob-Pfad** (Path-Traversal strukturell ausgeschlossen).
- Entscheidender Kipp-Punkt: **Ordner-Reparent-Asymmetrie.** Einen Ordner mit 1'000 Dokumenten umhängen kostet bei B **null** Datei-Operationen (nur `parentId`), bei pfad-gespiegeltem Layout C = 1'000 echte Moves auf transaktionslosem File-Treiber (Halbzustände).
- Folgen aus B: Platte ohne DB nicht zuordenbar (akzeptiert), kein Dedup (akzeptiert), keine eingebaute Integrität → **`checksum`-Feld** (sha256) trägt sie.
- Read-only Export-Mirror (menschlich lesbares Archiv) bleibt als **spätere optionale Phase** möglich, ohne Datenmodell-Änderung.

### OPEN-2 — `area`-Schema  ✅ a (Framework erzwingt)

`area` = Modul-Key + Config-Flag, Modul meldet Eigentümerschaft an — exakt das `viewArea`-Anmeldemuster. Erzwungene Isolation zwischen Modulen einer Installation, kleiner Framework-Zusatz, konsistent mit bestehendem Muster. Braucht ein Modul mehrere Bereiche → Modul-Key + optionaler Sub-Scope.

### OPEN-3 — Document-Felder  ✅ (inkl. `deletedAt` + `retentionUntil`)

Felder-Liste unten. Erweiterung gegenüber Entwurf:
- **`deletedAt`** (datetime|null) — Soft-Delete. Geschäftskritische/aufbewahrungspflichtige Dokumente werden nicht hart gelöscht.
- **`retentionUntil`** (date|null) — gesetzliche Aufbewahrungsfrist (CH: Fakturen/Buchhaltung oft 10 Jahre). Hard-Delete/Purge MUSS diese Frist respektieren.
- optionales `meta`-Freifeld (array) für modul-spezifische Daten (z.B. Rechnungsnummer) — vermeidet späteren Schema-Bruch.

### OPEN-4 — Storage-Modus `Document`  ✅ Collection-Mode

Nur Collection-Mode liefert die auto-increment `id`, die als Blob-Key dient (Document-Mode hätte keine → UUID nötig). Whole-File-Rewrite pro Upload verkraftbar, weil Doctrine-Switch vor kritischem Volumen geplant.

### OPEN-5 — Mail-Transport  ✅ Eigenbau (Phase 6)

SMTP/MIME/TLS selbst gebaut — hat sich im WDV bewährt, hält „keine unnötigen Dependencies". Erst Phase 6.

### OPEN-6 — Versionierung  ✅ out of scope v1

Kein Overengineering. Ersetzen = Bytes überschreiben (gleiche `id`/Blob-Key, `checksum`/`updatedAt` neu).

### OPEN-7 — Sichtbarkeit & Auslieferung (Public vs. Private)  ✅ Metadaten-gesteuert

**Grundsatz:** Speicherort und Sichtbarkeit sind getrennt. Eine physische Ablage (`BlobStorage`), Sichtbarkeit nur über Metadaten. Der frühere Ansatz „private storage → temporär nach `public/` kopieren → ausliefern" wird verworfen (unnötige I/O, Cleanup-/Race-Probleme, inkonsistente Löschzustände, Duplikation).

**Datenmodell-Erweiterung:**
- **`visibility`** (`private` | `public`), Default `private` (sichere Vorgabe, server-kontrolliert).
- **`publicPath`** (string|null) — stabiler, vom internen Blob-Pfad **unabhängiger** URL-Schlüssel. Nur gültig bei `visibility=public`.

**Auslieferung — immer über `DocumentService::serve()`:**

| Route | Zweck | Ablauf |
|---|---|---|
| `GET /documents/{id}/download` | privat | laden → `deletedAt IS NULL` → Berechtigung → delegieren |
| `GET /media/{publicPath}` | öffentlich | über `publicPath` auflösen → `visibility=public` + `deletedAt IS NULL` → delegieren |

> **Abgelöst (2026-06-23, ADR-017):** Die Webserver-Delegation unten (`X-Sendfile`/`X-Accel-Redirect`) ist **verworfen** — nicht portabel (cyon hat kein `mod_xsendfile`), der PHP-Range-Stream genügt. Byte-Übertragung ist jetzt **immer** der portable PHP-Range-Stream; `FileResponse` hat keinen `delivery`-Schalter mehr.

**Übertragung — portabel (Präzisierung gegenüber OPEN-7-Entwurf):** Grosse Dateien (Videos, ZIPs) streamt PHP **nicht selbst**, wenn der Webserver es kann — `serve()` delegiert via `X-Accel-Redirect` (Nginx) / `X-Sendfile` (Apache). PHP macht nur Auth/Autz/Metadaten-Auflösung/Logging. **Aber:** ist keine Webserver-Delegation verfügbar (PHP-Built-in-Server, Dev, Shared Hosting, Windows), fällt `serve()` auf einen **PHP-Stream mit Range-Support** (`Accept-Ranges`/`Content-Range`, chunked) zurück. Auslieferungsstrategie = Config, nicht in Stein (CLAUDE.md: Portabilität ist harte Anforderung).

**Sicherheits-Präzisierungen:**
- `publicPath` ist **Lookup-Key, nie Filesystem-Pfad** — wird nie konkateniert, nur als Gleichheits-Lookup aufgelöst (Unique-Index). Sanitisierung beim **Setzen**, nicht beim Lesen. `/media/{publicPath}`-Route braucht ein Catch-all-Segment (Slashes erlaubt). Blob-Pfad bleibt ID-basiert → kein Traversal auf der Platte.
- Constraint: `publicPath NOT NULL ⇒ visibility=public`. Wechsel public→private oder Soft-Delete sperrt die `/media/...`-Auslieferung.
- Public-Auslieferung darf Cache-Header setzen (`ETag` aus `checksum`, `Cache-Control: public, immutable`).

**Abgrenzung — ins DMS:** Slider-/Blogbilder, Downloads, PDFs, Videos, generierte Dokumente. **Nicht ins DMS:** CSS, JS-Bundles, Fonts, Build-Artefakte, App-Assets — die bleiben direkt unter `public/`.

**Zukunft:** Bei hoher Last optional read-only Mirror / CDN. Quelle der Wahrheit bleibt `BlobStorage` + Document-Metadaten; Auslieferungsstrategie darf sich ändern, ohne Datenmodell-/API-Bruch.

### OPEN-8 — Bild-Derivate (Profile)  ✅ entschieden 2026-06-15

Bilder bekommen **vor-generierte** Derivate (eager), nicht on-the-fly — schnellster Serve (statischer Byte-Serve via X-Sendfile) + responsive `srcset` braucht diskrete Breiten ohnehin. On-the-fly lohnt nur bei beliebigen Grössen (Image-Proxy). Bibliothek: **GD** (PHP-Extension, kein Composer-Dep; WebP/AVIF im Dev-Env verfügbar).

**Profile — Config-getrieben** (kein fixer Enum). Benannte Grössen-Sätze in Config:
```text
imageProfiles:
  admin:   { s:{w:160}, m:{w:480}, l:{w:1024}, xl:{w:2048} }                        # framework-fix
  slider:  { mobile:{w:768}, tablet:{w:1280}, desktop:{w:1920}, preserveOriginal:true }
  team:    { thumb:{w:200,h:200,fit:cover}, full:{w:600} }
```
- **`admin`** = framework-fix (Listen-Thumb + Vorschau-Pane fürs Verwaltungstool), wird für **jedes** Bild generiert → Tool ist universell browsbar. Built-in in `ImageProfileRegistry`, nicht projekt-konfigurierbar.
- **Projekt-Profile** (`slider`, `team`, …) frei definierbar, beliebig viele. Upload wählt das Profil (`SaveService::save(bytes, meta, profile: 'slider')`); generiert wird `admin` + gewähltes Profil. Ohne Angabe → nur `admin`.

**Config-Verortung (User-Bedingung): pro Modul.** Jedes Modul, das Bilder braucht, legt **optional** `src/App/Config/imageProfilesConfig.inc.php` an (Modul ohne Bilder = keine Datei). `ImageProfileRegistry::fromModules()` aggregiert über alle Modul-Keys, **area-scoped** (Modul-Key = `area`) → keine Namens-Kollisionen zwischen Modulen (`frontend.logo` ≠ `backend.logo`). Beispiel `frontendConfig`-Nachbar `imageProfilesConfig.inc.php`:
```php
return [
  'slider' => ['mobile'=>['w'=>768], 'tablet'=>['w'=>1280], 'desktop'=>['w'=>1920], 'preserveOriginal'=>true],
  'team'   => ['thumb'=>['w'=>200,'h'=>200,'fit'=>'cover'], 'full'=>['w'=>600]],
];
```

**Schärfe vs. GD-Schwäche — `showOriginal` (per Dokument) + `preserveOriginal` (per Profil):** GDs Encoding ist schwächer als Profi-Tools. Bei `showOriginal=true` ODER Profil `preserveOriginal:true` wird das Bild **nicht reprozessiert** — Front-End liefert Original-Bytes 1:1; nur `admin.s` (160px) wird für die Tool-Liste generiert. **Trade-off (bewusst):** kein responsives Downscale → Mobile lädt das Original. Richtig für handgemachte Bilder (Hero/Slider). Per-Dokument-Flag überschreibt; Profil-Flag setzt den Default für alle Bilder des Profils.

**GD-Default-Qualität** (Standardpfad, `showOriginal=false`): `imagecopyresampled`, JPEG-Q 90–92, Unsharp-Mask nach Downscale — schliesst den sichtbaren Abstand bei Downscales.

**Format v1** = Quellformat (jpg→jpg). `format` pro VariantSpec (WebP/AVIF) ist spätere Opt-in-Stufe.

**Reprocess:** Profil-Änderungen sind **nicht retroaktiv** (Alt-Bilder behalten alte Breiten). Ein Reprocess-Kommando ist spätere Phase — hier dokumentiert, damit niemand „Profile sind retroaktiv" annimmt.

**Blob-Layout (Verfeinerung OPEN-1/B — keine Abkehr): per-`id`-Verzeichnis**
```text
data/blobs/<shard>/<id>/orig.<ext>
data/blobs/<shard>/<id>/{s,m,l,…}.<ext>     # nur kind=image, profil-definierte Namen
```
Weiterhin ID-basiert → Blob-Key = `id` (Verzeichnis), Varianten per Name aufgelöst. **Move = nichts**, **Purge = Verzeichnis löschen** (alle Varianten auf einmal). `BlobStorage` wird variant-aware (`put/get/path(id, variant)`, `delete(id)`), bleibt aber profil-agnostisch — Profile leben in `shared/Documents/`.

---

## Begriffe (Code / UI)

- **Code:** `BlobStorage`, `DocumentKind`, `Folder`, `Document`, `SaveService`, `UploadService`, `DocumentService`, `FolderService`, `Mailer`.
- **UI (Deutsch):** „Ablage" / „Dokumente" / „Ordner" / „Verschicken". (UI-Wording offen, hier nur Platzhalter.)

---

## Datenmodell (Doctrine-ready)

**`Folder`** `implements TreeNode` (`use TreeNodeTrait` solange File):
```text
id, parentId, sortKey, name, area, system(bool)
```
- `area` partitioniert die Top-Level-Roots via `new TreeService(fn(Folder $f) => $f->getArea())` — exakt wie Navigation/Render-Slots (siehe ../topics/tree.md). `area` = Modul-Key (OPEN-2/a).
- `system=true` = vom Modul angelegter, geschützter Ordner (z.B. „Fakturen"); kein User-Delete.

**`Document`** (Collection-Mode, OPEN-4):
```text
id, folderId(FK), area, displayName, originalName, ext, mimeType,
kind, sizeBytes, checksum(sha256), source(uploaded|generated),
visibility(private|public), publicPath(string|null),
retentionUntil(date|null), deletedAt(datetime|null),
profile(string|null), showOriginal(bool), variants(map|null),
meta(array|null), createdBy, createdAt, updatedAt
```
- **Server-kontrolliert (kein `#[Clean]`):** `kind, mimeType, sizeBytes, checksum, area, source, visibility, publicPath, retentionUntil, deletedAt, profile, variants`. Muster wie `parentId/sortKey`, `passwordHash`. (`showOriginal` editierbar im Tool, aber nicht clean-frei zu vertrauen → server-validiert.)
- `profile`/`variants`/`showOriginal` nur relevant bei `kind=image` (OPEN-8). `variants` = Map `{name:{w,h,bytes}}` fürs `srcset`.
- `checksum` trägt die Integrität, die Layout B nicht eingebaut hat (OPEN-1).
- Blob-Key = die Dokument-`id` selbst (Layout B); **kein** separates `storageKey`-Feld (es würde nur `id` duplizieren). `BlobStorage` ist per `int $id` adressiert.
- `publicPath` Unique-Index; nur gesetzt wenn `visibility=public` (OPEN-7).
- Versionierung out of scope (OPEN-6).

---

## Service-Landschaft

```text
Consumer: Backend-UI  |  Projektmodule (Fakturen, Bilanzen, ER)
                 │ injizieren
                 ▼
          DocumentService            ← öffentliche Fassade (einziges Modul-API)
   ┌─────────┬──────────┬──────────┬───────────┐
   ▼         ▼          ▼          ▼           ▼
FolderSvc  SaveSvc   DocumentKind  Mailer   UploadSvc
(TreeSvc)  │  │      (Klassif.)   (Ph.6)   (HTTP, Backend)
           ▼  ▼
      BlobStorage  UEM(Folder/Document-Repos)
```

- **`DocumentService`** — `listByFolder/area`, `get`, `serve` (privat/öffentlich, inline/download, Webserver-Delegation + PHP-Fallback), `delete` (soft, respektiert `retentionUntil`), `move`, `saveGenerated(bytes,…)`, `publish(id, publicPath)` / `unpublish(id)`, `send(id,recipients)`. Einziges API, das Module sehen.
- **`SaveService`** — quellenunabhängig: validierte Bytes + Meta → `BlobStorage` + `Document` via `UEM`. Bedient Upload UND generierte Dateien.
- **`UploadService`** — HTTP-agnostisch: konsumiert fertige `UploadedFile`-VOs → Validierung → `SaveService`. Der `$_FILES` → `UploadedFile`-Bridge lebt in `core/Http/Request` (Verfeinerung C, siehe Verortung). So bleibt `UploadService` bei den anderen Domain-Services in shared, ohne ein HTTP-Runtime-Objekt zu importieren.
- **`BlobStorage`** — Bytes-I/O, treiber-fähig (lokales FS jetzt, später S3 analog Persistenz-Philosophie).
- **`DocumentKind`** — Enum/Registry: MIME+Extension → Typ + Icon + `previewable` + `mailable`. Ist gleichzeitig die Allowlist.
- **`Mailer`** (Phase 6) — Eigenbau: Interface + `Transport` (SMTP) + `Message`-VO (`to, subject, body, attachments[]`). `DocumentService::send()` baut Message mit Blob als Attachment.

---

## Verortung (Package-Layout)

Abgeleitet aus bestehenden Mustern (nicht erfunden). Volle Begründung → ADR-016 „Placement".
Drei Regeln, die der Code vorgibt: `z77/shared` = treiber-agnostische Domäne, **nach Art**
aufgeteilt (`Entities/`, `Repositories/`, `Validators/`, `Services/`, `ValueObjects/`),
feature-spezifische Maschinerie in einem **Feature-Namespace** (Präzedenz `Z77\Shared\Content\`).
`z77/persistence` = wo Daten physisch liegen. `z77/core` = HTTP/Runtime. Module = Controller.

| Komponente | Package → Pfad | Analog |
|---|---|---|
| `Folder`, `Document` (Entities, Collection-Mode) | shared → `src/Entities/` | `Content.php` |
| `FolderRepository`, `DocumentRepository` | shared → `src/Repositories/` | `ContentRepository.php` |
| `FolderValidator`, `DocumentValidator` | shared → `src/Validators/` | `ContentValidator.php` |
| `DocumentKind` (Enum/Registry + Allowlist) | shared → `src/Documents/` (neuer Feature-NS) | `src/Content/BlockRegistry.php` |
| `VariantSpec`, `ImageProfile`, `ImageProfileRegistry` (Config-Profile) | shared → `src/Documents/` | `src/Content/BlockRegistry.php` |
| `ImageProcessor` (Interface) + `GdImageProcessor` | shared → `src/Documents/` | — |
| `DocumentService`, `SaveService`, `UploadService`, `FolderService` | shared → `src/Services/` | `ContentService.php` |
| `UploadedFile` (VO) | shared → `src/ValueObjects/` | `UserPreferences.php` |
| `Mailer`, `SmtpTransport`, `Message` (Ph. 6) | shared → `src/Mail/` (neuer Feature-NS) | — |
| `BlobStorage` + `LocalBlobStorage`, später `S3BlobStorage` | persistence → `src/Blob/` (neuer NS) | `File/Storage/FileStorage.php` |
| `Request` Upload-Support; `FileResponse` inline + Range | core → `src/Http/` | bestehende Dateien erweitern |
| `FolderController`, `DocumentController` | module-backend → `src/Ui/Controllers/Documents/` | `Controllers/Content/Navigation*` |
| `MediaController` (`GET /media/{publicPath}`) | module-frontend | öffentlicher Endpunkt |
| Blobs physisch: `data/blobs/<shard>/<id>.<ext>` | RUNTIME (installierte App) | `data/content/…` |

**Entscheide (A/B/C):**
- **A** — `BlobStorage` nach `persistence/Blob/` (nicht core): Storage-Treiber neben `FileStorage`, eigener NS weil Bytes ≠ JSON-Metadaten.
- **B** — Domain-Services on-demand via `::create()` an der Consumer-Grenze (wie `ContentService`, ADR-012), **kein** DI-Singleton. Nur `BlobStorage` + `UnifiedEntityManager` sind Infrastruktur.
- **C** — `UploadService` HTTP-agnostisch; `$_FILES` → `UploadedFile`-Bridge in `core/Http/Request`.

---

## Sicherheit (querschnittlich, konzentriert in Upload/Save + serve)

- `finfo`-MIME-Sniffing — Client-Typ nie vertrauen.
- Extension/MIME-Allowlist über `DocumentKind`.
- Grössenlimit (Config).
- Speicherung unter `data/` (ausserhalb `public/`); Blob-Zugriff NUR via `DocumentService::serve()`, nie direkte URL.
- CSRF via Fetch-Envelope (vorhanden).
- **Path-Traversal:** bei Layout B/ID strukturell ausgeschlossen (kein User-Input im Blob-Pfad). `publicPath` ist Lookup-Key, nie Filesystem-Pfad → kein Traversal trotz pfadartigem String (OPEN-7).
- **Auslieferung:** `serve()` prüft IMMER `deletedAt IS NULL`; öffentliche Route zusätzlich `visibility=public`. Webserver-Delegation nur auf den intern aufgelösten Blob-Pfad, nie auf User-Input.
- `FileResponse` braucht inline-Variante für „anzeigen" (aktuell hart `attachment`, packages/kernel/core/src/Http/Response/FileResponse.php) + Range-Support für den PHP-Fallback-Stream.

---

## Phasen & Pausepunkte

Jeder Pausepunkt = lauffähiger Zustand.

### Phase 0 — Entscheidungen + Doku  ✅ ABGESCHLOSSEN (2026-06-15)
- [x] Grundsätze 1–4 abgestimmt.
- [x] OPEN-1…7 festgeklopft.
- [x] ADR-016 erstellt.
- [ ] Topic-Doc `docs/topics/documents.md` — folgt mit Phase-1-Code (Linter braucht existierende SOURCE-Pfade).

### Phase 1 — Kern ohne UI
- [x] `BlobStorage` + `LocalBlobStorage` → `persistence/src/Blob/` (variant-aware: `put/get/path/size(id,variant,ext)`, `delete(id)`, per-`id`/Shard-Verzeichnis). Smoke-getestet.
- [x] `DocumentKind` → `shared/src/Documents/` (Klassifikation + Allowlist; `previewable`/`hasImageVariants`/`icon`; `mailable` bewusst auf Phase 6 verschoben).
- [x] `VariantSpec` + `ImageProfile` + `ImageProfileRegistry` → `shared/src/Documents/` (Config-Profile, OPEN-8; area-scoped, `admin` built-in). Smoke-getestet.
- [x] **Topic-Doc `docs/topics/documents.md` angelegt** (grün, echte SOURCE-Pfade). Wächst ab hier pro Phase mit.

### Phase 2 — Metadaten
- [x] `Folder` (TreeNode + TreeNodeTrait, area-scoped) + `FolderRepository` (`findByArea`). Round-trip-getestet.
- [x] `Document` (Collection-Mode, inkl. `visibility`, `publicPath`, `deletedAt`, `retentionUntil`, `profile`, `variants`, `showOriginal`) + `DocumentRepository` (`findByFolder`/`findByArea`/`findByPublicPath`). Round-trip-getestet.

### Phase 3 — Speichern
- [x] `Request`-Upload-Support (`getUploadedFiles`/`getUploadedFile`) + `UploadedFile`-VO (`shared/ValueObjects`).
- [x] `UploadService` (HTTP-agnostisch, Validierung+Allowlist) + `SaveService` (quellenunabhängig, wählt `profile`, Zwei-Phasen-Save).
- [x] `ImageProcessor` + `GdImageProcessor` (kind=image → `admin` + Profil-Varianten; respektiert `showOriginal`/`preserveOriginal`; downscale-only, resampled/Q90/Unsharp). Smoke-getestet.
- [x] Ergebnis: Datei programmatisch UND per HTTP ablegbar, Bild-Derivate generiert. (E2E-Persistenz-Test offen bis Controller-Wiring Phase 5.)

### Phase 4 — Fassade + Auslieferung
- [x] `DocumentService` (list/get/serve/servePublic/delete/move/saveGenerated/publish/unpublish). Lint grün, E2E offen bis Phase 5.
- [x] `FileResponse` inline-Variante + Range-Support (`206`/`416`/`304`-ETag). Range-Streaming smoke-getestet.
- [x] `serve()`: Webserver-Delegation `X-Sendfile` (abs. Pfad) + PHP-Fallback-Stream (Default). `X-Accel-Redirect` ist `FileResponse`-fähig, in `DocumentService` noch nicht verdrahtet (deployment-spezifisches internal-URI-Mapping).
- [→ Phase 5] Routen `/documents/{id}/download` + `/media/{publicPath}` — gehören zu den Controllern (module-backend / module-frontend).

### Phase 5 — Backend-UI  ✅ ABGESCHLOSSEN (2026-06-15)
- [x] Neue Backend-Gruppe `documents` (`backendConfig`: `groupDefaults` + group-nested `controllers`, alle ADMIN).
- [x] `FolderController extends AbstractTreeEntityController` (area-scoped Tree, beliebige Tiefe, DnD; leer-/System-Ordner-Guards beim Löschen).
- [x] `DocumentController` (Liste pro Ordner, Multipart-Upload, Inline-Vorschau/Download, Umbenennen, Verschieben, Veröffentlichen/Zurückziehen, Soft-Delete). `DocumentService::rename()` ergänzt.
- [x] `MediaController` (module-frontend, Gruppe `media`, GUEST) — `GET /media/{publicPath}` über geseedeten `/media`-NavigationAlias (Remainder = `publicPath` via `getSlugs()`).
- [x] Templates (Folder/Document: listAction + edit/upload/move/publish/confirmDelete) + JS (`documents/folder-tree.js` DnD, `documents/upload.js` Multipart-Upload im Modal).
- [x] Nav/Alias-Seed (`navigation.default.json` + `navigation_aliases.default.json` + Live-Skeleton): Backend-Sektion „Ablage" + `/media`-Alias.
- [x] Verifiziert: Byte-Pfad e2e (CLI-Smoke, 17 Checks grün), HTTP-Routing (curl: Backend→302, `/media/x`→404, keine Fatals). Offen: Live-Upload mit Login durchklicken.

### Phase 6 — Verschicken  ✅ ABGESCHLOSSEN (2026-06-15)
- [x] `Mailer` + `MailTransport` + `SmtpTransport` + `Message` + `Attachment` + `MimeMessage` (Eigenbau, keine Dependency) → `shared/src/Mail/`. SMTP mit `tls`/`ssl`/`none`, `STARTTLS`, `AUTH LOGIN`, Dot-Stuffing; MIME multipart/mixed+alternative, base64, RFC2047. CRLF-Injection am VO-Rand abgewiesen.
- [x] `DocumentKind::mailable()` (alles ausser video/audio).
- [x] `DocumentService::send(id, recipients, subject, body, variant?)` — `mailable`-Gate, Blob-Bytes als Anhang, `Mailer::create()`.
- [x] Config: `Mailer` liest `config/mail` (`getBaseConfig`, `throwError:false`); fehlt/`enabled=false` → klarer Fehler. Sample `skeleton/config/mail.inc.php` (deaktiviert).
- [x] Backend-UI: `DocumentController::sendAction` + `send`-Modal + `icon-mail` + backendConfig.
- [x] Verifiziert: 20-Punkte-CLI-Smoke grün (Validierung, `mailable`, MIME-Build, unconfigured-Fehler, **echte SMTP-Konversation gegen Fake-Loopback inkl. Dot-Stuffing**), `send`-Route HTTP 302. Offen: Live-Relay + Installer-Seeding der Mail-Config.

### Phase 7 — Integration
- [ ] Modul-Integrationsbeispiel (Fakturen): generieren → speichern → anzeigen/verschicken.

---

## Reuse vs. Neu (Bausteinbilanz)

| Baustein | Status |
|---|---|
| Tree-Foundation (Folder-Hierarchie, DnD) | Reuse (../topics/tree.md) |
| `UnifiedEntityManager` / Repository-Pattern | Reuse (../topics/persistence-architecture.md) |
| `AbstractTreeEntityController` (move/reorder) | Reuse (../topics/backend.md) |
| Modal/Fetch/CSRF-Envelope | Reuse (../topics/fetch.md) |
| `FileResponse` (Download) | Reuse + inline- & Range-Erweiterung |
| `BlobStorage` (Bytes) | NEU |
| `Request`-Upload-Support | NEU |
| `DocumentKind` | NEU |
| `Folder` / `Document` Entities | NEU |
| `UploadService` / `SaveService` / `DocumentService` | NEU |
| `Mailer` (Phase 6, Eigenbau) | NEU |
