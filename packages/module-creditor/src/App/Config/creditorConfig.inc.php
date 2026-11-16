<?php
namespace Z77\Module\Creditor\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorJournalPosting;
use Z77\Module\Creditor\Entities\CreditorInvoiceText;
use Z77\Module\Creditor\Entities\CreditorLearned;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Close\UnpostedCreditorInvoicesCheck;
use Z77\Module\Creditor\Jobs\CreditorIntakeJob;
use Z77\Module\Creditor\Services\CreditorPaymentMethodUsage;
use Z77\Module\Creditor\Roles\CreditorContactRole;

/**
 * Creditor module (creditor plan `docs/03-development/creditor-module-bauplan.md`, owner answers
 * §11 of 2026-11-15) — payables. Stage 1a: the CREDITOR — a contact with a creditor profile (UID,
 * several IBANs, the posting defaults), the role «Kreditor» in the contact editor and the master
 * data «Kreditoren». Stage 1b: the CREDITOR INVOICE (lines, intake, the protocol table), the PDF
 * upload and the «Kreditorenjournal». Stage 1c: «Bezahlt am …» and the posting run «Journal
 * verbuchen …» (`postingVariant`, the open-work check, the payment-method usage). Stage 2: the
 * recognition after every intake (text layer, matching, the opt-in AI), the folder job
 * `creditor-intake` (`intake.folder`, `tools.pdftotext`, `jobs`) and the tab «Nicht eingelesen».
 *
 * This module has NO routes and no view area of its own: its backend screens are fragments
 * ({@see \Z77\Module\Creditor\Ui\CreditorProfileControllerTrait} «Kreditoren»,
 * {@see \Z77\Module\Creditor\Ui\CreditorJournalControllerTrait} «Kreditorenjournal») mounted by host
 * controllers in module-backend under `/backend/finance/creditor-profile` and
 * `/backend/finance/creditor-journal` (ADR-018 pattern); the menu entries come from this package's
 * seed `data/framework/routing/navigation.d/module-creditor.json` (ADR-050).
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): every entity below is
 * Doctrine (migrations in `res/migrations`). The PDFs lie in the DMS. The defaults reference module-
 * receipt's payment methods, the chart (module-financial) and the tax codes (module-vat) BY CODE.
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match) — it must carry the
 * FULL config. Known framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        CreditorProfile::class,
        CreditorIban::class,
        CreditorIntake::class,
        CreditorInvoice::class,
        CreditorInvoiceLine::class,
        CreditorJournalPosting::class,
        CreditorInvoiceText::class,
        CreditorLearned::class,
    ],

    // The intake of supplier PDFs (creditor plan §3): the largest PDF one file may be — read in ONE
    // place, `CreditorIntakeService::maxBytes()`; the upload's client gate shows the same limit.
    // `folder`: the intake folder of the job `creditor-intake` (stage 2) — relative = to the project
    // root (`shared/data/…` in the release layout), read only in `CreditorIntakeFolder::configuredDir()`.
    // The job creates it with `.work/` and `failed/`; the owner's upload script never does.
    'intake' => [
        'maxBytes' => 20 * 1024 * 1024,
        'folder'   => 'data/framework/creditor/intake',
    ],

    // The recognition's tools (creditor plan §4, stage 2): `pdftotext` = a binary path or name,
    // 'auto' = `pdftotext` from PATH (poppler on cyon). Never a command line — the first element of
    // an argument array. Read only in `CreditorRecognitionService::pdftotext()`. A host without it
    // degrades: «Texterkennung auf diesem Server nicht verfügbar», the row is completed by hand.
    // The AI step is switched elsewhere — `creditorAi` in config/client/kibot.inc.php (owner answer 9).
    'tools' => [
        'pdftotext' => 'auto',
    ],

    // The folder job (ADR-031, creditor plan §3, owner answer 7). It ships a schedule: it deletes no
    // installation record — a file leaves the intake folder only after the DMS holds its verified copy
    // (a move), a refused one stays in failed/ — so the jobs.md delete rule does not apply. The
    // operator may switch it off; «Jetzt starten» runs it at once. It also RECOGNISES every invoice
    // marked «Erkennung ausstehend» — the recognition runs in this job only, never in a request; the
    // upload and «Jetzt erkennen» queue it (CREDITOR-RECOG-WEB-001), the schedule is the fallback.
    'jobs' => [
        'creditor-intake' => [
            'class'           => CreditorIntakeJob::class,
            'label'           => 'Kreditoren-Eingang',
            'runAs'           => AuthRole::CRON_JOB,
            'maxAttempts'     => 3,
            'defaultSchedule' => 'every:1m',   // owner 2026-11-15: a folder file within a minute; safe — per-job lock, one open entry, rename() claim
        ],
    ],

    // HOW an invoice reaches the ledger (creditor plan §2.4, ADR-059) — per installation, read in ONE
    // place (`Posting\CreditorPostingVariant::current()`), snapshotted on every new invoice. Only
    // 'direct' is built (expense + input VAT to the payment method's account, dated «bezahlt am»);
    // any other value is refused loudly until its strategy exists. A project switches it under override/.
    'postingVariant' => 'direct',

    // financial asks every module «anything open?» (open-work registry of persistence-doctrine): a
    // PAID invoice not yet posted blocks the year close and the VAT return of its payment date; the
    // unpaid ones are a warning (cash principle — no liability in the books).
    'openWorkChecks' => [
        'period-close'  => [UnpostedCreditorInvoicesCheck::class],
        'ledger-report' => [UnpostedCreditorInvoicesCheck::class],
        'vat-return'    => [UnpostedCreditorInvoicesCheck::class],
    ],

    // module-receipt's payment methods count every use of their code (RECEIPT-PM-COUNT-001): an
    // invoice's «Bezahlt mit» is one. Receipt asks this registry and names no module.
    'paymentMethodUsage' => [
        CreditorPaymentMethodUsage::class,
    ],

    // The role «Kreditor» in the contact editor (creditor plan §2.2, the debtor model of
    // 2026-10-12): module-contact's registry `contactRoles` collects it — contact knows no module,
    // this one contributes. On = an empty creditor profile; off = delete it (with its IBANs).
    'contactRoles' => [
        'creditor' => CreditorContactRole::class,
    ],

    // Nothing here renders a page.
    'cache' => [
        'enabled' => false,
    ],
];
