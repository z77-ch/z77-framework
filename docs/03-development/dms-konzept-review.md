# dms-konzept-review.md — Konzept-Prüfung gegen Anforderungen (vor Phase 7)

**Status:** REVIEW (historisch). Konzept-Check vor Weiterbau. Entscheidungen A/B/C mit User
getroffen 2026-06-17; eine Design-Gabel (Public-Zugriff bei Folder-Vererbung) noch offen.
**Datum:** 2026-06-17

> **Weiterentwickelt 2026-06-20.** Das hier skizzierte Zugriffs-/Auslieferungsmodell wurde
> verfeinert: `deliveryMode`-Leiter (`sealed | protected | public`) + statische Materialisierung
> statt „public = GUEST-ACE"; ACL-Subjekt `user | role` (GUEST entfällt); Teilen als additive
> `Share`-Entity. Verbindlich: [`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md).
> Herleitung: [`dms-umbauplan.md`](dms-umbauplan.md). Dieses Review bleibt als Begründungs-Historie.
**Bezug:** [`../02-decisions/adr-016-document-management-storage-and-delivery.md`](../02-decisions/adr-016-document-management-storage-and-delivery.md),
[`dokumentenverwaltung-bauplan.md`](dokumentenverwaltung-bauplan.md), [`../topics/documents.md`](../topics/documents.md)

> Quelle: User-Anforderungsliste 2026-06-17 (11 Punkte) + Klärungen. Geprüft gegen real
> existierenden Code (Entities, SaveService, GdImageProcessor, DocumentService, MediaController,
> Repositories), nicht nur gegen die Doku.

---

## Anforderungs-Matrix

| # | Anforderung | Status | Befund |
|---|---|---|---|
| 1 | Folder enthält Dateien oder Folder | ✅ | `Folder` = `TreeNode` (parentId/sortKey); `Document.folderId`. |
| 2 | Datei jeder Art, MimeType server-seitig (nie User/Browser) | ⚠️ | `finfo`-Sniff + `DocumentKind::fromMime()` server-seitig korrekt. Aber `DocumentKind` ist eine **Allowlist** → „aller Art" ist eingeschränkt. Bewusst beibehalten (Upload-Sicherheit). |
| 3 | Public-Datei per URL ladbar | ✅ | `visibility=public` + `publicPath` + `/media/{publicPath}` → `servePublic()`. |
| 4 | Ordner public ⇒ Kinder public | ❌→entschieden | Lücke: `Folder` hatte kein `visibility`. **Entscheid A:** Folder bekommt `visibility` + Vererbung. |
| 5 | Über alle Folders loopen + Kinder zeigen | ⚠️→entschieden | Mechanik vorhanden (Repo + TreeService + listByFolder), aber keine Baum-API. **Entscheid C:** Folder-mit-Kindern-API. |
| 6 | Endlose Tiefe | ✅ | Tree-Foundation, beliebige Tiefe (DnD bestätigt). |
| 7 | Metadaten size/width/height/mimeType | ❌→entschieden | `sizeBytes`/`mimeType` ok. **Original-width/height fehlen** (nur Derivate in `variants`). **Entscheid B:** Original-Dimensionen persistieren. |
| 8 | Bild-Grössen via Config | ✅ | `ImageProfileRegistry` + pro-Modul `imageProfilesConfig.inc.php`, `admin` fix (OPEN-8). |
| 9 | Zugriff auf resized Datei (src/srcset) | ✅ | `serve($doc, variant)` / `?variant=`; `variants`-Map mit `{w,h}`. |
| 10 | Mandatsabhängig Ordner zuordnen/anzeigen/freigeben | ❌→geklärt | **Kein** Multi-Tenant. Gemeint: slug-basierter Zugriff auf einen Ordner + Kinder (`getBySlug('mandant-01')`, `getById(5)`). **Entscheid C/A.** |

**Fazit:** Fundament solide (7/11 sauber). Vier Konzept-Deltas nötig, alle modell-/ADR-relevant.

---

## Getroffene Entscheidungen (User 2026-06-17)

### A — Folder-Sichtbarkeit mit Vererbung (Anf. 4)
`Folder` bekommt `visibility` (`private`|`public`, Default `private`). Effektive Sichtbarkeit
eines Dokuments = eigenes `visibility=public` **ODER** ein Vorfahren-Ordner ist `public`
(Auflösung per parentId-Walk über `TreeService`). `serve()`/`servePublic()` prüfen die
effektive Sichtbarkeit, nicht mehr nur das Dokument-Flag.

### B — Original-Dimensionen in die Metadaten (Anf. 7)
Bei `kind=image` werden Breite/Höhe des Originals gespeichert. `GdImageProcessor` kennt
`sw/sh` bereits. Umsetzung: entweder dedizierte Felder `width`/`height` am `Document` **oder**
`variants['orig'] = {w,h,bytes}`. Greift auch bei `showOriginal`/`preserveOriginal` (sonst
bleibt die Original-Dimension unbekannt). → Verortung in der Datenmodell-Revision.

### C — Slug-Zugriff + Folder-mit-Kindern (Anf. 5 + 10)
Kein Mandanten-Feld. Stattdessen:
- `Folder.slug` (Lookup-Key) → `getBySlug(slug)` liefert den Ordner.
- Folder-mit-Kindern-API: `getById(id)` / `getBySlug(slug)` liefert den Ordner **inklusive**
  rekursiver Kinder (Subfolder + Dokumente) zum Anzeigen.
- Reuse: `TreeService` (Forest/Children) + `DocumentService::listByFolder()` pro Knoten.

---

## Resultierende Modell-/API-Deltas (Vorschlag)

**`Folder`** — neu: `slug` (string, Lookup), `visibility` (`private`|`public`, Default `private`).
**`Document`** — neu: `width`/`height` (int|null, nur image) ODER `variants['orig']`.
**`DocumentService`** (Fassade) — neu:
- `getFolderBySlug(area, slug): ?Folder`
- `getFolderTree(area, folderId|slug): array` — Ordner + rekursive Kinder (Folder + live Docs)
- effektive-Sichtbarkeits-Auflösung in `serve()`/`servePublic()` (Vorfahren-Walk)

**Slug-Eindeutigkeit:** Vorschlag unique **pro `area`** (konsistent mit Area-Isolation).
Offen, falls Public-Folder-Routing global auflösen muss → siehe Gabel unten.

---

## Offene Design-Gabel — Public-Zugriff bei Folder-Vererbung

Wenn ein Ordner `public` ist, müssen seine Kinder per URL erreichbar sein. Heute ist die
öffentliche URL der **per-Dokument** `publicPath` (Unique-Lookup). Zwei Modelle:

1. **Folder-Slug-Routing** — `/media/{folderSlug}/{rest...}`: erster Slug löst den public
   Ordner auf, der Rest navigiert Subfolder/Dokument per Name innerhalb des public Teilbaums.
   Passt zum User-Mental-Model (getBySlug → Ordner + Kinder anzeigen), keine per-Dokument-
   Freigabe nötig. Name-Navigation ist reiner DB-Lookup (Blob bleibt id-adressiert → kein
   Traversal), aber User-Input-Namen im Pfad.
2. **Auto-`publicPath` pro Kind** — beim Public-Schalten eines Ordners wird für jedes Kind ein
   `publicPath` abgeleitet (Ordnerpfad + Name). Bestehender `/media/{publicPath}`-Mechanismus
   bleibt unverändert, aber Vererbung wird zu einer Kaskade (neue Kinder brauchen Re-Run).

Entscheidung steuert, ob `publicPath` weiter pro Dokument der primäre Schlüssel ist oder ob
Folder-Slug der primäre öffentliche Zugriffspfad wird.

---

## Richtungswechsel: ACL-basiertes Zugriffsmodell (User 2026-06-17)

Die Vision ist breiter als ADR-016: das DMS ist **kein** Admin-Backend-Tool, sondern eine
Multi-Akteur-Dokumentplattform (Drive-artig, vgl. proton.me). Akteure: **Admin** (alles),
**User/Kunde** (eigener Space, CRUD — kein Backend), **Lieferant** (Upload ins Postfach),
**API** (Token = Account, gleiche Policy). Das rechtfertigt ein eigenes `dms`-Modul, das
Engine + die **eine** Authorization-Policy + den autorisierten Output besitzt; darüber dünne
Oberflächen (Admin-View, Kunden-Drive, Lieferanten-Upload, API).

**Entschieden (User 2026-06-17):**
- Zugriff über eine **echte ACL** (alles andere ist nicht sauber; vertrauliche Dokumente
  liegen künftig auf dem Server → ACL ist Pflicht).
- Eigentums-Einheit aktuell = **einzelner User** (`ownerId`); Account/Mandant mit mehreren
  Usern ist später denkbar, jetzt nicht im Scope.
- Teilen = ein **Berechtigungsdatensatz** (ACE) pro Subjekt.
- **„Public" ist kein Sonderfall:** GUEST ist ein Principal. Öffentlich = ein ACE, der dem
  GUEST-Principal READ gewährt. Damit entfallen `visibility` UND `publicPath`.

**Folge — alte offene Fragen lösen sich auf:**
- Public-Zugriffs-Gabel (Modell 1 vs. 2 / `publicPath`) → hinfällig. `/media`-URL ist
  strukturell (`area/ordner.../variant/datei`), die ACL entscheidet den Zugriff.
- „Folder public ⇒ Kinder public" → einfach **ACL-Vererbung** (ACE auf Ordner gilt für
  Nachfahren).

**Locks geschlossen (User 2026-06-17):**
- **Rechte-Set = READ / WRITE / MANAGE.** Kein separates SHARE; temporäres Freigeben ist eine
  MANAGE-Fähigkeit und kommt **später**. Owner = implizit volle Rechte.
- **Admin = Bypass** (Superuser, sieht/verwaltet alles).
- **Vererbung = additiv, einfach** (Unterordner erben die ACEs der Vorfahren). Kein
  Break/`isolated`.
- **`active` (bool) an Folder + Document** = Content-Status, **NICHT** Berechtigung. Zwei
  getrennte Achsen: ACL = *wer darf*, `active` = *ist es live*. Öffentliche Ausgabe + normale
  Liste verlangen `active=true` (self + Vorfahren) UND effektives READ; die Verwaltungs-
  Oberfläche (Owner/MANAGE/Admin) zeigt inaktive Einträge weiterhin. Konsistent mit dem
  `active`-Feld der Navigation.

**Datenmodell (steht):**
```
Folder   : id, parentId, sortKey, name, area, ownerId, system, active, slug, timestamps
Document : id, folderId, area, ownerId, displayName, originalName, ext, mimeType, kind,
           sizeBytes, width, height, checksum, source, retentionUntil, deletedAt,
           profile, variants, meta, active, slug, createdBy, timestamps
           — ENTFERNT ggü. ADR-016: visibility, publicPath
AccessControlEntry (NEU):
           id, resourceType(folder|document), resourceId,
           subjectType(user|guest), subjectId(null=guest), rights(read|write|manage),
           createdBy, createdAt   — additive Vererbung über Vorfahren-Ordner
```

**Media-Output-Ablauf:** Principal aus Session (User|GUEST) → Ressource aus Pfad auflösen →
`active`-Gate (self + Vorfahren live) → effektive Rechte = Owner? voll : Vereinigung der ACEs
auf Dokument + alle Vorfahren-Ordner, die auf den Principal oder GUEST passen (Admin → Bypass)
→ READ? ausgeben : 404.

**Konsequenz:** ADR-016 ist ein Spezialfall des neuen Modells und muss **substanziell
revidiert oder durch ein neues ADR ersetzt** werden (Besitz + ACL statt Admin + area-only +
visibility/publicPath). Noch keine finale Entscheidung zur Modulstruktur.

## Befund: Public-Routing missbraucht die Navigation (Phase-5-Fehler)

Die `/media`-Route wird heute über einen **Phantom-Navigations-Knoten** geroutet
([`../../packages/kernel/core/data/framework/routing/navigation.default.json`](../../packages/kernel/core/data/framework/routing/navigation.default.json), id 26)
+ Alias id 8. Dieser Knoten ist keine Seite (kein Parent, keine Group, kein Menü) — er
existiert nur, damit der Router (`NavigationAlias → static navigation → convention`) ein
Dispatch-Ziel findet. Damit verschmutzt ein reiner Infrastruktur-Endpunkt den
Navigationsbaum (= Menü/Sitemap/Struktur). **Navigation ist Navigation; der Alias-/Friendly-
URL-Layer ist für Seiten, nicht für Byte-Delivery.**

**User bestätigt 2026-06-17:** Das ist Missbrauch der Navigation. **Lösungsvorschlag (offen):**
eigene Routing-Schicht für System-/Public-Endpunkte, getrennt von der Navigation:
```
Router-Präzedenz neu:
  1. Reserved Routes   ← NEU: path-prefix → {module,group,controller,action}, aus Modul-Config
  2. NavigationAlias        (nur echte Seiten-URLs)
  3. static navigation
  4. convention
```
`/media` wird ein reserved prefix (Modul-Config, nicht Navigations-JSON); Trailing-Pfad →
`getSlugs()` wie gehabt. Phantom-Knoten id 26 + Alias id 8 entfallen. Orthogonal zur
Public-Zugriffs-Gabel (reserved route = *wie geroutet*; Folder-Walk vs. publicPath = *wie
die Slugs gedeutet werden*). Berührt `routing.md` (neue Präzedenz-Tier) + ADR-016 §7.

## Ergebnis

Das Konzept ist entschieden und überführt in:
- **[`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md)**
  — bindender Entscheid (Besitz + ACL + `active` + struktureller `/media` via Reserved-Route +
  `dms`-Modul). Löst ADR-016 ab (Engine bleibt gültig).
- **[`dms-umbauplan.md`](dms-umbauplan.md)** — Umbauplan R0–R7 (was bleibt / was geht, Phasen).

Dieses Review ist damit abgeschlossen; Arbeitsstand läuft ab jetzt über den Umbauplan.
