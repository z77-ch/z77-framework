# dms-umbauplan.md — Umbau auf Besitz + ACL + dms-Modul (löst Phasen 1–7 von ADR-016 ab)

**Status:** IM BAU — **R1–R5 abgeschlossen + verifiziert (R1+R2a 2026-06-22, R2b+R3+R4 2026-06-23,
Live-HTTP-Test 2026-06-29, R5 2026-06-29) → PAUSE.** R4 Code fertig; `composer update z77/module-dms`
gelaufen (`module-dms` in Lock + `moduleManager.inc.php` registriert), Live-`/media`-Test grün (s. R4c).
R5 (DocumentService-Fassade) fertig: `grant`/`revoke`/`setActive`, `effectiveDeliveryMode`,
struktureller `resolve()`, `serve()`-`cacheControl`-Override — alle vorhanden; `publish`/`unpublish`/
`visibility` raus, `visibility`/`publicPath`-Felder aus `Document` entfernt; save-seitige
Doc-Slug-Dedup pro Folder+Ext ergänzt; CLI-Smoke 18/18 grün. **Public-Materialisierung
(`public/media/…`) bleibt offen → R6** (Verwaltungs-Surface, wo `setDeliveryMode` + der
Materialisierungs-Job hingehören). Nebenbefund + Fix: `DataCache::clearAllApcu()` leerte den
prozesslokalen Lese-Tier nicht → Read-after-Write im selben Request lieferte den Stale-Wert (jetzt
behoben; s. R5-Block). **R6 läuft:** Shell-Entscheid via ADR-018 geklärt (eingebettetes `.dms`-Fragment,
kein eigener view-area); **R6a fertig (2026-06-29)** — `.dms`-Token-Set + `dms.css` + Topic-Doc
[`../topics/css-dms.md`](../topics/css-dms.md), `docs:check` grün. **R6b FERTIG (2026-06-29):**
3-Spalter-Drive (links Ordnerbaum, mitte Liste, rechts Vorschau) — `.dms`-base + `.dms-`-Komponenten
gebaut, `DriveController` + Template im Backend-Host (`/backend/documents/drive/list`), `dms.css` page-scoped
geladen + nach `public/assets/dms` publiziert, in `backendConfig` registriert. Mit Seed-Daten verifiziert
(echtes Template + Controller-VM + `dms.css` headless: `temp/dms-drive-live.png`) **und vom User live im
Browser bestätigt (2026-06-29).** Offen nur: Bild-Thumbnails live (GD fehlt). **R6c läuft (2026-06-30):**
Fetch-Pane-Updates fertig (`paneAction` + Pane-Partials + `drive.js`; `php -l` + Render-Smoke grün,
Live-Klick-Test im Browser offen). **Upload fertig (2026-07-01):** echter `[data-drive-upload]`-Button
+ `DriveController::addAction`/`uploadAction` (eigener Endpoint, Erfolg → `setRedirect` in den Zielordner)
über den wiederverwendeten `UploadService` + `upload.js`. Noch offen in R6c: Rename/Move/Delete +
`setDeliveryMode`/ACL-Aktionen, Bild-Thumbnails, Materialisierung — Resume-Block unten. R2-Detail in [`dms-r2-acl-bauplan.md`](dms-r2-acl-bauplan.md),
R4 in [`dms-r4-delivery-bauplan.md`](dms-r4-delivery-bauplan.md). CSS-Vorstufe (Wrapper-Tokens, ADR-018) erledigt:
[`css-wrapper-token-bauplan.md`](css-wrapper-token-bauplan.md). Konzept-Revision **abgeschlossen** und in ADR-017
(revidiert 2026-06-20) + Konzept-Review + Datenmodell unten festgehalten: `deliveryMode`-Leiter
(`sealed|protected|public`) + statische Materialisierung, ACL-Subjekt `user|role`, additive
`Share`-Entity. **Nächster Schritt beim Wiederaufnehmen:** (1) „Was bleibt / was geht"-Tabelle +
R-Phasen R1–R7 unten auf `deliveryMode`/`Share`/Materialisierungs-Job nachziehen (spiegeln noch das
alte ADR-017), dann R1 starten. Offene Implementierungsdetails: Bild-Varianten-Dateinamen,
Share-Key-Erzeugung + Ablaufpolitik. Bindender Entscheid (Zugriffsmodell):
[`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md).
Review/Begründung: [`dms-konzept-review.md`](dms-konzept-review.md). Vorgänger (abgelöste
Access-/Routing-Teile, Engine gültig): [`dokumentenverwaltung-bauplan.md`](dokumentenverwaltung-bauplan.md).
**Datum:** 2026-06-17 (Konzept-Revision 2026-06-20)

> **Grundsatz:** Die **Engine bleibt** (ADR-016 Phasen 1–6: `BlobStorage` Layout B, GD-Derivate
> + Config-Profile, Zwei-Phasen-`SaveService`, `FileResponse` Range/inline, `DocumentKind`,
> `Mailer`). Geändert wird die **Zugriffsschicht** (Besitz + ACL + `active`), das **Routing**
> (`/media` strukturell via Reserved-Route statt NavigationAlias) und die **Verortung**
> (eigenes `module-dms`). Kein Produktiv-Datenmigration: Framework pre-release, `skeleton/`
> ephemer — Seeds werden neu generiert, abgelöste `visibility`/`publicPath`-Pfade entfernt,
> nicht migriert.

---

## Entscheid Auslieferung + Materialisierung (2026-06-20)

Löst die zuvor offene Frage „public vs. protected". **Mit User abgestimmt 2026-06-20.**

**Quelle der Wahrheit:** `data/blobs/<id>/…` **+ der Document-Metadata-Eintrag**. Die Metadata
steuert zentral, in welchem Auslieferungs-Modus eine Datei ist. Eine Datei im Docroot ist NIE eine
zweite Quelle, sondern eine **deterministisch aus blob+metadata regenerierbare Materialisierung**
(CMS-Publish-Muster) ohne eigenen Zustand.

**Drei Auslieferungs-Modi (Metadata-gesteuert):**

| Modus | Steuerung | Bytes physisch | Auslieferung |
|---|---|---|---|
| **a) protected** | ACL (ACEs, Schritt 2) | nur `data/blobs` (nie Docroot) | PHP `readfile` + ACL-Gate pro Request, `Cache-Control: private` |
| **b) public** | `published` (Flag) | blob **+ materialisiert** nach `public/media/…` | statisch durch den Webserver (kein PHP) |
| **c) shared** | **`shared` (eigenes Flag + Key-Verwaltung)** | blob **+ materialisiert** nach `public/<hash>/…` | statisch, Zugriff per Key (security-by-obscurity, bewusst akzeptiert) |

**Warum statisch für b/c (Begründung geschärft):** nicht „Browser kann PHP-Antworten nicht
cachen" (kann er via `immutable`/`ETag`), sondern um den **PHP-Worker pro Erstabruf + pro
Revalidierung** zu sparen — relevant bei stark frequentierten öffentlichen Bildern. **a) bleibt
PHP**, weil der Traffic klein/authentifiziert ist UND die Bytes per Definition nicht im Docroot
liegen dürfen.

**Materialisierung:** idempotenter Job aus blob+metadata; bei Bildern alle public gebrauchten
Varianten (orig + Profil-Grössen). Flag aus → Zielverzeichnis löschen. Kein manuelles Cleanup,
jederzeit neu baubar → kein Sync-Konflikt, weil die Kopie keinen eigenen Zustand trägt.

**`shared` ist ein eigenes Metadata-Flag** (nicht nur ein ACE), weil dahinter eine eigene
Verwaltung steckt (Access-Key, Zugriffsliste, Lifecycle/Ablauf/Widerruf).

**ACL-Subjekt (Schritt 2, festgehalten):** `user | role | guest`; Rollen aus `AuthRole` (member,
visitor, …); Admin/Super_user = Bypass; Owner = implizit voll; benannte User-Gruppen/Teams =
spätere Erweiterung (deferred).

**Verworfene/abgelöste Wege** (Kontext): die früheren Optionen A „born public", B „Symlink", C
„Kopie beim Publizieren", „php für alles" gehen auf in: a=php, b/c=materialisierte Kopie
(= persistentes Publish-Materialisieren, NICHT der von ADR-016 verworfene Pro-Request-Temp-Copy).
Symlink (B) entfällt (cyon-Symlink-Verhalten ungetestet, Kopie ist robuster).

**Zwei-Achsen-Auslieferung (entschieden 2026-06-20):** Liefermodus und Zugriffsprüfung sind
getrennte Achsen.
- **Achse A — `deliveryMode`** (`protected | public | shared`) am Document/Folder (Metadata,
  gegenseitig ausschliessend → Enum). Steuert Materialisierung + Auslieferungspfad.
- **Achse B — `AccessControlService`** mit modusabhängiger Rolle:

| `deliveryMode` | AccessControlService | Bytes | Auslieferung |
|---|---|---|---|
| protected | **pro Byte-Request** (ACL-Gate) | `data/blobs` (nie Docroot) | PHP `readfile` |
| public | **gar nicht** | `data/blobs` + materialisiert `public/media/…` | statisch (offen für alle, Crawler) |
| shared | **einmal pro Anzeige** (Key-Gate aufs Template) | `data/blobs` + materialisiert `public/share/<hash>/…` | Template per PHP, Einzel-Bytes statisch |

GUEST entfällt als ACL-Subjekt (public = `deliveryMode`, keine Prüfung — nicht „guest darf").
ACL-Subjekt bleibt `user | role`.

**Share-Modell (zweistufig, entschieden 2026-06-20):**
- **Anzeige-Gate (PHP):** Share-URL trägt den Key → `AccessControlService` prüft ihn gegen die
  hinterlegten Share-Keys → Template rendert die zum Key gehörende **Sammlung** (Verzeichnis).
- **Byte-Ebene (statisch + Obscurity):** Dateien liegen in `public/share/<hash>/` unter
  **zufälligen, unerratbaren Namen**; wer den Namen kennt, lädt direkt — bewusst akzeptiert.
- **Granularität:** geteilt wird 1+ Datei oder ein ganzer Ordner. Beim Teilen wird
  `public/share/<hash>/` erstellt und die betroffenen Bytes dorthin **materialisiert** (Ordner-Share
  = Ordner anlegen + enthaltene Dateien kopieren). **Widerruf = Share-Record + Verzeichnis löschen**
  (Key invalidieren allein reicht nicht — die direkten Byte-URLs blieben sonst erreichbar).

**Noch offen:**
- Materialisierungs-Pfadgrammatik im Detail (`public/media/{area}/…/{variant}/{file}`; Layout
  innerhalb `public/share/<hash>/`).
- `shared`-Lifecycle: Key-Erzeugung, optionaler Ablauf.
- Datenmodell-Revision: `visibility`/`publicPath` → `deliveryMode`-Enum + Share-Record-Entity;
  `Document`/`Folder`-Felder nachziehen (der „## Datenmodell (Ziel, ADR-017)"-Block unten ist noch
  auf dem alten Stand).
- ADR-017 + `dms-konzept-review.md` auf diesen Stand bringen.

## Was bleibt / was geht

| Komponente | Aktion |
|---|---|
| `BlobStorage` / `LocalBlobStorage` (Layout B, per-`id`) | **bleibt** |
| `GdImageProcessor` / `VariantSpec` / `ImageProfile*` | **bleibt** |
| `SaveService` (Zwei-Phasen) / `UploadService` / `UploadedFile` | **bleibt** (Felder erweitern: `ownerId`, `active`) |
| `DocumentKind` (Allowlist, `finfo`) | **bleibt** |
| `Mailer`-Stack / `DocumentService::send()` | **bleibt** |
| `FileResponse` (Range/inline/ETag) | **bleibt** |
| `Folder` / `Document` Entities | **erweitern** (`ownerId`, `active`, `slug`; Doc: `width`/`height`) + `visibility`/`publicPath` **entfernen** |
| `AccessControlEntry` + Repository + `AclService` | **NEU** |
| `DocumentService` publish/unpublish/visibility | **entfernen**; ACL-grant/revoke + `active`-Toggle + struktureller `resolve()` **NEU** |
| `MediaController` (frontend, `/media` via Alias) | **ersetzen** durch `OutputController` (module-dms, Reserved-Route) |
| Phantom-Nav-Knoten id 26 + Alias id 8 | **löschen** |
| Reserved-Route-Tier im Router | **NEU** |
| Backend `documents`-Gruppe (Folder/Document-Controller) | **verschieben/anpassen** → `module-dms`-Surface (Shell-Entscheid offen) |

---

## Datenmodell (Ziel, Stand Konzept-Revision 2026-06-20)

```
Folder   : id, parentId, sortKey, name, slug, area,
           ownerId, active, system, deliveryMode(sealed|protected|public, nullable=erbt),
           createdBy, createdAt, updatedAt
Document : id, folderId, slug, area,
           ownerId, active, deliveryMode(sealed|protected|public, nullable=erbt),
           displayName, originalName, ext, mimeType, kind,
           sizeBytes, width, height, checksum, source,
           profile, variants, meta, retentionUntil, deletedAt,
           createdBy, createdAt, updatedAt
           — ENTFERNT ggü. ADR-016: visibility, publicPath
AccessControlEntry : id, resourceType(folder|document), resourceId,
           subjectType(user|role), subjectId(userId|roleName), rights(read|write|manage),
           createdBy, createdAt
           — nur für deliveryMode=protected relevant; GUEST entfällt (public ist kein ACE)
Share     : id, key(unique, zufällig), createdBy, createdAt, expiresAt(null), active
ShareItem : id, shareId, documentId, relPath(Pfad in public/share/<hash>/: Upload-Name;
           bei Ordner-Share <folder-name>/…/<upload-name>)
           — Share ist additiv, 0..n pro Document, nur bei deliveryMode=protected
```

**`deliveryMode` — geordnete Offenheits-Leiter (an Folder UND Document, `null` = erbt):**

| Modus | Bytes | Share | Vererbung an Nachfahren |
|---|---|---|---|
| **sealed** | nie ausserhalb `data/blobs` | verboten | **strikt** (Deckel/Tresor) — Override nach offener verboten |
| **protected** | nur `data/blobs`, PHP+ACL | erlaubt (additiv) | **keine** (neutral) — Kind frei |
| **public** | materialisiert `public/media/{slug-pfad}/` | n/a (eh offen) | **weicher lebender Default** — Kind darf strenger overriden |

- **Effektiver Modus** = eigener (falls gesetzt), sonst der des nächsten Vorfahr-Folders. Ein Kind
  ist **nie offener als ein `sealed` Vorfahr** (struktureller Riegel).
- **`sealed` = Riegel:** `publish()` UND `share()` werfen; die UI bietet die Aktionen nicht an.
  Garantie: ein Vertrag erreicht nie den Docroot.
- **public lebend:** neue Datei in einem public Ordner wird automatisch public; Original-Änderung →
  Materialisierung neu geschrieben (kein Snapshot, Stand C).
- **Pfad = `slug` pro Ebene** (URL-sicher), nicht der Anzeige-Name:
  `public/media/{area}/{folder-slug-pfad}/{doc-slug}.{variant}.{ext}`.

**Effektive Rechte (nur protected):** `owner ODER admin-bypass ? voll : Vereinigung der ACEs auf
Dokument + alle Vorfahren-Ordner, deren Subjekt auf den Principal (User-id) ODER eine seiner Rollen
passt`. Output-Gate: `active=true` (self + Vorfahren) **UND** effektives READ.

> **Wurzel-Default-Modus = `protected`** (entschieden 2026-06-20): sicher (nie offen ohne Aktion)
> und praktisch; `sealed` ist der bewusste Spezialfall (Tresor), nicht der Normalzustand.
> **Materialisierungs-Pfad (entschieden 2026-06-20):** entspricht der Folder-Hierarchie, Blatt =
> **Upload-Name** der Datei; Segmente beim Materialisieren URL-sicher (slug-Bildung). public →
> `public/media/<area>/<folder-pfad>/<upload-name>`; Share → Dateien direkt in
> `public/share/<hash>/`, geteilte Ordner als `public/share/<hash>/<folder-name>/…`. Der `<hash>`
> ist der zufällige/geheime Teil (Obscurity); die Dateien behalten ihren Upload-Namen.
> **Noch offen (Detail):** Varianten-Benennung (Bild-srcset), Share-Lifecycle (Key-Erzeugung,
> Ablauf). Phasen-/„Was bleibt"-Liste oben noch nachziehen.
> **ADR-017 + Konzept-Review: auf diesen Stand gebracht 2026-06-20.**

---

## Phasen (jeder Pausepunkt = lauffähig)

### R0 — Entscheid + Plan ✅
- [x] ADR-017 erstellt, ADR-016 als superseded markiert.
- [x] Dieser Umbauplan + Review.

### R1 — Entities + ACL-Speicher ✅ (2026-06-22, additiv)
**Additiv umgesetzt**, damit das System nach R1 lauffähig bleibt: `visibility`/`publicPath`
werden NICHT entfernt (das bricht die bestehende publish/serve-Kette) — Entfernung erst in R5
zusammen mit dem DocumentService-Refactor.
- [x] `Folder`: `+ownerId, +active, +slug, +deliveryMode`. `Document`: `+ownerId, +active,
  +slug, +width, +height, +deliveryMode`. `visibility`/`publicPath` bleiben (deprecated → R5).
  Alle server-kontrolliert (kein `#[Clean]`); `active` server-validiert (Default `true`).
  `deliveryMode`-Setter validiert gegen `sealed|protected|public` (sonst `null` = erbt).
- [x] `width`/`height` im `SaveService` via `getimagesizefromstring()` (statt das
  `ImageProcessor`-Interface zu erweitern); `slug` via `Naming::toSnakeCase` + `_→-`;
  `ownerId` = `req->ownerId ?? createdBy`. → keine Änderung an `UploadService`/Controllern nötig.
- [x] `AccessControlEntry`-Entity + Repository. **Name = `AccessControlEntryRepository`**
  (nicht `AccessControlRepository`): der EM wired `{Entity}Repository` automatisch. Methoden
  `findByResource` / `findForSubject`; Collection-Datei `documents/access_control.json`.
  Round-trip-Smoke (Wegwerf-CLI) grün: ACE-id/find*, Feld-Round-trip, SaveService-Population.
- [ ] **Offen R6:** Folder-`slug`/`ownerId` beim Anlegen befüllen (FolderController → module-dms).

### R2 — Authorization-Policy  → aufgeteilt, Detail/Resume: [`dms-r2-acl-bauplan.md`](dms-r2-acl-bauplan.md)
R2 ist in eine eigene Resume-Doku ausgelagert (kalt-Start-fähig), da die Session-Reserve für
R2 in einem Zug nicht reichte. Aufteilung:
- **R2a — AclService-Kern (uncached) + `Principal`-VO + Smoke** ✅ FERTIG (2026-06-22). Effektive
  Rechte (owner / admin-bypass / ACE-Vereinigung über Ahnen), `active`-Gate (self + Vorfahren).
  `Principal` aus `AuthService::getCurrentUser()`. Smoke (13 Checks) grün.
- **R2b — Caching + Invalidierung** ✅ FERTIG (2026-06-23). Zwei APCu-Schichten (`DataCache`):
  principal-unabhängige Inputs (Folder-Index pro Area + ACE-Set in einem Eintrag) + Ergebnis pro
  `(principal-signature, type, id)`. Invalidierung über das bestehende `invalidatesCache: true` auf
  ACE/`Folder`/`Document` → `clearAllApcu()`. Smoke (16 Checks) grün, Verhalten identisch zu R2a.
- **NICHT R2 (→ R4):** die verbindliche Auslieferungs-Reihenfolge (ACL-READ + `active` VOR
  Blob-Pfad/`X-Sendfile`, 404-statt-Leak) gehört zur Delivery-Phase R4, nicht zur Policy.

### R3 — Reserved-Route-Tier  ✅ FERTIG (2026-06-23)
- [x] Router/Request: neue Präzedenz `Reserved Routes → NavigationAlias → static nav → convention`.
  Reserved = path-prefix → 4-tuple aus Modul-Config; Trailing-Pfad → `getSlugs()`.
  `ModuleManager::getReservedRoutes()` (aggregiert über Module, Kollision = fail-fast) +
  `Request::matchReserved()` (longest-prefix). **Wichtig:** vor dem Fetch-Kurzschluss UND vor der
  Slug-Translation gematcht — eine `/media`-URL via `<img src>` ist Fetch-Modus (`no-cors`), Page-only
  hätte eingebettete Medien 404t.
- [x] `/media` als Reserved-Route in `frontendConfig` (`/media` → `frontend/media/media/serve`);
  Phantom-Nav-Knoten id 26 + Alias id 8 aus `navigation*.default.json` + Live-Skeleton entfernt.
  Lieferziel bleibt `MediaController::serveAction` (R4 zeigt auf `OutputController` um).
- [x] `routing.md` nachgeführt (neue Tier, flow, Strategie-Tabelle, Regeln, ROUTE-RESERVED-001),
  `docs:check` grün. Verifiziert per Wegwerf-CLI-Smoke (14 Checks: Page+Fetch identisch, slugs,
  bare `/media`, Durchfall bei nicht-reserviert). **Deploy-Hinweis:** APCu/Page-Cache leeren (Nav-JSON
  direkt editiert, keine Auto-Invalidierung).

### R4 — module-dms + OutputController
> Sub-Phasen + kalt-start-fähiges Detail: [`dms-r4-delivery-bauplan.md`](dms-r4-delivery-bauplan.md).
> Entscheide: Variante = Dateinamen-Suffix (ADR §8); Auslieferung nur PHP-Range-Stream (X-Sendfile verworfen).
- [x] **R4a (2026-06-23)** — Folder-`slug`-Befüllung aus R6 vorgezogen (der `/media`-Walk braucht sie):
  `Naming::toSlug()` shared, `SaveService::slugify` nutzt es; `FolderController::edit()` setzt `slug`
  bei Create/Rename, server-kontrolliert, eindeutig pro Parent. Smoke (`Naming::toSlug`, 7 Fälle) grün.
- [x] **R4b (2026-06-23)** — `DocumentService::resolve(area, segments)` → `['document','variant']` | null.
  Folder-slug-Walk + Variant-Suffix (ADR §8), Variante gegen `getVariants()` validiert, Ambiguität/Miss
  → null (nie falsche Bytes). Reine Auflösung, kein ACL/active-Gate (= R4c). Smoke 12/12 grün.
- [x] **R4c (2026-06-23)** — Package `module-dms` + `OutputController` (GUEST). `/media`-Reserved-Route
  von `frontendConfig` nach `dmsConfig` (`dms/media/output/serve`); `MediaController` + `media`-Gruppe
  gelöscht. `serveAction`: area + Pfad → `resolve()` → `effectiveDeliveryMode()`-Branch (`public` offen;
  `protected`/`sealed` → `AclService::canRead` READ+active **vor** Byte) → PHP-Range-`FileResponse` | 404
  (nie Existenz leaken). Cache-Header: public=immutable, privat=private,no-cache (`serve()` cacheControl-
  Override). `skeleton/composer.json` + Installer-Autodiscovery (`Z77\Module\*`) → `composer install`
  (Entwickler) regeneriert `moduleManager.inc.php`. **Live verifiziert 2026-06-29:** `composer update
  z77/module-dms` brachte das Path-Paket in `composer.lock` (`composer install` verweigert ein require, das
  nur in `composer.json`, nicht im Lock steht — Lock-First). Wegwerf-Seed (2 Text-Docs in Area `test`,
  je am Root) + `curl` gegen den laufenden Dev-Server: `public` → 200 + Bytes + `Cache-Control:
  …immutable`; `protected` (unauth) → 404; Slug-Miss → 404; falsche Area → 404. Beweist die ganze Kette
  Reserved-Route → `OutputController` → `resolve()` → `effectiveDeliveryMode()` → `canRead()`-Gate vor Byte
  → `FileResponse`. Seed + Daten (`data/documents`, `data/blobs/0`) danach entfernt.

### R5 — DocumentService-Refactor (Fassade)  ✅ FERTIG (2026-06-29)
- [x] Entfernt: `publish`/`unpublish`/`visibility` (Methoden) + `visibility`/`publicPath` (Felder) aus
  `Document`. Öffentlicher Zugriff ist jetzt ein `deliveryMode`, kein Pro-Dokument-Flag.
- [x] Neu/vorhanden in `DocumentService`: `grant`/`revoke` (idempotente ACE pro `(resource, subject)`),
  `setActive` (Output-Gate), `effectiveDeliveryMode` (geerbt über Folder-Kette, `sealed`-Deckel),
  struktureller `resolve(area, segments)` (Folder-Slug-Walk + Variant-Suffix).
- [x] `SaveService` setzt `ownerId` (= `ownerId ?? createdBy`), `active` (Default `true`, live —
  „publish-on-approval" deferred) und den **folder-eindeutigen** `slug`.
- [x] **Doc-Slug-Dedup** (`SaveService::uniqueSlug`): Slug eindeutig pro `Folder` **+ `ext`** unter den
  Live-Docs (`-2`/`-3`…, Fallback `datei`), gleiches Schema wie `FolderController::uniqueSlug`. Damit
  trifft `resolve()` nie auf ein mehrdeutiges `(slug, ext)`-Paar im selben Ordner. Andere Endung im
  selben Ordner kollidiert nicht (das Blatt disambiguiert per Ext).
- [x] CLI-Smoke (Wegwerf) **18/18 grün**: `effectiveDeliveryMode`, owner/guest/member-`canRead`,
  `grant`/idempotenz/raise, `setActive`-Gate, `revoke`, `serve()`→`FileResponse`, invalid-Reject,
  Slug-Dedup (gleicher Name → `-2`, andere Ext → Basis-Slug). Smoke + Testdaten danach entfernt.
- [x] **Nebenbefund + Fix (Cache):** `DataCache::clearAllApcu()` leerte nur APCu, nicht den
  prozesslokalen Lese-Tier (`$localCache`/`$toCache`). Da `clearAllApcu()` bei **jedem** Entity-Write
  läuft (`FileEntityManager::invalidateCache`) und `$localCache` VOR APCu gelesen wird, lieferte ein
  Read-after-Write im **selben** Request den Stale-Wert (im Smoke: `grant` → `canRead` blieb `false`).
  Behoben: `clearAllApcu()` verwirft jetzt auch die In-Process-Tiers. Produktiv (Request A grant /
  Request B serve) war es nie sichtbar, aber im R6-Surface (granten + Liste im selben Request neu
  rendern) hätte es gebissen.
- [ ] **Offen → R6:** `setDeliveryMode` (UI-Aktion) + Public-/Share-Materialisierung
  (`public/media/…`, `public/share/<hash>/…`) — gehört zum Verwaltungs-Surface, nicht zur Fassade.

### R6 — Verwaltungs-Surface (eingebettetes dms-Fragment)
> **Shell-Entscheid: KEIN offener Punkt — durch ADR-018 entschieden (`[APPROVED]` 2026-06-22).**
> `module-dms` ist **kein eigener view-area/Shell-Environment**, sondern ein **einbettbares
> Fragment**: es rendert eine `.dms`-gewrappte HTML-Komponente, die in bestehende Host-Views
> (frontend, backend, member, …) als HTML-Element eingebettet wird. Der Host entscheidet per
> seinem `layoutConfig`, ob das dms-Bundle (CSS/JS) geladen wird. Auth: der Host liefert
> Login/Shell, das Fragment gated pro Folder/Document via `AclService` (Admin = Bypass) —
> kein „Backend für Member öffnen". Bindend: [`../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md`](../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md).

- [x] **R6a (2026-06-29) — `.dms`-Default-CSS-Gerüst gebaut:** vollständiges `.dms`-Token-Set
  (`--dms-*` präfigiert, Entscheid 2026-06-29; Farben neutral/tool-typisch, Spacing/Typo/Effects =
  Framework-Skala) in `packages/module-dms/res/scss/tokens/` + Single-Bundle-Entry `dms.scss` →
  `res/assets/css/dms.css`. Single-Bundle statt responsive Split (Host besitzt Page-Layout/Breakpoints).
  npm-Scripts `watch:dms`/`build:dms` + in `watch`/`build` aufgenommen. **Noch offen → R6b:**
  `.dms`-scoped base + `.dms-`-präfigierte Komponenten (Klassen-Kollision, ADR-018 Regel 3).
- [x] **R6a (2026-06-29) — `.dms`-CSS dokumentiert:** neue Topic-Doc [`../topics/css-dms.md`](../topics/css-dms.md)
  + Embedded-Fragment-Ausnahme in `css-conventions.md` §1; `docs:check` grün (27/27). Nebenbefund
  dokumentiert (CSS-CONV-DRIFT-001): §3-Konvention zeigt generische `--color-*`, realer Code nutzt
  `--fe-*`/`--be-*` — `.dms` folgt dem realen Prefix-Muster.

#### R6b — Drive-Surface (3-Spalter), IM BAU → PAUSE (2026-06-29)

**Design-Entscheid (vom User vorgegeben, 2026-06-29):** ein **3-Spalter-Drive**:
- **links** Ordner-Hierarchie (Baum),
- **mitte** Dokument-Liste mit **Thumbnails** (Bilder) und **Datei-Typ-Icons** (Rest),
- **rechts** Vorschau-Bereich (Medien + Metadaten + Aktionen).

- [x] **`.dms`-base + `.dms-`-Komponenten-CSS gebaut** (in `packages/module-dms/res/scss/`):
  `base/_base.scss` (`.dms`-scoped Reset/Font, leakt NICHT in den Host) + Komponenten
  `components/_icon.scss` (`.dms-icon`/`.dms-iconbtn`), `_button.scss` (`.dms-btn` primary/ghost/muted),
  `_drive.scss` (`.dms-drive` 3-Spalten-Grid + Toolbar/Breadcrumb + Narrow-`@media` blendet Vorschau aus),
  `_tree.scss` (`.dms-tree`, Tiefe via `--dms-depth`, active/inactive/has-children), `_filelist.scss`
  (`.dms-file` Zeile: `.dms-file__thumb` mit Kind-Tints, `__name/__meta/__badge` für deliveryMode,
  Hover-`__actions`), `_preview.scss` (`.dms-preview` Media/Body/Meta/Actions + Empty-State). Entry
  `dms.scss` `@use`t base + alle Komponenten → `res/assets/css/dms.css` (~11 KB).
- [x] **Visuell verifiziert (standalone):** Vorschau `temp/dms-drive-preview.html`
  (Sample-3-Spalter-Markup + inline `dms.css` + Inline-SVG-Sprite; Bild-Thumbnails als Gradient-Platzhalter)
  headless gerendert (`temp/dms-drive-preview.png`) — Layout sitzt: Toolbar, Baum (aktiv/inaktiv), Liste mit
  Thumbs/Icons/Badges, Vorschau. **Markup-Vertrag für die Templates = dieses Preview-HTML.**
- [x] **Design-Freigabe:** User „weiter mit r6b" (2026-06-29) → Design wie Preview übernommen
  (Akzent Blau `#2563eb`, Zeilen-Liste). Offen gelassen für später: Liste-vs-Kachel-Umschalter.
- [x] **Backend-Integration gebaut (2026-06-29) — Host = Backend/Admin (erster Host):**
  1. `Documents\DriveController` (`extends BackendAbstractController`, Gruppe `documents`, ADMIN,
     `listAction`) — baut View-Model (Folder-Tree via `TreeService`, Liste via
     `DocumentService::listByFolder`, `effectiveDeliveryMode` pro Doc, Vorschau-VM); URL
     `/backend/documents/drive/list?folder=<id>&doc=<id>` (server-gerendert, kein JS).
  2. Template `Documents/DriveController/listAction.tpl.php` = `.dms`-gewrapptes 3-Spalter-Markup
     + Inline-SVG-Sprite (`#i-*`); Folder-/Datei-Links re-rendern.
  3. **Asset-Publish:** `dms.css` → `skeleton/public/assets/dms/css/dms.css` (für Dev manuell kopiert;
     produktiv via `composer install`/`Install::run`).
  4. dms-Bundle **page-scoped** via `layoutManager->addCss('dms','Z77\\Module\\Dms')` im Controller
     (nicht in der Backend-`layoutConfig` — nur die Drive-Seite braucht es).
  5. `DriveController` in `backendConfig` Gruppe `documents` registriert (ADMIN).
  - **Verifiziert (2026-06-29):** Wegwerf-Seed (`_seed_drive_demo.php`: 6 Ordner inkl. inaktiv +
    8 Text-Docs mit protected/sealed/public + aktiv/inaktiv) → echtes Template mit echtem Controller-VM
    + echter `dms.css` headless gerendert (`temp/dms-drive-live.png`): Baum (aktiv-Pfad offen, inaktiv
    durchgestrichen), Liste mit Kind-Icons + deliveryMode-Badges, Vorschau (Icon + Metadaten + Aktionen)
    — alles korrekt; **vom User live im Browser bestätigt (2026-06-29)** unter
    `/backend/documents/drive/list`. **Rest-Caveat:** **GD nicht geladen** → Bild-Varianten nicht
    generierbar, daher live nur Datei-Typ-Icons (Thumbnails im Standalone-Preview belegt; `extension=gd`
    in `C:\php\php.ini` aktiviert sie). Seed-Daten + `_seed_drive_demo.php` bleiben für die Live-Ansicht
    liegen (entfernbar: `data/documents` + `data/blobs` löschen).
- [x] **Alte `documents`-UI abgelöst (2026-07-01).** Der Drive deckt Upload/Rename/Move/Delete/Ordner/
  Modus/ACL ab → gelöscht: `FolderController` (ganze Klasse), `DocumentController` verschlankt auf
  **nur Byte-Delivery** (`preview`/`download`), Templates `DocumentController/{listAction,upload,send}` +
  `FolderController/listAction` weg, `folder-tree.js` weg. **Behalten** (Drive nutzt sie): `preview`/
  `download`, Modal-Templates `DocumentController/{edit,move,confirmDelete}` + `FolderController/
  {edit,confirmDelete}`, `upload.js`. `backendConfig`: `groupDefaults['documents']='drive'`,
  FolderController-Eintrag raus, DocumentController auf preview/download. Nav-Seeds: „Ordner"-Knoten (id 25)
  entfernt, „Dokumente" (id 24) → `drive`. **Feature-Verlust:** die „Verschicken"-UI (E-Mail) war nur in
  der alten UI → entfernt; `DocumentService::send` bleibt (bei Bedarf in den Drive integrieren).
  **Verifiziert (curl):** `/backend/documents` → Drive; `document/list`/`folder/list`/`document/upload`
  → 404; `document/preview` → 200; Drive edit- + folder-edit-Modals → 200. `docs:check` grün.

#### R6c — Drive-Interaktion + Aktionen (PENDENT, Start nach Pause)

Stand-Basis: R6b-Drive ist **read-only + server-gerendert** (Folder-/Datei-Klick = Full-Reload via
`?folder=`/`?doc=`). R6c macht ihn interaktiv und schreibfähig. Dateien & Einstieg:
- Controller: `packages/module-backend/src/Ui/Controllers/Documents/DriveController.php` (nur `listAction`).
- Template: `…/res/view/templates/Documents/DriveController/listAction.tpl.php`.
- Vorhandene Bausteine zum Wiederverwenden (Backend `documents`-Gruppe): `DocumentController`
  (`upload`/`preview`/`download`/`edit`/`move`/`confirm-delete`/`remove`/`send`), `FolderController`
  (`edit`/`move`/`confirm-delete`/`remove` + `folder-tree.js` DnD), Fetch-Envelope (`_Z77.core.fetch`,
  `FetchResponse` + Commands), Modal (`#z77-popup`), `upload.js` (multipart).

- [x] **Fetch-Pane-Updates statt Full-Reload (2026-06-30).** Umgesetzt als Kombination aus (a)+(b):
  EINE Fetch-Action `DriveController::paneAction` (`#[Fetch, HttpMethod('GET')]`, Route per Convention
  `/backend/documents/drive/pane`, in `backendConfig` als ADMIN-Action ergänzt) rendert dieselbe
  View-Model wie `listAction` und gibt die vier Panes als `replace-html`-Commands zurück
  (`.dms-drive__tree` / `__breadcrumb` / `__list` / `__preview`) — bestehendes Command-System (b),
  kein eigenes Pane-Diffing (Server-autoritativ, ganze Panes ersetzt). Das R6b-Template ist in vier
  self-contained Partials gesplittet (`_tree`, `_breadcrumb`, `_list`, `_preview`); `listAction.tpl.php`
  hält nur noch Sprite + Shell und zieht sie via `$this->partial`. `listAction` extrahiert in
  `buildViewModel(?folder,?doc)` (geteilt mit `paneAction`). Jeder Folder-/Crumb-/Datei-Link trägt
  `href` (Full-Reload-Fallback ohne JS) **und** `data-pane` (Fetch-Endpoint, server-gebaut — kein
  URL-Bau im JS). Ein winziges, page-scoped `documents/drive.js` (via `addJs`, delegierter Click auf
  `a[data-pane]` in `.dms-drive` → `preventDefault` + `_Z77.core.fetch.get`; Modifier-/Mittelklick
  fällt auf `href` durch) ist das einzige JS (a). **JS-Grundsatz gewahrt** (CSS/Server-first; JS nur
  als Progressive Enhancement). Verifiziert: `php -l` aller Dateien + Partial-Render-Smoke (19/19,
  `href`/`data-pane`/Escaping). **Live-Klick-Test im Browser bestätigt (User, 2026-07-01)** — Ordner-/
  Datei-Klick aktualisiert die Panes ohne Full-Reload. Adressleiste bleibt vorerst auf `…/list`
  (kein `pushState`; bewusst minimal, evtl. später).
- [x] **Upload im Drive (2026-07-01).** Der Platzhalter-Button (`<span>` „Upload folgt in R6d") ist ein
  echter `[data-drive-upload]`-Button; `drive.js` delegiert den Klick und öffnet das Modal via der
  **server-gebauten** `data-add-url` aus dem (bei jedem Pane-Refresh neu gerenderten) `_breadcrumb`-Pane
  → Ziel ist immer der offene Ordner, kein URL-Bau im JS. Eigene `DriveController::addAction` (Modal
  `_upload.tpl.php`, Zielordner-Select) + `uploadAction` (`#[Fetch, POST]`): Bytes über den bestehenden
  `UploadService` (Allowlist + finfo, `area` server-fix = `backend`, Ziel-Ordner area-validiert), Client =
  der **wiederverwendete** `documents/upload.js`. Eigener Endpoint (statt `DocumentController::upload`),
  weil der Erfolg via `setRedirect('…/drive/list?folder=<id>')` in den Zielordner zurückführen muss
  (der alte Handler macht `reload` → Wurzel). `upload.js` folgt jetzt einem Envelope-`redirect`
  (rückwärtskompatibel: ohne Redirect wie bisher `reload`). **Wichtig (Auto-Partial):** `addAction` hat
  KEIN eigenes `addAction.tpl.php` — das Modal steckt in `_upload.tpl.php` via explizitem `addPartials`.
  Das funktioniert nur im **Fetch-Mode** (`LayoutManager::initialize()` überspringt dann die
  Auto-Registrierung des action-benannten Partials); der Modal-GET kommt als Fetch (`Sec-Fetch-Mode:cors`
  aus `_Z77.core.fetch.get`), passt also. `addAction`/`uploadAction` als ADMIN in `backendConfig` registriert.
  **Live-Server-Test 2026-07-01 (curl gegen `php -S :8080`):** Login → Add-Modal rendert im Fetch-Mode als
  Fragment (action `…/drive/upload`, Ordner vorselektiert, `load-script`); Multipart-POST erreicht
  `uploadAction` (Fetch-Gate + CSRF + Datei + `folder_id`-Validierung ok) → `UploadService::save(area:backend,
  folderId,…)` korrekt aufgerufen. Blocker war **kein Code-Fehler**, sondern `fileinfo`/`gd` in
  `C:\php\php.ini` auskommentiert (finfo-Sniff fatal) → beide 2026-07-01 aktiviert (s. Memory `project_dev_environment`).
  **Live-Klick-Test im Browser bestätigt (User, 2026-07-01):** Upload (auch in Unterordner) + Fetch-Pane-
  Navigation funktionieren. Nebenbefund: `upload_max_filesize`/`post_max_size` in der php.ini waren 2M/8M
  → `UPLOAD_ERR_INI_SIZE` (error 1) bei grösseren Dateien; auf 1024M/1088M angehoben (Dev). App-eigene
  Grenze bleibt `UploadService::DEFAULT_MAX_BYTES = 50M`.
- [x] **Dokument-Aktionen im Drive: Rename / Move / Delete (2026-07-01).** Im Vorschau-Pane je ein
  `[data-modal]`-Button (server-gebaute URL); `drive.js` delegiert den Klick (Buttons leben im
  ersetzten Pane → keine Element-Bindung, daher Delegation) und öffnet das Modal via
  `_Z77.core.fetch.get`. Drive-eigene Actions `editAction`/`moveAction`/`confirmDeleteAction`/
  `removeAction` reuse die **Legacy-Modal-Templates** (`edit`/`move`/`confirmDelete` aus
  `DocumentController`) + `DocumentService::rename`/`move`/`delete`. `edit` postet an die Quell-URL
  (kein URL im Template), `move`/`confirmDelete` wurden um `$postUrl`/`$removeUrl` parametrisiert
  (Default = alte `documents`-URL → alte UI unverändert). Erfolg = **Pane-Refresh** (kein Reload,
  anders als der Upload-Redirect): neuer privater Helper `panes()` (geteilt mit `paneAction`) +
  `paneRefresh()` (= `panes()` + `close-modal`). Move folgt dem Dokument in den Zielordner, Delete
  ist soft (Bytes bleiben). Actions als ADMIN in `backendConfig`. Icons `i-edit`/`i-move`/`i-trash`
  ins Sprite ergänzt. **Live verifiziert (curl gegen `php -S`):** Rename (doc umbenannt, Name im
  Listen-Pane), Move (Wegwerf-Doc → Ordner 2, danach dort), Delete (soft, danach weg) — alle
  `status success` + 4× `replace-html` + `close-modal`. Modal-GET rendert als Fragment (Fetch-Mode).
- [x] **Ordner-Aktionen im Drive: anlegen / umbenennen / verschieben / löschen (2026-07-01).**
  „Neuer Ordner" in der Toolbar (`[data-drive-folder-add]`, liest die server-gebaute
  `data-folder-add-url` aus dem Breadcrumb → Ziel = aktueller Ordner/Wurzel); Umbenennen/Verschieben/
  Löschen als `.dms-iconbtn`-`[data-modal]`-Buttons **im Breadcrumb-Pane** (nur bei gewähltem Ordner,
  server-gebaute URLs, überleben Pane-Refresh via Delegation). Drive-eigene Actions
  `folderAdd/Edit/Move/ConfirmDelete/Remove`: `edit`/`confirmDelete`-Modals von `FolderController`
  wiederverwendet (`confirmDelete` um `$removeUrl` parametrisiert), Move über neues `_folderMove`-Modal
  (Zielordner-Select **ohne** sich selbst + Nachfahren; POST zusätzlich mit Zyklus-Guard `isDescendant`).
  Erfolg = **Pane-Refresh** (`paneRefresh`). Delete-Guard: System-Ordner + nicht-leer (Unterordner via
  `FolderRepository`, Live-Docs via `DocumentService::listByFolder`) blockiert. **Live verifiziert
  (curl):** create→rename→move (Breadcrumb `Ablage › Verträge › …` nach Reparent, self aus Optionen
  ausgeschlossen)→delete alle `status success`; nicht-leerer Ordner → Modal „nicht leer", keine
  Lösch-Form. **Schuld-Notiz (ERLEDIGT bei der Extraktion, ADR-019):** Slug-Eindeutigkeit +
  Delete-Guards waren in 3 Kopien (`FolderController`/`SaveService`/Drive) — jetzt im gemeinsamen
  `module-dms` `FolderService` konsolidiert. DnD im Baum bewusst nicht gebaut (Modal-Move genügt; DnD evtl. später).
- [x] **`setDeliveryMode` (Doc + Folder) fertig (2026-07-01).** Neu in `DocumentService`:
  `setDeliveryMode(id, ?mode)` + `setFolderDeliveryMode(id, ?mode)` (null = geerbt; Enum-Validierung im
  Entity-Setter), `effectiveFolderDeliveryMode(Folder)`, `hasSealedAncestor(type, id)` + gemeinsamer
  privater `resolveEffective(ownMode, ancestorStartId, area)` (bestehendes `effectiveDeliveryMode(Document)`
  darauf refaktoriert, verhaltensgleich). **Struktureller sealed-Deckel:** `assertOpenable` wirft, wenn
  `protected`/`public` unter einem versiegelten Vorfahr gesetzt werden soll. UI: Drive-Actions
  `modeAction`/`folderModeAction` + Modal `_mode.tpl.php` (Radios geerbt/protected/public/sealed; bei
  sealed-Vorfahr sind die offeneren Radios disabled + Alert), Buttons „Modus" (Schild-Icon) im
  Vorschau-Pane (Doc) + Breadcrumb (Folder). Erfolg = Pane-Refresh. **Live verifiziert (curl):** A→sealed,
  B (unter A) Modal zeigt effektiv=sealed + Alert + 2 disabled Radios, B→public **error** (Deckel), nach
  A→geerbt B→public erlaubt; Doc-Modus setzt Badge public und Reset→protected. Testdaten wieder entfernt.
- [x] **ACL-Verwaltung (grant/revoke) im Drive fertig (2026-07-01).** UI-Panel `_acl.tpl.php` pro
  Doc/Ordner: aktuelle ACEs (Subjekt + Recht) mit „Entfernen", plus Grant-Form (Subjekt `role`
  [member/visitor] oder `user` [ID], Recht `read|write|manage`). Eine Action `aclAction`
  (`?type=document|folder&id=`) rendert das Panel und wendet grant/revoke an; **selbst-auffrischend**:
  der POST gibt das neu gerenderte Panel als `text/html` zurück → der Core zeigt es via `popup.show`
  (re-wired) → Modal bleibt offen, Liste aktualisiert, ohne Pane-Refresh (ACEs ändern die Panes nicht).
  Reuse `DocumentService::grant`/`revoke`/`acesFor`; Rollen-/ID-Validierung im Controller
  (`applyAclOp`). Buttons: „Zugriff" (Users-Icon) im Vorschau-Pane (Doc) + Breadcrumb (Folder). Hinweis
  im Panel, dass ACL nur bei `protected` greift + Admin/Besitzer Bypass. **Live verifiziert (curl):**
  leer→„keine Regeln"; grant member/read + user/5/manage (2 ACEs); ungültige Rolle „foo" + nicht-numerische
  Benutzer-ID → error-Flash + Liste unverändert; revoke → 1 ACE; POST-Content-Type `text/html` bestätigt.
  Wegwerf-Ordner + ACEs danach entfernt. **Damit R6c-Kern (setDeliveryMode + ACL) komplett.**
- [x] **Papierkorb / restore + purge (2026-07-01).** Soft-Delete war ADR-konform, aber ohne UI unsichtbar
  → wirkte wie Hart-Löschen. Neu in `DocumentService`: `listDeleted(area)`, `restore(id)` (setzt
  `deletedAt=null`, verlangt existierenden Ursprungs-Ordner — Option A —, re-materialisiert), `purge(id)`
  (Record **und** Blob-Bytes weg, respektiert `retentionUntil`; nur soft-gelöschte purgebar). UI:
  Toolbar-Button „Papierkorb" → `DriveController::trashAction` + `_trash.tpl.php` (Liste mit
  „Wiederherstellen"/„Endgültig löschen", **selbst-auffrischend** wie das ACL-Panel via `text/html`).
  Lösch-Modal-Wording auf „Papierkorb (wiederherstellbar)" angepasst. **Live verifiziert (curl):** restore
  (→ live, Ordner 1) `text/html`; restore eines folder=null-Docs → Ordner-Guard-Fehler; purge → Record +
  Blob-Dir weg. Retention-Guard (`strtotime`) nur logisch (kein Doc mit `retentionUntil` vorhanden).
