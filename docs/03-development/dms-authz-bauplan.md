# dms-authz-bauplan.md — Domänen-erzwungene Autorisierung für DMS-Verwaltung (R7)

**Status:** R-authz-1 FERTIG + verifiziert (2026-07-01). Mutations-Gates in `DocumentService`/
`FolderService`/`UploadService` aktiv (Session-Principal, un-fälschbar). **R-authz-2 (Lese-Scoping)
FERTIG (2026-07-02, als RF-4a im ADR-020-Umbau):** Byte-Reads via `DocumentService::serveFor`
(effektives `read`), per-id-Lese-Gates (`readableDoc`/`readableFolder`) auf jeder Drive-Anfrage,
domänen-gescopte `listDeleted`, ACL-gefilterter Baum — deny-by-default, Detail:
[`dms-rootfolder-bauplan.md`](dms-rootfolder-bauplan.md). Offen: `send` (Modellfrage, s. u.). Bindend: [`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md)
(Besitz + ACL-Modell). Kontext-Vorgeschichte: [`dms-extraction-bauplan.md`](dms-extraction-bauplan.md)
(ADR-019, `module-dms` self-contained). Topic-Doc: [`../topics/documents.md`](../topics/documents.md).
**Datum:** 2026-07-01

> **Grundsatz:** Der Zugriffs-Guard existiert schon (`AclService::hasAccess`/`effectiveRight`, ADR-017).
> Er wird bisher nur für die **Auslieferung** genutzt (`canRead`), NICHT für die **Verwaltung**. Dieser
> Plan verdrahtet ihn in den Verwaltungs-Pfad — **in der Domäne, un-umgehbar**, nicht im UI.

---

## Warum in der Domäne, nicht im UI (die Kern-Erkenntnis)

Die UI-Schicht kann sich **prinzipiell nicht selbst schützen**: PHP-Trait-Precedence lässt eine
Klassen-Methode die Trait-Methode **lautlos** überschreiben — sogar eine `final` Trait-Methode (empirisch
getestet 2026-07-01: `final`-Trait-`preExecute` + Klassen-`preExecute` → Klasse gewinnt, kein Fatal). Ein
Gate in `DriveControllerTrait::preExecute` wäre also durch ein `preExecute` im Host-Controller kippbar,
geräuschlos. Ebenso: ein Host, der den Controller in eine public Group mountet (`main`/`actions['*']=>GUEST`),
umgeht jedes Controller-Gate (Action-Rolle schlägt Controller-Rolle, `AuthService::resolveRoleForCurrentController`).

**Konsequenz:** Der Riegel gehört dorthin, wo kein UI-Code ihn überschreiben kann — in die
**Domänen-Services** (`DocumentService`/`FolderService`). Die Mutation passiert nicht ohne Autorisierung,
egal wie der Controller aussieht (misskonfiguriert, überschrieben, feindlich).

## Un-fälschbar: Principal aus der Session, nicht vom Aufrufer

Nähme der Service einen `Principal` als Parameter, könnte ein Controller `new Principal(1, ['super_user'])`
fälschen. Deshalb: die gegateten Methoden holen den Principal **selbst aus der Session**
(`Principal::fromAuthUser(DI::getAuthService()->getCurrentUser())`). GUEST → Guest-`AuthUser` (`userId 0`,
kein Admin) → matcht keine `ownerId` (echte IDs >0) und keine member/visitor-ACE → `effectiveRight='none'`
→ **abgelehnt**. Un-umgehbar durch jede UI-Verdrahtung.

**GUEST ist strukturell nie owner** (Erzeugung ist authentifiziert; `ownerId = ownerId ?? createdBy` = der
eingeloggte Ersteller). Bestätigt am Code.

## Der Guard (existiert)

`AclService::hasAccess($p, $type, $id, 'read'|'write'|'manage'): bool` — `effectiveRight` mit
Admin-Bypass (`isAdmin()→manage`), sonst owner/Vorfahr-owner→manage, sonst Union der passenden ACEs
(max `none<read<write<manage`). `AclService::create()` als Factory vorhanden.

---

## Neuer Baustein: `Authz` (module-dms/Services)

Ein kleiner Helfer, den beide Services teilen — **eine** Principal-Quelle, **ein** Guard-Aufruf:

```
Authz::create(): self                         // wired: AclService + AuthService (via DI)
Authz::current(): Principal                    // aus getCurrentUser(), guest-sicher
Authz::require(type, id, level): void          // wirft NotFoundException, wenn !hasAccess(current, …)
```

`require` wirft **`NotFoundException` (404)** — kein Existenz-Leak, konsistent mit `OutputController`.
`DocumentService`/`FolderService` bekommen `Authz` via `create()` injiziert und rufen `require(...)`
als erste Zeile jeder gegateten Methode. (Test-fähig: in Tests ein Fake-`Authz` injizieren.)

## System- vs. User-Pfad (die Trennung)

Die Trennung ist **per Methoden-Identität**, kein fälschbares Flag:

| Pfad | Methoden | Gate |
|---|---|---|
| **System** (vertraut) | `DocumentService::saveGenerated` (modul-erzeugte Dateien), `SaveService::save` (Low-Level, von beiden genutzt), `rebuildMaterialization` (interner Seiteneffekt) | **kein** Gate |
| **User** (Drive/Host) | die Verwaltungs-Mutationen (Tabelle unten) + `UploadService::save` (User-Upload) | `Authz::require` |

Heute ruft **kein** System-Code die User-Mutationen (nur der Drive) — verifiziert. `saveGenerated` hat
aktuell keinen Aufrufer (Modul-Integrations-API). Braucht später ein Job echte Mutationen, kriegt er einen
**bewusst** injizierten System-Principal — nicht in diesem Schritt.

## Methode → Stufe (VORSCHLAG, zu bestätigen)

`DocumentService`:

| Methode | Ressource | Stufe | Anmerkung |
|---|---|---|---|
| `rename` (displayName) | document | **write** | reine Metadaten-Änderung |
| `move` | document | **manage** | Struktur (+ implizit write aufs Ziel via Folder) |
| `delete` (soft) | document | **manage** | |
| `restore` | document | **manage** | |
| `purge` (hart) | document | **manage** | destruktiv — evtl. admin-only? (Entscheid) |
| `setActive` | document | **manage** | Output-Gate |
| `setDeliveryMode` | document | **manage** | Öffentlichkeit |
| `setFolderDeliveryMode` | folder | **manage** | |
| `setFolderActive` | folder | **manage** | |
| `grant` / `revoke` | resource | **manage** | ACL-Änderung |
| `send` (E-Mail) | document | **read** | wer lesen/downloaden darf, darf senden |

`FolderService`:

| Methode | Ressource | Stufe | Anmerkung |
|---|---|---|---|
| `add` (parent gesetzt) | parent folder | **write** | Unterordner anlegen |
| `add` (parent = null, Top-Level) | — (Area-Root ist `null`) | **admin** | kein Ressource-Entity → admin-only (Entscheid) |
| `rename` | folder | **manage** | Slug → Media-Pfade (strukturell) |
| `move` | folder | **manage** | |
| `delete` | folder | **manage** | |

`UploadService::save` (User-Upload) | Ziel-folder | **write** | in einen Ordner schreiben |

**Lese-Methoden** (`listByFolder`/`listByArea`/`get`/`serve`/`resolve`/`effective*`/`acesFor`/…) — siehe
R-authz-2 (Scoping); im ersten Schritt ungegated (der Drive bleibt Admin-gemountet, s. u.).

---

## Phasen

### R-authz-1 — Mutations-Gates (der „boom"-Fix) ✅ FERTIG (2026-07-01)
- [x] `Authz`-Helfer (`current`/`require`/`requireAdmin`, wirft `NotFoundException`=404) + Factory.
- [x] `Authz` in `DocumentService`/`FolderService`/`UploadService` injiziert (via `create()`, selber
  Namespace → kein Import).
- [x] `require(type, id, level)` als erste Zeile jeder User-Mutation. **Entscheide (User 2026-07-01):**
  `rename`=**manage**, `delete`/`purge`=**manage** (nicht admin-only); Top-Level-Ordner (parent=null)=
  **admin** (`requireAdmin`); Sub-Ordner-add + Upload=**write** aufs Ziel/Parent; Principal **intern aus
  Session** (un-fälschbar); CLI-ohne-Session=abgelehnt (ok). `saveGenerated`/`SaveService::save`
  (System) bleiben ungegated.
- [x] Verifikation (Bootstrap-Smoke): **GUEST-Reject** — `delete`/`setActive`/`folder add`/`folder rename`
  ohne Session → alle `NotFoundException` (404). **Positiv** (AclService, fabrizierte Principals):
  admin→`manage` true (Bypass, auch auf nonexistent), Fremder(member,keine ACE)→`manage`/`read` false.
  `php -l` grün. **Live vom User bestätigt (2026-07-01):** als Admin rename/move/delete/mode/acl im
  Drive durchgeklickt — alles funktioniert, das Gate sperrt den Admin nicht aus (Reload genügte:
  vendor-Symlink + regenerierter Autoloader). member-mit-ACE folgt aus der getesteten `effectiveRight`-
  Policy (R2).
- [ ] **Zurückgestellt: `send`/`publish` (bis `send` eine UI bekommt).** `send` hat aktuell **keinen**
  Live-Aufrufer (E-Mail-UI in R6b entfernt) → 0 Risiko. **Entscheid User 2026-07-01: `publish` ist ein
  ORTHOGONALES Recht** (unabhängig von manage — „nicht jeder Manager darf senden"). Umbau bei Bedarf:
  `AccessControlEntry` muss dann **mehrere Rechte pro Subjekt** tragen (nicht ein linearer Wert) — d. h.
  `rights` wird von einem Skalar (`read|write|manage`) zu einer Menge, oder `publish` bekommt eigene ACEs;
  `effectiveRight`/`hasAccess`/grant-UI entsprechend erweitern. Erst bauen, wenn `send` eine UI kriegt.
- **Ergebnis:** keine Verwaltungs-Mutation ohne effektives Recht — unabhängig von Controller/Config.
  Der Drive ist damit **domänen-sicher**, auch wenn ein Host ihn public mountet.

### R-authz-2 — Lese-Scoping (Voraussetzung für nicht-Admin-Drive)
- [ ] `listByFolder`/`listByArea`/Tree nach `read`/`manage` des Principals filtern → ein Member sieht nur
  seinen Bereich (kein Area-Leak). Erst damit ist ein Member-/Owner-Drive ohne Info-Leak möglich.
- [ ] Bis dahin: der Drive bleibt **Admin-only** (Host-Config-Gate ADMIN als grobe erste Linie; das
  Domänen-Gate aus R-authz-1 ist die un-umgehbare zweite).

### R-authz-3 — weitere Akteure (später, aus ADR-017)
- [ ] Lieferanten-Upload (WRITE-ACE auf Eingangs-Ordner), API-Token → Principal → gleiche Policy,
  temporäres Freigeben (MANAGE), Team/Tenant-Besitz — alle laut ADR-017 deferred.

## Offene Entscheide (vor R-authz-1 bestätigen)

1. **Stufen-Mapping** (Tabelle oben) — insb.: `rename`=write vs manage? `purge`=manage vs admin-only?
   `send`=read vs write?
2. **Top-Level-Ordner anlegen** (parent=null): admin-only, oder eine Area-weite Rolle?
3. **Un-fälschbar (Principal aus Session, intern)** vs. injizierter Principal (testbarer, aber
   theoretisch fälschbar durch bösen Controller). Empfehlung: intern/un-fälschbar via `Authz` (der
   Fake-Inject für Tests bleibt möglich). Bestätigen.
4. **CLI/Smoke-Auswirkung:** gegatete Methoden ohne Session werden abgelehnt → Dev-Smokes müssen einen
   Principal setzen oder über den System-Pfad gehen. Akzeptiert?

## Defense-in-Depth (bleibt gestapelt)

- **Erste Linie (grob):** Host-Config-Rollen-Gate (`AccessGuard`) — Drive nie GUEST/public, eigene Group
  mit explizitem Per-Action-Block.
- **Zweite Linie (un-umgehbar):** Domänen-Gate (dieser Plan).
- **Auslieferung:** `OutputController` + `canRead` (schon vorhanden, getrennt).

## see also

- [`../topics/documents.md`](../topics/documents.md) — DMS-Topic; nach R-authz-1 Regeln + file map nachziehen
- [`dms-extraction-bauplan.md`](dms-extraction-bauplan.md) — `module-dms` self-contained (Vorbedingung)
- [`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md) — Besitz/ACL-Modell
