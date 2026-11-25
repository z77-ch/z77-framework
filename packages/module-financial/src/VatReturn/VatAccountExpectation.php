<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Vat\CountryPack\FormEdition;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The VAT accounts of a settlement period against the tax its coded lines expect on them — the
 * reconciliation behind the tab «Abstimmung», the blocking finding `vat-account-remainder` and the
 * clearing lines of the settlement entry (ADR-041 addendum 2026-11-24, point 6; ADR-042 addendum
 * 2026-11-24, point 1). The expectation is read from {@see VatAccountMap::taxLines()} — the same
 * table the lock of the VAT accounts uses, so return and lock cannot disagree:
 *
 *   - per coded line group and tax line: Σ sign × posted tax, ROLE-NORMALISED onto the account's
 *     natural side — the owed account (credit) `expected −= sign × tax`, an input account (debit)
 *     `expected += sign × tax`. So an output code adds to VAT owed, an input code and the
 *     subsequent deduction (410) to their input account, the correction and the reduction (415 /
 *     420) subtract from `vat-input-other`, and reverse charge appears on BOTH accounts;
 *   - the movement of each account in the period (without the settlement and the opening entries)
 *     on the same side; a difference is the blocking remainder.
 *
 * Every VAT account the mandator names shows, coded lines or not — an uncoded line on 2200 in a
 * period without sales must show. A key the mandator leaves empty shows only when its coded lines
 * expect tax on it (account number ''). When the record cannot be read at all, no account is
 * reconciled (the return reports that state as its own blocking finding).
 *
 * $extra takes more expected items in the shape of a tax line plus its tax and form field — what
 * a block of correction entries the return carries adds to the accounts (ADR-042 addendum 2 of
 * 2026-11-25, point 2).
 */
final class VatAccountExpectation
{
    private readonly VatAccountMap $map;

    public function __construct(private readonly UnifiedEntityManager $em, ?VatAccountMap $map = null)
    {
        $this->map = $map ?? new VatAccountMap($em);
    }

    /**
     * @param list<VatReturnLineGroup>          $groups    the coded line groups of the period (the preview's)
     * @param list<array<string, mixed>>        $movements the period's movement rows (`JournalLineRepository::vatReturnMovements()`)
     * @param list<array{key: string, account: ?string, sign: int, tax: Money, field: string}> $extra more expected items
     * @return list<VatAccountReconciliation> the owed account first, then the input accounts by number
     */
    public function reconcile(VatPeriod $period, FormEdition $edition, array $groups, array $movements, string $currency, array $extra = []): array
    {
        $zero = Money::zero($currency);

        try {
            $accounts = [];
            foreach ([LedgerService::VAT_OWED_ACCOUNT, LedgerService::VAT_INPUT_MATERIAL_ACCOUNT, LedgerService::VAT_INPUT_OTHER_ACCOUNT] as $key) {
                $this->slot($accounts, $key, array_search($key, $this->map->accounts(), true) ?: null, $zero);
            }
            $items = $extra;
            foreach ($groups as $g) {
                $assignment = $edition->assign($g->taxCode, $g->category, $g->rate);
                foreach ($this->map->taxLines($g->taxCode, $period->to) as $line) {
                    $input   = $line['key'] !== LedgerService::VAT_OWED_ACCOUNT;
                    $items[] = $line + ['tax' => $g->tax, 'field' => $input && $assignment->inputField !== null ? $assignment->inputField : $assignment->field];
                }
            }
        } catch (VatAccountUnavailableException) {
            return [];
        }

        foreach ($items as $item) {
            $slot   = $this->slot($accounts, $item['key'], $item['account'], $zero);
            $signed = $item['tax']->multiply($item['sign']);
            $accounts[$slot]['expected'] = $accounts[$slot]['role'] === VatAccountReconciliation::OWED
                ? $accounts[$slot]['expected']->subtract($signed)
                : $accounts[$slot]['expected']->add($signed);
            if (!in_array($item['field'], $accounts[$slot]['fields'], true)) {
                $accounts[$slot]['fields'][] = $item['field'];
            }
        }

        $moved = [];
        foreach ($movements as $m) {
            $moved[$m['number']] ??= ['name' => $m['name'], 'debit' => $zero, 'credit' => $zero, 'lines' => 0];
            $moved[$m['number']]['debit']  = $moved[$m['number']]['debit']->add(Money::fromDecimal($m['debit'], $currency));
            $moved[$m['number']]['credit'] = $moved[$m['number']]['credit']->add(Money::fromDecimal($m['credit'], $currency));
            $moved[$m['number']]['lines'] += $m['lines'];
        }

        $result = [];
        foreach ($accounts as $account) {
            if ($account['number'] === '' && $account['expected']->isZero()) {
                continue;   // nothing posted for a key without an account — nothing to reconcile
            }
            $m        = $moved[$account['number']] ?? null;
            $movement = $m === null ? $zero : ($account['role'] === VatAccountReconciliation::OWED ? $m['credit']->subtract($m['debit']) : $m['debit']->subtract($m['credit']));
            sort($account['fields'], SORT_STRING);
            $result[] = new VatAccountReconciliation(
                $account['role'],
                $account['number'],
                $m['name'] ?? $this->accountName($account['number']),
                $account['fields'],
                $account['expected'],
                $movement,
                $m['lines'] ?? 0,
            );
        }
        usort($result, static fn(VatAccountReconciliation $a, VatAccountReconciliation $b): int => [$a->role !== VatAccountReconciliation::OWED, $a->accountNumber] <=> [$b->role !== VatAccountReconciliation::OWED, $b->accountNumber]);

        return $result;
    }

    /**
     * The slot of an account in $accounts, created on first use: by its number, or `?{role}` while
     * the mandator leaves the key empty. Two keys on one number share the slot (two input keys —
     * same role, same sign; owed and input on one number is refused by the mandator and reported
     * by the return as `vat-account-shared`).
     *
     * @param array<string, array{number: string, role: string, fields: list<string>, expected: Money}> $accounts
     */
    private function slot(array &$accounts, string $key, ?string $number, Money $zero): string
    {
        $role = $key === LedgerService::VAT_OWED_ACCOUNT ? VatAccountReconciliation::OWED : VatAccountReconciliation::INPUT;
        $slot = $number === null || $number === '' ? '?' . $role : $number;
        $accounts[$slot] ??= ['number' => $number ?? '', 'role' => $role, 'fields' => [], 'expected' => $zero];

        return $slot;
    }

    private function accountName(string $number): string
    {
        return $number === '' ? '' : (string) $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getName();
    }
}
