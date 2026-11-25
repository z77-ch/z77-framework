<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Vat\CountryPack\FieldAssignment;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\CountryPack\FormEdition;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Shared\Money\Money;

/**
 * Computes the VAT return form of a settlement period from the aggregated
 * coded journal lines — pure: no database, no posting (build stage S1). The
 * rules are ADR-041 addendum 2026-11-24, point 7, and the review's C3–C7
 * (`vat-return-review-2026-11-24.md` 6.2):
 *
 *   - Each code + rate is placed by the country pack's form edition
 *     ({@see FormEdition::assign()}); an unmapped one REFUSES the whole
 *     return ({@see UnmappedTaxCodeException}).
 *   - Field 200 is the turnover BEFORE reductions. The reduction field (235)
 *     takes the reductions of the TAXABLE categories by ORIGIN (credit note,
 *     discount, loss); reversal pairs and corrections are netted into 200
 *     per code and rate. Every origin of a zero-rated or exempt code is
 *     netted into 200 AND into its own field — so field 299 equals the sum
 *     of the rate fields' bases by construction (eCH-0217, MWST-0005).
 *   - The tax of a rate field and of an acquisition field is base × rate,
 *     kept EXACT ({@see ExactAmount}); the total owed is the sum of the
 *     exact products; the payable amount is rounded ONCE, half away from
 *     zero (review C3: 667.83, not 667.84).
 *   - The input fields are the tax as POSTED (C4). A reverse-charge code
 *     adds to its input field the tax DECLARED in the acquisition field
 *     (C5), so its payable effect is zero to the Rappen.
 *   - An input-tax correction / reduction field (415 / 420) is the posted
 *     tax of its category — positive on its natural side, the CREDIT of
 *     `vat-input-other` (`TaxCategory::isDebitNatural()`) — subtracted in
 *     the input total; the subsequent deduction (410) is an input field and
 *     adds like 400 / 405.
 *   - A negative field is a warning, never a refusal (E9).
 *
 * Not here: which journal lines are selected (the aggregate query), the
 * method check, the snapshot and the posting.
 */
final class VatReturnCalculator
{
    public function __construct(private readonly FormEdition $edition) {}

    /**
     * @param list<VatReturnRow> $rows
     * @throws UnmappedTaxCodeException a code + rate the form does not map
     */
    public function calculate(string $currency, array $rows): VatReturnForm
    {
        $zero   = ExactAmount::zero($currency);
        $values = array_map(static fn() => $zero, $this->edition->fields());
        $bases  = [];
        foreach ([...$this->edition->fieldsOf(FieldKind::Rate), ...$this->edition->fieldsOf(FieldKind::Acquisition)] as $field) {
            $bases[$field] = Money::zero($currency);
        }
        $total     = $this->edition->fieldOf(FieldKind::Total);
        $reduction = $this->edition->fieldOf(FieldKind::Reduction);

        $posted      = Money::zero($currency);
        $exempt      = Money::zero($currency);
        $exemptField = null;
        $mixedUse    = Money::zero($currency);   // posted input-tax correction (mixed / own use)
        /** @var array<string, FieldAssignment> $assignments */
        $assignments = [];

        foreach ($rows as $row) {
            if ($row->base->currency !== $currency) {
                throw new \InvalidArgumentException("VAT return in {$currency}: a row of {$row->taxCode} is in {$row->base->currency}");
            }
            $key = $row->taxCode . '|' . $row->category . '|' . $row->rate;
            $a   = $assignments[$key] ??= $this->edition->assign($row->taxCode, $row->category, $row->rate);
            $f   = $a->field;

            switch ($a->kind) {
                case FieldKind::Rate:
                    $bases[$f]  = $bases[$f]->add($row->base);
                    $values[$f] = $values[$f]->add(ExactAmount::taxOf($row->base, $row->rate));
                    if ($row->origin === RowOrigin::Reduction) {
                        $values[$reduction] = $values[$reduction]->subtract(ExactAmount::ofMoney($row->base));
                    } else {
                        $values[$total] = $values[$total]->add(ExactAmount::ofMoney($row->base));
                    }
                    $posted = $posted->add($row->tax);
                    break;

                case FieldKind::Deduction:
                    $values[$total] = $values[$total]->add(ExactAmount::ofMoney($row->base));
                    $values[$f]     = $values[$f]->add(ExactAmount::ofMoney($row->base));
                    $posted         = $posted->add($row->tax);
                    if ($row->category === TaxCategory::Exempt->value) {
                        $exempt      = $exempt->add($row->base);
                        $exemptField = $f;
                    }
                    break;

                case FieldKind::Acquisition:
                    // Owed and deducted in the same return: the coded line's posted tax is on both
                    // sides (VAT owed and input), so it leaves the posted figure unchanged.
                    $declared              = ExactAmount::taxOf($row->base, $row->rate);
                    $bases[$f]             = $bases[$f]->add($row->base);
                    $values[$f]            = $values[$f]->add($declared);
                    $values[$a->inputField] = $values[$a->inputField]->add($declared);
                    break;

                case FieldKind::Input:
                    $values[$f] = $values[$f]->add(ExactAmount::ofMoney($row->tax));
                    $posted     = $posted->subtract($row->tax);
                    break;

                case FieldKind::InputCorrection:
                    // Correction and reduction (415 / 420) are credit-natural (TaxCategory::isDebitNatural()):
                    // positive tax = the input tax taken back (Cr vat-input-other), which the input total subtracts.
                    $values[$f] = $values[$f]->add(ExactAmount::ofMoney($row->tax));
                    $posted     = $posted->add($row->tax);
                    if ($row->category === TaxCategory::InputCorrection->value) {
                        $mixedUse = $mixedUse->add($row->tax);
                    }
                    break;

                default:
                    throw new \LogicException("Field {$f} of kind '{$a->kind->value}' is not filled by journal lines");
            }
        }

        $values[$this->edition->fieldOf(FieldKind::DeductionsTotal)] = $deductions = $this->sum($values, [...$this->edition->fieldsOf(FieldKind::Deduction), $reduction], $zero);
        $values[$this->edition->fieldOf(FieldKind::TaxableTotal)]    = $values[$total]->subtract($deductions);
        $values[$this->edition->fieldOf(FieldKind::TaxTotal)]        = $taxTotal = $this->sum($values, [...$this->edition->fieldsOf(FieldKind::Rate), ...$this->edition->fieldsOf(FieldKind::Acquisition)], $zero);
        $values[$this->edition->fieldOf(FieldKind::InputTotal)]      = $inputTotal = $this->sum($values, $this->edition->fieldsOf(FieldKind::Input), $zero)
            ->subtract($this->sum($values, $this->edition->fieldsOf(FieldKind::InputCorrection), $zero));

        $payable = $taxTotal->subtract($inputTotal)->rounded();
        $values[$this->edition->fieldOf(FieldKind::Payable)] = $payable->isPositive() ? ExactAmount::ofMoney($payable) : $zero;
        $values[$this->edition->fieldOf(FieldKind::Credit)]  = $payable->isNegative() ? ExactAmount::ofMoney($payable->negate()) : $zero;

        $assignments = array_values($assignments);
        usort($assignments, static fn(FieldAssignment $x, FieldAssignment $y): int => [$x->taxCode, $x->rate] <=> [$y->taxCode, $y->rate]);

        $checks = $this->checks($values, $bases, $payable, $zero);
        // Review L5 / 6.1: exempt turnover usually needs an input-tax correction for mixed use — a
        // warning only (bank interest is exempt and needs none up to CHF 250 000 a year).
        if (!$exempt->isZero() && $mixedUse->isZero()) {
            $checks[] = new VatReturnCheck(VatReturnCheck::EXEMPT_WITHOUT_CORRECTION, VatReturnCheck::WARNING, false, $exemptField, null, ExactAmount::ofMoney($exempt));
        }

        return new VatReturnForm($this->edition->name, $currency, $values, $bases, $payable, $posted, $assignments, $checks);
    }

