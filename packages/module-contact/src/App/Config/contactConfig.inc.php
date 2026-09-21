<?php
namespace Z77\Module\Contact\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Contact\Entities\Address;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Services\ContactService;

/**
 * Contact module (ADR-040, plan §4a) — one contact (person or organisation)
 * with n typed addresses, consumed by debtor, order and later modules.
 *
 * This module has NO routes and no view area of its own: its backend screens
 * are fragments ({@see \Z77\Module\Contact\Ui\ContactControllerTrait},
 * {@see \Z77\Module\Contact\Ui\AddressTypeControllerTrait}) mounted by host
 * controllers in module-backend under `/backend/contact/…` (the dms Drive /
 * member accounts / tax-code pattern, ADR-018). `defaultGroup` and
 * `groupDefaults` are therefore deliberately absent — `/contact/…` resolves
 * nothing.
 *
 * Storage: `Contact`, `Address`, `ContactAddress` are Doctrine entities
 * (ADR-039 decision 5 — announced here, nothing scans a directory; the
 * migrations live in `res/migrations`). `AddressType` is file-based
 * installation master data (`data/framework/contact/address_types.json`),
 * seeded once on first install from this package's `data/**\/*.default.json`
 * (ADR-024 seed-once) and referenced by `code` (ADR-043 decision 19).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Contact::class,
        Address::class,
        ContactAddress::class,
    ],

    // Rows the backend contact list shows at most (owner, 2026-09-21) — the
    // search narrows, the list says «n von m angezeigt» for what is beyond.
    // A positive int; anything else fails loudly when the list is opened
    // ({@see ContactService::listLimit()}). The package default is the
    // constant, so the number exists once; a project override writes its own.
    // ⚠️ Today a project override of this file REPLACES it (first source
    // match) — it must carry the FULL config, not just this key. Known
    // framework-wide gap, BOOT-CONFIG-001 (docs/topics/bootstrap.md):
    // proposed that an override merges in as deviation only.
    'contactListLimit' => ContactService::DEFAULT_LIST_LIMIT,

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
