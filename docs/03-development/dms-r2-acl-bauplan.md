# dms-r2-acl-bauplan.md — R2 Autorisierungs-Policy (Resume-Sheet für neue Session)

**Status:** R2 ✅ FERTIG (R2a 2026-06-22, R2b 2026-06-23). **R2 komplett.** Nächster Schritt = R4
(Delivery verdrahtet `AclService::canRead()` vor jeder Byte-Auflösung). Diese Datei ist das
Aufsetz-Dokument: eine kalte Session kann damit weiterbauen, ohne neu zu recherchieren.
**Datum:** 2026-06-23
**Master-Plan:** [`dms-umbauplan.md`](dms-umbauplan.md) (R2-Abschnitt verweist hierher)
**Bindender Entscheid:** [`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md)

---

## Wo R1 aufgehört hat (Ausgangslage)

Additiv gebaut, System lauffähig, alte publish/serve-Kette läuft noch (visibility/publicPath
bleiben bis R5):
- `Document`: `+ownerId, +active, +slug, +width, +height, +deliveryMode`.
- `Folder`: `+ownerId, +active, +slug, +deliveryMode` (`deliveryMode` nullable = erbt).
- `AccessControlEntry` + `AccessControlEntryRepository` (`findByResource(type,id)`,
  `findForSubject(type,id)`), Collection `data/documents/access_control.json`.
- `SaveService` setzt `ownerId` (Fallback `createdBy`), `active`, `slug`, `width`/`height`.

R2 baut **nur Policy** — keine Auslieferung. `AclService` wird bis R4 von nichts konsumiert,
darum ist ein fertiger-aber-uncached Service ein sauberer Pausepunkt.

---

## ZUERST LESEN (in dieser Reihenfolge)

1. `packages/kernel/shared/src/Entities/AccessControlEntry.php` — ACE-Felder (resourceType `folder|document`,
   resourceId, subjectType `user|role`, subjectId (string), rights `read|write|manage`).
2. `packages/kernel/shared/src/Repositories/AccessControlEntryRepository.php` — `findByResource`, `findForSubject`.
3. `packages/kernel/shared/src/Entities/Folder.php` + `packages/kernel/shared/src/Repositories/FolderRepository.php`
   — `ownerId`, `active`, `parentId` (TreeNodeTrait), `findByArea(area)`.
4. `packages/kernel/shared/src/Entities/Document.php` — `ownerId`, `active`, `folderId`, `area`, `deliveryMode`.
5. `packages/kernel/shared/src/Tree/TreeService.php` — `index($nodes)` (id→node), Ahnen-Walk via `parentId`
   (siehe `rootOf`/`isDescendantOf` als Muster; für R2 die volle Kette mit Guard sammeln).
6. `packages/kernel/shared/src/Services/AuthService.php` — `getCurrentUser(): AuthUser` (immer ein User).
7. `packages/kernel/shared/src/Auth/AuthUser.php` — `getId()` (0 = guest), `getRoles(): string[]`,
   `hasAtLeast(AuthRole::ADMIN)`.
8. `packages/kernel/core/src/Config/AuthRole.php` — Rollen + Hierarchie (`ADMIN=80`, `SUPER_USER=100`).

---

## Entscheidungen aus ADR-017 (verbindlich für R2)

- ACL gilt **nur für `deliveryMode = protected`**. `public` = Liefermodus (keine Prüfung,
  GUEST ist KEIN ACE-Subjekt); `sealed` = nie ausserhalb `data/blobs`. Das deliveryMode-
  Branching macht aber **R4** — `AclService` berechnet nur Rechte.
- ACE-Subjekt: `user | role` (Rollen aus `AuthRole`: member, visitor, …).
- **Admin / Super_user = Bypass** → volles Recht (`hasAtLeast(AuthRole::ADMIN)`).
- **Owner = implizit voll.** Owner des Dokuments ODER eines Vorfahr-Ordners → full
  (Owner eines Ordners besitzt den Teilbaum). _(Diese „Owner eines Vorfahren zählt"-Regel
  beim Aufsetzen kurz mit dem Entwickler bestätigen.)_
- **Effektives Recht** (kein Bypass/Owner) = Vereinigung der ACEs auf dem Dokument + allen
  Vorfahren-Ordnern, deren Subjekt auf die User-id ODER eine Rolle des Principals passt;
  Recht = Maximum auf der Leiter `read < write < manage`.
- **Output-Gate** = `active` (self + alle Vorfahren live) **UND** effektives `read`.

---

## Sub-Phasen (jede = sauberer Pausepunkt)

### R2a — AclService-Kern (uncached) + Principal + Smoke  ✅ FERTIG (2026-06-22)
Gebaut: `packages/kernel/shared/src/ValueObjects/Principal.php` + `packages/kernel/shared/src/Services/AclService.php`
(`effectiveRight`, `hasAccess`, `canRead`, `create`). Ahnenkette via Folder-`parentId`-Walk
(inline Index + Guard, ohne TreeService — äquivalent, weniger Abhängigkeiten). Throwaway-Smoke
mit 13 Checks grün (owner / folder-owner / user-ACE / role-ACE-Vererbung / admin / guest /
inaktiv doc+folder), `php -l` sauber, Docs nachgeführt. **Unten = Referenz, wie umgesetzt.**
Ziel: korrekte Policy, testbar, ohne Caching.

**Neue Dateien:**
- `packages/kernel/shared/src/ValueObjects/Principal.php` — VO `{ int $userId; string[] $roles }`
  + `isAdmin()` (hasAtLeast ADMIN via AuthRole-Hierarchie) + statisch `fromAuthUser(AuthUser)`.
  (Smoke baut Principals direkt → keine Session nötig; R4 baut ihn aus `AuthService`.)
- `packages/kernel/shared/src/Services/AclService.php` — Policy. Vorgeschlagene API:
  ```php
  final class AclService {
      public static function create(): self;                 // DI: ACE-Repo + Folder-Repo
      // 'none'|'read'|'write'|'manage'
      public function effectiveRight(Principal $p, string $resourceType, int $resourceId): string;
      public function hasAccess(Principal $p, string $resourceType, int $resourceId, string $required = 'read'): bool;
      public function canRead(Principal $p, Document $doc): bool;   // active-chain UND read
  }
  ```

**Algorithmus `effectiveRight`:**
1. `$p->isAdmin()` → `'manage'` (Bypass, vor allem anderen — Kurzschluss).
2. Ahnenkette bestimmen: Dokument → `folderId` → Ordnerkette nach oben; Ordner → sich selbst
   + Kette nach oben. Ordner der Area via `FolderRepository::findByArea($area)`, `TreeService::index()`,
   dann `parentId` hochlaufen mit Zyklus-Guard (max ~50, wie `TreeService::rootOf`).
3. Owner: `$p->userId > 0` und (`resource.ownerId === userId` ODER ein Vorfahr-Ordner
   `ownerId === userId`) → `'manage'`.
4. Sonst ACEs sammeln: Dokument (`findByResource('document', id)`) + jeder Vorfahr-Ordner
   (`findByResource('folder', folderId)`). Treffer = `subjectType='user' && subjectId===(string)userId`
   ODER `subjectType='role' && subjectId ∈ roles`. Effektiv = Maximum der Treffer-Rechte.
5. Kein Treffer → `'none'`.

**`canRead`** = `effectiveRight ≥ read` UND `active`-Kette (`doc.active` und alle Vorfahren-`active`).
Rechte-Leiter als const-Map in `AclService` (`none=0,read=1,write=2,manage=3`).

**Smoke (Wegwerf-CLI im Skeleton, wie R1, danach löschen):**
- owner des Dokuments → manage
- owner eines Vorfahr-Ordners → manage auf Dokument
- ACE user `read` auf Dokument → read
- ACE role `write` auf Vorfahr-Ordner → write (Vererbung)
- admin-Bypass → manage ohne ACE
- guest / keine ACE → none
- inaktives Dokument → `canRead` false trotz read
- inaktiver Vorfahr-Ordner → `canRead` false
- Cleanup aller Smoke-Artefakte (Doc/Folder/ACE + Blobs) am Ende.

**Done R2a:** Smoke grün, `php -l` sauber, `docs.md`/dieser Plan/Umbauplan nachgeführt, `docs:check` grün.

### R2b — Caching + Invalidierung  ✅ FERTIG (2026-06-23)
Zwei APCu-Schichten über `DataCache` (`DI::getCacheManager()->data()`), Policy-Semantik 1:1 wie R2a:
- **Layer 1 (principal-unabhängige Inputs):** `folderIndex(area)` (id → `{parentId, ownerId, active}`)
  + `aceIndex()` (alle ACEs als EIN Eintrag, gruppiert nach `<type>:<id>`). Beseitigt die
  redundanten Datei-Reads — R2a las `access_control.json` einmal **pro** Ahnen-Ordner (N+1),
  `FileStorage::load()` cacht nichts. Jetzt ein Load, dann reine In-Memory-Rechnung.
- **Layer 2 (Ergebnis):** effektives Recht pro `(principal-signature, type, id)` → wiederholter
  Lookup (R4 liefert dasselbe Dokument an denselben User) = reiner Hit ohne I/O. Signatur =
  `userId-md5(sortierte roles)`; Rollenwechsel baut den Principal neu → neue Signatur → kein Stale.
- **Invalidierung:** keine feinere nötig. `AccessControlEntry`/`Folder`/`Document` tragen alle
  `invalidatesCache: true` → jeder Write ruft `FileEntityManager::invalidateCache()` →
  `clearAllApcu()` + `page()->clearAll()`. Beide Layer fallen zusammen. APCu ist prozessweit
  geteilt → cross-request korrekt. Entscheid (statt „Ergebnis-only cachen"): Inputs **und**
  Ergebnis cachen (Entwickler-Entscheid 2026-06-23).
- **Verifiziert** (Wegwerf-CLI-Smoke, 16 Checks grün): 13 Policy-Werte identisch zu R2a +
  „APCu-Hit ignoriert geleerte ACE-Datei" (Cache greift cross-request) + „nach `clearAllApcu`
  → Neuberechnung → none" (Invalidierung greift). `php -l` sauber, Testdaten restauriert.

### NICHT R2 (gehört zu R4 — nur Querverweis)
- Verbindliche Auslieferungs-Reihenfolge (ACL-READ + active **vor** Blob-Pfad-Auflösung),
  404-statt-Leak, deliveryMode-Branching (protected=php+ACL, public/shared=statisch).
  Steht in [`dms-umbauplan.md`](dms-umbauplan.md) R4.

---

## Stolpersteine (aus R1 gelernt)

- Repository wird per `{Entity}Repository` **automatisch** gewired — Namen exakt treffen.
- `findBy`-Kriterien sind **snake_case** Keys (z.B. `resource_type`, `resource_id`); `matches()`
  ruft `Naming::toGetter($key)`.
- CLI-Smoke braucht im Bootstrap zusätzlich `ModuleManager` registriert (sonst wirft
  `ImageProfileRegistry::fromModules()`), plus `DataSourceResolver` + `UnifiedEntityManager`.
  Muster: `new Bootstrap();` dann diese drei in DI setzen.
- `skeleton/vendor/z77/*` sind Symlinks → Edits in `packages/` sind im Install sofort live.
- `docs:check`-FAIL `mail.md → /skeleton/config/mail.inc.php` ist erwartet (Skeleton ephemer,
  Mail nicht konfiguriert) — NICHT reparieren.

## see also
- [`dms-umbauplan.md`](dms-umbauplan.md) — Master (R1 ✅, R2 hierher, R3–R7)
- [`../topics/documents.md`](../topics/documents.md) — Topic-Doc (file map, Regeln, Stand)
