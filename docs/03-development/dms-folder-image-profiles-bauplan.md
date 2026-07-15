# dms-folder-image-profiles-bauplan.md — Folder-gebundene Bildprofile + Projekt-Override-Config

**Status:** FERTIG + live-bestätigt (2026-07-13) — S1–S9 gebaut; Upload nach
`front/slider/home/main` erzeugt die `slider`-Varianten (reference project). Nachträge nach dem
Live-Test: (a) Backend-CSS-Fix — `_normalize.scss` liess ALLE Backend-`<select>` ungestylt/ohne
Klick-Affordanz, `_forms.scss` stylt `select` jetzt wie `input` (inkl. bare ACL-Grant-Selects);
(b) **Admin-Set-Reduktion** (Entscheid mit dem Dev): greift ein Projekt-Profil, liefert `admin`
nur noch `s` (Listen-Thumb) + `m` (Vorschau-Pane, neue Konstante `ADMIN_PREVIEW`) — `l`/`xl`
duplizierten die grossen Projekt-Varianten (8 → 6 Dateien/Bild); ohne Projekt-Profil bleibt die
volle `admin`-Leiter. Re-Smoke 3/3 (exakte Varianten-Sets: Profil / default / ohne Config).
Nicht retroaktiv: frühere Uploads behalten `l`/`xl` (löschen + neu hochladen regeneriert;
identischer Re-Upload wird checksum-geskippt).
Ziel (User, Referenzprojekt): Im Drive einen Ordner anlegen (z. B. `drive/front/slider/home/main`),
Bilder dort hochladen, und der Upload resized gemäss einer dem Ordner zugeordneten Grössen-Config;
ohne Zuordnung greift eine Default-Config. Unterschiedliche Ordner können unterschiedliche Configs tragen.
Topic-Doc: [`../topics/documents.md`](../topics/documents.md).

## Ausgangslage (verifiziert 2026-07-13)

- Profile sind heute **modul-owned**: `ImageProfileRegistry::fromModules()` liest je Modul
  `App/Config/imageProfilesConfig.inc.php` und schlüsselt unter dem Modul-Key
  (`ImageProfileRegistry.php:55-91`). **Kein einziges Modul hat eine solche Datei** — es gibt keinen
  gelebten Konsumenten, nur das framework-fixe `admin`-Profil (s/m/l/xl).
- `SaveService::specsFor()` (`SaveService.php:401-436`) löst NUR einen **explizit übergebenen**
  Profilnamen auf (`$req->profile`), via `rootKeyOf($folderId)` → Partition-Root-**Key**
  (`SaveService.php:460-486`). Keyloser (human-created) Root → `null` → kein Projektprofil (S7).
- Der Drive-Upload übergibt **hart `profile: null`** (`DriveControllerTrait.php:295`) — Projektprofile
  sind über die Drive-UI heute unerreichbar.
- `front` im Referenzprojekt ist ein **human-created Root ohne Key** (Seed liefert nur den
  `drive`-Root; `front` wurde im Drive angelegt).
- Config-Loading: `ConfigManager::getArrayConfig` → `FileFinder::getFirstSourceMatch` — **first match,
  kein Merge** (`ConfigManager.php:34-39`). FileFinder-Namespaces haben `sourcePaths: [override, vendor]`
  (Projekt `config/fileFinder.inc.php`) → eine Projekt-Override-Datei ERSETZT die Vendor-Datei.
- Vererbungs-Vorbild: `deliveryMode` — nullable Feld am `Folder`, effektiver Wert via Ancestor-Walk
  (`DocumentService::resolveEffective`, `DocumentService.php:654-680`, nutzt `folderIndex()`).
- Ordner-Edit: EIN kombiniertes Modal (`DriveControllerTrait::combinedEdit`/`editVm`/`applyEditSave`
  ab `DriveControllerTrait.php:339`), changed-only-Semantik; Feld-Gates leben im Domain-Service
  (Muster `FolderService::setKey`, `FolderService.php:156-182`).

## Design-Entscheide (Diskussion mit dem User, 2026-07-13)

1. **Bindung = Folder-Feld + Vererbung (Variante B).** `Folder` erhält ein nullable Feld `profile`
   (Profilname), das die Kette hinunter vererbt — exakt das `deliveryMode`-Muster. KEINE Pfad-Muster-
   Config (bricht bei Rename/Move), KEIN Upload-Dropdown (nicht automatisch). Zuordnung = Daten
   (`folders.json`, Edit-Modal), Grössen-Sets = Config.
