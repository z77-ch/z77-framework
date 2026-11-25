<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The per-account classification of the turnover reconciliation — ONE rule for the return's tab
 * «Abstimmung» (one period) and the yearly overview (one tax year): which movement rows of
 * `JournalLineRepository::vatReturnMovements()` are a revenue account's movement, which are lines
 * without a tax code on a revenue account, which are output-coded lines on an account of another
 * type, which belong to a correction entry, which are uncoded credits on a fixed-asset account.
 * Pure over the rows it is given (the two readers below excepted); nothing written.
 *
 * Lifted from `VatReturnService::reconciliation()` / `findings()` so the year classifies exactly
 * as the period does; the fixed-asset group and the value-adjustment prefix stay
 * `VatReturnService::FIXED_ASSET_GROUP` / `VALUE_ADJUSTMENT_PREFIX` (one place).
 *
 * A row of a CORRECTION ENTRY (`correction_entry` set — ADR-042 addendum 2 of 2026-11-25, point 1)
 * is never an uncoded revenue line nor coded turnover on another type: the entry is the block's.
 * Its revenue rows are collected per entry, coded and uncoded apart.
 */
final class VatYearClassifier
{
    /**
     * @param list<array<string, mixed>> $movements  rows of `vatReturnMovements()` (with or without `correction_entry`)
     * @param array<string, string>      $categories normalised tax code → category ({@see categories()})
     * @param array<int, true>           $fixedAssets account id → true ({@see fixedAssetAccountIds()})
     * @return array{
     *     revenue: array<string, array{name: string, lines: int, amount: Money}>,
     *     uncoded: array<string, array{name: string, lines: int, amount: Money}>,
     *     otherType: array<string, array{name: string, type: string, accountId: int, lines: int, amount: Money}>,
     *     assetCredits: array<string, array{name: string, lines: int, amount: Money}>,
     *     corrections: array<int, array{coded: Money, uncoded: Money, lines: int}>
     * }
     *   - `revenue` — per revenue account (by number, in row order): credit − debit of ALL its rows;
     *   - `uncoded` — per revenue account: its rows WITHOUT a tax code (credit − debit), correction entries left out;
     *   - `otherType` — per non-revenue account: its OUTPUT-coded rows, the NEGATED base (in the declared turnover,
     *     not in the revenue movements), correction entries left out;
     *   - `assetCredits` — per fixed-asset account: the credit of its rows without a tax code (a possible sale);
     *   - `corrections` — per correction entry id: the revenue rows' credit − debit, coded and uncoded apart.
     */
    public static function turnover(array $movements, array $categories, string $currency, array $fixedAssets = []): array
    {
        $zero   = Money::zero($currency);
        $money  = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);
        $result = ['revenue' => [], 'uncoded' => [], 'otherType' => [], 'assetCredits' => [], 'corrections' => []];
        foreach ($movements as $m) {
            $net        = $money($m['credit'])->subtract($money($m['debit']));
            $correction = $m['correction_entry'] ?? null;
            if ($m['type'] === AccountType::Revenue->value) {
                $result['revenue'][$m['number']] ??= ['name' => $m['name'], 'lines' => 0, 'amount' => $zero];
                $result['revenue'][$m['number']]['lines'] += $m['lines'];
                $result['revenue'][$m['number']]['amount'] = $result['revenue'][$m['number']]['amount']->add($net);
                if ($correction !== null) {
                    $result['corrections'][$correction] ??= ['coded' => $zero, 'uncoded' => $zero, 'lines' => 0];
                    $result['corrections'][$correction][$m['tax_code'] === null ? 'uncoded' : 'coded'] = $result['corrections'][$correction][$m['tax_code'] === null ? 'uncoded' : 'coded']->add($net);
                    $result['corrections'][$correction]['lines'] += $m['lines'];
                } elseif ($m['tax_code'] === null) {
                    $result['uncoded'][$m['number']] ??= ['name' => $m['name'], 'lines' => 0, 'amount' => $zero];
                    $result['uncoded'][$m['number']]['lines'] += $m['lines'];
                    $result['uncoded'][$m['number']]['amount'] = $result['uncoded'][$m['number']]['amount']->add($net);
                }
                continue;
            }
            if ($m['tax_code'] === null) {
                if ($correction === null && $m['credit_lines'] > 0 && isset($fixedAssets[$m['account_id']])) {
                    $result['assetCredits'][$m['number']] ??= ['name' => $m['name'], 'lines' => 0, 'amount' => $zero];
                    $result['assetCredits'][$m['number']]['lines'] += $m['credit_lines'];
                    $result['assetCredits'][$m['number']]['amount'] = $result['assetCredits'][$m['number']]['amount']->add($money($m['credit']));
                }
                continue;
            }
            $category = $categories[TaxCode::normalizeCode($m['tax_code'])] ?? null;
            if ($correction === null && $category !== null && self::isOutput($category)) {
                $result['otherType'][$m['number']] ??= ['name' => $m['name'], 'type' => $m['type'], 'accountId' => (int) $m['account_id'], 'lines' => 0, 'amount' => $zero];
                $result['otherType'][$m['number']]['lines'] += $m['lines'];
                $result['otherType'][$m['number']]['amount'] = $result['otherType'][$m['number']]['amount']->subtract($money($m['base']));
            }
        }

        return $result;
    }

    /** Output turnover: a category whose lines land in field 200 (a rate or a deduction field). */
    public static function isOutput(string $category): bool
    {
        $kind = FieldKind::forCategory($category);

        return $kind === FieldKind::Rate || $kind === FieldKind::Deduction;
    }

    /**
     * Normalised tax code → its category as stored (VAT-CAT-001) — every code module-vat knows.
     *
     * @return array<string, string>
     */
    public static function categories(UnifiedEntityManager $em): array
    {
        $categories = [];
        foreach ($em->getRepository(TaxCode::class)->findAll() as $code) {
            $categories[TaxCode::normalizeCode($code->getCode())] = $code->getCategory();
        }

        return $categories;
    }

    /**
     * Every postable account under the chart group `VatReturnService::FIXED_ASSET_GROUP` (by the
     * parent chain; a cycle in the chart stops the walk) — except its value-adjustment accounts
     * (number ending in `9` AND name starting with `VatReturnService::VALUE_ADJUSTMENT_PREFIX`).
     *
     * @return array<int, true>
     */
    public static function fixedAssetAccountIds(UnifiedEntityManager $em): array
    {
        /** @var AccountRepository $repository */
        $repository = $em->getRepository(Account::class);
        $ids        = [];
        foreach ($repository->allInOrder() as $account) {
            if (!$account->isPostable() || self::isValueAdjustment($account)) {
                continue;
            }
            $seen = [];
            for ($parent = $account->getParent(); $parent !== null && !isset($seen[$parent->getNumber()]); $parent = $parent->getParent()) {
                if ($parent->getNumber() === VatReturnService::FIXED_ASSET_GROUP) {
                    $ids[(int) $account->getId()] = true;
                    break;
                }
                $seen[$parent->getNumber()] = true;
            }
        }

        return $ids;
    }

    private static function isValueAdjustment(Account $account): bool
    {
        return str_ends_with($account->getNumber(), '9') && str_starts_with($account->getName(), VatReturnService::VALUE_ADJUSTMENT_PREFIX);
    }
}
