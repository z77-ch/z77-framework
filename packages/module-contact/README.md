# z77/module-contact

Contacts for z77 business modules (ADR-040, plan §4a): one **contact** — person or
organisation — with **n typed addresses** (main, invoice, delivery, …), consumed by
debtor, order and later modules. There is no «order customer» and no «debtor
customer»: there is one contact, and each module takes the address it needs.
Depends on `z77/kernel` and `z77/persistence-doctrine` (ADR-039).
Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-contact`); not yet a split target or on Packagist — projects consume it through
a `path` repository until it is.

Model: `Contact` (kind, company, first/last name, language, e-mail, phone, active) and `Address` (addressee, street, zip, city, country) are
Doctrine entities; `ContactAddress` links the two with a **type** and a title — n links
per contact, two links may share one address row. `AddressType` (main, invoice,
delivery, regional) is file-based installation master data, seeded once, referenced by
`code`, deactivated and never deleted (ADR-043 decision 19). A contact is deactivated,
never deleted either — documents reference it by id and snapshot the address they used.

Pieces:

- `Entities/Contact`, `Entities/Address`, `Entities/ContactAddress` — Doctrine, tables
  `contact`, `address`, `contact_address` (`res/migrations/Version20260921153209`);
  `Entities/AddressType` — file (`data/framework/contact/address_types.json`).
- `Services/ContactService` — the write side: `save()` (a NEW contact with its attached
  addresses in one flush), `update($contact, $values)` / `setActive()` for an existing one,
  `addAddress()`, `saveAddress($link, $linkValues, $addressValues)`, `removeAddress()`.
  Every write runs the validators BEFORE a managed entity is touched (a refused change
  never reaches the next flush — ADR-039 decision 9); no delete.
- `Services/AddressTypes` — `labelOf(code)` (deactivated still resolves, never throws),
  `active()`, `all()`; `Services/AddressTypeMasterData` — save with an immutable code, deactivate.
- `Validators/*` — required fields by kind, ISO language and country, zip format per
  country (CH: four digits), member account uniqueness, type known / active for a new link.
- `Ui/ContactControllerTrait` + `Ui/AddressTypeControllerTrait` — the backend screens as
  fragments; `module-backend` mounts them at `/backend/contact/contact/list` and
  `/backend/contact/address-type/list`.

```php
$service = new ContactService(DI::getUnifiedEntityManager());

$contact = new Contact(['kind' => 'organisation', 'company' => 'Muster AG', 'language' => 'de', 'email' => 'info@muster.ch']);
$contact->addAddress(new ContactAddress($contact, 'main',    new Address(['name' => 'Muster AG', 'street' => 'Bahnhofstrasse', 'house_no' => '1', 'zip' => '8001', 'city' => 'Zürich'])));
$contact->addAddress(new ContactAddress($contact, 'invoice', new Address(['name' => 'Muster AG', 'address_row' => 'Buchhaltung', 'street' => 'Postfach', 'zip' => '8021', 'city' => 'Zürich'])));
$service->save($contact);   // one flush: contact, two addresses, two typed links

$service->update($contact, ['phone' => '+41 44 123 45 67']);   // validated on a draft, then applied
AddressTypes::from($em)->labelOf('invoice');                   // «Rechnungsadresse»
```

Docs: `docs/topics/contact.md` in the framework repository.