2. **Config-Ort = DMS-Namespace, Projekt-Override** (User-Entscheid): die Profil-Definitionen sind
   modulÜBERGREIFENDE, projektspezifische DMS-Verwaltungsdaten — sie liegen in
   `override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php` des PROJEKTS. Das Framework
   (`vendor/z77/module-dms`) liefert keine. NICHT `shared`/`core` (Kernel bleibt DMS-frei, Layering
   wie `mediaUrl()`).
3. **Zweistufig, partition-genamespaced:** `partitionIdent => profilName => variants`. Erhält die
   Kollisionssicherheit der alten modul-owned-Regel (`front.logo` ≠ `back.logo`) in EINER Datei.
   Ersetzt `fromModules()` (Single Source; kein dualer Lookup — es existiert keine Modul-Config,
   die zu erhalten wäre). Ein künftiges Modul mit programmatischen Saves definiert seine Profile
   unter seinem Partition-Key in derselben Datei (Modul-Key = Partition-Key).
4. **Partition-Auflösung `key ?? slug`:** keylose Roots (wie `front`) adressieren über den Slug —
   dasselbe Muster wie `mountRoot` (`findRootByKey ?? findRootBySlug`). KEIN Key-Setzen auf `front`
   nötig.
5. **Auflösung an der Save-Grenze:** `SaveService::specsFor` löst bei `profile === null` AUTOMATISCH
   auf: effektives Folder-Profil (eigenes ?? nächster Ahne) ?? Partitions-Profil `default` (falls
   definiert) ?? kein Projektprofil (nur `admin`). Dadurch braucht `uploadAction` KEINEN Umbau
   (`profile: null` = auto) und `saveGenerated`-Pfade verhalten sich identisch. Ein EXPLIZIT
   übergebener Profilname behält die heutige Semantik (unbekannt → throw).
6. **`default` = reservierter Profilname** je Partition (der Fallback), `admin` bleibt framework-fixed
   und in der Config verboten. `preserveOriginal`/`showOriginal`-Verhalten unverändert.
7. **Bekannte Grenze (unverändert):** Varianten sind NICHT retroaktiv — Profil-/Zuordnungsänderungen
   wirken nur auf künftige Uploads; Reprocessing ist eine spätere Phase.
8. **Regel-Revision:** documents.md-Regel „module-owned imageProfilesConfig / MUST NOT global"
   (Zeile ~198) wird revidiert; ADR-020 erhält eine Revisionsnotiz (Profile: modul-owned →
   partition-genamespaced DMS-Config; Zuordnung am Folder).

## Zielbild

```text
Projekt-Config (Override, EINE Datei):
  override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php
  return [
      'front' => [                                  // Partition (key ?? slug des Roots)
          'default' => ['w1600' => ['w' => 1600]],  // Fallback ohne Folder-Zuordnung
          'slider'  => ['mobile' => ['w' => 768], 'desktop' => ['w' => 1920, 'h' => 800, 'fit' => 'cover']],
      ],
  ];

Zuordnung (Daten, Drive-Edit-Modal):  drive/front/slider  →  profile = 'slider'
Vererbung:                            home, main erben 'slider'
Upload nach main:                     specsFor: effectiveProfile = 'slider' → Varianten mobile/desktop + admin s/m/l/xl
Upload in Ordner ohne Zuordnung:      → 'front.default' → w1600 + admin
Partition ohne Config:                → nur admin (wie heute)
```

## Schritte

### S1 — `Folder.profile` (Entity) — **FERTIG (2026-07-13)**

- `Folder`: Feld `?string $profile = null` + Getter/Setter, server-controlled (KEIN `#[Clean]`).
  **Gebaut:** Setter NORMALISIERT statt zu werfen (Muster `setDeliveryMode`): trim, `''`/falscher
  Charset (`[a-z0-9_-]`) → `null` — Hydration aus alter/korrupter JSON bleibt robust; die strenge
  Validierung (existiert das Profil?) macht `FolderService::setProfile` (S4). Mapping verifiziert:
  `ArrayMappable::mapToArray` reflektiert ALLE Props (Feld persistiert automatisch),
  `mapFromArray` hydriert über den Setter.
- **Verify:** `php -l` grün; Round-trip-Check im S-Smoke (S8).

### S2 — `ImageProfileRegistry` auf zweistufige DMS-Config — **FERTIG (2026-07-13)**

