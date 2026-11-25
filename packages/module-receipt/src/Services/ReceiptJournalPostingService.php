<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptJournalPosting;
use Z77\Module\Receipt\Entities\ReceiptState;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «JOURNAL VERBUCHEN» of the receipt journal (owner decisions E1 and E11, 2026-11-12; plan T4) —
 * the PATTERN of debtor's Faktura-Journal (ADR-054 decisions 5–7, `JournalPostingService`), not
 * its classes:
 *
 *   - {@see preview()} — the RUN up to a CUT-OFF date (E11; default {@see defaultCutOff()}, today
 *     since E16): every captured receipt dated on or before it, as the
 *     `{id, version}` list `post()` takes; what blocks the run (incomplete, not buildable, no fiscal
 *     year, a closed period, input tax of another calendar year in a settled VAT period, an output
 *     code in a settled VAT period) with its German reason; the entries summed per account; one
 *     muted line per receipt whose input tax is caught up. The same
 *     {@see ReceiptPostingBuilder::posting()} as the posting;
 *   - {@see post()} — the run the confirmation showed, in ONE unit of work, all or nothing.
 *
 * CATCHING UP INPUT TAX (VAT return stage S5, ADR-041 addendum 2026-11-25, point 9): a receipt with
 * an input code dated in a settled VAT period is no longer refused — within the calendar year it is
 * posted as TWO entries (the expense at the receipt date, key `receipt:{id}`; the tax moved from the
 * transit account to the input account on the first day of the running period, key
 * `receipt:{id}:vat`), across the year the run refuses it with financial's sentence (F-1). The
 * decision is `ReceiptPostingBuilder`'s (financial's `VatSettlement::inputTaxDeferral()`), never this
 * class's.
 *
 * REVERSE CHARGE (stage S6): a receipt with a reverse-charge code is ONE entry at the receipt date;
 * dated in a POSTED VAT period the run refuses it with financial's sentence (O-3 / O-8 — never caught
 * up), after «Abrechnung bis» it posts without the reverse-charge tax and the window names it in a
 * grey line (D-4). Again the builder decides (financial's `ReverseChargeTiming` — its own rule, O-4).
 *
 * The run is ALWAYS every captured receipt up to the cut-off (E11, like O7 of the Faktura-Journal):
 * one that is incomplete BLOCKS the whole run — nothing is posted until the owner completes,
 * re-dates or deletes it. Receipts dated after the cut-off stay in the journal untouched; the phone
 * keeps capturing while the owner posts last month.
 *
 * Lock order: receipt rows EXCLUSIVE by id (ascending) → reads → `journal-entry.*` (financial's
 * `post()` per receipt). No number range of its own — the journal numbers are the ledger's.
 * Year close and locked periods are the ledger's rules: its refusal (`PostingRefusedException`)
 * rolls the whole posting back and comes out as {@see ReceiptRefusedException::LEDGER} naming the
 * receipt; the open-work checks (`Close\UnpostedReceiptsCheck`) keep a year from closing while a
 * receipt dated in it waits here.
 */
final class ReceiptJournalPostingService
{
    /**
     * Behind financial's sentence for a settled VAT period, for a receipt whose code carries no input
     * tax (an output, zero-rated or exempt code — only input tax is caught up, stage S5): the date
     * stays what the slip says; the receipt goes «ohne MWST», the coded line by hand as a correction
     * entry (replaces stage S2's «das Belegdatum in die laufende Periode legen»).
     */
    private const NOT_CAUGHT_UP = '; nachgeholt wird nur Vorsteuer — den Beleg «ohne MWST» verbuchen und die MWST-Zeile als Korrekturbuchung erfassen';
    /** Behind financial's sentence for a date before «Abrechnung ab» (stage S2 slice 4): a receipt from before goes without VAT — nothing is caught up (S56-9). */
    private const BEFORE_START_WAY_OUT = '; beim Beleg «ohne MWST» wählen';

    /**
     * The grey line of the window for a reverse-charge receipt dated after «Abrechnung bis» (owner
     * D-4): posted without reverse-charge tax, nothing blocks — behind «Beleg {id} · {Text}».
     */
    public const AFTER_END_HINT = ': Belegdatum nach «Abrechnung bis» — z77 bucht keine Bezugsteuer; allenfalls ausserhalb von z77 melden.';

    private ?string $actor;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) at the posting */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /**
     * The proposed cut-off: TODAY (owner decision E16, 2026-11-12 — replaces E11's «last day of the
     * previous month», which made the window look empty; a receipt is never dated in the future, so
     * today's run is the whole journal). The one rule — the window proposes it, `preview()` defaults to it.
     */
    public static function defaultCutOff(?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        return ($today ?? new \DateTimeImmutable())->setTime(0, 0);
    }

    /**
     * What {@see post()} would book for the run up to $cutOff.
     *
     * The VAT totals (`vat`, `withoutVat`) are read off the SAME built lines — the lines of a
     * receipt's entries that carry the tax data — so the screen never computes VAT itself
     * (receipt.md); a caught-up input tax counts with its code (the tax entry's line, base 0.00), a
     * «nur Steuer» receipt in a row of its own (`taxOnly`).
     *
     * @return array{cutOff: \DateTimeImmutable, seen: list<array{id: int, version: int}>, blocked: list<array{id: int, reason: string}>, postable: bool, entries: int, gross: Money, rows: list<array{account: string, debit: Money, credit: Money}>, vat: list<array{code: string, rate: int, taxOnly: bool, base: Money, tax: Money}>, withoutVat: Money, blockedGross: Money, caughtUp: list<array{id: int, taxDate: \DateTimeImmutable, tax: Money, line: string}>, hints: list<array{id: int, line: string}>}
     *         `seen` = every receipt of the run (what `post()` takes); `postable` = the run is not empty and nothing blocks it;
     *         `entries` = the journal entries the run writes (a caught-up input tax: two per receipt);
     *         `gross` = the postable receipts; `vat` = per code, rate and kind the base and the tax of the postable receipts, by code;
     *         `withoutVat` = the gross of the postable receipts without a tax line; `blockedGross` = the gross of the blocked ones;
     *         `caughtUp` = per receipt whose input tax is caught up the line the window shows (board S5-3, part 2);
     *         `hints` = per reverse-charge receipt dated after «Abrechnung bis» the grey line (D-4) — posted without the tax
     */
    public function preview(?\DateTimeImmutable $cutOff = null): array
    {
        $cutOff ??= self::defaultCutOff();
        $builder  = new ReceiptPostingBuilder($this->em, new VatSettlement($this->em));
        $currency = ReceiptFields::currency();
        $seen     = [];
        $blocked  = [];
        $rows     = [];
        $vat      = [];
        $caughtUp = [];
        $hints    = [];
        $entries  = 0;
        $gross    = Money::zero($currency);
        $without  = Money::zero($currency);
        $blockedGross = Money::zero($currency);
        foreach ($this->receipts()->pending($cutOff) as $receipt) {
            $seen[] = ['id' => (int) $receipt->getId(), 'version' => $receipt->getVersion()];
            try {
                $posting = $builder->posting($receipt);
                $this->assertPeriodTakes($receipt, $posting, $builder->catchesUpInputTax($receipt) || $builder->chargesReverse($receipt));
            } catch (ReceiptRefusedException $e) {
                $blocked[]    = ['id' => (int) $receipt->getId(), 'reason' => $e->getMessage()];
                $blockedGross = $blockedGross->add($receipt->getAmount());
                continue;
            }
            $gross    = $gross->add($receipt->getAmount());
            if ($posting->reverseChargeAfterEnd) {
                $hints[] = ['id' => (int) $receipt->getId(), 'line' => 'Beleg ' . $receipt->getId() . ($receipt->getText() !== '' ? ' · ' . $receipt->getText() : '') . self::AFTER_END_HINT];
            }
            $taxLines = [];
            foreach ($posting->requests() as $request) {
                $entries++;
                foreach ($request->lines as $line) {
                    $rows[$line->account] ??= ['account' => $line->account, 'debit' => Money::zero($currency), 'credit' => Money::zero($currency)];
                    $rows[$line->account]['debit']  = $rows[$line->account]['debit']->add($line->debit);
                    $rows[$line->account]['credit'] = $rows[$line->account]['credit']->add($line->credit);
                    if ($line->taxCode !== null) {
                        $taxLines[] = $line;
                    }
                }
            }
            if ($taxLines === []) {
                $without = $without->add($receipt->getAmount());
                continue;
            }
            foreach ($taxLines as $taxLine) {
                $key = $taxLine->taxCode . '|' . $taxLine->taxRate . '|' . ($receipt->isTaxOnly() ? 'tax-only' : '');
                $vat[$key] ??= ['code' => (string) $taxLine->taxCode, 'rate' => (int) $taxLine->taxRate, 'taxOnly' => $receipt->isTaxOnly(), 'base' => Money::zero($currency), 'tax' => Money::zero($currency)];
                $vat[$key]['base'] = $vat[$key]['base']->add($taxLine->taxBase);
                $vat[$key]['tax']  = $vat[$key]['tax']->add($taxLine->taxAmount);
            }
            if ($posting->tax !== null && $posting->deferral !== null) {
                $caughtUp[] = self::caughtUpLine($receipt, $posting);
            }
        }
        ksort($rows, SORT_STRING);
        ksort($vat, SORT_STRING);

        return [
            'cutOff'       => $cutOff,
            'seen'         => $seen,
            'blocked'      => $blocked,
            'postable'     => $seen !== [] && $blocked === [],
            'entries'      => $entries,
            'gross'        => $gross,
            'rows'         => array_values($rows),
            'vat'          => array_values($vat),
            'withoutVat'   => $without,
            'blockedGross' => $blockedGross,
            'caughtUp'     => $caughtUp,
            'hints'        => $hints,
        ];
    }

    /**
     * The muted line of the window for a receipt whose input tax is caught up (board S5-3, part 2):
     * «Beleg 198 · Büromaterial: 3. Quartal 2026 ist abgerechnet — Aufwand am 12.08.2026 ohne MWST,
     * Vorsteuer 4.05 am 01.10.2026 nachgeholt.» — a «nur Steuer» receipt has no expense: «… ist
     * abgerechnet — Vorsteuer 81.00 am 01.10.2026 nachgeholt.»
     *
     * @return array{id: int, taxDate: \DateTimeImmutable, tax: Money, line: string}
     */
    private static function caughtUpLine(Receipt $receipt, ReceiptPosting $posting): array
    {
        $tax = Money::zero(ReceiptFields::currency());
        foreach ($posting->tax->lines as $line) {
            if ($line->taxAmount !== null) {
                $tax = $tax->add($line->taxAmount->isNegative() ? $line->taxAmount->negate() : $line->taxAmount);
            }
        }
        $taxDate = $posting->tax->date;
        $line    = 'Beleg ' . $receipt->getId() . ($receipt->getText() !== '' ? ' · ' . $receipt->getText() : '') . ': '
            . $posting->deferral->settledPeriod?->label() . ' ist abgerechnet — '
            . ($receipt->isTaxOnly() ? '' : 'Aufwand am ' . $receipt->getReceiptDate()->format('d.m.Y') . ' ohne MWST, ')
            . 'Vorsteuer ' . AmountFormat::of($tax) . ' am ' . $taxDate->format('d.m.Y') . ' nachgeholt.';

        return ['id' => (int) $receipt->getId(), 'taxDate' => $taxDate, 'tax' => $tax, 'line' => $line];
    }

    /**
     * The protocols of «Journal verbuchen» — the tab «Verbuchte Journale».
     *
     * @return list<ReceiptJournalPosting>
     */
    public function protocols(int $offset, int $limit, bool $newestFirst = true): array
    {
        return $this->em->getRepository(ReceiptJournalPosting::class)->page($offset, $limit, $newestFirst);
    }

    public function countProtocols(): int
    {
        return $this->em->getRepository(ReceiptJournalPosting::class)->countAll();
    }

    /**
     * Post the run up to $cutOff — the confirmation's `seen` list — into the ledger, ONE unit of
     * work, all or nothing:
     *
     *   1. every named row locked EXCLUSIVE in id order; each must still be `captured` at the version
     *      the confirmation showed, and the run must be exactly the captured receipts dated on or
     *      before $cutOff now — one added, re-dated or removed since is `changed`, nothing posted;
     *   2. a receipt of the run that is incomplete refuses the WHOLE run (`incomplete`, E11);
     *   3. the protocol row, then per receipt in date / id order: the posting
     *      ({@see ReceiptPostingBuilder::posting()}) → `LedgerService::post()` (date = the receipt date,
     *      source `receipt`, ref = id, key `receipt:{id}`; a caught-up input tax: then the tax entry
     *      on its tax date, key `receipt:{id}:vat`) → `Receipt::post()` with the entry (and the tax
     *      entry), the protocol, who and when; input tax of another calendar year in a settled VAT
     *      period refuses the whole run (F-1);
     *   4. after the commit, the photos of the posted receipts get their legal retention
     *      ({@see ReceiptRetention::apply()}, 10 years) — a run that rolled back sets nothing.
     *
     * @param list<array{id: int, version: int}> $seen
     * @throws ReceiptRefusedException EMPTY | CHANGED | INCOMPLETE | NOT_POSTABLE | INPUT_TAX_REFUSED | REVERSE_CHARGE_REFUSED | LEDGER
     * @throws \LogicException nobody is logged in and no actor was named
     */
    public function post(\DateTimeImmutable $cutOff, array $seen): ReceiptJournalPosting
    {
        $versions = [];
        foreach ($seen as $i => $pair) {
            if (!is_array($pair) || !isset($pair['id'], $pair['version'])) {
                throw new \InvalidArgumentException("post() takes a list of ['id' => int, 'version' => int] pairs — item {$i} is not one");
            }
            $versions[(int) $pair['id']] = (int) $pair['version'];
        }
        if ($versions === []) {
            throw new ReceiptRefusedException(ReceiptRefusedException::EMPTY, 'Bis zum Stichtag ' . $cutOff->format('d.m.Y') . ' ist kein Beleg im Belegjournal — nichts verbucht.');
        }
        $actor = $this->actor ??= Actor::current();
        $now   = new \DateTimeImmutable();

        $protocol = $this->em->getTransaction(JournalEntry::class)->run(function () use ($cutOff, $versions, $actor, $now): ReceiptJournalPosting {
            // 1. The rows, EXCLUSIVE, in id order — judged; the run compared; then read fresh.
            $ids    = array_keys($versions);
            sort($ids);
            $locked = $this->receipts()->lockIds($ids);
            foreach ($ids as $id) {
                $row = $locked[$id] ?? null;
                if ($row === null || $row['state'] !== ReceiptState::Captured->value || $row['version'] !== $versions[$id]) {
                    throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Beleg ' . $id . ' wurde seit der Bestätigung geändert, verbucht oder gelöscht — nichts wurde verbucht. Das Belegjournal neu laden und nochmals bestätigen.');
                }
            }
            if ($this->receipts()->capturedIdsUntil($cutOff) !== $ids) {
                throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Seit der Bestätigung hat sich das Belegjournal bis zum Stichtag ' . $cutOff->format('d.m.Y') . ' geändert (ein Beleg dazugekommen oder umdatiert) — nichts wurde verbucht. Neu laden und nochmals bestätigen.');
            }
            $receipts = array_map(fn(int $id): Receipt => $this->receipts()->refreshed($id), $ids);
            usort($receipts, static fn(Receipt $a, Receipt $b) => [$a->getReceiptDate(), $a->getId()] <=> [$b->getReceiptDate(), $b->getId()]);

            // 2. An incomplete receipt blocks the whole run (E11).
            $incomplete = array_values(array_filter($receipts, static fn(Receipt $r) => !$r->isComplete()));
            if ($incomplete !== []) {
                throw new ReceiptRefusedException(ReceiptRefusedException::INCOMPLETE, 'Verbuchen gesperrt — '
                    . implode('; ', array_map(static fn(Receipt $r) => ReceiptPostingBuilder::name($r) . ' (es fehlt: ' . implode(', ', $r->missing()) . ')', $incomplete))
                    . '. Vervollständigen, umdatieren oder löschen — nichts wurde verbucht.');
            }

            // 3. The protocol, then the postings.
            $gross = Money::zero(ReceiptFields::currency());
            foreach ($receipts as $receipt) {
                $gross = $gross->add($receipt->getAmount());
            }
            $protocol = new ReceiptJournalPosting($actor, $now, count($receipts), $gross);
            $this->em->persist($protocol);

            // The settled periods read inside this unit of work; the ledger judges each entry again under its locks.
            $builder = new ReceiptPostingBuilder($this->em, new VatSettlement($this->em));
            $ledger  = new LedgerService($this->em, $actor);
            foreach ($receipts as $receipt) {
                try {
                    $posting = $builder->posting($receipt);
                } catch (ReceiptRefusedException $e) {
                    if ($e->reason !== ReceiptRefusedException::INPUT_TAX_REFUSED && $e->reason !== ReceiptRefusedException::REVERSE_CHARGE_REFUSED) {
                        throw $e;
                    }
                    throw new ReceiptRefusedException($e->reason, rtrim($e->getMessage(), '.') . ' — nichts wurde verbucht.', $e);
                }
                $refs = [];
                foreach ($posting->requests() as $request) {
                    try {
                        $ref = $ledger->post($request);
                    } catch (PostingRefusedException $e) {
                        throw new ReceiptRefusedException(ReceiptRefusedException::LEDGER, ReceiptPostingBuilder::name($receipt) . ': '
                            . $this->ledgerMessage($e, $request->date, $builder->catchesUpInputTax($receipt) || $builder->chargesReverse($receipt), $request === $posting->tax) . ' — nichts wurde verbucht.', $e);
                    }
                    $refs[] = $ref->fiscalYear . '/' . $ref->number;
                }
                $receipt->post($refs[0], $protocol, $actor, $now, $refs[1] ?? null);
                $this->em->persist($receipt);
            }

            return $protocol;
        });

        // 4. AFTER the commit: the photos of the posted receipts kept 10 years (ReceiptRetention —
        //    the DMS is a file store, no part of this unit of work). The posting stands whatever the
        //    DMS does; a failed write is healed by the next run or «Verbuchte Journale».
        try {
            (new ReceiptRetention($this->em))->apply();
        } catch (\Throwable $e) {
            error_log('receipt retention after posting: ' . get_class($e) . ': ' . $e->getMessage());
        }

        return $protocol;
    }

    /**
     * The ledger's period rules asked BEFORE the posting, for the preview only — financial's own
     * lock-free answer (`LedgerService::periodRefusal()`: no fiscal year, a closed period, a tax
     * line before «Abrechnung ab» or in a settled VAT period), for each entry of the receipt on its
     * own date (a caught-up input tax: the tax entry on the first day of the running period);
     * `post()` leaves the judgement to `LedgerService::post()` under its locks. No period state is
     * read here.
     *
     * @param bool $inputTax the receipt carries an input or a reverse-charge code (its date rule was
     *                       asked) — a settled period then names no way out (the rule decided)
     * @throws ReceiptRefusedException LEDGER
     */
    private function assertPeriodTakes(Receipt $receipt, ReceiptPosting $posting, bool $inputTax): void
    {
        $ledger = new LedgerService($this->em);
        foreach ($posting->requests() as $request) {
            $date    = $request->date;
            $refusal = $ledger->periodRefusal($date, $request->hasTaxLine());
            $reason  = match ($refusal) {
                null                                    => null,
                PostingRefusedException::NO_FISCAL_YEAR => 'kein Geschäftsjahr für den ' . $date->format('d.m.Y') . ' — zuerst das Geschäftsjahr eröffnen',
                PostingRefusedException::PERIOD_CLOSED  => $date == $receipt->getReceiptDate()
                    ? 'die Periode ist abgeschlossen — das Belegdatum in eine offene Periode legen'
                    : lcfirst($ledger->periodRefusalText($refusal, $date)),
                // Before «Abrechnung ab»: financial's one sentence + the receipt's way out — nothing is caught up (S56-9).
                PostingRefusedException::BEFORE_VAT_START => lcfirst($ledger->periodRefusalText($refusal, $date)) . self::BEFORE_START_WAY_OUT,
                // The settled VAT period: financial's one sentence; an output code + the way out (only input tax is caught up).
                default                                 => lcfirst($ledger->periodRefusalText($refusal, $date)) . ($inputTax ? '' : self::NOT_CAUGHT_UP),
            };
            if ($reason !== null) {
                throw new ReceiptRefusedException(ReceiptRefusedException::LEDGER, ReceiptPostingBuilder::name($receipt) . ': ' . $reason . '.');
            }
        }
    }

    /**
     * The ledger's refusal in German, naming the next step — its own message is for the developer.
     *
     * @param bool $inputTax the receipt carries an input or a reverse-charge code — a settled period
     *                       then names no way out (the period was settled after the run decided; the
     *                       next run decides again)
     * @param bool $taxEntry the refused entry is the tax entry of a caught-up input tax (dated on the
     *                       first day of the running period, not on the receipt date)
     */
    private function ledgerMessage(PostingRefusedException $e, \DateTimeImmutable $date, bool $inputTax, bool $taxEntry = false): string
    {
        return match ($e->reason) {
            PostingRefusedException::NO_FISCAL_YEAR, PostingRefusedException::NO_PERIOD => 'kein Geschäftsjahr für den ' . $date->format('d.m.Y') . ' — zuerst das Geschäftsjahr eröffnen',
            PostingRefusedException::PERIOD_CLOSED      => $taxEntry
                ? lcfirst((new LedgerService($this->em))->periodRefusalText($e->reason, $date))
                : 'die Periode ist abgeschlossen — das Belegdatum in eine offene Periode legen',
            // The settled VAT period: financial's one sentence (the period comes with the refusal — no read here).
            PostingRefusedException::VAT_PERIOD_SETTLED => lcfirst((new LedgerService($this->em))->periodRefusalText($e->reason, $date, $e->vatPeriod)) . ($inputTax ? '' : self::NOT_CAUGHT_UP),
            PostingRefusedException::BEFORE_VAT_START   => lcfirst((new LedgerService($this->em))->periodRefusalText($e->reason, $date)) . self::BEFORE_START_WAY_OUT,
            PostingRefusedException::ACCOUNT_UNKNOWN, PostingRefusedException::ACCOUNT_NOT_POSTABLE, PostingRefusedException::ACCOUNT_INACTIVE
                                                        => 'die Buchhaltung nimmt das Konto ' . ($e->account ?? '') . ' nicht (unbekannt, Gruppe oder inaktiv)',
            PostingRefusedException::IDEMPOTENCY_CONFLICT => 'unter diesem Beleg steht im Journal schon eine andere Buchung',
            // The lock of the VAT accounts: financial's one sentence.
            PostingRefusedException::VAT_LINE_NOT_COMPUTED,
            PostingRefusedException::TAX_CODE_ON_VAT_ACCOUNT => lcfirst(LedgerService::vatLineRefusalText($e)),
            default                                     => $e->getMessage(),
        };
    }

    private function receipts(): ReceiptRepository
    {
        return $this->em->getRepository(Receipt::class);
    }
}
