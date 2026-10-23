<?php
namespace Z77\Module\Debtor\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Debtor\Accounting\LedgerAccountingGateway;
use Z77\Module\Debtor\Close\InvoicingInProgressCheck;
use Z77\Module\Debtor\Close\ReceivablesReconciliation;
use Z77\Module\Debtor\Close\UnbookedTransactionsCheck;
use Z77\Module\Debtor\Entities\BankMessage;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Module\Debtor\Entities\DunningRun;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceJournalPosting;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\InvoiceRevision;
use Z77\Module\Debtor\Entities\InvoiceTax;
use Z77\Module\Debtor\Entities\Payment;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Module\Debtor\Roles\DebtorContactRole;

/**
 * Debtor module (ADR-040, plan §6) — receivables. P3 part 1: the MASTER
 * DATA — the debtor profile per contact, the payment terms, the payment
 * targets and the dunning levels. P3 part 2: `InvoicingService` — invoices
 * and credit notes (`Invoice`, `InvoiceLine`, `InvoiceTax`), the states
 * `invoicing` / `final`, and the accounting port (`accountingGateway`,
 * below). PDF / QR-bill and the document screens are part 3; payments,
 * CAMT and the dunning runs are P4.
 *
 * This module has NO routes and no view area of its own: its backend screens
 * are fragments ({@see \Z77\Module\Debtor\Ui\PaymentTermsControllerTrait},
 * {@see \Z77\Module\Debtor\Ui\PaymentTargetControllerTrait},
 * {@see \Z77\Module\Debtor\Ui\DunningLevelControllerTrait},
 * {@see \Z77\Module\Debtor\Ui\DebtorControllerTrait}) mounted by host
 * controllers in module-backend under `/backend/finance/…` next to the tax
 * codes, the chart and the journal (ADR-018 pattern). `defaultGroup` and
 * `groupDefaults` are therefore deliberately absent — `/debtor/…` resolves
 * nothing.
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory):
 * `DebtorProfile`, `Invoice`, `InvoiceLine` and `InvoiceTax` are Doctrine
 * (the migrations live in `res/migrations`);
 * `PaymentTerms`, `PaymentTarget` and `DunningLevel` are file-based
 * installation master data under `data/framework/debtor/`, seeded once on
 * first install from this package's `data/**\/*.default.json` (ADR-024
 * seed-once) and referenced BY CODE (ADR-043 decision 19). The payment
 * targets carry NO seed on purpose: an IBAN cannot be guessed, and a seeded
 * placeholder would end up printed on a QR-bill.
 *
 * NO `debtorAccounts` any more (owner decision E2, 2026-09-23): the accounts
 * the receivables side posts to — receivable 1100, discount 3800, loss
 * 3805, rounding 3809 (its OWN account, owner 2026-09-22: rounding and
 * discount must stay separable in the reports), dunning fee 6950 (damages
 * for the delay, no VAT, not turnover — owner 2026-09-22) in the KMU chart
 * — live on the MANDATOR record (`z77/module-mandator`, backend
 * `/backend/finance/mandator`), edited there and checked against the chart
 * on save. The reader is unchanged: {@see \Z77\Module\Debtor\Services\DebtorAccounts}
 * stays the ONE access point (Rule 2) and refuses AT THE POINT OF USE, in
 * German, naming the key and the mandator, when a number is missing or the
 * bookkeeping will not take a posting on it — never at boot. A
 * `debtorAccounts` key still present here (a project override copied before
 * the move, BOOT-CONFIG-001) is REFUSED loudly by `DebtorAccounts`, never
 * read as a second source.
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match)
 * — it must carry the FULL config, not just the key it changes. Known
 * framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
// The placeholders of a document mail (filled by `Mail\InvoiceMails::values()`), listed in the editor.
$documentPlaceholders = [
    'nummer'   => 'Belegnummer',
    'kunde'    => 'Name der Rechnungsadresse',
    'betrag'   => 'Total mit Währung',
    'datum'    => 'Belegdatum',
    'faellig'  => 'Fälligkeit (Rechnung)',
    'absender' => 'Name des Mandanten',
    // The display name of the sender identity chosen under «Senden als» (owner 2026-10-23) —
    // a line holding only this placeholder disappears when it is empty (EmailService::documentMail()).
    'anzeigename' => 'Anzeigename des gewählten Absenders («Senden als»)',
];

return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        DebtorProfile::class,
        Invoice::class,
        InvoiceLine::class,
        InvoiceTax::class,
        Payment::class,
        PaymentAllocation::class,
        BankMessage::class,
        BankTransaction::class,
        DunningRun::class,
        DunningNotice::class,
        InvoiceRevision::class,          // the Faktura-Journal (ADR-054, step 1)
        InvoiceJournalPosting::class,
    ],

    // The accounting port (plan §6.6): the class `InvoicingService::finalize()` posts through.
    // `LedgerAccountingGateway` books into module-financial and refuses to run without it;
    // an installation that keeps its books elsewhere names `NullAccountingGateway` here.
    'accountingGateway' => LedgerAccountingGateway::class,

    // financial's year close asks every module «anything open?» (open-work registry of
    // persistence-doctrine, scope `period-close`, plan §5.3): a document still in `invoicing`
    // dated in the year blocks the close. Payments / CAMT (P4) will add their check here.
    'openWorkChecks' => [
        'period-close' => [InvoicingInProgressCheck::class, UnbookedTransactionsCheck::class],
    ],

    // financial's subledger reconciliation at the year close (owner decision 2026-10-13): debtor's open
    // items against the receivable account. Its OWN key, not under openWorkChecks — financial's
    // SubledgerReconciliationCheck is the check registered there; it asks the classes named here.
    // A ::class string loads nothing: without module-financial nobody reads this key.
    'subledgerReconciliations' => [ReceivablesReconciliation::class],

    // The role «Debitor» in the contact editor (owner decision 2026-10-12): module-contact's
    // registry `contactRoles` collects it — contact knows no module, this one contributes.
    // On = open the profile (customer number, default terms); off = delete it while no
    // document references the contact.
    'contactRoles' => [
        'debtor' => DebtorContactRole::class,
    ],

    // The backend dashboard (ADR-052): this module's pending work and «+ Neu» buttons —
    // module-backend collects every module's `dashboard` list (`DashboardContributors`).
    'dashboard' => [\Z77\Module\Debtor\Dashboard\DebtorDashboard::class],

    // Booked documents by e-mail (step 3 of the mail plan, owner 2026-10-22). Read only where
    // module-mailing is installed (debtor only SUGGESTS it): the outbox resolves the attachment
    // `invoice-pdf:<id>` at send time — the PDF from the snapshot, what the booking print printed.
    'mailAttachments' => [
        \Z77\Module\Debtor\Mail\InvoicePdfAttachment::TYPE => \Z77\Module\Debtor\Mail\InvoicePdfAttachment::class,
    ],

    // The document mail kinds this module sends («Beleg-Mails», kernel DocumentMailKinds): the
    // SEED of subject and text per language — the operator edits them under Service › E-Mail ›
    // Beleg-Mails (a record there wins while active). Placeholders are filled by
    // `Mail\InvoiceMails::values()`. `dunning` is prepared (the same shape), not sent yet.
    // Subjects do not END in a number (mail.md, KAM_NUMSUBJECT) — hence «… — {absender}».
    'documentMails' => [
        'invoice' => [
            'label'        => 'Rechnung',
            'subject'      => ['de' => 'Rechnung {nummer} — {absender}', 'en' => 'Invoice {nummer} — {absender}'],
            'body'         => [
                'de' => "Guten Tag\n\nIm Anhang erhalten Sie unsere Rechnung {nummer} über {betrag}, zahlbar bis {faellig}.\n\nFreundliche Grüsse\n{anzeigename}\n{absender}",
                'en' => "Hello\n\nPlease find attached our invoice {nummer} for {betrag}, payable by {faellig}.\n\nKind regards\n{anzeigename}\n{absender}",
            ],
            'placeholders' => $documentPlaceholders,
        ],
        'credit-note' => [
            'label'        => 'Gutschrift',
            'subject'      => ['de' => 'Gutschrift {nummer} — {absender}', 'en' => 'Credit note {nummer} — {absender}'],
            'body'         => [
                'de' => "Guten Tag\n\nIm Anhang erhalten Sie unsere Gutschrift {nummer} über {betrag}.\n\nFreundliche Grüsse\n{anzeigename}\n{absender}",
                'en' => "Hello\n\nPlease find attached our credit note {nummer} for {betrag}.\n\nKind regards\n{anzeigename}\n{absender}",
            ],
            'placeholders' => $documentPlaceholders,
        ],
        'dunning' => [
            'label'        => 'Mahnung',
            'subject'      => ['de' => 'Zahlungserinnerung zu Rechnung {nummer} — {absender}', 'en' => 'Payment reminder for invoice {nummer} — {absender}'],
            'body'         => [
                'de' => "Guten Tag\n\nUnsere Rechnung {nummer} über {betrag} ist noch offen. Im Anhang finden Sie die Zahlungserinnerung.\n\nFreundliche Grüsse\n{anzeigename}\n{absender}",
                'en' => "Hello\n\nOur invoice {nummer} for {betrag} is still open. Please find the payment reminder attached.\n\nKind regards\n{anzeigename}\n{absender}",
            ],
            'placeholders' => $documentPlaceholders,
        ],
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
