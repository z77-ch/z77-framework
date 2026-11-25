<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Shared\Money\Money;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The transit account of the year-end VAT correction — the mandator's `vat-correction-pending`,
 * KMU 2209 «MWST-Korrektur, noch nicht abgerechnet» (ADR-042 addendum 3, point 4). The ONE reader of
 * its balance, for the year-close warning ({@see \Z77\Module\Financial\Close\VatCorrectionPendingCheck})
 * and the yearly overview.
 *
 * The pattern it serves (posted BY HAND with the ordinary rules, no special input): an amount that
 * belongs to the old fiscal year, whose VAT period is already posted, is booked on the LAST day of
 * that fiscal year WITHOUT a tax code — the revenue net, the VAT onto 2209 (credit; an input-tax
 * correction in the owner's favour mirrors it, a debit); the correction entry in the running VAT
 * period then books the net WITH the code (z77 computes the VAT line), the same net without a code
 * on the other side, and clears 2209. The account is NOT a VAT account: `VatAccountMap`, the return's
 * reconciliation and the lock never name it, so both entries pass the lock.
 *
 *   - {@see balanceAt()} — the balance at the end of a day within the fiscal year of that day, its
 *     year opening included: what the overview shows «per 31.12.».
 *   - {@see openAfter()} — what is still OPEN of the balance at the end of a day: that balance plus
 *     every movement dated after it, in any later fiscal year, WITHOUT the year openings (they carry
 *     the same balance forward and would count it twice). The correction entry dated after the year
 *     end — the normal case, the year's last VAT period is posted — therefore silences the warning
 *     at once; a later year opening does not hide an amount still open. Note: a movement after the
 *     day counts whatever it belongs to — a NEW year-end amount of a later year shows here too.
 *
 * Amounts with the account statement's sign: debit − credit (a VAT liability parked on 2209 is
 * negative). Base currency only (ADR-042 decision 4). Reads `JournalLineRepository`, writes nothing;
 * no fiscal year for the day, no account named or not in the chart → 0.00.
 */
final class VatCorrectionTransit
{
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?LedgerService $ledger = null) {}

    /**
     * The account number named on the mandator, or null — no number is assumed (an existing record
     * names it on the screen). Null as well when the mandator record cannot be read: nothing is told
     * about an account nobody can name.
     */
    public function account(): ?string
    {
        try {
            return $this->ledger()->vatReturnAccount(LedgerService::VAT_CORRECTION_PENDING_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    /** The balance at the END of $day in the fiscal year of that day, its year opening included (debit − credit). */
    public function balanceAt(\DateTimeImmutable $day): Money
    {
        $account = $this->ledgerAccount();
        $year    = $account === null ? null : $this->em->getRepository(FiscalYear::class)->findByDate($day);
        if ($account === null || $year === null) {
            return Money::zero(LedgerService::baseCurrency());
        }

        return Money::fromDecimal(
            $this->lines()->balanceAtOpeningOf((int) $account->getId(), (int) $year->getId(), $day->modify('+1 day')->format('Y-m-d'), $this->openingAccountId()),
            LedgerService::baseCurrency()
        );
    }

    /**
     * What is still open of {@see balanceAt()}($day): that balance plus every movement on the account
     * dated after $day, the year openings left out (debit − credit). 0.00 = cleared.
     */
    public function openAfter(\DateTimeImmutable $day): Money
    {
        $open    = $this->balanceAt($day);
        $account = $this->ledgerAccount();
        if ($account === null) {
            return $open;
        }
        foreach ($this->lines()->changesOnAccount((int) $account->getId(), $day->modify('+1 day')->format('Y-m-d'), '9999-12-31', $this->openingAccountId()) as $change) {
            $open = $open->add(Money::fromDecimal($change['amount'], $open->currency));
        }

        return $open;
    }

    /** The chart account of the named number, or null. */
    private function ledgerAccount(): ?Account
    {
        $number = $this->account();

        return $number === null ? null : $this->em->getRepository(Account::class)->findOneBy(['number' => $number]);
    }

    /** The id of the mandator's opening contra account (the year opening is recognised by it too), 0 when none. */
    private function openingAccountId(): int
    {
        try {
            $number = $this->ledger()->yearOpeningAccount(LedgerService::OPENING_BALANCE_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return 0;
        }

        return $number === null ? 0 : (int) $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getId();
    }

    private function ledger(): LedgerService
    {
        return $this->ledger ?? new LedgerService($this->em);
    }

    private function lines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }
}