- **Gebaut:** `fromModules()` ersetzt durch `fromConfig()` — liest `App/Config/imageProfilesConfig`
  im Namespace `Z77\Module\Dms\` (`throwError: false`; keine Datei = keine Projektprofile). Struktur
  `partitionIdent => profilName => profilCfg`; Validierung: Partition-Wert + Profil-Wert müssen
  Arrays sein, `admin` verboten (je Partition), Rest via `ImageProfile::fromConfig` (unverändert).
  API: `get`/`has` (Signaturen gleich, Param heisst jetzt `$partitionIdent`), NEU
  `names($partitionIdent): list<string>` (Edit-Modal-Dropdown) + Konstante `DEFAULT = 'default'`.
  Docblock trägt die Ownership-Story (Projekt-Override, partition-genamespaced, Entscheid 2/3/4).
  Einziger Konsument `SaveService::create()` umgestellt (grep-verifiziert).
- **Verify:** `php -l` grün; Registry-Checks im S-Smoke (S8).

### S3 — `SaveService`: Auto-Auflösung + `key ?? slug` — **FERTIG (2026-07-13)**

- **Gebaut:** `rootKeyOf()` ersetzt durch `chainInfoOf(?int $folderId): [?ident, ?effectiveProfile]`
  — EIN Walk sammelt beides: Partition-Ident (`key ?? (slug ?: null)`) und effektives Folder-Profil
  (eigenes ?? nächster Ahne, Partition eingeschlossen; Drive-Root nie erreicht relevant).
  Neue private `resolveProfile(SaveRequest): ?ImageProfile`: explizite Namen behalten die strikte
  Semantik (unbekannt → RuntimeException; `admin` → admin-Profil), `null` = AUTO
  (Folder-Profil ?? Partition-`default` ?? kein Projektprofil) — bewusst LENIENT: eine verwaiste
  Zuordnung (Config verlor das Profil später) fällt zurück statt den Upload zu brechen.
  `specsFor()` nutzt nur noch `resolveProfile`. `save()`/`saveFromUpload()` persistieren den
  AUFGELÖSTEN Namen (`$this->resolveProfile($req)?->name`) statt des rohen `$req->profile` — das
  Dokument dokumentiert, womit gerechnet wurde. Poster-Pfad (Video) läuft durch dieselbe Auflösung
  (konsistent: der Poster bekommt die Ordner-Varianten wie ein Bild). `replace*`-Pfade unverändert
  (gespeichertes Profil bleibt).
- **Verify:** `php -l` grün; Save-Fälle im S-Smoke (S8).

### S4 — Domain: `effectiveFolderProfile` + `FolderService::setProfile` — **FERTIG (2026-07-13)**

- **Gebaut:** `DocumentService::partitionIdentOf(int $folderId): ?string` (public, delegiert an das
  bestehende `rootOf()`; `key ?? (slug ?: null)`) + `DocumentService::effectiveFolderProfile(Folder):
  ?string` (eigenes ?? Ahnen-Walk via `folderIndex()`, Muster `resolveEffective` ohne
  Sealed-Sonderfall; Display-Helper — die autoritative Save-Auflösung bleibt in `SaveService`).
  `FolderService::setProfile(int $id, ?string $profile): Folder` — Gate effektives `manage` (wie
  `rename`), Drive-Root abgelehnt, `''`/null = Zuordnung löschen (Vererbung), sonst Validierung:
  `admin` verboten + `ImageProfileRegistry::fromConfig()->has($ident, $name)` via
  `partitionIdentOf`. KEINE Rematerialisierung (Profile ändern keine URLs).
- **Verify:** `php -l` grün; setProfile happy/unknown/root + Vererbung im S-Smoke (S8).

### S5 — Combined-Edit-Modal: Feld „Bildprofil" — **FERTIG (2026-07-13)**

- **Gebaut:** `editVm` (folder-Zweig) liefert `ownProfile`, `effectiveProfile`
  (`effectiveFolderProfile`), `profileOptions` (`ImageProfileRegistry::fromConfig()->names($ident)`
  via `partitionIdentOf`; leer für Drive-Root). `default` ist in den Optionen enthalten (explizit
  zuordenbar). `_edit.tpl.php`: eigene Sektion „Bildprofil" (nur `$type === 'folder'` UND
  `profileOptions !== []` — kein totes Feld), `<select name="profile">` mit Leeroption
  `Geerbt / keines — effektiv: X` + Hinweistext auf den `default`-Fallback. `applyEditSave`
  (folder-Zweig): `array_key_exists('profile')` (Feld nur gerendert wenn Optionen da), changed-only
  → `folderService()->setProfile(...)`. Import `ImageProfileRegistry` im Trait ergänzt.
- **Verify:** `php -l` grün (Trait + Template); Live-Klick im Projekt (S9).
- **Live-Befund (2026-07-13, Referenzprojekt):** Feld erschien, Select aber als nackter Text ohne
  Klick-Affordanz. Ursache NICHT das neue Feld: `_normalize.scss` resettet ALLE Form-Controls
  (`appearance:none`, kein Border/Background), `_forms.scss` stylte nur `input` zurück —
  `select` war im ganzen Backend nie nachgestylt (gleiche Lücke bei den ACL-Selects im selben
  Modal). Fix in `_forms.scss`: `select` in die `input`-Regeln aufgenommen (Box/Focus/invalid)
  + `appearance:auto; cursor:pointer` restauriert; `.be-form__grid > select` deckt die bare
  ACL-Grant-Selects. `npm run build:backend` + `composer install` im Projekt (Asset-Copy nach
  `public/assets`, css-watch.md-Flow). Feld zusätzlich auf das bewährte Muster umgebaut
  (im ersten `.be-form__grid`, mit `<label>`, wie das Zielordner-Select im Verschieben-Modal).

### S6 — `uploadAction`-Kommentar — **FERTIG (2026-07-13)**

- Kein Funktionsumbau (Entscheid 5). Kommentar bei `profile: null` ergänzt:
  „null = AUTO: folder-assigned/inherited profile ?? partition 'default' (SaveService)".

### S7 — Doku — **FERTIG (2026-07-13)**

- `docs/topics/documents.md`: Mental-Model-Bullet (Profile = projekt-owned, partition-genamespaced)
  + Regel „module needs image profiles" ersetzt durch „project needs image profiles" + NEUE Regel
  „choosing WHICH profile an upload gets" (Folder-Bindung, Vererbung, Auto-Auflösung
  lenient/strict, `default`-Fallback, nicht retroaktiv) + `## pending`-Eintrag mit As-built.
  ADR-020: Revisionsnotiz 2026-07-13 (Header). `npm run docs:check` grün.

