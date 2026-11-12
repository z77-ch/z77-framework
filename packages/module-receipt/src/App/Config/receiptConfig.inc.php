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

    // Nothing here renders a page.
    'cache' => [
        'enabled' => false,
    ],
];
