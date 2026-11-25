<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * THE one reader of «settled» (ADR-042 addendum 2026-11-24, point 2): a period is settled while a
 * LIVE return covers it — read from the posted returns (`vat_return`), never from the
 * period state and never stored a second time. Every question «is this date settled?», «which
 * return is live?», «which is the latest?» is answered here, so the correction path (stage S3)
 * changes one place.
 *
 * Two kinds of answer:
 *
 *   - LOCK-FREE ({@see periodCovering()}, {@see isSettled()}, {@see returnCovering()},
 *     {@see liveReturns()}, {@see latest()}): computed from ONE read of the live returns
 *     per instance, kept for the instance's life — one instance per request or per unit of work,
 *     like `VatReturnService`. A screen, a pre-check, the list.
 *   - LOCKING ({@see lockedPeriodCovering()}): a shared row lock on the committed state, inside an
 *     open unit of work — what a posting must ask after its own locks (stage S2 slice 2): under
 *     REPEATABLE READ a plain read answers from the snapshot taken at the unit of work's first read,
 *     i.e. possibly from before a return that committed meanwhile.
 *
 * Read only: the one writer of the rows is `VatReturnPostingService` (post, lift — stage S2
 * slices 5a, 6a); this class writes nothing.
 *
 * The running period and catching up INPUT tax (ADR-041 addendum 2026-11-25, point 9):
 * {@see claimableFrom()} — the first day of the first VAT period not yet posted — and
 * {@see inputTaxDeferral()} — the input-tax rule, decided by the PAYMENT date. The reverse-charge
 * date rule (decided by the INVOICE date, a posted period always by correction entry — point 10
 * there) is a separate rule in another place and deliberately not answered here (owner,
 * 2026-11-25: two rules, no common date check).
 */
final class VatSettlement
{
    /** @var list<VatReturn>|null */
    private ?array $live = null;

    private ?VatSettings $settings;

    private bool $settingsRead;

    /**
     * @param VatSettings|null $settings the mandator's VAT settings (a harness passes its own); null =
     *                                   read once through {@see LedgerService::vatSettings()} when
     *                                   {@see claimableFrom()} first needs them
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?VatSettings $settings = null)
    {
        $this->settings     = $settings;
        $this->settingsRead = $settings !== null;
    }

    /**
     * The first day of the RUNNING period — the first VAT period not yet posted (owner, 2026-11-25:
     * not necessarily the current calendar period): the day after the latest live return, or
     * «Abrechnung ab» while none is posted. Returns are contiguous from «Abrechnung ab» (ADR-041
     * addendum 2026-11-24, point 2), so one date answers it. Null when there is no such period: the
     * mandator is not liable, «Abrechnung ab» is not set, the day would lie after «Abrechnung bis»,
     * or the record cannot be read. Lock-free.
     */
    public function claimableFrom(): ?\DateTimeImmutable
    {
        $s = $this->settings();
        if ($s === null || !$s->liable || $s->from === null) {
            return null;
        }
        $from  = $s->from->setTime(0, 0);
        $after = $this->latest()?->period()->to->setTime(0, 0)->modify('+1 day');
        $day   = $after !== null && $after > $from ? $after : $from;
        if ($s->until !== null && $day->format('Y-m-d') > $s->until->format('Y-m-d')) {
            return null;
        }

        return $day;
    }

    /**
     * The INPUT-TAX catch-up rule («Vorsteuer nachholen», ADR-041 addendum 2026-11-25, point 9) for
     * an expense posted on $paymentDate — the payment date of a creditor invoice, the voucher date
     * of a receipt; the invoice date plays no part here (it is only the rate date):
     *
     *   - $paymentDate in no settled period, or the mandator not liable → {@see InputTaxDeferral::none()};
     *   - settled, and {@see claimableFrom()} in the SAME calendar year (Art. 34 MWSTG — the calendar
     *     year, not the business year) → {@see InputTaxDeferral::catchUp()} on that day, through the
     *     transit account;
     *   - settled, and the running period in another calendar year or none left →
     *     {@see InputTaxDeferral::refused()}: the correction path, by hand.
     *
     * For INPUT tax only — never for reverse charge (see the class comment). Lock-free: an answer
     * for building and showing; the ledger's freeze judges the entries under its locks (a tax entry
     * dated into a period posted meanwhile is refused there).
     */
    public function inputTaxDeferral(\DateTimeImmutable $paymentDate): InputTaxDeferral
    {
        $s = $this->settings();
        if ($s !== null && !$s->liable) {
            return InputTaxDeferral::none();
        }
        $settled = $this->periodCovering($paymentDate);
        if ($settled === null) {
            return InputTaxDeferral::none();
        }
        $claimable = $this->claimableFrom();
        if ($claimable !== null && $claimable->format('Y') === $paymentDate->format('Y')) {
            return InputTaxDeferral::catchUp($claimable, $settled);
        }

        return InputTaxDeferral::refused((int) $paymentDate->format('Y'), $settled);
    }

    /** The settled period covering $date — null when the date is not settled. Lock-free. */
    public function periodCovering(\DateTimeImmutable $date): ?VatPeriod
    {
        return $this->returnCovering($date)?->period();
    }

    /** Whether a live return covers $date. Lock-free. */
    public function isSettled(\DateTimeImmutable $date): bool
    {
        return $this->returnCovering($date) !== null;
    }

    /** The live return covering $date — null when none does. Lock-free. */
    public function returnCovering(\DateTimeImmutable $date): ?VatReturn
    {
        foreach ($this->liveReturns() as $return) {
            if ($return->period()->covers($date)) {
                return $return;
            }
        }

        return null;
    }

    /**
     * The settled period covering $date under a SHARED row lock (until the caller's commit) — the
     * committed state, never the snapshot of the caller's unit of work. Needs an open unit of work.
     *
     * @throws \LogicException outside an open transaction
     */
    public function lockedPeriodCovering(\DateTimeImmutable $date): ?VatPeriod
    {
        $row = $this->returns()->lockedLiveCovering($date);
        if ($row === null) {
            return null;
        }
        return VatPeriod::stored(new \DateTimeImmutable($row['period_from']), new \DateTimeImmutable($row['period_to']), VatRhythm::from($row['rhythm']));
    }

    /**
     * Every live return, in period order — one per settled period. Lock-free, read once
     * per instance.
     *
     * @return list<VatReturn>
     */
    public function liveReturns(): array
    {
        return $this->live ??= $this->returns()->live();
    }

    /** The latest live return — the one a lift starts from and the next return follows. Lock-free. */
    public function latest(): ?VatReturn
    {
        $live = $this->liveReturns();

        return $live === [] ? null : $live[array_key_last($live)];
    }

    /**
     * Every return of the period starting on $period's first day — its revisions, the
     * latest first; at most one of them live. Not cached.
     *
     * @return list<VatReturn>
     */
    public function revisionsOf(VatPeriod $period): array
    {
        return $this->returns()->revisionsFrom($period->from);
    }

    /**
     * Every return — live or lifted — whose period ends between the two days, by period,
     * the latest revision first: the list of a fiscal year in one read. Not cached.
     *
     * @return list<VatReturn>
     */
    public function returnsEndingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->returns()->endingBetween($from, $to);
    }

    private function returns(): VatReturnRepository
    {
        return $this->em->getRepository(VatReturn::class);
    }

    private function settings(): ?VatSettings
    {
        if (!$this->settingsRead) {
            $this->settings     = (new LedgerService($this->em))->vatSettings();
            $this->settingsRead = true;
        }

        return $this->settings;
    }
}
