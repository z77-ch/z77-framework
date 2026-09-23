# contact

2026-09-22

## entry

1. `packages/module-contact/src/Services/ContactService.php` — the write side: a contact with n typed addresses in one flush, add / edit / remove an address, deactivate; every rule that must hold no matter who writes
2. `packages/module-contact/src/Entities/ContactAddress.php` — the typed link contact ↔ address, and where the file-based `AddressType` is referenced by code (ADR-043 decision 19)
3. `tests/module-contact.php` — the harness against MariaDB: the migration through `z77-db`, the round trip, validation, the reference rule, the search

## file map

SOURCE=/packages/module-contact/composer.json
SOURCE=/packages/module-contact/README.md
SOURCE=/packages/module-contact/src/App/Config/contactConfig.inc.php
SOURCE=/packages/module-contact/src/Entities/Contact.php
SOURCE=/packages/module-contact/src/Entities/ContactKind.php
SOURCE=/packages/module-contact/src/Entities/Address.php
SOURCE=/packages/module-contact/src/Entities/ContactAddress.php
SOURCE=/packages/module-contact/src/Entities/AddressType.php
SOURCE=/packages/module-contact/src/Repositories/ContactRepository.php
SOURCE=/packages/module-contact/src/Repositories/ContactAddressRepository.php
SOURCE=/packages/module-contact/src/Repositories/AddressTypeRepository.php
SOURCE=/packages/module-contact/src/Validators/ContactValidator.php
SOURCE=/packages/module-contact/src/Validators/AddressValidator.php
SOURCE=/packages/module-contact/src/Validators/ContactAddressValidator.php
SOURCE=/packages/module-contact/src/Validators/AddressTypeValidator.php
SOURCE=/packages/module-contact/src/Services/ContactService.php
SOURCE=/packages/module-contact/src/Services/AddressTypes.php
SOURCE=/packages/module-contact/src/Services/AddressTypeMasterData.php
SOURCE=/packages/module-contact/src/Services/ContactException.php
SOURCE=/packages/module-contact/src/Services/InvalidContactException.php
SOURCE=/packages/module-contact/src/Services/InvalidAddressException.php
SOURCE=/packages/module-contact/src/Services/AddressTypeCodeChangedException.php
SOURCE=/packages/module-contact/src/Ui/ContactControllerTrait.php
SOURCE=/packages/module-contact/src/Ui/ContactLayout.php
SOURCE=/packages/module-contact/src/Ui/AddressTypeControllerTrait.php
SOURCE=/packages/module-contact/src/Ui/AddressTypeLayout.php
SOURCE=/packages/module-contact/res/migrations/Version20260921153209.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/listAction.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/edit.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/editAddress.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/actions.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/confirmRemoveAddress.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/_address.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/ContactController/_addressFields.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/AddressTypeController/listAction.tpl.php
SOURCE=/packages/module-contact/res/view/templates/Backend/AddressTypeController/edit.tpl.php
SOURCE=/packages/module-contact/data/framework/contact/address_types.default.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Contact/ContactController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Contact/AddressTypeController.php
SOURCE=/packages/module-backend/src/Ui/Config/Contact/contactControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Contact/addressTypeControllerConfig.inc.php
SOURCE=/packages/module-backend/res/view/templates/Contact/ContactController/list.hc1.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Contact/ContactController/list.hc2.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Contact/AddressTypeController/list.hc1.tpl.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/tests/module-contact.php
SOURCE=/docs/02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md
SOURCE=/docs/02-decisions/adr-040-business-module-cut.md
SOURCE=/docs/02-decisions/adr-043-order-status-and-stock-movements.md
SOURCE=/docs/03-development/order-debtor-financial-bauplan.md

## mental model