    /**
     * The plausibility checks, recomputed from the finished figures — not
     * from the running sums above, so a change of the derivation shows here.
     *
     * @param array<string, ExactAmount> $values
     * @param array<string, Money>       $bases
     * @return list<VatReturnCheck>
     */
    private function checks(array $values, array $bases, Money $payable, ExactAmount $zero): array
    {
        $e = $this->edition;
        $checks = [];
        $equal = static function (string $id, string $field, ExactAmount $expected) use (&$checks, $values): void {
            $checks[] = new VatReturnCheck($id, VatReturnCheck::ERROR, $values[$field]->equals($expected), $field, $expected, $values[$field]);
        };

        $rateBases = $zero;
        foreach ($e->fieldsOf(FieldKind::Rate) as $field) {
            $rateBases = $rateBases->add(ExactAmount::ofMoney($bases[$field]));
        }
        $taxable = $e->fieldOf(FieldKind::TaxableTotal);

        $equal(VatReturnCheck::DEDUCTIONS_TOTAL, $e->fieldOf(FieldKind::DeductionsTotal), $this->sum($values, [...$e->fieldsOf(FieldKind::Deduction), $e->fieldOf(FieldKind::Reduction)], $zero));
        $equal(VatReturnCheck::TAXABLE_TOTAL, $taxable, $values[$e->fieldOf(FieldKind::Total)]->subtract($values[$e->fieldOf(FieldKind::DeductionsTotal)]));
        $equal(VatReturnCheck::TAXABLE_TOTAL_RATE_BASES, $taxable, $rateBases);
        $equal(VatReturnCheck::TAX_TOTAL, $e->fieldOf(FieldKind::TaxTotal), $this->sum($values, [...$e->fieldsOf(FieldKind::Rate), ...$e->fieldsOf(FieldKind::Acquisition)], $zero));
        $equal(VatReturnCheck::INPUT_TOTAL, $e->fieldOf(FieldKind::InputTotal), $this->sum($values, $e->fieldsOf(FieldKind::Input), $zero)
            ->subtract($this->sum($values, $e->fieldsOf(FieldKind::InputCorrection), $zero)));

        $declared = $values[$e->fieldOf(FieldKind::TaxTotal)]->subtract($values[$e->fieldOf(FieldKind::InputTotal)])->rounded();
        $shown    = $values[$e->fieldOf(FieldKind::Payable)]->subtract($values[$e->fieldOf(FieldKind::Credit)]);
        $checks[] = new VatReturnCheck(VatReturnCheck::PAYABLE, VatReturnCheck::ERROR, $shown->equals(ExactAmount::ofMoney($declared)) && $declared->equals($payable),
            $e->fieldOf(FieldKind::Payable), ExactAmount::ofMoney($declared), $shown);

        foreach ($values as $field => $value) {
            $figure = isset($bases[$field]) ? ExactAmount::ofMoney($bases[$field]) : $value;
            if ($figure->isNegative()) {
                $checks[] = new VatReturnCheck(VatReturnCheck::NEGATIVE_FIELD, VatReturnCheck::WARNING, false, $field, null, $figure);
            }
        }

        return $checks;
    }

    /**
     * @param array<string, ExactAmount> $values
     * @param list<string>               $fields
     */
    private function sum(array $values, array $fields, ExactAmount $zero): ExactAmount
    {
        $sum = $zero;
        foreach ($fields as $field) {
            $sum = $sum->add($values[$field]);
        }

        return $sum;
    }
}
