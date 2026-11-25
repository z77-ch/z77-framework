<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Repositories\VatAccountMovementSearch;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The entries of a settlement period that do not fit the lock of the VAT accounts — listed one by
 * one under their account on the tab «Abstimmung» (owner decision S4-5, the board «Buchungen ohne
 * passenden Code»): per VAT account, every entry whose movement on it differs from the tax its own
 * coded lines compute for it. The equation is the lock's (ADR-042 addendum 2 of 2026-11-25, point
 * 7; `PostingRules::vatLinesRefusal()`), applied entry by entry: Σ (debit − credit) of the entry's
 * lines on the account = Σ sign × posted tax of its coded lines mapping to it
 * ({@see VatAccountMap::taxLines()} at the entry's date — the table the lock and the reconciliation
 * read). What lands here: entries from before the lock (a typed tax line, a tax line on the wrong
 * account) — a new posting cannot, the lock refuses it.
 *
 * The same entries as the reconciliation's movements ({@see VatAccountExpectation}): the period's
 * fiscal years and days, without the year opening and the return's own settlement entries. So the
 * differences of the listed entries of an account add up to the account's «Nicht erklärt» — except
 * a ledger reversal PAIR inside the period: an entry and its reversal mirror each other and cancel,
 * so neither is listed. A correction entry needs no special case: its own coded lines explain its
 * own movement. Only the mandator's three VAT accounts are judged ({@see VatAccountMap::accounts()}
 * — never the settlement account, 1179 or another transit account).
 *
 * Amounts are on the account's natural side, as in «Abstimmung»: the owed account credit − debit,
 * an input account debit − credit; `difference` = movement − expected. Read only.
 */
final class VatAccountViolations
{
    private readonly VatAccountMap $map;

    public function __construct(private readonly UnifiedEntityManager $em, ?VatAccountMap $map = null)
    {
        $this->map = $map ?? new VatAccountMap($em);
    }

    /**
     * Per VAT account number, the entries of $period that do not fit — in date / number order; an
     * account without such an entry has no key. Empty when the mandator names no VAT account or the
     * record cannot be read (the return reports that state itself).
     *
     * @return array<string, list<array{entryId: int, ref: string, date: \DateTimeImmutable, text: string, expected: Money, movement: Money, difference: Money, typed: bool}>>
     *         `typed` — the entry has a line WITHOUT a tax code on the account (a tax line typed by hand)
     */
    public function forPeriod(VatPeriod $period, string $currency): array
    {
        $accounts = $this->map->accounts();
        if ($accounts === []) {
            return [];
        }
        $owed = [];
        foreach ($accounts as $number => $key) {
            $owed[(string) $number] = $key === LedgerService::VAT_OWED_ACCOUNT;
        }

        /** @var JournalLineRepository $lines */
        $lines = $this->em->getRepository(JournalLine::class);
        $rows  = $lines->vatAccountMovementLines(new VatAccountMovementSearch(
            $this->yearIds($period),
            $period->from->format('Y-m-d'),
            $period->to->format('Y-m-d'),
            array_map('strval', array_keys($owed)),
            [YearOpeningService::SOURCE_TYPE, VatReturnService::SOURCE_TYPE],
            $this->openingAccountId(),
        ));

        $zero = Money::zero($currency);
        /** @var array<int, array{ref: string, date: \DateTimeImmutable, text: string, reversalOf: int, booked: array<string, Money>, computed: array<string, Money>, typed: array<string, true>}> $byEntry */
        $byEntry = [];
        try {
            foreach ($rows as $r) {
                $id = $r['entry_id'];
                $byEntry[$id] ??= ['ref' => $r['year'] . '/' . $r['number'], 'date' => new \DateTimeImmutable($r['date']), 'text' => $r['text'],
                    'reversalOf' => $r['reversal_of'], 'booked' => [], 'computed' => [], 'typed' => []];
                $e = &$byEntry[$id];
                if (isset($owed[$r['account']])) {
                    $e['booked'][$r['account']] = ($e['booked'][$r['account']] ?? $zero)
                        ->add(Money::fromDecimal($r['debit'], $currency))->subtract(Money::fromDecimal($r['credit'], $currency));
                    if ($r['tax_code'] === null) {
                        $e['typed'][$r['account']] = true;
                    }
                }
                $tax = Money::fromDecimal($r['tax'], $currency);
                if ($r['tax_code'] !== null && !$tax->isZero()) {
                    foreach ($this->targets($r['tax_code'], $e['date']) as $target) {
                        if ($target['account'] !== null && isset($owed[$target['account']])) {
                            $e['computed'][$target['account']] = ($e['computed'][$target['account']] ?? $zero)->add($tax->multiply($target['sign']));
                        }
                    }
                }
                unset($e);
            }
        } catch (VatAccountUnavailableException) {
            return [];
        }

        /** @var array<int, array<string, array{expected: Money, movement: Money, difference: Money}>> $misfits entry id → account → amounts */
        $misfits = [];
        foreach ($byEntry as $id => $e) {
            foreach (array_keys($e['booked'] + $e['computed']) as $number) {
                $number   = (string) $number;
                $booked   = $e['booked'][$number] ?? $zero;
                $computed = $e['computed'][$number] ?? $zero;
                if ($booked->equals($computed)) {
                    continue;
                }
                // On the account's natural side, as «Abstimmung» shows it.
                $movement = $owed[$number] ? $booked->negate() : $booked;
                $expected = $owed[$number] ? $computed->negate() : $computed;
                $misfits[$id][$number] = ['expected' => $expected, 'movement' => $movement, 'difference' => $movement->subtract($expected)];
            }
        }
        // A ledger reversal pair inside the period cancels: neither half is listed.
        foreach (array_keys($misfits) as $id) {
            $partner = $byEntry[$id]['reversalOf'];
            if ($partner !== 0 && isset($misfits[$partner])) {
                unset($misfits[$id], $misfits[$partner]);
            }
        }

        $result = [];
        foreach ($misfits as $id => $perAccount) {
            $e = $byEntry[$id];
            foreach ($perAccount as $number => $amounts) {
                $result[$number][] = ['entryId' => $id, 'ref' => $e['ref'], 'date' => $e['date'], 'text' => $e['text']] + $amounts + ['typed' => isset($e['typed'][$number])];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * The VAT-account targets of a code at the entry's date; none for a code the country pack cannot
     * place — the return refuses such a period as a whole, so there is no tab to list it on.
     *
     * @return list<array{key: string, account: ?string, sign: int}>
     * @throws VatAccountUnavailableException
     */
    private function targets(string $taxCode, \DateTimeImmutable $date): array
    {
        try {
            return $this->map->taxLines($taxCode, $date);
        } catch (UnknownTaxCodeException | FormMappingException) {
            return [];
        }
    }

    /**
     * The fiscal years the period touches — a period may span two.
     *
     * @return list<int>
     */
    private function yearIds(VatPeriod $period): array
    {
        $from = $period->from->format('Y-m-d');
        $to   = $period->to->format('Y-m-d');
        $ids  = [];
        foreach ($this->em->getRepository(FiscalYear::class)->findAll() as $year) {
            if ($year->getStartDate()->format('Y-m-d') <= $to && $year->getEndDate()->format('Y-m-d') >= $from) {
                $ids[] = (int) $year->getId();
            }
        }
        sort($ids);

        return $ids;
    }

    private function openingAccountId(): int
    {
        $number = (new LedgerService($this->em))->yearOpeningAccount(LedgerService::OPENING_BALANCE_ACCOUNT);
        if ($number === null) {
            return 0;
        }

        return (int) ($this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getId() ?? 0);
    }
}