`z77/module-contact` (plan §4a, ADR-040) holds the one party every business module shares: a **contact** — person or organisation — with **n typed addresses**. There is no «order customer» and no «debtor customer»; debtor and order reference a contact by id and take the address they need through its type, and each document stores the address it used as a SNAPSHOT, so a later change here never alters an issued document (built: debtor's `AddressSnapshot` embeddable on `invoice`, 2026-09-23 — `debtor.md`). Module-specific data (payment terms, dunning block, order defaults) stays in its module, keyed by contact id — this module knows none of it. The first module with Doctrine tables and therefore with migrations (`res/migrations`).

- **Three Doctrine entities** (`#[Entity('doctrine')]`, announced in `contactConfig.inc.php` → `doctrineEntities`): `Contact` (table `contact`: `kind`, `company`, `first_name`, `last_name`, `language`, `email`, `phone`, `active`), `Address` (table `address`: `salutation`, `title`, `first_name`, `name`, `address_row`, `street`, `house_no`, `zip`, `city`, `country`), `ContactAddress` (table `contact_address`: `contact_id` FK, `address_id` FK, `type_code`, `title`; unique on the three). `Contact` ⟵ OneToMany ⟶ `ContactAddress` ManyToOne `Address`, both with `cascade: persist`, so a NEW contact built with its links is ONE `persist()` + `flush()`. Two links may share one address row (the wdv `Addressing` shape).
- **Kind decides the required name part** (`ContactKind`: `person` / `organisation`): an organisation needs `company`, a person `lastName`; the other is optional (a contact person at a company). `displayName()` phrases it once for every list.
- **`AddressType` is file-based master data** (`data/framework/contact/address_types.json`, seeded once from `address_types.default.json`: `main`, `invoice`, `delivery`, `regional`, German labels). Referenced BY `code` from `contact_address.type_code` — no foreign key across the two drivers (ADR-043 decision 19). Codes are lower-case kebab (`[a-z][a-z0-9-]{1,15}`), normalized at the setter.
- **The reference rule in code**: a NEW link needs an ACTIVE type, and an existing link is never SWITCHED to a deactivated one (`ContactAddressValidator`, `$requireActiveType` — set by the service for a new link or a changed code; owner 2026-09-21); an EXISTING link KEEPS a deactivated type and stays editable (`AddressTypes::labelOf()` answers for any existing code and never throws — the raw code shows when a `data/` restore lost the row); the code of a type is immutable (`AddressTypeMasterData::save()` → `AddressTypeCodeChangedException`); NO delete of a type anywhere. The type's LABEL is not snapshotted on the link — the link is master data, not a document; documents snapshot the whole address (§4a). A `resolve(code)` that returns the row arrives with the snapshot consumer (P3), not before.
- **A contact is deactivated, never deleted** — `ContactService` has no delete, the backend trait no delete action; `active = false` means «not offered for new documents», existing references keep resolving. An ADDRESS link may be removed (`removeAddress()`); the address row goes only with its LAST link.
- **Write side = `ContactService`**: `save($newContact)` (with its attached links, one flush), `update($contact, $values)` / `setActive()` for an existing contact, `addAddress($link)`, `saveAddress($link, $linkValues, $addressValues)`, `removeAddress($link)`. Every write runs the validators and throws `InvalidContactException` (carries `ContactValidator` + the draft) or `InvalidAddressException` (carries `ContactAddressValidator` + `AddressValidator` + the draft link), so an import or a shop checkout is bound to the same rules as the backend. One `flush()` per operation is one Doctrine transaction (ADR-039 decision 10) — no transaction port needed here.
- **Validate BEFORE mutating a managed entity** (ADR-039 decision 9, flush scope — the reviewer's probes of 2026-09-21): Doctrine writes every managed entity that changed, `persist()`ed or not, so a rejected change applied to a loaded entity would be written by the NEXT flush of anything else in the request — and inside `getTransaction()->run()` by the port's own flush (DOCTRINE-TX-007). Therefore a change to an EXISTING contact or address reaches the service as cleaned VALUES: `update()` and `saveAddress()` apply them to a detached draft (`clone`, `__clone` gives the draft an empty collection), validate the draft, and only then apply them to the managed entity; `addAddress()` attaches the link to the contact's collection only after it passed (the `ContactAddress` constructor never attaches — `Contact::addAddress()` does). The backend trait never calls `mapFromArray()` on a loaded entity (source guard in the harness); a refused change comes back as the exception's draft and is what the form shows again.
- **Unique constraint under a race** (`uniq_contact_address_type`): two requests can pass the validators and link the same address twice under one type, and only the database sees the collision — `ContactService` catches `UniqueConstraintViolationException`, recognises the index by name and rethrows it as the same field error the validator gives (`ContactAddressValidator::flagFieldError()`), so the form re-renders instead of a 500. After that failed flush the EntityManager has been replaced (DOCTRINE-TX-004); the entity in the exception is detached and serves the form only.
- **Validation** (`ContactValidator`, `AddressValidator`): `language` ISO 639-1 lower-case; `email` optional but deliverable when set (`MailAddress::isDeliverable`, reserved TLDs refused); address: `name`, `street`, `zip`, `city`, `country` required, `country` ISO 3166-1 alpha-2 upper-cased, zip **four digits for CH**, 3–10 characters elsewhere. No zip directory (wdv `ChZipCode`) — an unmaintained directory refuses real addresses.
- **Reads through the unified API** (ADR-039 decision 6): `getRepository(Contact::class)` → `ContactRepository`, `ContactAddress` → `ContactAddressRepository`, `AddressType` → `AddressTypeRepository` (File). **Doctrine-only** methods (decision 8, documented deviations): `ContactRepository::search($q, $limit)` / `countMatching($q)` — SQL on `connection()` selecting ids (company / last / first name in either order / e-mail; `LIKE … ESCAPE '!'` with `!`, `%`, `_` escaped, so it holds under `NO_BACKSLASH_ESCAPES` too), hydrated through `findBy(['id' => …])`, sorted by the shown name (`IF(kind = 'organisation', company, last_name)` — an expression, no index serves it; the limit keeps it cheap); `ContactAddressRepository::findByContact()` / `findForContacts()` — DQL on `em()` with a FETCH-JOIN on the address (one query per screen, no lazy proxy per link — 200 contacts were ~400 queries before); `countByAddress()` / `countByTypeCode()` — SQL on `connection()`.
- **Migration** `Version20260921153209` (`res/migrations`, namespace `Z77\Module\Contact\Migrations`): generated with `z77-db diff` against an empty database, reviewed — `utf8mb4_unicode_ci` on every table, `ENGINE = InnoDB` spelled out, expand only. `tests/module-contact.php` applies it through the `z77-db` application (never `SchemaTool`) and proves `diff` reports «No changes» afterwards.
- **Backend screens**: the fragment pattern (ADR-018, like `module-vat`): logic and templates in `module-contact` (`Ui/ContactControllerTrait`, `Ui/AddressTypeControllerTrait`, `res/view/templates/Backend/…`), thin hosts in `module-backend` `Ui/Controllers/Contact/`, layouts pinned by `Ui/Config/Contact/*ControllerConfig.inc.php` → `ContactLayout::config()` / `AddressTypeLayout::config()`, hc1 (add) and hc2 (search) slots in the backend namespace. Backend group **`contact`** (`groupDefaults['contact'] = 'contact'`, ADMIN, `list` convention, no `controllers` entry — proposed, see pending). URLs `/backend/contact/contact/list` (actions `add`, `edit`, `toggle-active`, `actions`, `add-address?id=`, `edit-address?id=`, `confirm-remove-address?id=`, `remove-address`) and `/backend/contact/address-type/list` (`add`, `edit`, `toggle-active`). The contact form carries an OPTIONAL first address on «add»; addresses are managed in the ⋮ hub. The search is a plain GET form (`?q=`), the list shows at most `contactListLimit` rows (contactConfig, default 200, read by `ContactService::listLimit()`) and says how many match. No CSS and no JavaScript of its own; the address fields post under the `address_` prefix (`first_name` and `title` exist on the contact / the link too).
- **Entity-token contexts**: `contact` (id), `contactAddressAdd` (contact id — a new link has no id), `contactAddress` (link id), `addressType` (id).
- **The navigation entry is NOT in the kernel seed** (a host entry that fatals without the module would be wrong on a fresh install): a project adds «Kontakte» → `/backend/contact/contact/list` and «Adresstypen» → `/backend/contact/address-type/list` in the backend, like `member-accounts` and `tax-code`.
- **Where the package is required**: the monorepo ROOT `composer.json` (so `vendor/` carries it for the harness); NOT `skeleton/composer.json` — a project requires it when it needs it (owner 2026-09-21, as for module-vat). No `RUNTIME=` paths therefore. Requires PHP 8.4 like `persistence-doctrine`.
- **The module has no routes and no view area**: `contactConfig.inc.php` carries no `defaultGroup`; `/contact/…` resolves nothing. It is a library plus two fragments.

## rules

- When another module needs a party (debtor's `DebtorProfile`, an order's customer) → MUST reference the `Contact` by id and keep its own data in its own module keyed by that id; MUST NOT add module-specific columns to `contact`
- When a document (quote, order, invoice, dunning notice) uses an address → MUST store a SNAPSHOT of the address fields on the document; MUST NOT hold a foreign key to `address` or `contact_address` that a later edit would change (§4a)
- When writing a `Contact` or a `ContactAddress` from any code path (backend, import, checkout) → MUST go through `ContactService` (`save()`, `update()`, `setActive()`, `addAddress()`, `saveAddress()`, `removeAddress()`); MUST NOT `persist()` these entities directly — the validators and the reference rule live there
- When changing an EXISTING (managed) contact, link or address → MUST hand the cleaned values to `ContactService::update($contact, $values)` / `saveAddress($link, $linkValues, $addressValues)`; MUST NOT call `mapFromArray()` or a setter on the loaded entity first — a refused change would be written by the next flush of anything else (ADR-039 decision 9; `persistence-doctrine.md` rule)
- When building a NEW contact with its addresses → MUST attach each link with `$contact->addAddress(new ContactAddress($contact, $typeCode, $address, $title))` and call `save($contact)` ONCE; MUST NOT call `addAddress()` on the service for a contact without an id (refused)
- When adding an address to an EXISTING contact → MUST build the link unattached and pass it to `ContactService::addAddress($link)`, which attaches it after validation; MUST NOT call `Contact::addAddress()` on a managed contact yourself
- When choosing an address type for a NEW link or CHANGING a link's type → MUST offer `AddressTypes::active()` only (the service refuses a deactivated target); when displaying an EXISTING link → MUST resolve through `AddressTypes::labelOf()`, which answers for a deactivated type as well
- When a `UniqueConstraintViolationException` can only come from a race the validator cannot see → MUST let `ContactService` map it to the field error (`flagFieldError()`), MUST NOT catch it in a controller and MUST NOT re-use the detached entity for anything but the form
- When a type is no longer needed → MUST deactivate it (`AddressTypeMasterData::setActive()`); MUST NOT delete the row or add a delete action (ADR-043 decision 19 — `contact_address.type_code` references it)
- When writing an `AddressType` → MUST go through `AddressTypeMasterData::save()`; MUST NOT change `code` on an existing row (refused with `AddressTypeCodeChangedException`) — a different kind of address is a new type
- When a contact is no longer needed → MUST deactivate it (`active = false`); MUST NOT delete the row or add a delete action — documents reference it by id
- When removing an address from a contact → MUST use `ContactService::removeAddress($link)`, which drops the address row only when no other link points at it; MUST NOT `remove()` an `Address` directly
- When the list needs a query the interface cannot express (search, counts) → MUST add it to the entity's repository as SQL on `connection()` marked Doctrine-only, selecting ids and hydrating through `findBy()`; MUST NOT hydrate the whole table to filter in PHP
- When a screen renders an association of every row (the address of every link) → MUST load it fetch-joined in the repository (DQL on `em()`, `findForContacts()` is the model) and MUST NOT let the template touch a lazy proxy per row (N+1)
- When composing a `LIKE` from user input → MUST escape `!`, `%` and `_` and name the escape character (`ESCAPE '!'`); MUST NOT rely on the backslash (`NO_BACKSLASH_ESCAPES` makes it literal)
- When adding a column or an entity to this module → MUST run `php vendor/bin/z77-db diff --namespace="Z77\Module\Contact\Migrations"` and commit the migration with the entity, expand/contract (ADR-039 decisions 12–14); MUST NOT change an existing migration that an installation may have applied
- When adding a validation on `Contact` / `Address` / `ContactAddress` / `AddressType` → MUST put it into the matching validator (the write service and the backend run them); MUST keep the kind-dependent required fields and the CH zip rule intact
- When a template shows a typed address → MUST render the `Backend/ContactController/_address` partial (namespace `Z77\Module\Contact`); when a form edits one → MUST render `_addressFields` with the `address_` prefix; MUST NOT phrase or lay out the fields inline
- When mounting the backend screens elsewhere (another group, a project backend) → MUST `use ContactControllerTrait` / `AddressTypeControllerTrait`, delegate the controller layout config to `ContactLayout::config()` / `AddressTypeLayout::config()`, and override `contactListBase()` / `addressTypeListBase()` to the mount's URL root — the templates build every URL from it
- When an installation needs a different list size → MUST set `contactListLimit` (a positive int) in the project's override copy of `contactConfig.inc.php` — today the FULL config, because an override replaces the package file (first source match; framework-wide, BOOT-CONFIG-001 in `bootstrap.md`, proposed: override merges as deviation only); MUST read it only through `ContactService::listLimit()`, which throws `UnexpectedValueException` for 0, a negative number, a string, a float or null instead of falling back; MUST NOT hard-code a limit in the trait or the template
- When a kind needs a German label in a screen → MUST read it from the UI layer (`KIND_LABELS` in the trait); MUST NOT put display text into `ContactKind`
- When editing `address_types.json` or the `*.default.json` seed by hand → MUST keep UTF-8 without BOM; MUST NOT round-trip it through Windows PowerShell (DATA-JSON-001, `persistence-file.md`)
- When adding a method to this package → MUST have a production caller in the same change (CLAUDE.md «no just-in-case», plan «nothing in stock»); snapshot serialisation, pickers and the like arrive with the module that needs them

## known issues

- **CONTACT-TYPE-001** — resolved 2026-09-21 (owner decision): switching an existing link TO a deactivated type is refused by `saveAddress()` (the target of a type CHANGE must be active); keeping the link's current, deactivated type on edit stays allowed. Don't assume «exists» is enough for a changed code.
- **CONTACT-ORPHAN-001** — don't assume `removeAddress()` never leaves an address row behind: two requests removing the two last links of one shared address at the same moment can both count «still referenced» (the count runs before the delete, outside a lock) and neither removes the row. Harmless — an unreferenced `address` row is dead data, no screen reaches it; no lock was added for it.
- **CONTACT-SEED-001** — don't assume an existing installation receives the type seed or a later seed change: the installer walk is seed-once per FILE (`data/framework/contact/address_types.json` present → untouched). The record-level import (ADR-032) is not wired for `AddressType` (see pending); a new type goes in through the backend.
- **CONTACT-NAV-001** — don't expect «Kontakte» / «Adresstypen» entries in the navigation after install: the kernel seed carries none (a host entry that fatals without `module-contact` would be wrong on every fresh install). The project adds them under a section of its choice. Checked 2026-09-22 (P2 exit check S4): no «Finanzen» section is seeded either; a project that keeps contacts with its bookkeeping adds them to the «Finanzen» section it creates itself (`financial.md` FIN-NAV-001).
- **CONTACT-MEMBER-001** — resolved 2026-09-21 by removal: don't look for a link from a contact to a member account. `memberAccountId` removed (owner, 2026-09-21) — added back with the first consumer (customer portal/shop). It had no caller and no named purpose (plan «nothing in stock»); column, unique index, validator rule, repository lookup, race mapping and form field went with it, and the first migration was adjusted in place before it was ever committed.
- **CONTACT-LIST-001** — don't assume the list shows every contact: it stops at `contactListLimit` rows (contactConfig, default 200 — configurable since 2026-09-21, owner decision) and says «n von m angezeigt»; the search narrows. No pagination yet — a screen for thousands of contacts is a later step.
- **CONTACT-FK-001** — don't assume the foreign keys of `contact_address` carry readable names: Doctrine names them by hash (`FK_97614E00E7A1254A`, `IDX_…`), and the migration keeps those names so `diff` stays clean. Renaming them by hand would be reported as a change.

## pending

- **Backend group `contact`** — proposed here (`groupDefaults['contact'] = 'contact'`, URLs `/backend/contact/…`), not owner-confirmed. Alternatives: the `finance` group next to the tax codes, or a broader «Stammdaten» group. Renaming touches `backendConfig`, the two host controllers, the `*ListBase()` defaults and this doc.
- **Snapshot shape** — closed 2026-09-23 with the invoice (`debtor.md`, P3 part 2): a document stores the ten `Address` fields as FLAT COLUMNS through an embeddable of the consuming module (`Z77\Module\Debtor\Entities\AddressSnapshot`, `#[ORM\Embedded(columnPrefix: 'addr_')]` on `invoice`), filled with `AddressSnapshot::of($address)` at issue — no JSON, no reference to `address` / `contact_address`; this module still carries no serialisation of its own. The invoice takes the contact's `invoice`-typed link, else `main`, else the first, by `ContactAddress::getTypeCode()` — so `AddressTypes::resolve(code)` was NOT needed and stays unbuilt (no caller); it comes back if a consumer ever snapshots the TYPE rather than the address.
- **Import of `AddressType`** (adopt a seed change into an existing installation, ADR-032): needs `#[ImportIdentity(['code'])]`, `importEntities` in `contactConfig`, and the module-side validator seam module-vat is waiting for too (`vat.md` pending). Same blocker, same fix.
- `tests/module-contact.php` covers the traits only by reflection (no delete actions); a request-level check of the screens (mount, hc1/hc2 slots, toggle, the optional address block on «add») is manual in a project installation until a controller harness exists.
- Publishing: the package is not a split target yet (`.github/workflows/split.yml`, repo `z77-ch/module-contact`, Packagist) — owner's step when the package is ready to publish.

## see also

- [`persistence-doctrine.md`](persistence-doctrine.md) — the driver behind the three entities, `doctrineEntities`, `z77-db diff` / `migrate`, the deploy order for the first migration
- [`persistence-file.md`](persistence-file.md) — the File driver `AddressType` lives on; seed-once `*.default.json`; the no-PowerShell rule for `data/**/*.json`
- [`vat.md`](vat.md) — the sibling module this one mirrors: fragment pattern, deactivate-never-delete, the reference rule for file-based master data
- [`debtor.md`](debtor.md) — the first consumer of the address snapshot: `AddressSnapshot` (ten flat `addr_*` columns on `invoice`), taken from the `invoice` / `main` link at issue
- [`backend.md`](backend.md) — the fragment/host mount pattern, header slots (`hc1`, `hc2`), the deviation-only `backendConfig`
- [`../02-decisions/adr-043-order-status-and-stock-movements.md`](../02-decisions/adr-043-order-status-and-stock-movements.md) — decision 19, the reference rule for file-based master data held by Doctrine rows
