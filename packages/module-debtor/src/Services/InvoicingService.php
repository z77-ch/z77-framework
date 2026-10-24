<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Repositories\ContactAddressRepository;
use Z77\Module\Debtor\Accounting\AccountingGateway;
use Z77\Module\Debtor\Accounting\AccountingGateways;
use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\InvoiceRevision;
use Z77\Module\Debtor\Entities\InvoiceTax;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Module\Debtor\Entities\PaymentSnapshot;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Invoicing\Correction;
use Z77\Module\Debtor\Invoicing\DocumentSnapshot;
use Z77\Module\Debtor\Invoicing\DocumentNumber;
use Z77\Module\Debtor\Invoicing\DocumentStamp;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Invoicing\LineDraft;
use Z77\Module\Debtor\Invoicing\NoTaxShareException;
use Z77\Module\Debtor\Invoicing\PostingBuilder;
use Z77\Module\Debtor\Invoicing\QrBill;
use Z77\Module\Debtor\Invoicing\SubjectLabel;
use Z77\Module\Debtor\Invoicing\TaxShares;
use Z77\Module\Debtor\Pdf\InvoiceArchive;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\PaymentAllocationRepository;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\TaxSummaryEntry;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Vat\Calculation\VatLine;
use Z77\Module\Vat\Calculation\VatResult;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Services\NoRateException;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
use Z77\Module\Vat\Services\VatRates;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The single entry for every source that issues a document (plan §6.2,
 * ADR-040 decision 3): an order, a manual invoice, a later contract or fee
 * run — all hand an {@see InvoiceDraft} to {@see invoice()}. Three
 * operations, each ONE unit of work through the transaction port
 * (ADR-039 decision 10, ADR-040 decision 7):
 *
 *   - {@see invoice()}: VAT per line through module-vat by the SERVICE
 *     date → totals → the 0.05 rounding as a separate line → the DRAFT in
 *     state `invoicing` with its full snapshot — WITHOUT a number (owner
 *     2026-10-10: «a printed invoice with a number IS a document»; a draft
 *     prints as a preview). NOTHING is posted, no range is touched;
 *   - {@see reinvoice()}: only in `invoicing` — a new snapshot, nothing to
 *     reverse because nothing was posted, still no number;
 *   - {@see discard()}: only in `invoicing` — the draft is DELETED (owner
 *     2026-10-13, ADR-040 addendum): no number, nothing posted, nothing
 *     references it;
 *   - {@see issue()} — «FAKTURIEREN» (the booking print, «Buchungsdruck»
 *     until 2026-10-23 — ADR-054): a batch in one unit of work — the month
 *     ranges `document.YYMM` of the batch locked first (in name order), then
 *     every document (in id order), then the numbers `YYMMnnnn` drawn in a
 *     fixed order (invoice date, then id — {@see DocumentNumber}, one series
 *     for every kind), then per document its stamp, state `issued` and its
 *     archived PDF. NOTHING is posted: the document stands in the
 *     Faktura-Journal until {@see JournalPostingService::post()} posts it
 *     (`final`, immutable, corrected by a credit note);
 *   - {@see finalize()} — issue AND post in one step, for the dunning fee
 *     only (ADR-054 decision 2).
 *
 * Validation happens BEFORE the unit of work opens (the draft against the
 * party, the master data, the tax tables and the accounts), so a refused
 * draft never takes a lock (the `LedgerService::post()` order). Inside, the
 * writes are new rows and one locked re-read — no managed entity is
 * mutated before a check (ADR-039 decision 9).
 *
 * Since P7 slim (2026-10-14) a source that IS its own draft (an order) has a
 * second sanctioned form: {@see invoiceAndIssue()} creates and issues in one
 * unit of work (joining the caller's), with {@see preview()} for its totals
 * and preview PDF, {@see prepareDocumentRanges()} before its unit of work and
 * {@see openDebtorProfile()} for a prospect (plan §7, D9).
 *
 * Lock order of the module (2026-10-10): `document.YYMM` ranges → invoice
 * rows → `journal-entry.*` (financial's `post()`). `issue()`, `finalize()`,
 * `invoiceAndIssue()`, the journal posting (rows → journal, no range) and
 * the dunning run take them in exactly this order
 * (with the profile rows SHARED between ranges and invoice rows — debtor.md);
 * a payment takes the last two; `invoice()` / `reinvoice()` take no DOCUMENT range at all (an issued
 * credit note only holds a SHARED lock on the invoice row it references,
 * through its foreign key). `invoice()` opens no debtor profile (since
 * 2026-10-19 — a contact without one is refused); the range `customer` is
 * taken only by {@see openDebtorProfile()}, in its own unit of work.
 *
 * The open amount is DERIVED ({@see openAmount()}: gross − Σ final credit
 * notes; P4 subtracts payments and allocations) — there is no `open_item`
 * table in this state, because nothing reads one (plan §6.3: never stored
 * in parallel; the `final` invoice row IS the open item).
 *
 * Money: integer minor units throughout; the line amount is rounded once
 * per step (quantity × unit price, then the discount); the tax on the sum
 * per code once (`VatCalculator`); the document total to 0.05 once
 * (`Money::roundTo(5)`), never per line.
 */
final class InvoicingService
{
    /** The 0.05 rounding of a document total applies to these currencies (step in minor units); every other currency rounds to 0.01. */
    private const ROUNDING_STEPS = ['CHF' => 5];

    private readonly string $actor;
    private ?AccountingGateway $gateway;
    private ?InvoiceArchive $archive = null;

    /**
     * @param string|null            $actor   the author's name; null = the session identity ({@see Actor::current()})
     * @param AccountingGateway|null $gateway null = the one `debtorConfig → accountingGateway` names, built at first use
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null, ?AccountingGateway $gateway = null)
    {
        $this->actor   = $actor !== null ? Actor::normalize($actor) : Actor::current();
        $this->gateway = $gateway;
    }

    /**
     * Issue a NEW document in state `invoicing` — a DRAFT without a number
     * (owner 2026-10-10: the number is drawn by «Fakturieren»,
     * {@see issue()}). Joins an open unit of work (an order that changes
     * its status and invoices in one) or opens its own. No range is touched:
     * a draft that is never booked consumes no number, and a re-issue may
     * move the invoice date into another month freely.
     *
     * **A contact without a debtor profile is refused** (`no-debtor-profile`, owner 2026-10-18 /
     * 2026-10-19 — no document for a contact that is no debtor; the sentence is
     * `DebtorProfileService::notDebtor()`). Until 2026-10-19 `$openProfile` let the editor open
     * the profile with the draft (DEBTOR-CONTACT-001); the editor asks «Debitor anlegen» since
     * 2026-10-18 and the parameter is gone. A source creates the debtor FIRST through
     * `DebtorProfileService::open()` (an importer, a shop); the one remaining fallback is an old
     * order's booking print ({@see openDebtorProfile()}, plan D9).
     *
     * For an EXISTING profile the unit of work first locks its row SHARED
     * (`DebtorProfileRepository::lockForContact()`, lock order: profile row → invoice rows):
     * `DebtorProfileService::delete()` («Debitor» off, owner 2026-10-12) locks it exclusive
     * and counts the documents — so either the delete sees this document and is refused, or
     * this draft finds the profile gone and is refused (`no-debtor-profile`). Never a document
     * without a profile.
     *
     * @throws InvoiceRefusedException the draft is refused — nothing was written
     */
    public function invoice(InvoiceDraft $draft): Invoice
    {
        $composed = $this->compose($draft, null);
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(Invoice::class)->run(function () use ($draft, $composed, $now): Invoice {
            if ($this->em->getRepository(DebtorProfile::class)->lockForContact((int) $composed['contact']->getId(), exclusive: false) === null) {
                // The profile compose() found was removed since («Debitor» off in the contact
                // editor, owner 2026-10-12): the shared lock waits for that delete and sees it.
                throw new InvoiceRefusedException(InvoiceRefusedException::NO_DEBTOR_PROFILE, '«' . $composed['contact']->displayName() . '» ist soeben als Debitor entfernt worden — im Kontakt wieder als Debitor festlegen.');
            }
            $invoice = new Invoice($draft->kind, $composed['contact'], $composed['creditNoteOf'], $this->actor, $now);
            [$lines, $taxes] = $this->materialize($invoice, $composed);
            $invoice->issue($composed['snapshot'], $lines, $taxes, $this->paymentPart($invoice, $composed));
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * The UNSAVED profile {@see preview()} composes an old order's booking-print preview against
     * (`$openProfile`, plan D9 — nothing is written; {@see openDebtorProfile()} opens it for real
     * before the booking): an active contact without one, with the DEFAULT payment terms ({@see PaymentTermsRepository::defaultForNewDebtor()}, owner 2026-10-12) —
     * the draft's own terms, if it names any, stay on the document only; without an active
     * default, with the terms the draft names. Or null: the contact has a profile (the usual
     * path), or compose() refuses the party with its own reason (unknown, inactive), or the
     * draft is no invoice.
     *
     * @throws InvoiceRefusedException NO_DEBTOR_PROFILE — no active default and the draft names no payment terms
     */
    private function profileToOpen(InvoiceDraft $draft): ?DebtorProfile
    {
        if ($draft->kind !== InvoiceKind::Invoice) {
            return null;
        }
        $contact = $this->em->getRepository(Contact::class)->find($draft->contactId);
        if ($contact === null || !$contact->isActive() || $this->em->getRepository(DebtorProfile::class)->findByContact($contact) !== null) {
            return null;
        }
        // The profile is the standing agreement: it gets the DEFAULT terms (owner 2026-10-12);
        // terms the draft names apply to this document only. Without an active default the
        // terms the draft names go to the profile (the behaviour before the default existed).
        $terms = $this->em->getRepository(PaymentTerms::class)->defaultForNewDebtor()?->getCode()
            ?? PaymentTerms::normalizeCode((string) $draft->paymentTermsCode);
        if ($terms === '') {
            throw new InvoiceRefusedException(
                InvoiceRefusedException::NO_DEBTOR_PROFILE,
                '«' . $contact->displayName() . '» ist noch kein Debitor — Zahlungskonditionen wählen: sie werden beim neuen Debitor hinterlegt (unter Zahlungskonditionen sind keine aktiven Standard-Konditionen markiert).'
            );
        }
        $profile = new DebtorProfile();
        $profile->setContact($contact);
        $profile->setPaymentTermsCode($terms);

        return $profile;
    }

    /**
     * Replace the content of a document still in `invoicing` (plan §6.2; a
     * draft has no number yet — owner 2026-10-10). Takes id + the VERSION the caller saw (the
     * `ManualEntryService::update()` model): the row is re-read under an
     * exclusive lock, a stale version is refused
     * ({@see InvoiceConflictException}), a `final` document is refused
     * (`not-invoicing`), and the kind may not change.
     *
     * @throws InvoiceRefusedException
     * @throws InvoiceConflictException the document is gone or at another version
     */
    public function reinvoice(int $invoiceId, int $expectedVersion, InvoiceDraft $draft): Invoice
    {
        $existing = $this->invoices()->withLines($invoiceId);
        if ($existing === null) {
            throw new InvoiceConflictException($invoiceId, $expectedVersion);
        }
        if ($existing->kind() !== $draft->kind) {
            throw new InvoiceRefusedException(InvoiceRefusedException::KIND_CHANGED, $existing->documentName() . ' bleibt eine ' . $existing->kind()->label() . ' — für die andere Art ein neues Dokument erstellen.');
        }
        $composed = $this->compose($draft, $existing);
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(Invoice::class)->run(function () use ($invoiceId, $expectedVersion, $composed, $now): Invoice {
            $invoice = $this->invoices()->lockForUpdate($invoiceId);
            if ($invoice === null || $invoice->getVersion() !== $expectedVersion) {
                throw new InvoiceConflictException($invoiceId, $expectedVersion);
            }
            $this->assertInvoicing($invoice);
            if ($invoice->getCreditNoteOf()?->getId() !== $composed['creditNoteOf']?->getId() || $invoice->getContact()->getId() !== $composed['contact']->getId()) {
                throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, $invoice->documentName() . ' gehört zu einer anderen Partei oder Rechnung — dafür ein neues Dokument erstellen.');
            }
            [$lines, $taxes] = $this->materialize($invoice, $composed);
            $invoice->reissue($composed['snapshot'], $lines, $taxes, $this->paymentPart($invoice, $composed), $this->actor, $now);
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * Discard a DRAFT (owner 2026-10-13, review H3; ADR-040 addendum 2026-10-13): the document
     * in `invoicing` is DELETED with its lines and tax rows — a real DELETE, not a state. The
     * correction principle (plan §1: «never an edit, never a deletion») binds what was BOOKED;
     * a draft has no number (owner 2026-10-10), nothing was posted and nothing references it
     * (a credit note, a payment, a dunning notice all need a FINAL document), so nothing is
     * left to correct. Why it is needed: a forgotten draft blocked the year close
     * (`InvoicingInProgressCheck`) and kept the «Debitor» role from being switched off.
     *
     * Takes id + the VERSION the caller saw (the {@see reinvoice()} model): the row is locked
     * (lock order: invoice row only — no range, no journal), a stale version or a missing row
     * is a conflict, a booked document is refused (`not-invoicing`).
     *
     * @return string the document's name as it read before the delete («Rechnung (Entwurf vom …)»)
     * @throws InvoiceRefusedException  NOT_INVOICING
     * @throws InvoiceConflictException the draft is gone or at another version
     */
    public function discard(int $invoiceId, int $expectedVersion): string
    {
        return $this->em->getTransaction(Invoice::class)->run(function () use ($invoiceId, $expectedVersion): string {
            $invoice = $this->invoices()->lockForUpdate($invoiceId);
            if ($invoice === null || $invoice->getVersion() !== $expectedVersion) {
                throw new InvoiceConflictException($invoiceId, $expectedVersion);
            }
            $this->assertInvoicing($invoice);
            $name = $invoice->documentName();
            $this->em->remove($invoice);   // cascade: its lines and tax rows go with it

            return $name;
        });
    }

    /**
     * «FAKTURIEREN» — the booking print since the Faktura-Journal (ADR-054 decision 4, owner
     * 2026-10-23; «Buchungsdruck» until that day): `invoicing` → `issued` for a batch, ONE unit
     * of work, all or nothing — the documents get their NUMBER, their STAMP and their ARCHIVED
     * PDF here; NOTHING is posted. They stand in the Faktura-Journal until «Journal verbuchen»
     * ({@see JournalPostingService::post()}) posts them. The caller delivers the archived PDFs
     * (`Pdf\InvoiceArchive::combined()`).
     *
     *   1. Before the unit of work: the batch is read (no lock) for the months of its invoice
     *      dates, and each month's range `document.YYMM` is created with
     *      `NumberRangeRepository::create()` (idempotent, consumes no number; outside a unit of
     *      work committed at once — DOCTRINE-NR-003).
     *   2. Inside, FIRST: those ranges locked in NAME order (`create()` in a unit of work holds the
     *      existing row exclusively) — several months in one batch always lock in the same order,
     *      so two batches never wait on each other crosswise; the dunning run takes the same first
     *      lock.
     *   3. Every document locked (id order); any that is missing, at another VERSION than the
     *      caller saw, or no draft refuses the whole batch; a document whose month is not among
     *      the locked ranges was re-dated since the read — refused as a conflict. The stored
     *      revenue accounts and the VAT liability are judged (a document that could not be posted
     *      later is not issued), and the payment part each document will print is taken again
     *      ({@see bookedPaymentPart()}) and checked by `QrBill` — before a number is drawn
     *      (owner 2026-10-13).
     *   4. The numbers drawn — `next('document.YYMM')`, the first WRITES of the unit of work — in a
     *      fixed order: invoice date, then id. So a batch is gapless per month and numbered by
     *      date; the 10000th document of a month is refused (`number-range-full`). Each document
     *      gets its number and its numbered payment part (`Invoice::assignNumber()`).
     *   5. Per document, in the same order: the STAMP of revision 1 (`Invoicing\DocumentStamp`,
     *      unique against every archived version), `Invoice::markIssued()`, the PDF rendered ONCE
     *      and stored as a file (`Pdf\InvoiceArchive::archive()`), its `invoice_revision` row.
     *
     * A refusal or a failure at commit ends the unit of work — no state, no number, no row; the
     * archive files this call wrote are removed again (when it owns the unit of work). The caller
     * names the version it SAW per document (DEBTOR-FINAL-001): a document re-issued in between is
     * refused with {@see InvoiceConflictException}. Issuing twice is REFUSED (`not-invoicing`), not
     * idempotent — the screen recognises a repeated submit itself.
     *
     * @param list<array{id: int, version: int}> $documents
     * @return list<Invoice> in the order they were numbered (invoice date, then id)
     * @throws InvoiceRefusedException  NOT_FOUND | NOT_INVOICING | NUMBER_RANGE_FULL | DATES | TARGET_UNKNOWN | TARGET_INACTIVE | PAYMENT_PART | VAT_LIABILITY_CHANGED | ACCOUNT_*
     * @throws InvoiceConflictException a document is at another version than the caller saw
     */
    public function issue(array $documents): array
    {
        return $this->issueBatch($documents, 'issue', post: false);
    }

    /**
     * Issue AND post in one step — the ONE sanctioned caller is the dunning run's fee document
     * (ADR-054 decision 2, owner decision O2): derived from a POSTED invoice, without an editing
     * use case, it never enters the Faktura-Journal. Exactly {@see issue()} (number, stamp,
     * archived PDF), then per document, in number order, `PostingBuilder` → `AccountingGateway::
     * post()` → `final` (`Invoice::post()`, no journal protocol) — in the SAME unit of work
     * (joining the caller's). Lock order: ranges → rows → `journal-entry.*`.
     *
     * @param list<array{id: int, version: int}> $documents
     * @return list<Invoice> in the order they were numbered
     * @throws InvoiceRefusedException  as issue()
     * @throws InvoiceConflictException
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException
     */
    public function finalize(array $documents): array
    {
        return $this->issueBatch($documents, 'finalize', post: true);
    }

    /**
     * @param list<array{id: int, version: int}> $documents
     * @return list<Invoice>
     */
    private function issueBatch(array $documents, string $method, bool $post): array
    {
        $versions = [];
        foreach ($documents as $i => $document) {
            if (!is_array($document) || !isset($document['id'], $document['version'])) {
                throw new \InvalidArgumentException("{$method}() takes a list of ['id' => int, 'version' => int] pairs — item {$i} is not one");
            }
            $versions[(int) $document['id']] = (int) $document['version'];
        }
        $ids = array_keys($versions);
        if ($ids === []) {
            return [];
        }
        $gateway    = $post ? $this->gateway() : null;
        $receivable = $post ? (new DebtorAccounts($this->em))->postableNumber('receivable') : '';
        $now        = new \DateTimeImmutable();

        // 1. The month ranges of the batch, created ahead (DOCTRINE-NR-003). Read in SQL without a lock — not as
        //    entities, so the locked re-read below is not served stale from the identity map; a document re-dated
        //    after this read is caught under the lock (its version moved, or its month is not held).
        $months = $this->monthRangesOf(array_map(static fn(string $date) => new \DateTimeImmutable($date), array_values($this->invoices()->invoiceDatesOf($ids))));
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        foreach ($months as $range) {
            $ranges->create($range);
        }
        $transaction = $this->em->getTransaction(Invoice::class);
        $owned       = !$transaction->isOpen();

        try {
            return $transaction->run(function () use ($ids, $versions, $gateway, $receivable, $now, $months, $ranges, $post): array {
                // 2. The month ranges FIRST, in name order (lock order: document.YYMM → invoice rows → journal-entry.*).
                foreach ($months as $range) {
                    $ranges->create($range);
                }

                // 3. Every row of the batch, locked in id order.
                $locked   = [];
                $payments = [];
                $sorted   = $ids;
                sort($sorted);
                foreach ($sorted as $id) {
                    $invoice = $this->invoices()->lockForUpdate($id);
                    if ($invoice === null) {
                        throw new InvoiceRefusedException(InvoiceRefusedException::NOT_FOUND, "Dokument {$id} gibt es nicht.");
                    }
                    if ($invoice->getVersion() !== $versions[$id]) {
                        throw new InvoiceConflictException($id, $versions[$id]);
                    }
                    $this->assertInvoicing($invoice);
                    $this->assertStoredRevenueAccounts($invoice);
                    // A draft keeps the amounts of its last save; composed under another VAT liability than
                    // the mandator's now, it is REFUSED (owner 2026-10-21) — saved again, the user sees the
                    // amount that will be issued. Never recomposed here. Only a document with a PRICED line
                    // tells: a text-only one carries no code either way.
                    $priced = array_filter($invoice->getLines(), static fn(InvoiceLine $line) => $line->getUnitPrice() !== null) !== [];
                    if ($priced && $invoice->carriesVat() !== $this->chargesVat($invoice->kind(), $invoice->getCreditNoteOf())) {
                        throw new InvoiceRefusedException(InvoiceRefusedException::VAT_LIABILITY_CHANGED, $invoice->kind()->label() . ' (Entwurf ' . $id . '): die MWST-Pflicht des Mandanten hat sich seit dem Speichern geändert — den Entwurf öffnen und neu speichern, dann fakturieren.');
                    }
                    if (!in_array($this->documentRange($invoice->getInvoiceDate()), $months, true)) {
                        throw new InvoiceConflictException($id, $versions[$id]);   // re-dated between the read and the lock
                    }
                    // The payment part this issue prints, checked BEFORE any number of the batch is drawn
                    // (owner 2026-10-13, review H5): a document is never issued irrevocably without a
                    // printable payment part.
                    $payments[spl_object_id($invoice)] = $this->printablePaymentPart($invoice);
                    $locked[$id] = $invoice;
                }

                // 4. + 5. The numbers — the first writes — by invoice date, then id; stamp, issue, archive.
                $order = $this->numberAndIssue(array_values($locked), $payments, $ranges, $now);

                // The dunning fee only (finalize()): posted at once, in the same order.
                if ($post) {
                    foreach ($order as $invoice) {
                        $request = PostingBuilder::build($invoice, $receivable);
                        $invoice->post($request === null ? null : $gateway->post($request), null, $this->actor, $now);
                        $this->em->persist($invoice);
                    }
                }

                return $order;
            });
        } catch (\Throwable $e) {
            if ($owned) {
                $this->archive()->discardWritten();   // the rows rolled back — no file may stay without its row
            }
            throw $e;
        }
    }

    /**
     * Steps 4 and 5 of {@see issue()} (and of {@see invoiceAndIssue()}): the numbers drawn in the
     * order invoice date, then id; per document its numbered payment part, its STAMP, `issued`,
     * its PDF archived with the `invoice_revision` row of revision 1. Inside the caller's unit of
     * work, after every row is locked and judged.
     *
     * @param list<Invoice>               $invoices locked, judged, with ids
     * @param array<int, PaymentSnapshot> $payments the printable payment part by spl_object_id
     * @return list<Invoice> in number order
     */
    private function numberAndIssue(array $invoices, array $payments, NumberRangeRepository $ranges, \DateTimeImmutable $now): array
    {
        usort($invoices, static fn(Invoice $a, Invoice $b) => [$a->getInvoiceDate()->format('Y-m-d'), $a->getId()] <=> [$b->getInvoiceDate()->format('Y-m-d'), $b->getId()]);
        /** @var DebtorProfileRepository $profiles */
        $profiles  = $this->em->getRepository(DebtorProfile::class);
        $revisions = $this->em->getRepository(InvoiceRevision::class);
        $customers = [];
        foreach ($invoices as $invoice) {
            $number   = $this->drawNumber($ranges, $invoice->getInvoiceDate());
            $customer = $customers[spl_object_id($invoice)] = $profiles->findByContact($invoice->getContact())?->getCustomerNumber() ?? 0;
            $invoice->assignNumber($number, $payments[spl_object_id($invoice)]->numbered($customer, $number, self::paymentMessage($invoice, $number)));
        }
        $batch = [];
        foreach ($invoices as $invoice) {
            $stamp = DocumentStamp::of($invoice, $customers[spl_object_id($invoice)], static fn(string $s): bool => isset($batch[$s]) || $revisions->findOneBy(['documentStamp' => $s]) !== null);
            $batch[$stamp] = true;
            $invoice->markIssued($stamp, $this->actor, $now);
            $this->em->persist($invoice);
            $this->em->persist($this->archive()->archive($invoice, $this->actor, $now));
        }

        return $invoices;
    }

    // ── the seams of a source that is its own draft (P7 slim, plan §7, owner go 2026-10-14) ──

    /**
     * What $draft WOULD become — composed exactly as {@see invoice()} and the booking print
     * compose it (party, terms, address, lines, VAT per code by the service date, the 0.05
     * rounding line, the payment part as the booking print would take it NOW), materialized
     * into a TRANSIENT `Invoice` in state `invoicing`: never persisted, no id, no number,
     * nothing locked, nothing written. The ONE computation a source uses for its totals and
     * its preview PDF (plan D11): `Pdf\InvoicePdf::render()` draws it with the existing
     * PREVIEW layout (watermark «VORSCHAU», no number, no payment part).
     *
     * $openProfile composes a contact WITHOUT a debtor profile against the unsaved profile with
     * the default terms ({@see profileToOpen()}) — the booking-print preview of an OLD order whose
     * customer has no profile yet (plan D9; since 2026-10-19 a new order needs a debtor). The one
     * remaining place that treats a contact without a profile as a customer.
     *
     * What the payment part would lack is not a refusal here (the draft is still being
     * written): ask {@see bookingBill()} of the preview — its `problems()` are what
     * {@see invoiceAndIssue()} and {@see issue()} refuse (`payment-part`).
     *
     * Do not persist the result, and do not hand it to anything that writes: a preview is not a
     * document (it has no lines in the database, no version, no origin row).
     *
     * @throws InvoiceRefusedException the draft would be refused — the same reasons as invoice()
     */
    public function preview(InvoiceDraft $draft, bool $openProfile = false): Invoice
    {
        $opening  = $openProfile ? $this->profileToOpen($draft) : null;
        $composed = $this->compose($draft, null, $opening);
        $invoice  = new Invoice($draft->kind, $composed['contact'], $composed['creditNoteOf'], $this->actor, new \DateTimeImmutable());
        [$lines, $taxes] = $this->materialize($invoice, $composed);
        $invoice->issue($composed['snapshot'], $lines, $taxes, $this->paymentPart($invoice, $composed));

        return $invoice;
    }

    /**
     * Whether a document of $kind is computed WITH VAT — the ONE rule (owner 2026-10-21: the
     * mandator's «mehrwertsteuerpflichtig» is binding for documents). compose() applies it, and
     * the editors (debtor's invoice editor, module-order's window) ask it to show or hide VAT:
     *
     *   - a fee: never (plan §6.5 — damages, not a supply);
     *   - a credit note: as its INVOICE was computed ({@see Invoice::carriesVat()}) — a reduction
     *     of consideration follows the original supply (the rule of the rate, ADR-041 decision 4),
     *     also when the mandator's flag changed in between;
     *   - an invoice: by the mandator's flag `liable_to_vat`. No mandator saved, or a record that
     *     cannot be read (`Creditor::mandator()` → null) counts as LIABLE — today's behaviour; the
     *     absence of a record is no declaration of «not liable».
     *
     * Reads the flag NOW: a draft is composed again at every save, an issued document carries
     * the result in its snapshot and is never recomputed (ADR-041 decision 6).
     */
    public function chargesVat(InvoiceKind $kind = InvoiceKind::Invoice, ?Invoice $creditNoteOf = null): bool
    {
        return match (true) {
            $kind === InvoiceKind::Fee => false,
            $creditNoteOf !== null     => $creditNoteOf->carriesVat(),
            default                    => Creditor::mandator($this->em)?->isLiableToVat() ?? true,
        };
    }

    /**
     * Creates the month ranges `document.YYMM` of $invoiceDates ahead of a unit of work that
     * will draw from them (DOCTRINE-NR-003) — for a caller that opens its OWN unit of work and
     * lets {@see invoiceAndIssue()} join it (an order's booking print): inside that unit of
     * work the ranges are only locked, never created, so the first document of a new month
     * never takes the new-row path under contention (DEBTOR-NR-001). Idempotent, consumes no
     * number, committed at once.
     *
     * Refused inside an open unit of work — there `create()` would not commit ahead, which is
     * the one thing this method is for.
     *
     * @param list<\DateTimeImmutable> $invoiceDates the invoice dates of the documents to come
     * @return list<string> the range names, in name order (the order they are locked in)
     * @throws InvoiceRefusedException DATES — a date outside 2000–2099
     * @throws \LogicException called inside an open unit of work
     */
    public function prepareDocumentRanges(array $invoiceDates): array
    {
        if ($this->em->getTransaction(Invoice::class)->isOpen()) {
            throw new \LogicException('prepareDocumentRanges() runs BEFORE the caller opens its unit of work — inside one the ranges would not be committed ahead (DOCTRINE-NR-003)');
        }
        $months = $this->monthRangesOf($invoiceDates);
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        foreach ($months as $range) {
            $ranges->create($range);
        }

        return $months;
    }

    /**
     * Opens the debtor profile of an active contact that has none — with the DEFAULT payment
     * terms (`PaymentTermsRepository::defaultForNewDebtor()`, owner 2026-10-12), the customer
     * number drawn from `customer` — in its OWN unit of work, BEFORE a source books
     * (plan D9, answer Q4): the profile of a prospect who was quoted is opened when the first
     * invoice is booked. Never inside {@see invoiceAndIssue()}'s unit of work: that would
     * draw `customer` next to `document.YYMM` and widen the lock order. A profile left over
     * after a refused booking is harmless — «Debitor» can be switched off while no document
     * exists.
     *
     * @return bool true = opened now, false = the contact already is a debtor (nothing written)
     * @throws InvoiceRefusedException CONTACT_UNKNOWN | CONTACT_INACTIVE | NO_DEBTOR_PROFILE (no active default terms)
     * @throws \LogicException called inside an open unit of work
     */
    public function openDebtorProfile(int $contactId): bool
    {
        if ($this->em->getTransaction(Invoice::class)->isOpen()) {
            throw new \LogicException('openDebtorProfile() runs in its own unit of work BEFORE the booking (plan D9) — never inside one');
        }
        $contact = $this->em->getRepository(Contact::class)->find($contactId);
        if ($contact === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CONTACT_UNKNOWN, "Kontakt {$contactId} gibt es nicht.");
        }
        if ($this->em->getRepository(DebtorProfile::class)->findByContact($contact) !== null) {
            return false;
        }
        if (!$contact->isActive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CONTACT_INACTIVE, 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Debitor angelegt.');
        }
        $terms = $this->em->getRepository(PaymentTerms::class)->defaultForNewDebtor();
        if ($terms === null) {
            throw new InvoiceRefusedException(
                InvoiceRefusedException::NO_DEBTOR_PROFILE,
                '«' . $contact->displayName() . '» ist noch kein Debitor, und keine aktiven Zahlungskonditionen sind als «Standard» markiert — unter Stammdaten › Aufträge › Zahlungskonditionen eine markieren oder den Kontakt als Debitor festlegen.'
            );
        }
        try {
            (new DebtorProfileService($this->em))->open($contact, $terms->getCode());   // range `customer` — its own unit of work
        } catch (InvalidDebtorProfileException $e) {
            if ($this->em->getRepository(DebtorProfile::class)->findByContact($contactId) !== null) {
                return false;   // opened by another request in between — what was wanted
            }
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_DEBTOR_PROFILE, '«' . $contact->displayName() . '» kann nicht als Debitor angelegt werden: ' . implode(' ', [...$e->validator->getErrors(), ...array_values($e->validator->getFieldErrors())]), $e);
        }

        return true;
    }

    /**
     * «Fakturieren» of a source that is its own draft (an order, plan §7, ADR-040 addendum
     * 2026-10-14; since the Faktura-Journal, ADR-054 decision 4: issue WITHOUT posting — until
     * 2026-10-23 `invoiceAndFinalize()`, which also posted): the documents are created and issued
     * (number, stamp, archived PDF, state `issued`) in ONE unit of work without a stored draft ever
     * existing. Kind `invoice` only. JOINS the caller's open unit of work (the order locks its rows
     * first and writes its own status after this call) or opens its own; any refusal rolls back
     * the WHOLE unit of work — the caller's writes included. NOTHING is posted: «Journal verbuchen»
     * posts them ({@see JournalPostingService}).
     *
     * Inside, debtor's lock order (debtor.md «Lock order of the module»):
     *
     *   1. the month ranges `document.YYMM` of the drafts locked FIRST, in name order
     *      (`create()` — created ahead by {@see prepareDocumentRanges()} when the caller joins,
     *      by this method itself when it opens its own unit of work);
     *   2. each draft's debtor profile row SHARED, by contact id (the counterpart of «Debitor»
     *      off) — no profile is `no-debtor-profile`: open it BEFORE with
     *      {@see openDebtorProfile()} (plan D9), never inside;
     *   3. THEN the reads that judge: `compose()` of every draft (party, terms, tax codes and
     *      rates, the revenue accounts incl. «auf Belegen wählbar») — after the last lock, so the
     *      read view sees what the lock holders committed;
     *   4. the documents inserted (flushed for their ids); the payment part each will print
     *      taken NOW and checked (`QrBill::problems()` → `payment-part`) BEFORE any number;
     *   5. the numbers drawn in the order invoice date, then id (`number-range-full` at the
     *      10000th of a month); each numbered with its payment part, stamped, `issued`, its PDF
     *      archived ({@see numberAndIssue()}).
     *
     * The drafts' origin (`sourceType` / `sourceRef`, per line too) is the caller's and opaque.
     * IDEMPOTENCY IS THE CALLER'S: this method issues what it is given every time — a source
     * must refuse a second issue itself (an order already linked to an invoice) and decide what a
     * repeated submit answers (plan §7, «idempotent on the screen»). A joining caller whose own
     * unit of work fails AFTER this call may leave the archive files of this call behind
     * ({@see discardArchived()} removes them).
     *
     * @param list<InvoiceDraft> $drafts
     * @return list<Invoice> the issued documents, in the order they were numbered
     * @throws InvoiceRefusedException any refusal of invoice() or issue() — nothing written
     * @throws \InvalidArgumentException a draft that is not an InvoiceDraft of kind invoice
     */
    public function invoiceAndIssue(array $drafts): array
    {
        if ($drafts === []) {
            return [];
        }
        $contactIds = [];
        $dates      = [];
        foreach ($drafts as $i => $draft) {
            if (!$draft instanceof InvoiceDraft || $draft->kind !== InvoiceKind::Invoice) {
                throw new \InvalidArgumentException("invoiceAndIssue() issues invoices only — item {$i} is no InvoiceDraft of kind invoice");
            }
            $contactIds[$draft->contactId] = $draft->contactId;
            $dates[] = $draft->invoiceDate;
        }
        sort($contactIds);
        $months      = $this->monthRangesOf($dates);   // a date outside 2000–2099 is refused before any lock
        $transaction = $this->em->getTransaction(Invoice::class);
        $owned       = !$transaction->isOpen();
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        if ($owned) {
            foreach ($months as $range) {
                $ranges->create($range);   // standalone: created ahead and committed (DOCTRINE-NR-003), as issue() does
            }
        }

        try {
            return $transaction->run(function () use ($drafts, $contactIds, $months, $ranges): array {
                // 1. The month ranges FIRST, in name order.
                foreach ($months as $range) {
                    $ranges->create($range);
                }
                // 2. The profile rows SHARED, by contact id — locking reads, no read view yet.
                /** @var DebtorProfileRepository $profiles */
                $profiles = $this->em->getRepository(DebtorProfile::class);
                foreach ($contactIds as $contactId) {
                    if ($profiles->lockForContact($contactId, exclusive: false) === null) {
                        $name = $this->em->getRepository(Contact::class)->find($contactId)?->displayName() ?? "Kontakt {$contactId}";
                        throw new InvoiceRefusedException(InvoiceRefusedException::NO_DEBTOR_PROFILE, '«' . $name . '» ist kein Debitor (mehr) — nichts fakturiert; im Kontakt als Debitor festlegen und nochmals versuchen.');
                    }
                }

                // 3. The reads that judge, after the last lock.
                $now    = new \DateTimeImmutable();
                $issued = [];
                foreach ($drafts as $draft) {
                    $composed = $this->compose($draft, null);
                    $invoice  = new Invoice($draft->kind, $composed['contact'], null, $this->actor, $now);
                    [$lines, $taxes] = $this->materialize($invoice, $composed);
                    $invoice->issue($composed['snapshot'], $lines, $taxes, $this->paymentPart($invoice, $composed));
                    $issued[] = $invoice;
                }

                // 4. The payment part each will print — checked before anything is written or numbered.
                $payments = [];
                foreach ($issued as $invoice) {
                    $payments[spl_object_id($invoice)] = $this->printablePaymentPart($invoice);
                    $this->em->persist($invoice);
                }
                $this->em->flush();   // the ids: the order of the numbers and the stamp need them

                // 5. Numbers, stamps, `issued`, archive.
                $issued = $this->numberAndIssue($issued, $payments, $ranges, $now);
                // Written NOW, not first at the caller's commit: a joining caller reads the issued state in the same
                // unit of work (SQL reads such as PaymentStates see the rows, not the identity map), and a unique-index
                // failure surfaces inside this call (found on the dev DB 2026-10-14: without it the rows read `invoicing`).
                $this->em->flush();

                return $issued;
            });
        } catch (\Throwable $e) {
            if ($owned) {
                $this->archive()->discardWritten();
            }
            throw $e;
        }
    }

    /**
     * Removes the archive files this service wrote in a unit of work that did NOT commit — for a
     * caller that JOINED {@see invoiceAndIssue()} into its own unit of work and saw it fail
     * afterwards (an order's «Fakturieren»: its status move or stock call refused). Harmless when
     * nothing was written. Never call it after a commit: the rows name those files.
     */
    public function discardArchived(): void
    {
        $this->archive()->discardWritten();
    }

    private function archive(): InvoiceArchive
    {
        return $this->archive ??= new InvoiceArchive($this->em);
    }
    /**
     * The month ranges `document.YYMM` of $dates, unique, in NAME order — the order they are
     * locked in (two batches never wait on each other crosswise).
     *
     * @param iterable<\DateTimeImmutable> $dates
     * @return list<string>
     * @throws InvoiceRefusedException DATES — a date outside 2000–2099
     */
    /**
     * The QR bill's unstructured message (owner 2026-10-22): the document name with its number and
     * the due date — «Rechnung 2610001, zahlbar bis 21.10.2026» — one ASCII line, written once at the
     * booking print and kept in the snapshot. The bank import still finds the number behind the
     * kind's label (BankImportService: label, then digits).
     */
    private static function paymentMessage(Invoice $invoice, int $number): string
    {
        return self::paymentMessageFor($invoice->kind(), $number, $invoice->getDueDate());
    }

    private static function paymentMessageFor(InvoiceKind $kind, int $number, \DateTimeImmutable $dueDate): string
    {
        return $kind->label() . ' ' . $number . ', zahlbar bis ' . $dueDate->format('d.m.Y');
    }

    private function monthRangesOf(iterable $dates): array
    {
        $months = [];
        foreach ($dates as $date) {
            if (!$date instanceof \DateTimeImmutable) {
                throw new \InvalidArgumentException('An invoice date is a DateTimeImmutable');
            }
            $months[$this->documentRange($date)] = true;
        }
        $months = array_keys($months);
        sort($months, SORT_STRING);

        return $months;
    }

    /**
     * What is still owed on a POSTED invoice: gross − Σ gross of its final
     * credit notes − Σ of its allocations (payments, discount, loss — P4
     * part 1, `PaymentAllocation`). DERIVED, never stored (plan §6.3). Zero
     * while `invoicing` (plan §6.2: no open item yet), while `issued` (in the
     * Faktura-Journal: no debtor ledger entry, not payable — ADR-054, owner
     * decision 6 of 2026-10-23), when void and for a credit note
     * (it is not a receivable, it reduces one). Negative = more was credited
     * than invoiced (a refund is owed).
     */
    public function openAmount(Invoice $invoice): Money
    {
        if (!$invoice->isPosted() || $invoice->isCreditNote()) {
            return Money::zero($invoice->getCurrency());
        }
        /** @var PaymentAllocationRepository $allocations */
        $allocations = $this->em->getRepository(PaymentAllocation::class);

        return $invoice->getGrossTotal()
            ->subtract(Money::fromDecimal($this->invoices()->sumOfFinalCreditNotes($invoice), $invoice->getCurrency()))
            ->subtract(Money::fromDecimal($allocations->sumAllocated($invoice), $invoice->getCurrency()));
    }

    /** The month range `document.YYMM` of $invoiceDate; a date outside 2000–2099 is refused (`dates`). */
    private function documentRange(\DateTimeImmutable $invoiceDate): string
    {
        try {
            return DocumentNumber::range($invoiceDate);
        } catch (\InvalidArgumentException $e) {
            throw new InvoiceRefusedException(InvoiceRefusedException::DATES, 'Das Rechnungsdatum muss in den Jahren 2000–2099 liegen — die Belegnummer trägt Jahr und Monat zweistellig (JJMM).', $e);
        }
    }

    /**
     * The next document number of $invoiceDate's month — `next()` on its range
     * (held since the start of the unit of work), composed `YYMMnnnn`. The
     * 10000th of a month is refused; the caller's unit of work rolls back and
     * the range stays at 9999.
     *
     * @throws InvoiceRefusedException NUMBER_RANGE_FULL
     */
    private function drawNumber(NumberRangeRepository $ranges, \DateTimeImmutable $invoiceDate): int
    {
        $sequence = $ranges->next(DocumentNumber::range($invoiceDate));
        try {
            return DocumentNumber::compose($invoiceDate, $sequence);
        } catch (\OverflowException $e) {
            throw new InvoiceRefusedException(
                InvoiceRefusedException::NUMBER_RANGE_FULL,
                'Für den Monat ' . $invoiceDate->format('m.Y') . ' sind alle ' . DocumentNumber::PER_MONTH
                . ' Belegnummern vergeben — ein weiteres Dokument mit Rechnungsdatum in diesem Monat kann nicht fakturiert werden.',
                $e
            );
        }
    }

    private function gateway(): AccountingGateway
    {
        return $this->gateway ??= AccountingGateways::fromConfig($this->em, $this->actor);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }

    /**
     * Whether the document may still be edited — null = yes (a draft in `invoicing`), else the
     * German reason the write path refuses with (`not-invoicing`). The ONE mask of the document
     * screens shows a document that is no draft read-only with this sentence on top (owner 2026-10-21,
     * the order window's `editRefusal()` model).
     */
    public function editRefusal(Invoice $invoice): ?string
    {
        try {
            // Since step 4 (ADR-054 decision 12) an ISSUED document of the mask is editable too — its save
            // is a revision or a replacement ({@see revise()}); one issued from a SOURCE is corrected there.
            $invoice->isInJournal() ? $this->assertCorrectable($invoice) : $this->assertInvoicing($invoice);
        } catch (InvoiceRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    // ── corrections while in the Faktura-Journal (ADR-054 decisions 8–14; steps 4–6 of the build plan) ──

    /**
     * What saving $draft over the ISSUED $issued would do — the decision rule of ADR-054 decision 8
     * ({@see Correction}): NONE, a REVISION (same number, revision + 1) or a REPLACEMENT (a new number,
     * $issued `replaced`). Pure: the draft is composed exactly as {@see revise()} composes it into a
     * TRANSIENT document (never persisted, nothing locked or written). The mask asks it BEFORE the save
     * to name the consequence; a source (an order) asks it to flag its invoice ({@see flagReissue()}).
     *
     * $fromSource = true: the caller IS the document's source (module-order) — a sourced document is
     * corrected only there.
     *
     * @throws InvoiceRefusedException NOT_IN_JOURNAL | SOURCED | KIND_CHANGED | CREDIT_NOTE_TARGET | any refusal of compose()
     */
    public function classify(Invoice $issued, InvoiceDraft $draft, bool $fromSource = false): Correction
    {
        return $this->corrected($issued, $draft, $fromSource)['correction'];
    }

    /**
     * Save a correction of an ISSUED document (ADR-054 decisions 8, 10, 11; step 4 of the build plan) —
     * the server classifies it ({@see classify()}) and answers the document that STANDS afterwards:
     *
     *   - NONE: nothing written (a source's re-issue flag is cleared — the source is back in step);
     *   - REVISION: the same row and number, `revision + 1`, a new stamp, the new version's PDF archived
     *     as a new file (`<number>-r<n>-<stamp>.pdf`, `invoice_revision` row with the reason); the earlier
     *     revisions' rows and files stay; the payment part keeps its reference, its message and due date
     *     are taken again;
     *   - REPLACEMENT: a NEW document (successor) is created and issued — its number from the month of
     *     ITS invoice date, stamped, archived; its PDF prints «ersetzt Rechnung <A> (ID <stamp of A>)»
     *     (decision 23, owner O11); $issued goes `replaced` with the link to the successor, who / when /
     *     why; its waiting outbox mails are cancelled (decision 21). The successor is returned.
     *
     * ONE unit of work (joining the caller's — module-order's «Neu fakturieren» — or its own). Lock order
     * (debtor.md): the successor's month range `document.YYMM` (created ahead; joined: by the caller,
     * {@see prepareDocumentRanges()}) → the successor's debtor profile row SHARED → the issued row
     * EXCLUSIVE, its version compared → the writes. The classification is judged BEFORE the unit of work
     * and AGAIN when $expect is given: when it is no longer what the user confirmed, `correction-changed`
     * — nothing written, the screen asks again (Rule 7).
     *
     * @param string|null $expect the kind the user confirmed (Correction::REVISION / REPLACEMENT), null = no confirmation asked
     * @param string|null $reason why (protocolled on the revision row / as the void reason); null = the classification's summary
     * @throws InvoiceRefusedException  NOT_IN_JOURNAL | SOURCED | CORRECTION_CHANGED | PAYMENT_PART | any refusal of compose() / issue()
     * @throws InvoiceConflictException the document is gone or at another version
     */
    public function revise(int $invoiceId, int $expectedVersion, InvoiceDraft $draft, ?string $expect = null, ?string $reason = null, bool $fromSource = false): Invoice
    {
        $existing = $this->invoices()->withLines($invoiceId);
        if ($existing === null || $existing->getVersion() !== $expectedVersion) {
            throw new InvoiceConflictException($invoiceId, $expectedVersion);
        }
        ['correction' => $correction, 'composed' => $composed] = $this->corrected($existing, $draft, $fromSource);
        if ($expect !== null && $correction->kind !== $expect) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CORRECTION_CHANGED, $existing->documentName() . ': die Änderung wird jetzt anders eingestuft (' . self::correctionWord($correction) . ' — ' . $correction->summary() . ') — bitte nochmals prüfen und bestätigen.');
        }
        $now         = new \DateTimeImmutable();
        $transaction = $this->em->getTransaction(Invoice::class);
        $owned       = !$transaction->isOpen();
        $reason      = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($correction->isNone()) {
            if ($existing->getReissueNeeded() === null) {
                return $existing;
            }

            return $transaction->run(function () use ($invoiceId, $expectedVersion, $fromSource): Invoice {
                $invoice = $this->lockCorrectable($invoiceId, $expectedVersion, $fromSource);
                $invoice->flagReissue(null);
                $this->em->persist($invoice);
                $this->em->flush();

                return $invoice;
            });
        }

        $months = $correction->isReplacement() ? $this->monthRangesOf([$composed['snapshot']->invoiceDate]) : [];
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        if ($owned) {
            foreach ($months as $range) {
                $ranges->create($range);   // ahead and committed (DOCTRINE-NR-003), as issue() does
            }
        }

        try {
            return $transaction->run(function () use ($invoiceId, $expectedVersion, $correction, $composed, $months, $ranges, $now, $reason, $fromSource): Invoice {
                foreach ($months as $range) {
                    $ranges->create($range);   // 1. the successor's month range FIRST
                }
                if ($correction->isReplacement()) {
                    // 2. the successor's profile row SHARED (the counterpart of «Debitor» off).
                    if ($this->em->getRepository(DebtorProfile::class)->lockForContact((int) $composed['contact']->getId(), exclusive: false) === null) {
                        throw new InvoiceRefusedException(InvoiceRefusedException::NO_DEBTOR_PROFILE, '«' . $composed['contact']->displayName() . '» ist kein Debitor (mehr) — nichts ersetzt.');
                    }
                }
                // 3. the issued row EXCLUSIVE, the version compared, judged again.
                $invoice = $this->lockCorrectable($invoiceId, $expectedVersion, $fromSource);

                return $correction->isRevision()
                    ? $this->writeRevision($invoice, $composed, $reason ?? 'Neue Version — ' . $correction->summary(), $now)
                    : $this->writeReplacement($invoice, $composed, $reason ?? 'Ersetzt — ' . $correction->summary(), $ranges, $now);
            });
        } catch (\Throwable $e) {
            if ($owned) {
                $this->archive()->discardWritten();   // the rows rolled back — no file may stay without its row
            }
            throw $e;
        }
    }

    /**
     * The SOURCE's seam (ADR-054 decision 9, owner decision O9): $reason set = the source no longer
     * matches the issued document (the Faktura-Journal is blocked until it is re-invoiced); null = in
     * step again. Joins the caller's unit of work (module-order: after its order row lock — lock order
     * order row → invoice row) or opens its own; the row is locked EXCLUSIVE and must still be in the
     * journal — posted in between, the caller's write is refused (`not-in-journal`).
     *
     * @throws InvoiceRefusedException NOT_IN_JOURNAL | NOT_FOUND
     */
    public function flagReissue(int $invoiceId, ?string $reason): Invoice
    {
        return $this->em->getTransaction(Invoice::class)->run(function () use ($invoiceId, $reason): Invoice {
            $invoice = $this->invoices()->lockForUpdate($invoiceId)
                ?? throw new InvoiceRefusedException(InvoiceRefusedException::NOT_FOUND, "Dokument {$invoiceId} gibt es nicht.");
            if (!$invoice->isInJournal()) {
                throw new InvoiceRefusedException(InvoiceRefusedException::NOT_IN_JOURNAL, $this->notInJournal($invoice));
            }
            if ($invoice->getReissueNeeded() !== ($reason === null ? null : mb_substr(trim($reason), 0, Invoice::REASON_LENGTH))) {
                $invoice->flagReissue($reason);
                $this->em->persist($invoice);
                $this->em->flush();
            }

            return $invoice;
        });
    }

    /**
     * DELETE an issued document (ADR-054 decision 14, Q7 — decided provisionally): `issued` → `deleted`,
     * kept with its number and snapshot (who / when / why), never posted; its waiting outbox mails
     * cancelled. A posted one is corrected by a credit note; a document with payments (none can exist
     * before the posting, O6) is refused; one issued from a SOURCE only through it ($fromSource — an
     * order's deletion, joined into its unit of work). The reason is mandatory. $expectedVersion null:
     * no version is compared — for a source that holds its own row lock and judged the document under it.
     *
     * @throws InvoiceRefusedException REASON_REQUIRED | NOT_IN_JOURNAL | SOURCED | HAS_PAYMENTS
     * @throws InvoiceConflictException the document is gone or at another version
     */
    public function delete(int $invoiceId, ?int $expectedVersion, string $reason, bool $fromSource = false): Invoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvoiceRefusedException(InvoiceRefusedException::REASON_REQUIRED, 'Ein Grund ist nötig — die gelöschte Rechnung bleibt mit ihrer Nummer und dem Grund aufbewahrt.');
        }
        $now = new \DateTimeImmutable();

        return $this->em->getTransaction(Invoice::class)->run(function () use ($invoiceId, $expectedVersion, $reason, $fromSource, $now): Invoice {
            $invoice = $this->lockCorrectable($invoiceId, $expectedVersion, $fromSource);
            /** @var PaymentAllocationRepository $allocations */
            $allocations = $this->em->getRepository(PaymentAllocation::class);
            if ($allocations->forInvoice($invoice) !== []) {
                throw new InvoiceRefusedException(InvoiceRefusedException::HAS_PAYMENTS, $invoice->documentName() . ' trägt Zahlungen — sie wird nicht gelöscht.');
            }
            $invoice->markDeleted($reason, $this->actor, $now);
            $this->em->persist($invoice);
            $this->em->flush();
            $this->cancelWaitingMails($invoice);

            return $invoice;
        });
    }

    /**
     * The composed correction of $issued by $draft: the composition (as {@see revise()} writes it) and the
     * classification against a transient candidate.
     *
     * @return array{correction: Correction, composed: array<string, mixed>}
     */
    private function corrected(Invoice $issued, InvoiceDraft $draft, bool $fromSource): array
    {
        $this->assertCorrectable($issued, $fromSource);
        if ($issued->kind() !== $draft->kind) {
            throw new InvoiceRefusedException(InvoiceRefusedException::KIND_CHANGED, $issued->documentName() . ' bleibt eine ' . $issued->kind()->label() . ' — für die andere Art ein neues Dokument erstellen.');
        }
        $composed = $this->compose($draft, $issued);
        if ($issued->getCreditNoteOf()?->getId() !== $composed['creditNoteOf']?->getId()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, $issued->documentName() . ' gehört zu einer anderen Rechnung — dafür ein neues Dokument erstellen.');
        }
        $candidate = new Invoice($draft->kind, $composed['contact'], $composed['creditNoteOf'], $this->actor, new \DateTimeImmutable());
        [$lines, $taxes] = $this->materialize($candidate, $composed);
        $candidate->issue($composed['snapshot'], $lines, $taxes, $this->paymentPart($candidate, $composed));

        return ['correction' => Correction::classify($issued, $candidate), 'composed' => $composed];
    }

    /**
     * The REVISION's writes inside the unit of work: the new content under the same number, the payment
     * part taken again (same reference, the message with the new due date) and checked, the stamp, the
     * PDF archived with its `invoice_revision` row.
     *
     * @param array<string, mixed> $composed
     */
    private function writeRevision(Invoice $invoice, array $composed, string $reason, \DateTimeImmutable $now): Invoice
    {
        $customer = $this->customerNumberOf($invoice);
        $payment  = $this->paymentPart($invoice, $composed)
            ->numbered($customer, (int) $invoice->getNumber(), self::paymentMessageFor($invoice->kind(), (int) $invoice->getNumber(), $composed['snapshot']->dueDate));
        [$lines, $taxes] = $this->materialize($invoice, $composed);
        $invoice->revise($composed['snapshot'], $lines, $taxes, $payment, $this->actor, $now);
        if ($payment->hasPaymentPart() && ($problems = QrBill::of($invoice)->problems()) !== []) {
            throw new InvoiceRefusedException(InvoiceRefusedException::PAYMENT_PART, $invoice->documentName() . ': der Zahlteil (QR-Rechnung) liesse sich nicht drucken — ' . implode(' ', $problems));
        }
        $revisions = $this->em->getRepository(InvoiceRevision::class);
        $invoice->restamp(DocumentStamp::of($invoice, $customer, static fn(string $s): bool => $revisions->findOneBy(['documentStamp' => $s]) !== null));
        $this->em->persist($invoice);
        $this->em->flush();   // the new lines (orphan removal drops the old ones) before the PDF reads them
        $this->em->persist($this->archive()->archive($invoice, $this->actor, $now, $reason));
        $this->em->flush();

        return $invoice;
    }

    /**
     * The REPLACEMENT's writes inside the unit of work: the successor inserted, $predecessor `replaced`
     * (flushed, so the successor's PDF finds whom it replaces), the successor numbered, stamped, issued
     * and archived ({@see numberAndIssue()}), the predecessor's waiting mails cancelled.
     *
     * @param array<string, mixed> $composed
     */
    private function writeReplacement(Invoice $predecessor, array $composed, string $reason, NumberRangeRepository $ranges, \DateTimeImmutable $now): Invoice
    {
        $successor = new Invoice($predecessor->kind(), $composed['contact'], $composed['creditNoteOf'], $this->actor, $now);
        [$lines, $taxes] = $this->materialize($successor, $composed);
        $successor->issue($composed['snapshot'], $lines, $taxes, $this->paymentPart($successor, $composed));
        $payments = [spl_object_id($successor) => $this->printablePaymentPart($successor)];
        $this->em->persist($successor);
        $this->em->flush();
        $predecessor->replaceBy($successor, $reason, $this->actor, $now);
        $this->em->persist($predecessor);
        $this->em->flush();
        $this->numberAndIssue([$successor], $payments, $ranges, $now);
        $this->em->flush();
        $this->cancelWaitingMails($predecessor);

        return $successor;
    }

    /** The row EXCLUSIVE at the version the caller saw (null = any), still correctable. */
    private function lockCorrectable(int $invoiceId, ?int $expectedVersion, bool $fromSource): Invoice
    {
        $invoice = $this->invoices()->lockForUpdate($invoiceId);
        if ($invoice === null || ($expectedVersion !== null && $invoice->getVersion() !== $expectedVersion)) {
            throw new InvoiceConflictException($invoiceId, (int) $expectedVersion);
        }
        $this->assertCorrectable($invoice, $fromSource);

        return $invoice;
    }

    /**
     * Only an ISSUED document (in the Faktura-Journal) is revised, replaced or deleted — and one issued
     * from a source (an order) only by that source.
     *
     * @throws InvoiceRefusedException NOT_IN_JOURNAL | SOURCED
     */
    private function assertCorrectable(Invoice $invoice, bool $fromSource = false): void
    {
        if (!$invoice->isInJournal()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NOT_IN_JOURNAL, $this->notInJournal($invoice));
        }
        if (!$fromSource && $invoice->getSourceType() !== null) {
            $origin = $invoice->subjectLine() ?? trim($invoice->getSourceType() . ' ' . $invoice->getSourceRef());
            throw new InvoiceRefusedException(InvoiceRefusedException::SOURCED, $invoice->documentName() . ' ist aus «' . $origin . '» fakturiert — sie wird dort geändert und neu fakturiert (oder dort gelöscht), nicht hier.');
        }
    }

    private function notInJournal(Invoice $invoice): string
    {
        return match (true) {
            $invoice->isPosted() => $invoice->documentName() . ' ist verbucht und bleibt unverändert — eine Korrektur ist eine Gutschrift.',
            $invoice->isDraft()  => $invoice->documentName() . ' ist noch ein Entwurf — er wird bearbeitet, nicht korrigiert.',
            default              => $invoice->documentName() . ' ist ' . $invoice->state()->label() . ' und bleibt unverändert.',
        };
    }

    /** «neue Version» / «Ersatz mit neuer Nummer» / «keine Änderung» — how a screen names the kind of a correction. */
    public static function correctionWord(Correction $correction): string
    {
        return match ($correction->kind) {
            Correction::REVISION    => 'neue Version mit gleicher Nummer',
            Correction::REPLACEMENT => 'Ersatz mit neuer Nummer',
            default                 => 'keine Änderung',
        };
    }

    private function customerNumberOf(Invoice $invoice): int
    {
        return $this->em->getRepository(DebtorProfile::class)->findByContact($invoice->getContact())?->getCustomerNumber() ?? 0;
    }

    /**
     * The waiting outbox mails of a replaced / deleted document are cancelled (ADR-054 decision 21) — in
     * the caller's unit of work; a mail already sending or sent stays history. Nothing without the outbox.
     */
    private function cancelWaitingMails(Invoice $invoice): void
    {
        $mails = new \Z77\Module\Debtor\Mail\InvoiceMails($this->em);
        if ($mails->available()) {
            $mails->cancelWaiting($invoice);
        }
    }

    /**
     * Only a DRAFT is written by reinvoice() / discard() / the booking print. An issued document
     * in the Faktura-Journal is corrected by revision or replacement (ADR-054 decision 8 — step 4
     * of the build plan; until then nothing produces one), a void one stays as it is.
     *
     * @throws InvoiceRefusedException NOT_INVOICING
     */
    private function assertInvoicing(Invoice $invoice): void
    {
        if ($invoice->isDraft()) {
            return;
        }
        // «verbucht» is the UI word for `final` (owner 2026-10-10) — the mask shows this sentence.
        throw new InvoiceRefusedException(InvoiceRefusedException::NOT_INVOICING, match (true) {
            $invoice->isPosted()    => $invoice->documentName() . ' ist verbucht und bleibt unverändert — eine Korrektur ist eine Gutschrift.',
            $invoice->isInJournal() => $invoice->documentName() . ' ist fakturiert und steht im Faktura-Journal — sie wird nicht mehr als Entwurf bearbeitet.',
            default                 => $invoice->documentName() . ' ist ' . $invoice->state()->label() . ' und bleibt unverändert.',
        });
    }

    // ── composing a document from a draft ───────────────────────────────

    /**
     * Everything a draft becomes, decided BEFORE the unit of work: the
     * party, the address, the terms as applied, the lines with their
     * amounts and resolved rates, the tax summary, the totals and the
     * rounding. Refuses with a reason; nothing here writes.
     *
     * @param Invoice|null $existing the document being re-issued — relaxes the «new reference needs an
     *                               active row» rule for what the document already carries (ADR-043 decision 19)
     * @param DebtorProfile|null $opening the unsaved profile {@see preview()} composes against for a contact without one
     * @return array{contact: Contact, creditNoteOf: ?Invoice, snapshot: DocumentSnapshot, lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>, target: ?PaymentTarget, creditor: ?Creditor}
     * @throws InvoiceRefusedException
     */
    private function compose(InvoiceDraft $draft, ?Invoice $existing, ?DebtorProfile $opening = null): array
    {
        $isNew    = $existing === null;
        // A correction of an issued document that names ANOTHER party (a replacement, owner decision O4)
        // takes the new party under the rules of a new document: active contact, active debtor.
        $newParty = $isNew || $existing->getContact()->getId() !== $draft->contactId;
        $currency = strtoupper(trim($draft->currency));
        if ($currency !== DebtorCurrency::base()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CURRENCY, 'Dokumente werden nur in der Basiswährung ' . DebtorCurrency::base() . ' erstellt (Q6) — «' . $currency . '» ist nicht möglich.');
        }
        if ($draft->kind === InvoiceKind::Invoice && $draft->serviceFrom === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::DATES, 'Eine Rechnung braucht ein Leistungsdatum — der MWST-Satz richtet sich danach.');
        }
        if ($draft->lines === []) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_LINES, 'Ein Dokument braucht mindestens eine Position.');
        }

        // The party, its profile, its terms, its address.
        $contact = $this->em->getRepository(Contact::class)->find($draft->contactId);
        if ($contact === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CONTACT_UNKNOWN, "Kontakt {$draft->contactId} gibt es nicht.");
        }
        if ($newParty && !$contact->isActive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CONTACT_INACTIVE, 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein neues Dokument erstellt.');
        }
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);
        $profile  = $profiles->findByContact($contact)
            ?? ($opening !== null && $opening->getContact()?->getId() === $contact->getId() ? $opening : null);
        if ($profile === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_DEBTOR_PROFILE, DebtorProfileService::notDebtor($contact));
        }
        if ($newParty && !$profile->isActive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::DEBTOR_INACTIVE, 'Debitor «' . $contact->displayName() . '» ist inaktiv — kein neues Dokument.');
        }
        $termsCode = PaymentTerms::normalizeCode($draft->paymentTermsCode ?? $profile->getPaymentTermsCode());
        $terms     = $this->em->getRepository(PaymentTerms::class)->findByCode($termsCode);
        if ($terms === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TERMS_UNKNOWN, 'Zahlungskonditionen «' . $termsCode . '» gibt es nicht.');
        }
        if (!$terms->isActive() && ($isNew || $existing->getPaymentTermsCode() !== $termsCode)) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TERMS_INACTIVE, 'Zahlungskonditionen «' . $terms->getLabel() . '» sind inaktiv — beim Debitor andere hinterlegen.');
        }
        $address = $draft->address ?? $this->invoiceAddressOf($contact);
        if ($address === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_ADDRESS, 'Kontakt «' . $contact->displayName() . '» hat keine Adresse — ohne Rechnungsadresse kein Dokument.');
        }
        if (!$address->isComplete()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::ADDRESS_INCOMPLETE, 'Die Rechnungsadresse ist unvollständig (Name, PLZ, Ort, Land).');
        }
        $i18n     = DI::getI18n();
        $language = $contact->getLanguage() !== '' ? $contact->getLanguage() : $i18n->getDefaultLanguage();

        // The payment target whose account the payment part prints — resolved now for the draft
        // (paymentPart()); the booking print takes it again (bookedPaymentPart()).
        $target = $this->paymentTargetOf($draft);

        // A credit note names the FINAL invoice it corrects.
        $creditNoteOf = null;
        if ($draft->kind === InvoiceKind::CreditNote) {
            $creditNoteOf = $draft->creditNoteOfId === null ? null : $this->invoices()->find($draft->creditNoteOfId);
            if ($creditNoteOf === null || $creditNoteOf->isCreditNote()) {
                throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, 'Eine Gutschrift bezieht sich auf eine Rechnung — «' . ($draft->creditNoteOfId ?? '?') . '» ist keine.');
            }
            if (!$creditNoteOf->isPosted()) {
                // While the invoice is `invoicing` nothing is posted: the correction is reinvoice(), and a
                // credit note would mirror a posting that does not exist.
                throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, $creditNoteOf->isInJournal()
                    ? $creditNoteOf->documentName() . ' steht im Faktura-Journal und ist noch nicht verbucht — eine Gutschrift erst nach «Journal verbuchen».'
                    : $creditNoteOf->documentName() . ' ist noch ein Entwurf — sie wird bearbeitet, nicht gutgeschrieben.');
            }
            if ($creditNoteOf->getContact()->getId() !== $contact->getId()) {
                throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, $creditNoteOf->documentName() . ' gehört einer anderen Partei.');
            }
        } elseif ($draft->creditNoteOfId !== null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, 'Eine Rechnung bezieht sich auf keine andere Rechnung.');
        }

        // The service date: a credit note's is the ORIGINAL supply's unless the draft names its own.
        $serviceFrom = $draft->serviceFrom ?? $creditNoteOf?->getServiceFrom();
        $serviceTo   = $draft->serviceFrom === null ? $creditNoteOf?->getServiceTo() : $draft->serviceTo;
        if ($serviceFrom === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::DATES, 'Ohne Leistungsdatum kein Dokument.');
        }
        if ($serviceTo !== null && $serviceTo->format('Y-m-d') < $serviceFrom->format('Y-m-d')) {
            throw new InvoiceRefusedException(InvoiceRefusedException::DATES, 'Das Ende der Leistungsperiode liegt vor ihrem Beginn.');
        }

        // The optional subject (P7 slim, plan D10): printed under the title — refused when too long, never cut.
        $subject = trim((string) $draft->subject);
        if (mb_strlen($subject) > Invoice::SUBJECT_LENGTH) {
            throw new InvoiceRefusedException(InvoiceRefusedException::SUBJECT, 'Der Betreff hat höchstens ' . Invoice::SUBJECT_LENGTH . ' Zeichen.');
        }
        // The optional subject label «Betreffname» (owner 2026-10-21): normalised (trimmed, trailing colon
        // stripped — the print adds «: »), refused when too long, never cut. SubjectLabel is the one rule.
        $subjectLabel = SubjectLabel::normalize($draft->subjectLabel);
        if (SubjectLabel::isTooLong($subjectLabel)) {
            throw new InvoiceRefusedException(InvoiceRefusedException::SUBJECT_LABEL, 'Der Betreffname hat höchstens ' . SubjectLabel::LENGTH . ' Zeichen.');
        }

        // The invoice date carries the month of the document number drawn at the booking print (owner 2026-10-10,
        // DocumentNumber): a two-digit year, so 2000–2099 only — refused here, at issue, not first at the booking.
        // A draft has no number yet, so a re-issue may move its date into any month.
        $this->documentRange($draft->invoiceDate);

        // The lines: flattened (children after their parent), amounts computed. A fee's line carries no tax code (plan §6.5);
        // a document WITHOUT VAT (mandator not liable, owner 2026-10-21 — chargesVat()) stores none either: the
        // codes a source hands in (an order line takes its article's) are dropped, not refused.
        $isFee   = $draft->kind === InvoiceKind::Fee;
        $withVat = $this->chargesVat($draft->kind, $creditNoteOf);
        $lines   = $this->composeLines($draft->lines, $currency, taxFree: $isFee, withVat: $withVat);

        // The note a document WITHOUT VAT prints (owner 2026-10-22): the mandator's «Hinweis ohne MWST» as it
        // reads NOW (Mandator::documentVatNote() — only while the company is not liable), snapshotted so a
        // reprint reads as issued (ADR-041 decision 6). Never from the draft: it is the mandator's, no
        // source posts it. A credit note of a no-VAT invoice takes today's text (it is composed afresh); a
        // fee carries no VAT by nature and gets the note too while the company is not liable — the sentence
        // «nicht mehrwertsteuerpflichtig» is true of the issuer, not of the supply; while liable, none (the
        // fee's missing VAT is the fee's nature, not the company's state). Null with VAT or without a text.
        $vatNote = $withVat ? null : Creditor::mandator($this->em)?->documentVatNote();

        // VAT once, by the service date, on the priced lines — none for a fee or a document without VAT: no rates,
        // no tax rows, net = the lines, the price mode changes no amount.
        $vat        = !$withVat ? null : $this->calculateVat($lines, $currency, $serviceFrom, $draft->priceMode, $isNew ? null : $existing);
        $rateByCode = [];
        foreach ($vat?->lines ?? [] as $resolvedLine) {
            $rateByCode[$resolvedLine->rate->code] = $resolvedLine->rate;
            $lines[(int) $resolvedLine->line->ref]['tax_rate']  = $resolvedLine->rate->rate;
            $lines[(int) $resolvedLine->line->ref]['tax_label'] = $resolvedLine->rate->label;
        }

        // A credit note reduces the consideration of the ORIGINAL supply: for every code the
        // invoice carries, the credit note's rate must be the invoice's (ADR-041 decision 4) —
        // otherwise the VAT account keeps a residue and the return reports the reduction under
        // the wrong rate (review 2026-09-23). A code the invoice never carried resolves by date.
        if ($creditNoteOf !== null) {
            foreach ($creditNoteOf->getTaxes() as $invoiceTax) {
                $rate = $rateByCode[$invoiceTax->getTaxCode()] ?? null;
                if ($rate !== null && $rate->rate !== $invoiceTax->getTaxRate()) {
                    throw new InvoiceRefusedException(
                        InvoiceRefusedException::CREDIT_NOTE_RATE,
                        'MWST-Code «' . $rate->code . '»: die Gutschrift löst am ' . $serviceFrom->format('d.m.Y') . ' ' . TaxRate::formatPercent($rate->rate)
                        . ' % auf, ' . $creditNoteOf->documentName() . ' trägt ' . TaxRate::formatPercent($invoiceTax->getTaxRate())
                        . ' % — eine Gutschrift folgt dem Satz der ursprünglichen Leistung (Leistungsdatum der Rechnung übernehmen).'
                    );
                }
            }
        }

        // Gross mode: every posted line must keep a positive net after its tax share (review 2026-09-23).
        if ($vat !== null && $draft->priceMode === PriceMode::Gross) {
            $this->assertTaxSharesFit($lines, $vat->summary->entries());
        }

        // The accounts every priced line names — checked softly against the bookkeeping; the flag
        // «auf Belegen wählbar» only for an invoice (owner 2026-10-13, see assertRevenueAccounts()).
        $this->assertRevenueAccounts($lines, $draft->kind === InvoiceKind::Invoice);

        // Totals and the rounding line.
        $net      = $vat?->net() ?? array_reduce($lines, static fn(Money $sum, array $l) => $sum->add($l['amount']), Money::zero($currency));
        $tax      = $vat?->tax() ?? Money::zero($currency);
        $exact    = $net->add($tax);
        $step     = self::ROUNDING_STEPS[$currency] ?? 1;
        $gross    = $exact->roundTo($step);
        $rounding = $gross->subtract($exact);
        if ($draft->kind === InvoiceKind::Invoice && $gross->isNegative()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NEGATIVE_TOTAL, 'Der Rechnungsbetrag ist negativ — das ist eine Gutschrift, keine Rechnung.');
        }
        if ($isFee && !$gross->isPositive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NEGATIVE_TOTAL, 'Eine Gebühr muss positiv sein.');
        }
        if ($draft->kind === InvoiceKind::CreditNote && !$gross->isPositive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NEGATIVE_TOTAL, 'Der Gutschriftsbetrag muss positiv sein — die Positionen werden wie gedruckt erfasst, das Vorzeichen gibt die Gutschrift.');
        }
        if (!$rounding->isZero()) {
            $lines[] = [
                'type' => LineType::Rounding, 'parent' => null, 'text' => 'Rundung', 'quantity' => null, 'unit' => null,
                'unit_price' => null, 'discount' => 0, 'amount' => $rounding, 'tax_code' => null, 'tax_rate' => null, 'tax_label' => null,
                'account' => (new DebtorAccounts($this->em))->postableNumber('rounding'), 'source_type' => null, 'source_ref' => null, 'code' => null,
            ];
        }

        // The tax summary, one row per code.
        $taxes = [];
        foreach ($vat?->summary->entries() ?? [] as $entry) {
            $rate    = $rateByCode[$entry->code];
            $taxes[] = ['code' => $entry->code, 'category' => $rate->category, 'label' => $rate->label, 'rate' => $entry->rate, 'base' => $entry->base, 'tax' => $entry->tax];
        }

        // The terms as applied.
        $dueDate = $draft->invoiceDate->modify('+' . $terms->getDueDays() . ' days');
        $tiers   = [];
        foreach ($terms->getDiscounts() as $tier) {
            $tiers[] = ['days' => $tier['days'], 'percent' => $tier['percent'], 'until' => $draft->invoiceDate->modify('+' . $tier['days'] . ' days')->format('Y-m-d')];
        }

        $snapshot = new DocumentSnapshot(
            $address,
            $language,
            $draft->invoiceDate,
            $serviceFrom,
            $serviceTo,
            $currency,
            $draft->priceMode,
            $termsCode,
            $dueDate,
            $tiers,
            DocumentText::resolve($terms->getDocumentText(), $language, $i18n->getDefaultLanguage()),
            $net,
            $tax,
            $rounding,
            $gross,
            self::opaque($draft->sourceType, Invoice::SOURCE_TYPE_LENGTH),
            self::opaque($draft->sourceRef, Invoice::SOURCE_REF_LENGTH),
            $subject !== '' ? $subject : null,
            $subjectLabel,
            $vatNote,
        );

        return [
            'contact' => $contact, 'profile' => $profile, 'creditNoteOf' => $creditNoteOf, 'snapshot' => $snapshot, 'lines' => $lines, 'taxes' => $taxes,
            'target' => $target, 'creditor' => $target === null ? null : Creditor::of($target, Creditor::mandator($this->em)),
        ];
    }

    /**
     * The draft's payment target (P3 part 3): none when the draft names none;
     * a credit note never carries one (it has no payment part). The target
     * must be ACTIVE — at issue AND at a re-issue (owner 2026-10-13, review
     * M4): the booking print takes the target again and refuses an inactive
     * one ({@see bookedPaymentPart()}), so a draft saved with it could never
     * be booked. Until 2026-10-13 a re-issue kept a target deactivated since
     * (the ADR-043 decision 19 relaxation for what a document already
     * carries) — dropped: a draft is not a document yet, nothing printed
     * names that target.
     *
     * @throws InvoiceRefusedException TARGET_UNKNOWN | TARGET_INACTIVE | NO_PAYMENT_PART
     */
    private function paymentTargetOf(InvoiceDraft $draft): ?PaymentTarget
    {
        $code = PaymentTarget::normalizeCode((string) $draft->paymentTargetCode);
        if ($code === '') {
            return null;
        }
        if ($draft->kind === InvoiceKind::CreditNote) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_PAYMENT_PART, 'Eine Gutschrift hat keinen Zahlteil — ohne Zahlungsziel erstellen.');
        }
        $target = $this->em->getRepository(PaymentTarget::class)->findByCode($code);
        if ($target === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TARGET_UNKNOWN, 'Zahlungsziel «' . $code . '» gibt es nicht.');
        }
        if (!$target->isActive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TARGET_INACTIVE, 'Zahlungsziel «' . $target->getLabel() . '» ist inaktiv — ein anderes wählen.');
        }

        return $target;
    }

    /**
     * The payment part of the DRAFT $invoice: target, account, reference type
     * and creditor as they read now — the reference and the message follow
     * with the number at «Fakturieren» ({@see issue()},
     * `PaymentSnapshot::numbered()`).
     *
     * @param array{target: ?PaymentTarget, creditor: ?Creditor} $composed
     */
    private function paymentPart(Invoice $invoice, array $composed): PaymentSnapshot
    {
        return PaymentSnapshot::forDocument($invoice->kind(), $composed['target'], $composed['creditor']);
    }

    /**
     * The payment part the booking print prints (owner 2026-10-13): taken AGAIN from the
     * draft's payment target as it reads NOW — a draft is not a document yet, so a QR-IBAN
     * entered after the draft was saved must reach the bill (it did not: draft 50, saved
     * before the QR-IBAN, was booked with reference type NON). A new reference needs an
     * ACTIVE target (ADR-043 decision 19). A draft without payment part keeps none.
     *
     * Public because the draft's detail shows THIS part (what will be printed), not the
     * part stored with the draft (owner 2026-10-13, review H5) — see {@see bookingBill()}.
     *
     * @throws InvoiceRefusedException TARGET_UNKNOWN | TARGET_INACTIVE
     */
    public function bookedPaymentPart(Invoice $invoice): PaymentSnapshot
    {
        $draft = $invoice->getPayment();
        if (!$draft->hasPaymentPart()) {
            return $draft;
        }
        $target = $this->em->getRepository(PaymentTarget::class)->findByCode($draft->getTargetCode());
        if ($target === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TARGET_UNKNOWN, $invoice->documentName() . ': Zahlungsziel «' . $draft->getTargetCode() . '» gibt es nicht mehr — im Entwurf ein anderes wählen.');
        }
        if (!$target->isActive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TARGET_INACTIVE, $invoice->documentName() . ': Zahlungsziel «' . $target->getLabel() . '» ist inaktiv — im Entwurf ein anderes wählen.');
        }

        return PaymentSnapshot::forDocument($invoice->kind(), $target, Creditor::of($target, Creditor::mandator($this->em)));
    }

    /**
     * The QR-bill as the booking print of $invoice WILL print it — for a draft from
     * {@see bookedPaymentPart()} (a target gone or inactive is the bill's one problem), for a
     * booked document from its snapshot. The draft's mask (its facts block) and its list «Vor dem
     * Buchungsdruck beheben» read this (owner 2026-10-13, review H5), so what they report is
     * exactly what {@see issue()} refuses.
     */
    public function bookingBill(Invoice $invoice): QrBill
    {
        if (!$invoice->isDraft()) {
            return QrBill::of($invoice);
        }
        try {
            return QrBill::of($invoice, null, $this->bookedPaymentPart($invoice));
        } catch (InvoiceRefusedException $e) {
            return QrBill::unavailable($invoice, $e->getMessage());
        }
    }

    /**
     * The payment part the booking print writes for $invoice — refused when its bill would
     * not be printable (owner 2026-10-13, review H5: never book a document irrevocably without
     * its payment part). A draft without a payment part (no target, a credit note) passes:
     * it prints none, as it was issued.
     *
     * @throws InvoiceRefusedException TARGET_UNKNOWN | TARGET_INACTIVE | PAYMENT_PART
     */
    private function printablePaymentPart(Invoice $invoice): PaymentSnapshot
    {
        $payment = $this->bookedPaymentPart($invoice);
        if (!$payment->hasPaymentPart()) {
            return $payment;
        }
        $problems = QrBill::of($invoice, null, $payment)->problems();
        if ($problems !== []) {
            throw new InvoiceRefusedException(
                InvoiceRefusedException::PAYMENT_PART,
                $invoice->documentName() . ': der Zahlteil (QR-Rechnung) liesse sich nicht drucken — ' . implode(' ', $problems) . ' Im Entwurf, beim Zahlungsziel oder beim Mandanten beheben.'
            );
        }

        return $payment;
    }

    /**
     * The draft lines flattened into document order with their amounts,
     * every rule per type applied. `parent` is the index of the parent line
     * in the returned list, or null.
     *
     * @param list<LineDraft> $drafts
     * @return list<array<string, mixed>>
     * @throws InvoiceRefusedException LINE
     */
    private function composeLines(array $drafts, string $currency, bool $taxFree = false, bool $withVat = true): array
    {
        $lines = [];
        $walk  = function (LineDraft $draft, ?int $parent, int $position) use (&$lines, &$walk, $currency, $taxFree, $withVat): void {
            if (!$draft instanceof LineDraft) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: keine Position.");
            }
            $type = $draft->type;
            $text = trim($draft->text);
            if ($type === LineType::Rounding) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: die Rundungszeile wird vom System gesetzt.");
            }
            if ($parent !== null && $draft->children !== []) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: eine Unterposition trägt keine weiteren Unterpositionen (eine Ebene).");
            }
            if ($draft->children !== [] && !$type->isPriced()) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: nur eine Position mit Preis trägt Unterpositionen.");
            }
            if ($draft->discountPercent < 0 || $draft->discountPercent > 10000) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: der Rabatt liegt zwischen 0 und 100 %.");
            }
            if ($draft->unit !== null && mb_strlen($draft->unit) > InvoiceLine::UNIT_LENGTH) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: die Einheit hat höchstens " . InvoiceLine::UNIT_LENGTH . ' Zeichen.');
            }
            // The line's short code (owner 2026-10-22): any line type, trimmed, '' = none, never cut.
            $code = trim((string) $draft->code);
            if (mb_strlen($code) > InvoiceLine::CODE_LENGTH) {
                throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: der Code hat höchstens " . InvoiceLine::CODE_LENGTH . ' Zeichen.');
            }

            if ($type->isPriced()) {
                if ($draft->unitPrice === null || $draft->unitPrice->currency !== $currency) {
                    throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: ein Preis in {$currency} fehlt.");
                }
                $taxCode = TaxCode::normalizeCode((string) $draft->taxCode);
                if ($taxFree) {
                    // A fee (plan §6.5): damages for the delay, never a supply — no tax code on its line.
                    if ($taxCode !== '') {
                        throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: eine Gebühr trägt keinen MWST-Code.");
                    }
                    $taxCode = null;
                } elseif (!$withVat) {
                    // A document without VAT (chargesVat()): the line keeps no code — no rate, no tax row, no
                    // tax data on its posting line. Not refused: an order line carries its article's code.
                    $taxCode = null;
                } elseif ($taxCode === '') {
                    throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: der MWST-Code fehlt.");
                }
                $account = trim((string) $draft->revenueAccount);
                if (!preg_match('/^[0-9]{1,' . InvoiceLine::ACCOUNT_LENGTH . '}$/', $account)) {
                    throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: das Ertragskonto fehlt oder ist keine Kontonummer.");
                }
                if ($type === LineType::Service) {
                    $quantity = trim((string) $draft->quantity);
                    if (!preg_match(InvoiceLine::QUANTITY_PATTERN, $quantity)) {
                        throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: die Menge ist keine Zahl mit höchstens drei Dezimalen.");
                    }
                    $amount = $draft->unitPrice->multiply($quantity);
                } else {
                    if ($draft->quantity !== null) {
                        throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: eine Pauschale hat keine Menge.");
                    }
                    $quantity = null;
                    $amount   = $draft->unitPrice;
                }
                if ($draft->discountPercent > 0) {
                    $amount = $amount->subtract($amount->multiplyByRatio($draft->discountPercent, 10000));
                }
                $unit      = $draft->unit !== null && trim($draft->unit) !== '' ? trim($draft->unit) : null;
                $unitPrice = $draft->unitPrice;
            } else {
                if ($draft->unitPrice !== null || $draft->quantity !== null || $draft->taxCode !== null || $draft->revenueAccount !== null || $draft->discountPercent !== 0) {
                    throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: eine Textposition trägt weder Menge, Preis, Rabatt, MWST-Code noch Konto.");
                }
                if ($text === '') {
                    throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: eine Textposition ohne Text.");
                }
                $taxCode = null; $account = null; $quantity = null; $amount = Money::zero($currency); $unit = null; $unitPrice = null;
            }

            $lines[] = [
                'type' => $type, 'parent' => $parent, 'text' => $text, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $unitPrice,
                'discount' => $draft->discountPercent, 'amount' => $amount, 'tax_code' => $taxCode, 'tax_rate' => null, 'tax_label' => null,
                'account' => $account, 'source_type' => self::opaque($draft->sourceType, InvoiceLine::SOURCE_TYPE_LENGTH),
                'source_ref' => self::opaque($draft->sourceRef, InvoiceLine::SOURCE_REF_LENGTH),
                'code' => $code === '' ? null : $code,
            ];
            $index = count($lines) - 1;
            foreach ($draft->children as $child) {
                $walk($child, $index, count($lines) + 1);
            }
        };
        foreach ($drafts as $draft) {
            $walk($draft, null, count($lines) + 1);
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @throws InvoiceRefusedException TAX_CODE_UNKNOWN | TAX_CODE_INACTIVE | NO_TAX_RATE
     */
    private function calculateVat(array $lines, string $currency, \DateTimeImmutable $serviceDate, PriceMode $mode, ?Invoice $existing): VatResult
    {
        $codes    = $this->em->getRepository(TaxCode::class);
        $kept     = $existing === null ? [] : array_fill_keys(array_filter(array_map(static fn(InvoiceLine $l) => $l->getTaxCode(), $existing->getLines())), true);
        $vatLines = [];
        foreach ($lines as $i => $line) {
            if ($line['tax_code'] === null) {
                continue;
            }
            $code = $codes->findByCode($line['tax_code']);
            if ($code === null) {
                throw new InvoiceRefusedException(InvoiceRefusedException::TAX_CODE_UNKNOWN, 'Position ' . ($i + 1) . ': MWST-Code «' . $line['tax_code'] . '» gibt es nicht.');
            }
            // The reference rule: a NEW document takes active codes only; a re-issue keeps what it carried.
            if (!$code->isActive() && !isset($kept[$code->getCode()])) {
                throw new InvoiceRefusedException(InvoiceRefusedException::TAX_CODE_INACTIVE, 'Position ' . ($i + 1) . ': MWST-Code «' . $code->getCode() . '» ist inaktiv — nur ein unverändertes Dokument behält ihn.');
            }
            $vatLines[] = new VatLine((string) $i, $line['amount'], $code->getCode());
        }

        try {
            return (new VatCalculator(VatRates::from($this->em)))->calculate($currency, $vatLines, $serviceDate, $mode);
        } catch (UnknownTaxCodeException $e) {
            throw new InvoiceRefusedException(InvoiceRefusedException::TAX_CODE_UNKNOWN, 'MWST-Code «' . $e->taxCode . '» gibt es nicht.', $e);
        } catch (NoRateException $e) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_TAX_RATE, 'Für MWST-Code «' . $e->taxCode . '» gilt am ' . $serviceDate->format('d.m.Y') . ' kein Satz.', $e);
        }
    }

    /**
     * Gross mode: the per-line tax share the journal posting will post must leave
     * every line a positive net ({@see TaxShares}). Run when the DRAFT is
     * saved, so the degenerate document (a code made of 0.01 lines) is
     * refused while it can still be edited — not first at the booking
     * print, where it would stop a whole batch.
     *
     * @param list<array<string, mixed>> $lines
     * @param list<TaxSummaryEntry> $entries
     * @throws InvoiceRefusedException LINE_TAX_SHARE
     */
    private function assertTaxSharesFit(array $lines, array $entries): void
    {
        foreach ($entries as $entry) {
            $amounts = [];
            foreach ($lines as $line) {
                if ($line['tax_code'] === $entry->code && !$line['amount']->isZero()) {
                    $amounts[] = $line['amount'];
                }
            }
            try {
                TaxShares::distribute($amounts, $entry->tax, $entry->rate, PriceMode::Gross);
            } catch (NoTaxShareException $e) {
                throw new InvoiceRefusedException(
                    InvoiceRefusedException::LINE_TAX_SHARE,
                    'MWST-Code «' . $entry->code . '»: die Positionen sind zu klein, um ihren MWST-Anteil von ' . $entry->tax->toDecimal()
                    . ' zu tragen (grösste Position ' . $e->largestAmount->toDecimal() . ') — Positionen zusammenfassen oder netto erfassen.',
                    $e
                );
            }
        }
    }

    /**
     * Every revenue account a line names, asked softly of the bookkeeping
     * ({@see LedgerAccountCheck}: `null` = cannot tell, not a failure): it
     * must take a posting AND be of the chart's type «Ertrag»
     * ({@see LedgerAccountCheck::TYPE_REVENUE}, owner 2026-10-10) — for every
     * kind: an invoice line earns, a credit note reverses the same revenue,
     * a fee is revenue too. The rounding line is not passed in (it posts to
     * the mandator's rounding account, set by the system).
     *
     * An INVOICE line names an account the USER chose, so it must also be
     * flagged «auf Belegen wählbar» in the chart
     * ({@see LedgerAccountCheck::isDocumentAccount()}, owner 2026-10-12 —
     * not the revenue deductions 3800 / 3805 / 3809). $documentChoice =
     * false skips the flag for the two kinds that are not a free choice:
     * a CREDIT NOTE mirrors the invoice it corrects (owner 2026-10-13) — the
     * flag switched off after the invoice must never block its correction,
     * so its lines need the type only; a FEE's line carries the mandator's
     * dunning-fee account (`DebtorAccounts`, KMU 6950), a setting, not a
     * pick — switching 6950 off for invoice lines never blocks a dunning run.
     *
     * $prefix names the document in a refusal at the booking print, where a
     * batch holds several («Rechnung (Entwurf vom …), »); empty at issue.
     *
     * @param array<int, array{account: ?string}> $lines 0-based, in position order
     * @throws InvoiceRefusedException ACCOUNT_NOT_POSTABLE | ACCOUNT_NOT_REVENUE | ACCOUNT_NOT_SELECTABLE
     */
    private function assertRevenueAccounts(array $lines, bool $documentChoice, string $prefix = ''): void
    {
        $check = new LedgerAccountCheck($this->em);
        $seen  = [];
        foreach ($lines as $i => $line) {
            $account = $line['account'];
            if ($account === null || isset($seen[$account])) {
                continue;
            }
            $seen[$account] = true;
            if ($check->isPostable($account) === false) {
                throw new InvoiceRefusedException(InvoiceRefusedException::ACCOUNT_NOT_POSTABLE, $prefix . 'Position ' . ($i + 1) . ': Konto ' . $account . ' gibt es in der Buchhaltung nicht, es ist eine Gruppe oder inaktiv.');
            }
            if ($check->hasType($account, LedgerAccountCheck::TYPE_REVENUE) === false) {
                throw new InvoiceRefusedException(InvoiceRefusedException::ACCOUNT_NOT_REVENUE, $prefix . 'Position ' . ($i + 1) . ': Konto ' . $account . ' ist kein Ertragskonto — eine Position bucht nur auf ein Konto vom Typ «Ertrag» im Kontenplan.');
            }
            if ($documentChoice && $check->isDocumentAccount($account) === false) {
                throw new InvoiceRefusedException(InvoiceRefusedException::ACCOUNT_NOT_SELECTABLE, $prefix . 'Position ' . ($i + 1) . ': Konto ' . $account . ' ist im Kontenplan nicht «auf Belegen wählbar» — ein anderes Ertragskonto wählen oder das Konto im Kontenplan freigeben.');
            }
        }
    }

    /**
     * The same rule at the booking print, on the STORED lines: a draft saved
     * before the rule (or whose account changed its type in the chart since,
     * or — an invoice only — was switched off «auf Belegen wählbar») is
     * refused with the reason, never posted to a balance-sheet account.
     *
     * @throws InvoiceRefusedException ACCOUNT_NOT_POSTABLE | ACCOUNT_NOT_REVENUE | ACCOUNT_NOT_SELECTABLE
     */
    private function assertStoredRevenueAccounts(Invoice $invoice): void
    {
        $lines = [];
        foreach ($invoice->getLines() as $line) {
            $lines[$line->getPosition() - 1] = ['account' => $line->type() === LineType::Rounding ? null : $line->getRevenueAccount()];
        }
        $this->assertRevenueAccounts($lines, $invoice->kind() === InvoiceKind::Invoice, $invoice->documentName() . ', ');
    }

    /**
     * The entity rows for THIS document from what {@see compose()} decided.
     *
     * @param array{lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>} $composed
     * @return array{0: list<InvoiceLine>, 1: list<InvoiceTax>}
     */
    private function materialize(Invoice $invoice, array $composed): array
    {
        $lines = [];
        foreach ($composed['lines'] as $i => $l) {
            $lines[$i] = new InvoiceLine(
                $invoice,
                $i + 1,
                $l['type'],
                $l['parent'] === null ? null : $lines[$l['parent']],
                $l['text'],
                $l['quantity'],
                $l['unit'],
                $l['unit_price'],
                $l['discount'],
                $l['amount'],
                $l['tax_code'],
                $l['tax_rate'],
                $l['tax_label'],
                $l['account'],
                $l['source_type'],
                $l['source_ref'],
                $l['code'],
            );
        }
        $taxes = [];
        foreach ($composed['taxes'] as $i => $t) {
            $taxes[] = new InvoiceTax($invoice, $i + 1, $t['code'], $t['category'], $t['label'], $t['rate'], $t['base'], $t['tax']);
        }

        return [array_values($lines), $taxes];
    }

    /**
     * The contact's `invoice` address, else its `main` one, else its first — as a snapshot; null
     * without any. The ONE rule which address a document goes to (Rule 2): `compose()` snapshots
     * it, and a source that prints to «the invoice address» asks here (module-order's delivery
     * note without a delivery address, P7 slim step 8). Reads, writes nothing.
     */
    public function invoiceAddressOf(Contact $contact): ?AddressSnapshot
    {
        /** @var ContactAddressRepository $links */
        $links = $this->em->getRepository(ContactAddress::class);
        $all   = $links->findByContact($contact);
        if ($all === []) {
            return null;
        }
        foreach (['invoice', 'main'] as $type) {
            foreach ($all as $link) {
                if ($link->getTypeCode() === $type) {
                    return AddressSnapshot::of($link->getAddress());
                }
            }
        }

        return AddressSnapshot::of($all[0]->getAddress());
    }

    /** An opaque origin string trimmed to its column; empty = none. */
    private static function opaque(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
