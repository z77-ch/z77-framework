<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorJournalPosting;
use Z77\Module\Creditor\Close\ImportVatPeriods;
use Z77\Module\Creditor\Posting\CreditorPostingVariant;
use Z77\Module\Creditor\Posting\DirectPosting;
use Z77\Module\Creditor\Posting\PaymentEntries;
use Z77\Module\Creditor\Posting\ReverseChargeCodes;
use Z77\Module\Creditor\Repositories\CreditorInvoiceLineRepository;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Creditor\Repositories\CreditorJournalPostingRepository;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «JOURNAL VERBUCHEN …» of the Kreditorenjournal (owner answer 3, creditor plan §6, ADR-059) — the
 * PATTERN of receipt's `ReceiptJournalPostingService` (and debtor's Faktura-Journal, ADR-054), not
 * its classes (the extraction is a pending candidate, financial.md):
 *
 *   - {@see preview()} — the RUN up to a CUT-OFF on the PAYMENT date: every invoice still in the
 *     journal whose «bezahlt am» lies on or before it, as the `{id, version}` list `post()` takes;
 *     what blocks the run (a suspected duplicate, incomplete, not buildable, no fiscal year, a closed
 *     period, a tax line before «Abrechnung ab», input tax paid in a settled VAT period of an
 *     earlier calendar year) with its German reason; the entries summed per account and VAT code;
 *     the grey lines (`hints`): input tax caught up, import VAT whose invoice date lies in another
 *     VAT period than the payment (owner O-5 — information, nothing blocks), the reverse charge at
 *     the invoice date, after «Abrechnung bis» (D-4).
 *     UNPAID invoices wait in the journal — except for their REVERSE-CHARGE tax entry: the run posts
 *     the pair of every open invoice not paid in it whose invoice date lies on or before the cut-off
 *     (ADR-059 addendum 2026-11-25, point 2); its refusal blocks the run;
 *   - {@see post()} — the run the confirmation showed, in ONE unit of work, all or nothing: per
 *     invoice the entries of its own strategy ({@see CreditorPostingVariant::strategyFor()},
 *     today {@see DirectPosting}: expense + input VAT to the payment method's account, dated
 *     «bezahlt am»; input tax caught up as a second entry on the first day of the running period),
 *     the protocol row, the invoice `posted` with both references — immutable from then on.
 *
 * Lock order: invoice rows EXCLUSIVE by id (ascending) → reads → `journal-entry.*` (financial's
 * `post()` per invoice). The ledger's refusal rolls the whole run back and comes out as
 * {@see CreditorRefusedException::LEDGER} naming the invoice. After the commit the PDFs get their
 * 10-year retention ({@see CreditorRetention}).
 */
final class CreditorJournalPostingService
{
    /**
     * The way out behind financial's sentence for a tax line paid before «Abrechnung ab» (ADR-041
     * addendum 2026-11-25, point 2: nothing before that day is caught up — the voucher is posted
     * «ohne MWST», founding costs by hand).
     */
    public const BEFORE_START_WAY_OUT = '; die Rechnung «ohne MWST» verbuchen';

