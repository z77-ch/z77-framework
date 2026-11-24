<?php
namespace Z77\Module\Receipt\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Receipt\Close\UnpostedReceiptsCheck;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptJournalPosting;

/**
 * Receipt module (gateway plan `gateway-app-kanal-bauplan.md` T4, owner decisions E1–E12 of
 * 2026-11-12): receipt templates and payment methods, the receipt journal a phone captures into,
 * the owner's correction and split, «Journal verbuchen» up to a cut-off into module-financial.
 *
 * This module has NO routes and no view area of its own. Its backend screens are fragments
 * ({@see \Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait} «Belegjournal»,
 * {@see \Z77\Module\Receipt\Ui\ReceiptTemplateControllerTrait} «Belegvorlagen»,
 * {@see \Z77\Module\Receipt\Ui\PaymentMethodControllerTrait} «Zahlungsarten») mounted by host
 * controllers in module-backend under `/backend/finance/…` (ADR-018 pattern); their menu entries
 * come from this package's seed `data/framework/routing/navigation.d/module-receipt.json`. Its API targets (`receipt-templates`, `payment-methods`, `receipts`) and its screen in
 * the app shell `/app` are declared below (`apiServices`, `appScreens`) — module-api finds them,
 * this package never names module-api.
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): `Receipt` and
 * `ReceiptJournalPosting` are Doctrine (migrations in `res/migrations`); `ReceiptTemplate` and
 * `PaymentMethod` are file-based installation master data (`data/framework/receipt/
 * receipt_templates.json`, `payment_methods.json`, not seeded) and need no announcement. The photos live in the DMS partition «Belege» (module key
 * `receipt`, remappable by `dmsConfig['moduleFolders']`).
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match) — it must carry the
 * FULL config. Known framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Receipt::class,
        ReceiptJournalPosting::class,
    ],

    // financial asks every module «anything open?» (open-work registry of persistence-doctrine): a
    // receipt still in the journal blocks the year close and the VAT return of its date, and the
    // reports of its range warn about it.
    'openWorkChecks' => [
        'period-close'  => [UnpostedReceiptsCheck::class],
        'ledger-report' => [UnpostedReceiptsCheck::class],
        'vat-return'    => [UnpostedReceiptsCheck::class],
    ],

    // First-setup step 70 «Zahlungsart erfassen» (project checks — module-backend's
    // ProjectHealthDashboard shows the next open ones in setup order; slot table:
    // docs/03-development/project-health-bauplan.md).
    'projectChecks' => [\Z77\Module\Receipt\ProjectHealth\PaymentMethodCheck::class],

    // Bank-driven bookkeeping (ADR-061 stages C4 / C5): financial's check of a bank account asks which
    // posting on the bank's ledger account belongs to which linked record — a receipt's entry belongs
    // to its bank link `receipt:{id}`. Financial names no module (`Bank\BankEntryOwners`).
    'bankEntryOwners' => [\Z77\Module\Receipt\Services\ReceiptBankEntryOwner::class],

    // The finance journal's document link (owner 2026-11-20): a receipt's entry opens its photo.
    // Financial names no module (`Ledger\EntryDocuments`).
    'entryDocuments' => [\Z77\Module\Receipt\Ui\ReceiptEntryDocuments::class],

    // financial's VAT return: the display origin of a receipt's entry in «Herkunft» (ADR-041
    // addendum 2026-11-24, point 6). Financial names no module (`VatReturn\VatReturnSources`).
    'vatReturnSources' => [
        'receipt' => ['label' => 'Belege'],
    ],

    // Who reads the photos besides the super user (owner 2026-11-20, documents.md
    // DMS-MODULE-ACL-001): role admin, READ only, on the partition «Belege». Applied once per
    // installation by module-dms (`Services\ModuleGrants`) — also on an existing installation, on
    // the first DMS use after the deploy; a later change in the Drive stays.
    'dmsPartitionGrants' => [
        \Z77\Module\Receipt\Services\ReceiptCaptureService::MODULE_KEY => [AuthRole::ADMIN => 'read'],
    ],

    // The phone's targets on module-api's gateway (wave 2, ADR-057): every one answers a paired
    // device with the scope `receipt.capture` only — a bearer key is refused (DeviceOnlyTarget).
    'apiServices' => [
        'receipt-templates' => \Z77\Module\Receipt\Api\ReceiptTemplatesService::class,
        'payment-methods'   => \Z77\Module\Receipt\Api\PaymentMethodsService::class,
        'receipts'          => \Z77\Module\Receipt\Api\ReceiptsService::class,
    ],
    // The scope an app user can be given for these targets (module-api ApiDeviceScopes, ADR-058):
    // the linked person needs at least EDITOR in the backend — the role of the employees who
    // capture (review B7); no `member` key = never for members.
    'apiDeviceScopes' => [
        'receipt.capture' => [
            'label'  => 'Belege erfassen',
            'hint'   => 'Belege mit Foto und Betrag ins Belegjournal erfassen.',
            'realms' => ['backend' => AuthRole::EDITOR],
        ],
    ],
    // The screen this module contributes to the app shell `/app` (module-api AppScreens): shown
    // to a device holding `receipt.capture`. Template and script live in this package.
    'appScreens' => [
        'receipt' => [
            'scope'    => 'receipt.capture',
            'title'    => 'Belege',
            'template' => 'App/receipt',
            'js'       => ['app/receipt'],
        ],
    ],

    // The foreign currencies a document may carry (receipt FX plan F7; ISO 4217, upper case, never
    // the base currency, two minor places only) — read ONLY by `ReceiptFields::foreignCurrencies()`.
    // Used today by module-creditor (stage A0, owner 2026-11-15: an invoice in USD / EUR keeps its
    // original amount, the CHF amount is typed from the bank debit); the receipt journal follows
    // with its FX build. USD is in the list for the creditor invoices (Tinify) — F7 named only EUR.
    'foreignCurrencies' => ['EUR', 'USD'],

    // The bank match (ADR-061 stage C5): the days around the RECEIPT DATE a bank movement is looked
    // for — a card purchase is booked a few days after the slip; `daysBefore` covers a slip dated
    // a day late. Read only by `ReceiptBankMatchService`.
    'bankMatch' => [
        'daysBefore' => 2,
        'daysAfter'  => 10,
    ],

    // Nothing here renders a page.
    'cache' => [
        'enabled' => false,
    ],
];