### S8 — Verify (Framework, throwaway CLI-Smoke im Skeleton) — **FERTIG (2026-07-13), 22/22 grün**

- Gelaufen im Skeleton (vendor = Symlink auf `packages/`): Registry (zweistufig, `default`,
  unbekannte Partition leer, `names()`, `admin` global); Folder (`profile` Round-trip,
  Vererbung über 2 Ebenen, `partitionIdentOf` via Slug bei keylosem Root, `setProfile`-Gate
  verweigert Guest); Save (geerbt → Slider-Varianten + admin-Thumb, ohne Zuordnung → `default`,
  verwaiste Zuordnung → default-Fallback, Partition ohne Config → nur admin + `profile = null`,
  expliziter unbekannter Name → throw, expliziter Name überstimmt Folder-Zuordnung; aufgelöster
  Name je Fall am Dokument persistiert). PNG 1200×600 via GD erzeugt. Smoke-Script, Seed-Daten
  (`data/documents`, `data/blobs`) und die Test-Override-Config danach entfernt. `php -l` über
  alle geänderten Dateien sauber. Befund nebenbei: `UnifiedEntityManager` hat kein `clear()` —
  File-Driver liest ohnehin pro Repository-Call.

### S9 — Referenzprojekt (kein Framework-Code) — **TEILWEISE (2026-07-13)**

- **Erledigt:** Live-Daten verifiziert (`front` id 2, key=null, slug `front`, deliveryMode
  `public`; Kette slider(4) → home(5) → main(6) existiert). Override-Config angelegt:
  `override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php` — `front` →
  `default` (w1600/w800) + `slider` (mobile 768 / tablet 1280 / desktop 1920, proportional).
  Grössen sind sinnvolle Startwerte — bei Bedarf anpassen (`h` + `fit: cover` möglich).
  Kein composer-Schritt nötig: `vendor/z77/*` ist im Projekt auf `packages/` gesymlinkt,
  der Framework-Stand ist sofort live (keine neuen Klassen/autoload.files — nur bestehende
  Dateien geändert).
- **Offen (User, Browser):** (1) Drive → `drive/front/slider` → Bearbeiten → Bildprofil
  `slider` wählen; (2) Test-Upload nach `.../home/main` → erwartete Varianten
  `mobile/tablet/desktop` + `s/m/l/xl` (Blob-Verzeichnis `data/blobs/<shard>/<id>/` bzw.
  `/media/front/slider/home/main/<slug>.<variant>.<ext>`); (3) Gegenprobe: Upload nach
  `front/imgs` (ohne Zuordnung) → `w1600/w800`.