    private ?string $actor;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) at the posting */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /** The proposed cut-off: TODAY (a payment date is never in the future — the run is every paid invoice). */
    public static function defaultCutOff(?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        return ($today ?? new \DateTimeImmutable())->setTime(0, 0);
    }

    /**
     * What {@see post()} would book for the run up to $cutOff — the same strategy as the posting.
     *
     * Besides the PAID invoices of the run, the run takes the REVERSE-CHARGE tax entry of every open
     * invoice NOT paid in it whose invoice date lies on or before the cut-off (ADR-059 addendum
     * 2026-11-25, point 2 — `taxSeen`): the pair alone at the invoice date; the invoice stays in the
     * journal, unpaid and editable. A refusal of such an entry (the invoice date in a posted period,
     * O-8; before «Abrechnung ab»; incomplete) blocks the run like a paid invoice does.
     *
     * @return array{cutOff: \DateTimeImmutable, seen: list<array{id: int, version: int}>, taxSeen: list<array{id: int, version: int}>, blocked: list<array{id: int, reason: string}>, postable: bool, entries: int, taxEntries: int, gross: Money, rows: list<array{account: string, debit: Money, credit: Money}>, vat: list<array{code: string, rate: int, base: Money, tax: Money}>, withoutVat: Money, blockedGross: Money, later: int, unpaid: int, hints: list<array{id: int, text: string}>, catchUps: int, reverseCharges: int}
     *         `seen` = every paid invoice of the run; `taxSeen` = the invoices whose reverse-charge tax entry
     *         alone the run posts; `postable` = the run is not empty and nothing blocks it;
     *         `later` = invoices paid after the cut-off; `unpaid` = invoices without «bezahlt am» (both stay);
     *         `hints` = the grey lines per invoice; `catchUps` = how many invoices get a second entry for
     *         input tax caught up; `reverseCharges` = how many reverse-charge pairs are posted apart from the payment
     */
    public function preview(?\DateTimeImmutable $cutOff = null): array
    {
        $cutOff ??= self::defaultCutOff();
        $currency = ReceiptFields::currency();
        $zero     = Money::zero($currency);
        $seen     = [];
        $taxSeen  = [];
        $blocked  = [];
        $rows     = [];
        $vat      = [];
        $gross    = $zero;
        $without  = $zero;
        $blockedGross = $zero;
        $hints    = [];
        $catchUps = 0;
        $pairs    = 0;
        $codes    = new ReverseChargeCodes($this->em);
        $invoices = $this->invoices()->paidUntil($cutOff);
        $taxOnly  = $this->invoices()->reverseChargeDueUntil($cutOff, $codes->codes(), $this->vatUntil());
        $lines    = $this->lines()->forInvoices(array_map(static fn(CreditorInvoice $i) => (int) $i->getId(), [...$invoices, ...$taxOnly]));
        $import   = new ImportVatPeriods($this->em);
        $sum      = static function (array $requests) use (&$rows, &$vat, $zero): void {
            foreach ($requests as $request) {
                foreach ($request->lines as $line) {
                    $rows[$line->account] ??= ['account' => $line->account, 'debit' => $zero, 'credit' => $zero];
                    $rows[$line->account]['debit']  = $rows[$line->account]['debit']->add($line->debit);
                    $rows[$line->account]['credit'] = $rows[$line->account]['credit']->add($line->credit);
                    if ($line->taxCode === null) {
                        continue;
                    }
                    $key = $line->taxCode . '|' . $line->taxRate;
                    $vat[$key] ??= ['code' => (string) $line->taxCode, 'rate' => (int) $line->taxRate, 'base' => $zero, 'tax' => $zero];
                    $vat[$key]['base'] = $vat[$key]['base']->add($line->taxBase);
                    $vat[$key]['tax']  = $vat[$key]['tax']->add($line->taxAmount);
                }
            }
        };
        foreach ($invoices as $invoice) {
            $id     = (int) $invoice->getId();
            $own    = $lines[$id] ?? [];
            $seen[] = ['id' => $id, 'version' => $invoice->getVersion()];
            try {
                $entries = $this->request($invoice, $own, $codes);
                foreach ($entries->requests() as $request) {
                    $this->assertPeriodTakes($invoice, $request->date, $request->hasTaxLine());
                }
            } catch (CreditorRefusedException $e) {
                $blocked[]    = ['id' => $id, 'reason' => $e->getMessage()];
                $blockedGross = $blockedGross->add($invoice->getAmount() ?? $zero);
                continue;
            }
            $gross = $gross->add($invoice->getAmount() ?? $zero);
            $sum($entries->requests());
            // The grey lines of the window (boards S5-2 / S5-3 / S6-3, owners O-5 and D-4): input tax
            // caught up, the reverse charge at the invoice date, after «Abrechnung bis», and import
            // VAT whose invoice date lies in another VAT period than the payment.
            if ($entries->catchesUp()) {
                $hints[] = ['id' => $id, 'text' => self::catchUpLine($invoice, $entries)];
                $catchUps++;
            }
            if ($entries->separatesReverseCharge()) {
                $hints[] = ['id' => $id, 'text' => self::reverseChargeLine($invoice, $entries->reverseCharge, $entries->tax->date, $entries->expense->date)];
                $pairs++;
            }
            if ($entries->reverseChargeAfterEnd) {
                $hints[] = ['id' => $id, 'text' => self::afterEndLine($invoice)];
            }
            if (array_filter($own, static fn(CreditorInvoiceLine $l): bool => $l->isTaxOnly()) !== []) {
                $importHint = $import->sentence($invoice);
                if ($importHint !== null) {
                    $hints[] = ['id' => $id, 'text' => $importHint];
                }
            }
            foreach ($own as $invoiceLine) {
                if ($invoiceLine->getTaxCode() === null && $invoiceLine->getAmount() !== null) {
                    $without = $without->add($invoiceLine->getAmount());
                }
            }
        }
        // The reverse-charge tax entries of invoices not paid in this run (ADR-059 addendum 2026-11-25, point 2).
        foreach ($taxOnly as $invoice) {
            $id        = (int) $invoice->getId();
            $taxSeen[] = ['id' => $id, 'version' => $invoice->getVersion()];
            try {
                $request = CreditorPostingVariant::strategyFor($invoice, $this->em)->reverseChargeEntry($invoice, $lines[$id] ?? []);
                if ($request !== null) {
                    $this->assertPeriodTakes($invoice, $request->date, true);
                }
            } catch (CreditorRefusedException $e) {
                $blocked[] = ['id' => $id, 'reason' => $e->getMessage()];
                continue;
            }
            if ($request === null) {
                continue;   // a tax of 0.00 — nothing to post
            }
            $sum([$request]);
            $hints[] = ['id' => $id, 'text' => self::reverseChargeLine($invoice, self::taxOf($request), $request->date, null)];
            $pairs++;
        }
        ksort($rows, SORT_STRING);
        ksort($vat, SORT_STRING);
        $totals = $this->invoices()->paymentTotals();

        return [
            'cutOff'       => $cutOff,
            'seen'         => $seen,
            'taxSeen'      => $taxSeen,
            'blocked'      => $blocked,
            'postable'     => ($seen !== [] || $taxSeen !== []) && $blocked === [],
            'entries'      => count($seen) - count(array_filter($blocked, static fn(array $b): bool => in_array($b['id'], array_column($seen, 'id'), true))),
            'taxEntries'   => count($taxSeen) - count(array_filter($blocked, static fn(array $b): bool => in_array($b['id'], array_column($taxSeen, 'id'), true))),
            'gross'        => $gross,
            'rows'         => array_values($rows),
            'vat'          => array_values($vat),
            'withoutVat'   => $without,
            'blockedGross' => $blockedGross,
            'later'        => max(0, $totals['paid']['count'] - count($seen)),
            'unpaid'       => $totals['unpaid']['count'],
            'hints'        => $hints,
            'catchUps'     => $catchUps,
            'reverseCharges' => $pairs,
        ];
    }

    /**
     * «Rechnung 2608-117 · Druckerei Beispiel AG: 3. Quartal 2026 ist abgerechnet — Aufwand am
     * 18.08.2026 ohne MWST, Vorsteuer 40.50 am 01.10.2026 nachgeholt.» (board S5-3, part 1)
     */
    public static function catchUpLine(CreditorInvoice $invoice, PaymentEntries $entries): string
    {
        $creditor = $invoice->getCreditor()?->getContact()?->displayName();

        return $invoice->label() . ($creditor !== null && $creditor !== '' ? ' · ' . $creditor : '') . ': ' . $entries->settledPeriod?->label() . ' ist abgerechnet — Aufwand am '
            . $entries->expense->date->format('d.m.Y') . ' ohne MWST, Vorsteuer ' . AmountFormat::of($entries->caughtUp) . ' am ' . $entries->tax?->date->format('d.m.Y') . ' nachgeholt.';
    }

    /**
     * The reverse charge at the invoice date (board S6-3, part 1): «Rechnung INV-55120 · Pixelsoft
     * Inc.: Bezugsteuer 7.17 am Rechnungsdatum 30.10.2026, Aufwand 90.12 am Zahldatum 12.11.2026.» —
     * an invoice not paid in the run ($paidOn null): «…: Bezugsteuer 7.17 am Rechnungsdatum
     * 30.10.2026, Aufwand bei der Zahlung.»
     */
    public static function reverseChargeLine(CreditorInvoice $invoice, ?Money $tax, \DateTimeImmutable $taxDate, ?\DateTimeImmutable $paidOn): string
    {
        $creditor = $invoice->getCreditor()?->getContact()?->displayName();

        return $invoice->label() . ($creditor !== null && $creditor !== '' ? ' · ' . $creditor : '') . ': Bezugsteuer ' . AmountFormat::of($tax) . ' am Rechnungsdatum ' . $taxDate->format('d.m.Y')
            . ($paidOn === null ? ', Aufwand bei der Zahlung.' : ', Aufwand ' . AmountFormat::of($invoice->getAmount()) . ' am Zahldatum ' . $paidOn->format('d.m.Y') . '.');
    }

    /**
     * After «Abrechnung bis» (owner D-4 — no entry, no block): «Rechnung INV-55120 · Pixelsoft Inc.:
     * Rechnungsdatum nach «Abrechnung bis» — z77 bucht keine Bezugsteuer; allenfalls ausserhalb von
     * z77 melden.»
     */
    public static function afterEndLine(CreditorInvoice $invoice): string
    {
        $creditor = $invoice->getCreditor()?->getContact()?->displayName();

        return $invoice->label() . ($creditor !== null && $creditor !== '' ? ' · ' . $creditor : '')
            . ': Rechnungsdatum nach «Abrechnung bis» — z77 bucht keine Bezugsteuer; allenfalls ausserhalb von z77 melden.';
    }

    /** The reverse-charge tax of a tax entry — the tax of its coded line(s). */
    private static function taxOf(PostingRequest $request): ?Money
    {
        $tax = null;
        foreach ($request->lines as $line) {
            if ($line->taxCode !== null && $line->taxAmount !== null) {
                $tax = $tax === null ? $line->taxAmount : $tax->add($line->taxAmount);
            }
        }

        return $tax;
    }

    /** «Abrechnung bis» of a liable mandator — after it the run posts no reverse-charge tax entry (D-4); null = none. */
    private function vatUntil(): ?\DateTimeImmutable
    {
        $s = (new LedgerService($this->em))->vatSettings();

        return $s !== null && $s->liable && $s->from !== null ? $s->until : null;
    }

    /** @return list<CreditorJournalPosting> the protocols of «Journal verbuchen» — the tab «Verbuchte Journale» */
    public function protocols(int $offset, int $limit, bool $newestFirst = true): array
    {
        return $this->protocolRows()->page($offset, $limit, $newestFirst);
    }

    public function countProtocols(): int
    {
        return $this->protocolRows()->countAll();
    }

    /**
     * Post the run up to $cutOff — the confirmation's `seen` list — into the ledger, ONE unit of
     * work, all or nothing:
     *
     *   1. every named row locked EXCLUSIVE in id order; each must still be in the journal at the
     *      version the confirmation showed, and the run must be exactly the paid invoices up to
     *      $cutOff now — one paid, re-dated, changed or removed since is `changed`, nothing posted;
     *   2. an invoice of the run that is BLOCKED (suspected duplicate) or incomplete refuses the WHOLE
     *      run (`incomplete`);
     *   3. the protocol row, then per invoice in payment-date / id order: its strategy's payment
     *      posting → `LedgerService::post()` (date = «bezahlt am», source `creditor`, ref = id, key
     *      `creditor:{id}`; input tax caught up: the tax entry on its own day, key `creditor:{id}:vat`)
     *      → `CreditorInvoice::post()` with the entries, the protocol, who and when;
     *   4. the reverse-charge tax entries of the invoices NOT paid in the run ($taxSeen, ADR-059
     *      addendum 2026-11-25 point 2): per invoice, in id order, the pair alone at its invoice date
     *      (key `creditor:{id}:vat`) → the invoice notes it and stays in the journal, unpaid;
     *   5. after the commit, the PDFs of the posted invoices get their 10-year retention.
     *
     * @param list<array{id: int, version: int}> $seen    the paid invoices of the run (`preview()['seen']`)
     * @param list<array{id: int, version: int}> $taxSeen the invoices whose reverse-charge tax entry alone is posted (`preview()['taxSeen']`)
     * @return CreditorJournalPosting|null the protocol of the paid invoices; null when the run posted only tax entries
     * @throws CreditorRefusedException EMPTY_RUN | CHANGED | INCOMPLETE | NOT_POSTABLE | LEDGER
     * @throws \LogicException nobody is logged in and no actor was named
     */
    public function post(\DateTimeImmutable $cutOff, array $seen, array $taxSeen = []): ?CreditorJournalPosting
    {
        $versions    = self::pairs($seen);
        $taxVersions = self::pairs($taxSeen);
        if ($versions === [] && $taxVersions === []) {
            throw new CreditorRefusedException(CreditorRefusedException::EMPTY_RUN, 'Bis zum Stichtag ' . $cutOff->format('d.m.Y') . ' ist keine bezahlte Rechnung im Kreditorenjournal — nichts verbucht.');
        }
        $actor = $this->actor ??= Actor::current();
        $now   = new \DateTimeImmutable();

        $protocol = $this->em->getTransaction(JournalEntry::class)->run(function () use ($cutOff, $versions, $taxVersions, $actor, $now): ?CreditorJournalPosting {
            // 1. The rows, EXCLUSIVE, in id order — judged; the run compared; then read fresh.
            $ids = array_keys($versions);
            sort($ids);
            $taxIds = array_keys($taxVersions);
            sort($taxIds);
            $all = array_values(array_unique([...$ids, ...$taxIds]));
            sort($all);
            $locked = $this->invoices()->lockIds($all);
            foreach ($all as $id) {
                $row = $locked[$id] ?? null;
                if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true) || $row['version'] !== ($versions[$id] ?? $taxVersions[$id])) {
                    throw new CreditorRefusedException(CreditorRefusedException::CHANGED, 'Rechnung ' . $id . ' wurde seit der Bestätigung geändert, verbucht oder gelöscht — nichts wurde verbucht. Das Kreditorenjournal neu laden und nochmals bestätigen.');
                }
            }
            $codes = new ReverseChargeCodes($this->em);
            if ($this->invoices()->paidIdsUntil($cutOff) !== $ids || $this->invoices()->reverseChargeDueIdsUntil($cutOff, $codes->codes(), $this->vatUntil()) !== $taxIds) {
                throw new CreditorRefusedException(CreditorRefusedException::CHANGED, 'Seit der Bestätigung hat sich das Kreditorenjournal bis zum Stichtag ' . $cutOff->format('d.m.Y') . ' geändert (eine Rechnung bezahlt oder umdatiert) — nichts wurde verbucht. Neu laden und nochmals bestätigen.');
            }
            $invoices = array_map(fn(int $id): CreditorInvoice => $this->invoices()->refreshed($id) ?? throw new \LogicException("Creditor invoice {$id} vanished under its lock"), $ids);
            usort($invoices, static fn(CreditorInvoice $a, CreditorInvoice $b) => [$a->getPaidOn(), $a->getId()] <=> [$b->getPaidOn(), $b->getId()]);
            $lines = $this->lines()->forInvoices($all);

            // 2. A blocked or incomplete invoice blocks the whole run.
            $stops = [];
            foreach ($invoices as $invoice) {
                $missing = $invoice->missing($lines[(int) $invoice->getId()] ?? [], $codes->codes());
                if ($invoice->isBlocked()) {
                    $stops[] = DirectPosting::name($invoice) . ' (gesperrt — ' . $invoice->getDuplicateReason() . ')';
                } elseif ($missing !== []) {
                    $stops[] = DirectPosting::name($invoice) . ' (es fehlt: ' . implode(', ', $missing) . ')';
                }
            }
            if ($stops !== []) {
                throw new CreditorRefusedException(CreditorRefusedException::INCOMPLETE, 'Verbuchen gesperrt — ' . implode('; ', $stops) . '. Vervollständigen, entscheiden oder das Zahlungsdatum ändern — nichts wurde verbucht.');
            }

            // 3. The protocol (only when an invoice is paid in the run), then the postings.
            $protocol = null;
            if ($invoices !== []) {
                $gross = Money::zero(ReceiptFields::currency());
                foreach ($invoices as $invoice) {
                    $gross = $gross->add($invoice->getAmount());
                }
                $protocol = new CreditorJournalPosting($actor, $now, count($invoices), $gross);
                $this->em->persist($protocol);
            }

            $ledger = new LedgerService($this->em, $actor);
            foreach ($invoices as $invoice) {
                $entries = $this->request($invoice, $lines[(int) $invoice->getId()] ?? [], $codes);
                $refs    = [];
                foreach ($entries->requests() as $request) {
                    $refs[] = $this->postOne($ledger, $invoice, $request);
                }
                $invoice->post($refs[0], $protocol, $actor, $now, $refs[1] ?? null);
                $this->em->persist($invoice);
            }

            // 4. The reverse-charge tax entries of the invoices not paid in this run — the invoice stays in the journal.
            foreach ($taxIds as $id) {
                $invoice = $this->invoices()->refreshed($id) ?? throw new \LogicException("Creditor invoice {$id} vanished under its lock");
                $request = CreditorPostingVariant::strategyFor($invoice, $this->em)->reverseChargeEntry($invoice, $lines[$id] ?? []);
                if ($request === null) {
                    continue;   // a tax of 0.00 — nothing to post
                }
                $invoice->noteTaxEntry($this->postOne($ledger, $invoice, $request));
                $this->em->persist($invoice);
            }

            return $protocol;
        });

        // 5. AFTER the commit: the PDFs kept 10 years (the DMS is a file store, no part of this unit
        //    of work). The posting stands whatever the DMS does; the next run or «Verbuchte Journale» heals it.
        if ($protocol !== null) {
            try {
                (new CreditorRetention($this->em))->apply();
            } catch (\Throwable $e) {
                error_log('creditor retention after posting: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        return $protocol;
    }

    /**
     * $request posted for $invoice — the ledger's refusal in German, naming the invoice.
     *
     * @return string the entry's reference «2026/17»
     * @throws CreditorRefusedException LEDGER
     */
    private function postOne(LedgerService $ledger, CreditorInvoice $invoice, PostingRequest $request): string
    {
        try {
            $ref = $ledger->post($request);
        } catch (PostingRefusedException $e) {
            throw new CreditorRefusedException(CreditorRefusedException::LEDGER, DirectPosting::name($invoice) . ': ' . $this->ledgerMessage($e, $request->date) . ' — nichts wurde verbucht.', $e);
        }

        return $ref->fiscalYear . '/' . $ref->number;
    }

    /**
     * @param list<array{id: int, version: int}> $pairs
     * @return array<int, int> id → version
     */
    private static function pairs(array $pairs): array
    {
        $versions = [];
        foreach ($pairs as $i => $pair) {
            if (!is_array($pair) || !isset($pair['id'], $pair['version'])) {
                throw new \InvalidArgumentException("post() takes a list of ['id' => int, 'version' => int] pairs — item {$i} is not one");
            }
            $versions[(int) $pair['id']] = (int) $pair['version'];
        }

        return $versions;
    }

    /**
     * The payment posting of $invoice through ITS strategy — the expense entry and, for input tax
     * caught up or a reverse-charge pair at another invoice date, the tax entry — refused when
     * blocked or incomplete.
     *
     * @param list<CreditorInvoiceLine> $lines
     * @throws CreditorRefusedException INCOMPLETE | NOT_POSTABLE
     */
    private function request(CreditorInvoice $invoice, array $lines, ReverseChargeCodes $codes): PaymentEntries
    {
        if ($invoice->isBlocked()) {
            throw new CreditorRefusedException(CreditorRefusedException::INCOMPLETE, DirectPosting::name($invoice) . ': gesperrt — ' . $invoice->getDuplicateReason() . '.');
        }
        $missing = $invoice->missing($lines, $codes->codes());
        if ($missing !== []) {
            throw new CreditorRefusedException(CreditorRefusedException::INCOMPLETE, DirectPosting::name($invoice) . ': es fehlt: ' . implode(', ', $missing) . '.');
        }
        $request = CreditorPostingVariant::strategyFor($invoice, $this->em)->paymentPosting($invoice, $lines);
        if ($request === null) {
            throw new \LogicException("The posting variant '{$invoice->getPostingVariant()}' books no payment entry — the run cannot post creditor invoice {$invoice->getId()}");
        }

        return $request;
    }

    /**
     * The ledger's period rules asked BEFORE the posting, for the preview only, per entry on its own
     * date ($date: «bezahlt am», or the tax entry's first day of the running period) — financial's
     * own lock-free answer (`LedgerService::periodRefusal()`: no fiscal year, a closed period, a tax
     * line before «Abrechnung ab» — financial's sentence plus the way out «ohne MWST» — or in a
     * settled VAT period — financial's sentence); `post()` leaves the judgement to
     * `LedgerService::post()` under its locks. No period state is read here.
     *
     * @throws CreditorRefusedException LEDGER
     */
    private function assertPeriodTakes(CreditorInvoice $invoice, \DateTimeImmutable $date, bool $withTaxLine): void
    {
        $ledger  = new LedgerService($this->em);
        $refusal = $ledger->periodRefusal($date, $withTaxLine);
        $reason  = match ($refusal) {
            null                                      => null,
            PostingRefusedException::NO_FISCAL_YEAR   => 'kein Geschäftsjahr für den ' . $date->format('d.m.Y') . ' — zuerst das Geschäftsjahr eröffnen',
            PostingRefusedException::PERIOD_CLOSED    => 'die Periode des Zahlungsdatums ist abgeschlossen — das Zahlungsdatum prüfen',
            PostingRefusedException::BEFORE_VAT_START => lcfirst($ledger->periodRefusalText($refusal, $date)) . self::BEFORE_START_WAY_OUT,
            default                                   => lcfirst($ledger->periodRefusalText($refusal, $date)),
        };
        if ($reason !== null) {
            throw new CreditorRefusedException(CreditorRefusedException::LEDGER, DirectPosting::name($invoice) . ': ' . $reason . '.');
        }
    }

    /** The ledger's refusal in German, naming the next step — its own message is for the developer. */
    private function ledgerMessage(PostingRefusedException $e, \DateTimeImmutable $date): string
    {
        return match ($e->reason) {
            PostingRefusedException::NO_FISCAL_YEAR, PostingRefusedException::NO_PERIOD => 'kein Geschäftsjahr für das Zahlungsdatum — zuerst das Geschäftsjahr eröffnen',
            PostingRefusedException::PERIOD_CLOSED      => 'die Periode des Zahlungsdatums ist abgeschlossen — das Zahlungsdatum prüfen',
            // The settled VAT period / a date before «Abrechnung ab»: financial's one sentence (the period comes with the refusal, the day from the mandator).
            PostingRefusedException::VAT_PERIOD_SETTLED => lcfirst((new LedgerService($this->em))->periodRefusalText($e->reason, $date, $e->vatPeriod)),
            PostingRefusedException::BEFORE_VAT_START   => lcfirst((new LedgerService($this->em))->periodRefusalText($e->reason, $date, $e->vatPeriod)) . self::BEFORE_START_WAY_OUT,
            PostingRefusedException::ACCOUNT_UNKNOWN, PostingRefusedException::ACCOUNT_NOT_POSTABLE, PostingRefusedException::ACCOUNT_INACTIVE
                                                        => 'die Buchhaltung nimmt das Konto ' . ($e->account ?? '') . ' nicht (unbekannt, Gruppe oder inaktiv)',
            PostingRefusedException::IDEMPOTENCY_CONFLICT => 'unter dieser Rechnung steht im Journal schon eine andere Buchung',
            // The lock of the VAT accounts: financial's one sentence.
            PostingRefusedException::VAT_LINE_NOT_COMPUTED,
            PostingRefusedException::TAX_CODE_ON_VAT_ACCOUNT => lcfirst(LedgerService::vatLineRefusalText($e)),
            default                                     => $e->getMessage(),
        };
    }

    private function invoices(): CreditorInvoiceRepository
    {
        return $this->em->getRepository(CreditorInvoice::class);
    }

    private function lines(): CreditorInvoiceLineRepository
    {
        return $this->em->getRepository(CreditorInvoiceLine::class);
    }

    private function protocolRows(): CreditorJournalPostingRepository
    {
        return $this->em->getRepository(CreditorJournalPosting::class);
    }
}