- [x] **Aktions-Hub (⋮-Menü) + Aktiv-Switch (2026-07-01).** Jede Baum-Zeile (Ordner) + Listen-Zeile
  (Dokument) hat vor dem Namen ein **⋮** (`.dms-rowmenu`) → öffnet `DriveController::actionsAction`
  (`?type=&id=`) als **Hub-Modal** (`_actions.tpl.php`). Der Hub **startet** die bestehenden Sub-Modals
  (Umbenennen/Verschieben/Modus/Zugriff/Löschen, Ordner zusätzlich Unterordner/Upload, Doc zusätzlich
  Öffnen/Download) via `data-fetch-get` (core-wired im Popup) **und** trägt den inline **Aktiv-Switch**
  (`.be-switch` via `data-fetch-toggle` → `&op=active`, globaler CSRF). Der Toggle gibt **Pane-Refresh-
  Commands** zurück → **Durchstreichung aktualisiert sofort** (wie beim Rename); die aktuelle Auswahl
  (`folder`/`doc`) wird über die ⋮-URL → Hub → Toggle durchgereicht, damit die Ansicht erhalten bleibt. Neu: `DocumentService::setFolderActive`
  (Doc-`setActive` gab es schon), beide re-materialisieren. **Zeilen-Umbau:** Zeile ist ein `<div>`; **nur
  `.dms-tree__name` bzw. `.dms-file__main` ist der Link** (data-pane), das `⋮` (`.dms-rowmenu`) sitzt davor —
  beim Ordner **zwischen Icon und Name**, bei der Datei **zwischen Thumb und Main** (Thumb/Badge sind nicht
  verlinkt); `.dms`-SCSS + `dms.css` neu gebaut/publiziert. **Verifiziert (curl):** 8 ⋮/Links im Baum,
  Hub für Marketing (inaktiv → Switch aus, 7 Buttons), Aktiv-Toggle → `active=true`. **Marketing (Seed-Demo,
  war absichtlich inaktiv) dabei aktiviert.** Vorschau-Pane + Breadcrumb behalten vorerst ihre
  Schnellzugriffe (Aufräumen auf „wichtigste" später, wie mit User vereinbart).
- [x] **Papierkorb: Pane-Refresh hinter dem Modal (2026-07-02).** restore/purge gaben nur das
  re-gerenderte Panel zurück → die Liste dahinter blieb bis zum Reload stehen (User-Befund). Jetzt:
  Trash-URL trägt die aktuelle Auswahl (`data-trash-url` am Breadcrumb-Pane, Toolbar-Button
  `[data-drive-trash]` liest sie — Muster wie Upload-Button), der POST hängt die 4 `replace-html`-
  Pane-Commands an die `HtmlResponse` (embedded Envelope). Live verifiziert (curl: 4 Commands,
  wiederhergestelltes Doc sofort in der Liste). Der wörtlich gemeldete Fall („Papierkorb selbst
  leer direkt nach Löschen") war server-seitig NICHT reproduzierbar (delete → Trash-GET zeigt das
  Doc sofort, `no-store`-Header) — vermutlich Beobachtung der Panes, nicht des Panels.
- [x] **Upload: Checksum-Dedupe + Namenskonflikt-Dialog + `publicUrl()` (entschieden + gebaut
  2026-07-02).** Identische Bytes im Zielordner → übersprungen (Info); gleicher Name, andere Bytes
  → `status 'conflict'` + `data.conflicts`, `upload.js` fragt einmal (confirm) und re-postet NUR
  die Konfliktdateien mit `overwrite=1` → `SaveService::replace` in-place (id/Slug/URL/ACL/Modus
  bleiben; Bytes/Mime/Kind/Size/Checksum/Dimensionen/Varianten neu; Guards: `manage` aufs Doc,
  effektiv-`sealed` + laufende `retentionUntil` blockieren). **Keine Versionierung** (YAGNI, mit
  User entschieden), **keine Namensrotation** — Browser-Cache-Frische via
  `DocumentService::publicUrl($doc,$variant)` = einziger Weg, `/media`-URLs zu bauen (`?v=` +
  Checksum-Präfix; lange Cache-Header für `/media` in der Deploy-`.htaccess` bleiben
  erlaubt/erwünscht; nackte extern publizierte URLs bleiben bis Cache-Ablauf stale — akzeptiert).
  Verifiziert: CLI-Smoke 9/9 (replace-Mechanik, publicUrl-Format/-Token) + curl-Live-Flow
  (Erst-Upload / identischer Re-Upload übersprungen / conflict-Envelope / overwrite → gleiche id,
  neue Checksum / sealed → error). Regeln in [`../topics/documents.md`](../topics/documents.md).
- [ ] **Bild-Thumbnails live:** `extension=gd` aktivieren (Dev) → SaveService generiert `s/m/l`-Varianten;
  `.dms-file__thumb img` + `.dms-preview__media img` greifen dann (Code dafür ist schon im Template/VM).
- [x] Backend-`documents`-Gruppe (alte `be-*`-UI) abgelöst (2026-07-01, s. R6b-Block oben).
- [x] **Keine Dokumente im Wurzelbereich (Option A, 2026-07-01).** Entscheid: der Area-Root ist `null`
  (kein Folder-Entity) → unkonfigurierbar (kein `deliveryMode`/ACL, nicht verwaltbar). Deshalb: **Dokumente
  leben immer in einem Ordner; die oberste Ebene organisiert nur Ordner** (Top-Level-Ordner `parent_id=null`
  bleiben erlaubt). Core-Guards: `SaveService::save` + `DocumentService::move` werfen bei `folderId=null`;
  `resolve()` lehnt eine `/media`-URL ohne Ordner-Segment ab. Drive: „Wurzelbereich"-Baumknoten entfernt
  (Top-Level-Ordner direkt), Liste ohne Ordner = Prompt „Ordner wählen/anlegen", Upload verlangt einen
  Ordner (Root-Option aus `_upload`/Doc-`move` raus; **Ordner**-Move behält „Wurzelbereich", da ein Ordner
  Top-Level sein darf), `uploadAction` weist `folder_id=null` ab. **Verifiziert (curl):** Baum ohne
  Wurzel-Knoten, 0 live Root-Docs, Upload ohne Ordner → „Bitte einen Ziel-Ordner wählen", Upload in Ordner
  → ok, Doc-Move ohne / Folder-Move mit Root-Option.
- [x] **Public-Materialisierung fertig (2026-07-01).** `DocumentService::rebuildMaterialization(area)`:
  idempotenter Voll-Rebuild — löscht `public/media/<area>` und schreibt jedes **live + aktiv-Kette +
  effektiv-public** Dokument (Original + Bild-Varianten) an den Pfad, der die `/media`-URL spiegelt
  (`public/media/<area>/<folder-slug-pfad>/<doc-slug>[.<variant>].<ext>`). Reine Funktion aus
  blob+metadata, kein Eigen-Zustand → jederzeit neu baubar. Trigger nach jeder public-relevanten Mutation:
  in `DocumentService` (`setDeliveryMode`/`setFolderDeliveryMode`/`move`/`delete`/`setActive`/
  `saveGenerated`) + in `DriveController` (Upload, Ordner-Rename/Move). **Leak-Fix:** `isActiveChain`
  public gemacht; `OutputController`-public-Fallback prüft jetzt die **volle** Aktiv-Kette (nicht nur
  `doc->isActive()`) — PHP-Fallback und statische Kopie sind konsistent. **Live verifiziert (curl):**
  doc→public schreibt die Datei (+ die public-Seed-Docs), `/media/…`-URL liefert sie statisch
  (Inhalt identisch, 200); doc→geerbt entfernt sie wieder. **Perf-Notiz:** Voll-Rebuild pro Mutation
  (O(docs), findByArea gecached) — für das DMS-Volumen ok, gezielter Diff später.
- [ ] **Share-Materialisierung** (`public/share/<hash>/…`) — noch offen: `Share`/`ShareItem`-Entities,
  Key-Erzeugung/Ablauf/Widerruf, Materialisierung geteilter Dateien/Ordner unter zufälligem `<hash>`,
  Anzeige-Gate (Key → Template). Separater Block (Share ist additiv, ADR-017).
- [ ] `documents.md` Topic-Doc auf den neuen Stand bringen (file map = realer Code), `docs:check` grün.

### R7 — Weitere Akteure (später)
- [ ] Lieferanten-Upload (WRITE-ACE auf Eingangs-Ordner).
- [ ] API-Surface (Token → Principal → gleiche Policy).
- [ ] Temporäres Freigeben (MANAGE-Fähigkeit), account/tenant-Besitz, break-inheritance — alle
  laut ADR-017 deferred.

---

## Offene Punkte (aus ADR-017)

- **URL-Grammatik bestätigen:** Variante steht **immer** im Pfad, Original explizit `orig`
  (`/media/{area}/{folder…}/{variant}/{file}`). Sonst Disambiguierung Variante-vs-Ordner nötig
  (fragil). → in ADR-017 als verbindliche Zeile schärfen, sobald bestätigt.
- Surface-Decomposition + Drive-Shell (R6).
- `active`-Default beim Upload (live oder erst nach Freigabe?).
- API-Authentifizierung (Token-Modell) — R7.
- `xaccel`-Verdrahtung (Übernahme aus ADR-016, deployment-spezifisch).

## Deployment-Voraussetzung (R4)

`delivery=xsendfile` braucht auf dem Zielserver `mod_xsendfile` + `XSendFilePath` auf den
Blob-Wurzelordner (`data/blobs`). Ist es nicht verfügbar → `delivery=php` (funktioniert überall,
nur PHP-Worker während der Übertragung belegt). Installer/Doku-Hinweis nötig.

**Ergebnis cyon-Test (2026-06-17):** cyon läuft auf **LiteSpeed** (Signatur: `alt-svc` QUIC
`v="43,46"`; unbekannte `.htaccess`-Direktiven werfen kein 500). `.htaccess` WIRD gelesen
(`Header set` greift, per `X-Htaccess-Test` verifiziert), aber die `mod_xsendfile`-Direktiven
(`XSendFile`/`XSendFilePath`) werden still ignoriert und der `X-Sendfile`-Antwort-Header NICHT
honoriert (rauscht durch, `Content-Length: 0`). → **`delivery=php` ist der Default für
cyon-Deployments — abschliessend geklärt.**

**Optionaler späterer Modus `delivery=litespeed`:** LiteSpeeds Pendant zu X-Sendfile ist
`X-LiteSpeed-Location` (internes Redirect, analog nginx `X-Accel-Redirect`) und muss vom Hoster
freigeschaltet sein. Falls cyon das aktiviert, lässt sich ein `litespeed`-Auslieferungsmodus
ergänzen (setzt `X-LiteSpeed-Location` mit intern gemappter URI statt `X-Sendfile`) — eine
Config-Zeile, kein Umbau. Gleiche Form wie der offene `xaccel`-Punkt.

**Sicherheitsregel daraus:** Der `X-Sendfile`-Header wird NUR gesetzt, wenn `delivery=xsendfile`
konfiguriert ist — sonst leakt ein nicht-unterstützender Server den absoluten Blob-Pfad in der
Antwort (im Test beobachtet: `/home/sdhcspei/...`). Bei `delivery=php` kein Header, kein Leak.
