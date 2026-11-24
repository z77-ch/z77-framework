<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

/**
 * The VAT return of ONE settlement period as the preview reads it — computed, never stored (build
 * stage S1: no `VatReturn` entity, no posting). What the return page's tabs «Formular» and
 * «Herkunft» show; the tab «Abstimmung» and the findings are asked separately
 * ({@see VatReturnService::reconciliation()} / {@see VatReturnService::findings()}) — they cost
 * queries of their own.
 *
 *   - `period`, `method` / `consideration` (the settings it was computed with — S2 snapshots them),
 *     `methodLabel` (German), `edition` (the form edition's name), `currency`;
 *   - `state` — {@see STATE_OPEN} in S1 (a posted return arrives with S2), `stateLabel` German;
 *   - `form` — the calculator's answer: every field, exact; `payable`, `postedTax`,
 *     `roundingDifference()`, the plausibility checks;
 *   - `fields` — every field of the edition in form order with its label, rounded figure, line
 *     count and the flag `shown` ({@see VatReturnField});
 *   - `breakdown` — the tree of «Herkunft»: one node per field filled by journal lines and holding
 *     any, in form order ({@see VatReturnBreakdown});
 *   - `groups` / `rows` — the aggregate it was computed from (per account / per origin).
 *
 * `DECLARATION_NOTE` is the line the screen and the PDF print (review T2: the portal asks first
 * whether turnover is declared gross or net).
 */
final class VatReturnPreview
{
    public const STATE_OPEN = 'open';

    public const DECLARATION_NOTE = 'Deklaration netto (ohne MWST)';

    /**
     * @param list<VatReturnField>      $fields
     * @param list<VatReturnBreakdown>  $breakdown
     * @param list<VatReturnLineGroup>  $groups
     * @param list<VatReturnRow>        $rows
     */
    public function __construct(
        public readonly VatPeriod $period,
        public readonly VatMethod $method,
        public readonly VatConsideration $consideration,
        public readonly string $methodLabel,
        public readonly string $edition,
        public readonly string $currency,
        public readonly VatReturnForm $form,
        public readonly array $fields,
        public readonly array $breakdown,
        public readonly array $groups,
        public readonly array $rows,
        public readonly string $state = self::STATE_OPEN,
    ) {}

    /** German label of the state: «offen» (S1 knows no other). */
    public function stateLabel(): string
    {
        return 'offen';
    }

    /** Field 500 − field 510, signed — positive = to pay, negative = credit. */
    public function payable(): Money
    {
        return $this->form->payable;
    }

    /**
     * The tax the coded OUTPUT lines posted (the rate and deduction categories) — the «gebucht» beside
     * the total owed (screen decision 5: the difference declared vs. posted shows at the total owed and
     * at the payable amount only). Its counterpart for the payable amount is `form->postedTax`.
     */
    public function postedTaxOwed(): Money
    {
        $sum = Money::zero($this->currency);
        foreach ($this->groups as $group) {
            $kind = FieldKind::forCategory($group->category);
            if ($kind === FieldKind::Rate || $kind === FieldKind::Deduction) {
                $sum = $sum->add($group->tax);
            }
        }

        return $sum;
    }

    /** The field row of one field number. */
    public function field(string $field): VatReturnField
    {
        foreach ($this->fields as $row) {
            if ($row->field === $field) {
                return $row;
            }
        }

        throw new \InvalidArgumentException("Field {$field} is not a field of '{$this->edition}'");
    }

    /** The tree node of one field, null when no journal line fills it. */
    public function breakdownOf(string $field): ?VatReturnBreakdown
    {
        foreach ($this->breakdown as $node) {
            if ($node->key === $field) {
                return $node;
            }
        }

        return null;
    }
}
