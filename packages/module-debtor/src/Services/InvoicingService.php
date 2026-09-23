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
use Z77\Module\Debtor\Entities\InvoiceTax;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Invoicing\DocumentSnapshot;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Invoicing\LineDraft;
use Z77\Module\Debtor\Invoicing\NoTaxShareException;
use Z77\Module\Debtor\Invoicing\PostingBuilder;
use Z77\Module\Debtor\Invoicing\TaxShares;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
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
 *     date → totals → the 0.05 rounding as a separate line → the number
 *     from `NumberRange` (`invoice` / `credit-note`) as the FIRST write →
 *     the document in state `invoicing` with its full snapshot. NOTHING is
 *     posted;
 *   - {@see reinvoice()}: only in `invoicing` — the same number, a new
 *     snapshot, nothing to reverse because nothing was posted;
 *   - {@see finalize()}: a batch in one unit of work — every document
 *     locked first (in id order, before any number is drawn: two batches
 *     that overlap then wait at the first shared row instead of
 *     deadlocking on the journal range), then per document: state `final`
 *     and the posting through the {@see AccountingGateway} port. After
 *     `final` a document is immutable; the correction is a credit note.
 *
 * Validation happens BEFORE the unit of work opens (the draft against the
 * party, the master data, the tax tables and the accounts), so a refused
 * draft never takes a lock or draws a number (the `LedgerService::post()`
 * order). Inside, the writes are new rows and one locked re-read — no
 * managed entity is mutated before a check (ADR-039 decision 9).
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
     * Issue a NEW document in state `invoicing`. Joins an open unit of work
     * (an order that changes its status and invoices in one) or opens its
     * own.
     *
     * @throws InvoiceRefusedException the draft is refused — nothing was written, no number drawn
     */
    public function invoice(InvoiceDraft $draft): Invoice
    {
        $composed = $this->compose($draft, null);
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(Invoice::class)->run(function () use ($draft, $composed, $now): Invoice {
            /** @var NumberRangeRepository $ranges */
            $ranges  = $this->em->getRepository(NumberRange::class);
            $number  = $ranges->next($draft->kind->numberRange());   // the FIRST write (lock order)
            $invoice = new Invoice($draft->kind, $number, $composed['contact'], $composed['creditNoteOf'], $this->actor, $now);
            [$lines, $taxes] = $this->materialize($invoice, $composed);
            $invoice->issue($composed['snapshot'], $lines, $taxes);
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * Replace the content of a document still in `invoicing` under its
     * number (plan §6.2). Takes id + the VERSION the caller saw (the
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
            $invoice->reissue($composed['snapshot'], $lines, $taxes, $this->actor, $now);
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * `invoicing` → `final` for a batch, ONE unit of work: all or nothing.
     * Every document is locked first (id order), any that is missing, at
     * another VERSION than the caller saw, or already `final` refuses the
     * whole batch; then each is posted through the accounting port and set
     * `final` with what the port answered. A refusal of the bookkeeping
     * ({@see \Z77\Module\Debtor\Accounting\AccountingRefusedException}) or a
     * unique-index failure at commit ends the unit of work — no state
     * changes, no journal number consumed, nothing half-written; the caller
     * does not retry inside (plan §6.6 contract).
     *
     * The caller names the version it SAW per document (review 2026-09-23,
     * the `reinvoice()` model): a document re-issued between the caller's
     * look and its finalize is refused with {@see InvoiceConflictException}
     * instead of being finalized with content the caller never checked.
     *
     * Finalizing twice is REFUSED (`not-invoicing`), not idempotent: a batch
     * that names a final document is a stale list, and the caller should
     * see that rather than get a silent «done».
     *
     * @param list<array{id: int, version: int}> $documents
     * @return list<Invoice> in the order given
     * @throws InvoiceRefusedException  NOT_FOUND | NOT_INVOICING
     * @throws InvoiceConflictException a document is at another version than the caller saw
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException
     */
    public function finalize(array $documents): array
    {
        $versions = [];
        foreach ($documents as $i => $document) {
            if (!is_array($document) || !isset($document['id'], $document['version'])) {
                throw new \InvalidArgumentException("finalize() takes a list of ['id' => int, 'version' => int] pairs — item {$i} is not one");
            }
            $versions[(int) $document['id']] = (int) $document['version'];
        }
        $ids = array_keys($versions);
        if ($ids === []) {
            return [];
        }
        $gateway    = $this->gateway();
        $receivable = (new DebtorAccounts($this->em))->postableNumber('receivable');
        $now        = new \DateTimeImmutable();

        return $this->em->getTransaction(Invoice::class)->run(function () use ($ids, $versions, $gateway, $receivable, $now): array {
            // 1. Every row of the batch, locked in id order, BEFORE any number is drawn.
            $locked = [];
            $sorted = $ids;
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
                $locked[$id] = $invoice;
            }

            // 2. Post and finalize, in the order given.
            $done = [];
            foreach ($ids as $id) {
                $invoice = $locked[$id];
                $request = PostingBuilder::build($invoice, $receivable);
                $ref     = $request === null ? null : $gateway->post($request);
                $invoice->finalize($ref, $this->actor, $now);
                $this->em->persist($invoice);
                $done[] = $invoice;
            }

            return $done;
        });
    }

    /**
     * What is still owed on a FINAL invoice: gross − Σ gross of its final
     * credit notes (P4 subtracts payments, discount and loss). Zero while
     * `invoicing` (plan §6.2: no open item yet) and for a credit note (it
     * is not a receivable, it reduces one). Negative = more was credited
     * than invoiced (a refund is owed).
     */
    public function openAmount(Invoice $invoice): Money
    {
        if (!$invoice->isFinal() || $invoice->isCreditNote()) {
            return Money::zero($invoice->getCurrency());
        }

        return $invoice->getGrossTotal()->subtract(
            Money::fromDecimal($this->invoices()->sumOfFinalCreditNotes($invoice), $invoice->getCurrency())
        );
    }

    private function gateway(): AccountingGateway
    {
        return $this->gateway ??= AccountingGateways::fromConfig($this->em, $this->actor);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }

    /** @throws InvoiceRefusedException NOT_INVOICING */
    private function assertInvoicing(Invoice $invoice): void
    {
        if ($invoice->isFinal()) {
            throw new InvoiceRefusedException(
                InvoiceRefusedException::NOT_INVOICING,
                $invoice->documentName() . ' ist abgeschlossen und bleibt unverändert — eine Korrektur ist eine Gutschrift.'
            );
        }
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
     * @return array{contact: Contact, creditNoteOf: ?Invoice, snapshot: DocumentSnapshot, lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>}
     * @throws InvoiceRefusedException
     */
    private function compose(InvoiceDraft $draft, ?Invoice $existing): array
    {
        $isNew    = $existing === null;
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
        if ($isNew && !$contact->isActive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::CONTACT_INACTIVE, 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein neues Dokument erstellt.');
        }
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);
        $profile  = $profiles->findByContact($contact);
        if ($profile === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NO_DEBTOR_PROFILE, 'Kontakt «' . $contact->displayName() . '» ist kein Debitor — zuerst ein Debitorenprofil anlegen.');
        }
        if ($isNew && !$profile->isActive()) {
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

        // A credit note names the FINAL invoice it corrects.
        $creditNoteOf = null;
        if ($draft->kind === InvoiceKind::CreditNote) {
            $creditNoteOf = $draft->creditNoteOfId === null ? null : $this->invoices()->find($draft->creditNoteOfId);
            if ($creditNoteOf === null || $creditNoteOf->isCreditNote()) {
                throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, 'Eine Gutschrift bezieht sich auf eine Rechnung — «' . ($draft->creditNoteOfId ?? '?') . '» ist keine.');
            }
            if (!$creditNoteOf->isFinal()) {
                // While the invoice is `invoicing` nothing is posted: the correction is reinvoice(), and a
                // credit note would mirror a posting that does not exist.
                throw new InvoiceRefusedException(InvoiceRefusedException::CREDIT_NOTE_TARGET, $creditNoteOf->documentName() . ' ist noch in Fakturierung — sie wird neu fakturiert, nicht gutgeschrieben.');
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

        // The lines: flattened (children after their parent), amounts computed.
        $lines = $this->composeLines($draft->lines, $currency);

        // VAT once, by the service date, on the priced lines.
        $vat        = $this->calculateVat($lines, $currency, $serviceFrom, $draft->priceMode, $isNew ? null : $existing);
        $rateByCode = [];
        foreach ($vat->lines as $resolvedLine) {
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
        if ($draft->priceMode === PriceMode::Gross) {
            $this->assertTaxSharesFit($lines, $vat->summary->entries());
        }

        // The accounts every priced line names — checked softly against the bookkeeping.
        $this->assertRevenueAccounts($lines);

        // Totals and the rounding line.
        $net      = $vat->net();
        $tax      = $vat->tax();
        $exact    = $net->add($tax);
        $step     = self::ROUNDING_STEPS[$currency] ?? 1;
        $gross    = $exact->roundTo($step);
        $rounding = $gross->subtract($exact);
        if ($draft->kind === InvoiceKind::Invoice && $gross->isNegative()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NEGATIVE_TOTAL, 'Der Rechnungsbetrag ist negativ — das ist eine Gutschrift, keine Rechnung.');
        }
        if ($draft->kind === InvoiceKind::CreditNote && !$gross->isPositive()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NEGATIVE_TOTAL, 'Der Gutschriftsbetrag muss positiv sein — die Positionen werden wie gedruckt erfasst, das Vorzeichen gibt die Gutschrift.');
        }
        if (!$rounding->isZero()) {
            $lines[] = [
                'type' => LineType::Rounding, 'parent' => null, 'text' => 'Rundung', 'quantity' => null, 'unit' => null,
                'unit_price' => null, 'discount' => 0, 'amount' => $rounding, 'tax_code' => null, 'tax_rate' => null, 'tax_label' => null,
                'account' => (new DebtorAccounts($this->em))->postableNumber('rounding'), 'source_type' => null, 'source_ref' => null,
            ];
        }

        // The tax summary, one row per code.
        $taxes = [];
        foreach ($vat->summary->entries() as $entry) {
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
        );

        return ['contact' => $contact, 'creditNoteOf' => $creditNoteOf, 'snapshot' => $snapshot, 'lines' => $lines, 'taxes' => $taxes];
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
    private function composeLines(array $drafts, string $currency): array
    {
        $lines = [];
        $walk  = function (LineDraft $draft, ?int $parent, int $position) use (&$lines, &$walk, $currency): void {
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

            if ($type->isPriced()) {
                if ($draft->unitPrice === null || $draft->unitPrice->currency !== $currency) {
                    throw new InvoiceRefusedException(InvoiceRefusedException::LINE, "Position {$position}: ein Preis in {$currency} fehlt.");
                }
                $taxCode = TaxCode::normalizeCode((string) $draft->taxCode);
                if ($taxCode === '') {
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
     * Gross mode: the per-line tax share `finalize()` will post must leave
     * every line a positive net ({@see TaxShares}). Run at ISSUE so the
     * degenerate document (a code made of 0.01 lines) is refused before a
     * number is drawn — at `finalize()` it would be too late.
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
     * ({@see LedgerAccountCheck}: `null` = cannot tell, not a failure).
     *
     * @param list<array<string, mixed>> $lines
     * @throws InvoiceRefusedException ACCOUNT_NOT_POSTABLE
     */
    private function assertRevenueAccounts(array $lines): void
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
                throw new InvoiceRefusedException(InvoiceRefusedException::ACCOUNT_NOT_POSTABLE, 'Position ' . ($i + 1) . ': Konto ' . $account . ' gibt es in der Buchhaltung nicht, es ist eine Gruppe oder inaktiv.');
            }
        }
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
            );
        }
        $taxes = [];
        foreach ($composed['taxes'] as $i => $t) {
            $taxes[] = new InvoiceTax($invoice, $i + 1, $t['code'], $t['category'], $t['label'], $t['rate'], $t['base'], $t['tax']);
        }

        return [array_values($lines), $taxes];
    }

    /** The contact's `invoice` address, else its `main` one, else its first — as a snapshot; null without any. */
    private function invoiceAddressOf(Contact $contact): ?AddressSnapshot
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
