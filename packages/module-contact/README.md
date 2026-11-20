# z77/module-contact

Contacts for the z77 business modules: one **contact** — person or organisation — with **any
number of typed addresses** (main, invoice, delivery, …). There is no separate «order customer» or
«debtor customer»: every module references the contact by id, takes the address it needs by its
type, and stores a snapshot of it on its document — a later change here never alters an issued
document. This module knows no other module; others contribute **roles** to a contact (e.g.
module-debtor's «Debitor», module-creditor's «Kreditor») through a registry.

Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-contact`); not yet a split target or on Packagist — projects consume it through
a `path` repository until it is. MIT licence.

## What it does

- **Entities** (Doctrine, tables `contact`, `address`, `contact_address`): `Contact` (kind,
  company, first / last name, language, e-mail, phone, active), `Address` (salutation, title,
  first name, name, address row, street, house number, zip, city, country, e-mail, phone,
  «Briefanrede» — the greeting of a mail to this address), `ContactAddress` (the typed link with
  an optional title; two links may share one address row).
- **Address types** (`Entities\AddressType`, file master data
  `data/framework/contact/address_types.json`, seeded once: Hauptadresse `main`,
  Rechnungsadresse `invoice`, Lieferadresse `delivery`, Regionaladresse `regional`, Newsletter
  `newsletter`), referenced by code, deactivated and never deleted (ADR-043 decision 19).
- **Write side** (`Services\ContactService`): `save()` a new contact with its addresses in one
  flush, `update()` / `setActive()`, `addAddress()`, `saveAddress()`, `removeAddress()`. Every
  write is validated BEFORE a managed entity is touched (ADR-039 decision 9), so an import or a
  shop checkout is bound to the same rules as the backend. A contact is deactivated, never
  deleted.
- **Validation** (`Validators\*`): the name part the kind requires (organisation → company,
  person → last name), ISO language and country, zip four digits for CH, deliverable e-mail.
- **Building blocks for other modules**: `Ui\ContactForm` and the partials `_contactFields`,
  `_addressFields`, `_address` (the «new contact» form another module embeds — e.g. debtor's
  «+ Kontakt»), and the **contact-role registry** (`Roles\ContactRoles`, config key
  `contactRoles` → a class implementing `Roles\ContactRoleInterface`).

```php
$service = new ContactService(DI::getUnifiedEntityManager());

$contact = new Contact(['kind' => 'organisation', 'company' => 'Muster AG', 'language' => 'de', 'email' => 'info@example.ch']);
$contact->addAddress(new ContactAddress($contact, 'main',    new Address(['name' => 'Muster AG', 'street' => 'Bahnhofstrasse', 'house_no' => '1', 'zip' => '8001', 'city' => 'Zürich', 'country' => 'CH'])));
$contact->addAddress(new ContactAddress($contact, 'invoice', new Address(['name' => 'Muster AG', 'address_row' => 'Buchhaltung', 'street' => 'Postfach', 'zip' => '8021', 'city' => 'Zürich', 'country' => 'CH', 'email' => 'buchhaltung@example.ch'])));
$service->save($contact);   // one flush: contact, two addresses, two typed links

$service->update($contact, ['phone' => '+41 44 123 45 67']);   // validated on a draft, then applied
AddressTypes::from($em)->labelOf('invoice');                   // «Rechnungsadresse»
```

## Requirements

PHP 8.4, `z77/kernel`, `z77/persistence-doctrine`.

## Enabling it in a project

1. Require the package (`composer require z77/module-contact`, or the monorepo's `path`
   repository); `composer install` / `update` adds the navigation entries of
   `data/framework/routing/navigation.d/module-contact.json` and the installer seeds
   `address_types.json` once (an existing installation does not receive a later seed change —
   add a new type in the backend).
2. Register the namespace in the project's `composer.json` `autoload.psr-4`:
   `"Z77\\Module\\Contact\\": ["override/z77/module/contact/src/"]`
   — an unregistered package is installed but not a module, and `z77-db` does not see its
   migrations ([Switch the modules on](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md#2-switch-the-modules-on)).
3. Apply the migrations (`res/migrations`, namespace `Z77\Module\Contact\Migrations`):
   `php vendor/bin/z77-db migrate`.
4. Optional: the list size `contactListLimit` (default 200) in the project's copy of
   `contactConfig.inc.php`.

## Backend screens

Mounted by `z77/module-backend` (`src/Ui/Controllers/Contact/`); the forms are modal dialogs, the
help sits in the toolbar of each list:

| Menu | URL | What |
|---|---|---|
| Kontakte | `/backend/contact/contact/list` | search, «+ Kontakt», the active switch, ⋮ → edit, add / edit / remove an address; the roles other modules register |
| Stammdaten › Kontakte › Adresstypen | `/backend/contact/address-type/list` | the address types: label, active switch, usage count |

## Not built yet

- Paging the contact list (it shows the first `contactListLimit` matches and says how many match).
- The newsletter to the «Newsletter» address (module-mailing, step 4 of the mail plan).
- Importing a later address-type seed into an existing installation (ADR-032 import for
  `AddressType`).
- A delete of a contact or an address type — deliberately never: both are deactivated.

## Documentation

- Topic: [`docs/topics/contact.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/contact.md) — model, rules, known issues,
  pending.
- Decisions: ADR-039 (Doctrine driver), ADR-040 (business module cut), ADR-043 decision 19 (file
  master data referenced by code).
- Harness: `tests/module-contact.php` (runs against a throwaway MariaDB schema).

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
