<?php

/**
 * VAT return harness (CLI) — the pure-logic core of the Swiss VAT return,
 * build stage S1 (ADR-041 / ADR-042 addenda 2026-11-24,
 * docs/03-development/vat-return-review-2026-11-24.md):
 *
 *   R. the country pack CH rule file — category + rate → form field, the
 *      per-code exceptions (221, the reverse-charge input field), the two
 *      input-tax correction categories, the refusal of an unmapped code
 *      (G16), input tax at a reduced rate mapped (G17), and a broken rule
 *      file refused at load;
 *   X. the exact amount — base × rate without rounding, rounded once, half
 *      away from zero, no float;
 *   G. the golden cases of the review (G1–G11, G13): the form computed from
 *      the aggregated coded lines of the reviewer's ledger model, every field
 *      to the last of six decimals, the payable amount, the posted figure and
 *      the rounding difference, the checks and warnings;
 *   P. the settlement periods — quarter, month, year, a short first and
 *      last period, a change of rhythm on 1 January, a period spanning two
 *      fiscal years.
 *
 * The case rows were derived from the reviewer's Python model of the decided
 * rules (journal shapes as z77 posts them), aggregated per code + rate +
 * origin. Where the model and the ADR addendum differ, the addendum wins and
 * the case says so (G1: payable rounded ONCE, 667.83; field 235 by ORIGIN —
 * a rebate line of an invoice is netted, not a reduction).
 *
 * Run: php tests/run.php financial-vat-return (or php tests/financial-vat-return.php)
 * Needs nothing but PHP: no database, no vendor/. Writes only into its own
 * temp directory (the broken rule files of R), removed at the end.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Shared\\'           => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Module\\Vat\\'       => __DIR__ . '/../packages/module-vat/src/',
        'Z77\\Module\\Financial\\' => __DIR__ . '/../packages/module-financial/src/',
        'Z77\\Module\\Mandator\\'  => __DIR__ . '/../packages/module-mandator/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\RowOrigin;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatPeriodCalendar;
use Z77\Module\Financial\VatReturn\VatReturnCalculator;
use Z77\Module\Financial\VatReturn\VatReturnCheck;
use Z77\Module\Financial\VatReturn\VatReturnForm;
use Z77\Module\Financial\VatReturn\VatReturnRow;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** The exception thrown by $fn, or null. */
function thrown(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

/** "2026-07-01..2026-09-30" (+ " short") — a period as the checks compare it. */
function span(?VatPeriod $p): string
{
    return $p === null ? 'none' : $p->from->format('Y-m-d') . '..' . $p->to->format('Y-m-d') . ($p->short ? ' short' : '');
}

/** @param list<array{0:string,1:string,2:int,3:string,4:string,5:string}> $rows */
function rowsOf(array $rows): array
{
    return array_map(static fn(array $r): VatReturnRow => new VatReturnRow($r[0], $r[1], $r[2], RowOrigin::from($r[3]), chf($r[4]), chf($r[5])), $rows);
}

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-return-' . getmypid();
@mkdir($work, 0777, true);
register_shutdown_function(static function () use ($work): void {
    foreach (glob($work . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($work);
});

// ─────────────────────────────────────────────────────────────────────────
echo "R. Country pack CH — the rule file\n";

$mapping = FormMapping::forCountry('CH');
$edition = $mapping->edition(day('2026-07-01'), day('2026-09-30'));
check('R1 the CH rule file loads; the 2024 edition covers Q3 2026', $edition->name === 'ESTV 0535, edition 01.24' && $edition->validFrom === '2024-01-01');
check('R2 the edition holds the form fields in form order, 200 … 510',
    array_map('strval', array_keys($edition->fields())) === ['200', '220', '221', '225', '230', '235', '280', '289', '299', '302', '303', '312', '313', '342', '343', '382', '383', '399', '400', '405', '410', '415', '420', '479', '500', '510'],
    implode(',', array_keys($edition->fields())));

$e = thrown(fn() => $mapping->edition(day('2023-10-01'), day('2023-12-31')));
check('R3 a period before 2024 has no edition (the 2018 form is not mapped) → FormMappingException', $e instanceof FormMappingException && str_contains($e->getMessage(), '2023-10-01'), $e?->getMessage() ?? 'nothing thrown');
check('R3a a period across the edition start is refused too', thrown(fn() => $mapping->edition(day('2023-12-01'), day('2024-01-31'))) instanceof FormMappingException);
check('R3b an unknown country has no pack', thrown(fn() => FormMapping::forCountry('DE')) instanceof FormMappingException);

$assigned = static fn(string $code, string $category, int $rate): string => (static function () use ($edition, $code, $category, $rate): string {
    $a = $edition->assign($code, $category, $rate);
    return $a->field . ($a->inputField !== null ? '+' . $a->inputField : '') . ':' . $a->kind->value;
})();
foreach ([
    ['R4', 'UN', 'standard', 810, '303:rate', 'standard 8.1 % → 303'],
    ['R5', 'UN', 'standard', 770, '302:rate', 'standard 7.7 % (service before 2024) → 302'],
    ['R6', 'UR', 'reduced', 260, '313:rate', 'reduced 2.6 % → 313'],
    ['R7', 'UR', 'reduced', 250, '312:rate', 'reduced 2.5 % → 312'],
    ['R8', 'US', 'special', 380, '343:rate', 'special 3.8 % → 343'],
    ['R9', 'US', 'special', 370, '342:rate', 'special 3.7 % → 342'],
    ['R10', 'UE', 'zero', 0, '220:deduction', 'zero-rated (export) → 220'],
    ['R11', 'UA', 'exempt', 0, '230:deduction', 'exempt → 230'],
    ['R12', 'VM', 'input-material', 810, '400:input', 'input-material 8.1 % → 400'],
    ['G17', 'VM2', 'input-material', 260, '400:input', 'input-material at 2.6 % (an installation\'s own code) → 400, mapped at ANY rate'],
    ['R13', 'VI3', 'input-other', 380, '405:input', 'input-other at 3.8 % → 405'],
    ['R14', 'KV', 'input-correction', 810, '415:input-correction', 'NEW category input-correction → 415'],
    ['R15', 'KS', 'input-reduction', 0, '420:input-correction', 'NEW category input-reduction → 420'],
    ['R16', 'UL', 'zero', 0, '221:deduction', 'code exception: supplies abroad UL (zero) → 221, not 220'],
    ['R17', 'UL', 'standard', 810, '303:rate', 'the exception holds only for the category it names: UL as standard → 303'],
    ['R18', 'BM', 'reverse-charge', 810, '383+400:acquisition', 'reverse charge BM 8.1 % → 383, input 400'],
    ['R19', 'BI', 'reverse-charge', 770, '382+405:acquisition', 'reverse charge BI 7.7 % → 382, input 405'],
    ['R20', 'BI', 'reverse-charge', 260, '383+405:acquisition', 'reverse charge at the reduced rate 2.6 % → 383'],
] as [$id, $code, $category, $rate, $expected, $label]) {
    $got = thrown(fn() => $assigned($code, $category, $rate)) ?? $assigned($code, $category, $rate);
    check("{$id} {$label}", $got === $expected, is_string($got) ? $got : get_class($got) . ': ' . $got->getMessage());
}

$e = thrown(fn() => $edition->assign('UX', 'standard', 500));
check('G16 an unmapped category + rate REFUSES with a typed exception naming code, category and rate',
    $e instanceof UnmappedTaxCodeException && $e->taxCode === 'UX' && $e->category === 'standard' && $e->rate === 500
    && str_contains($e->getMessage(), "'UX'") && str_contains($e->getMessage(), "'standard'") && str_contains($e->getMessage(), '500'),
    $e?->getMessage() ?? 'nothing thrown');
$e = thrown(fn() => $edition->assign('BX', 'reverse-charge', 810));
check('G16a a reverse-charge code without an input field refuses (the code must name 400 or 405)', $e instanceof UnmappedTaxCodeException && $e->taxCode === 'BX' && str_contains($e->getMessage(), 'input field'), $e?->getMessage() ?? 'nothing thrown');
$e = thrown(fn() => $edition->assign('ZZ', 'luxury', 810));
check('G16b an unknown category refuses', $e instanceof UnmappedTaxCodeException && $e->category === 'luxury', $e?->getMessage() ?? 'nothing thrown');
$e = thrown(fn() => (new VatReturnCalculator($edition))->calculate('CHF', rowsOf([['UN', 'standard', 810, 'ordinary', '100.00', '8.10'], ['UX', 'standard', 500, 'ordinary', '100.00', '5.00']])));
check('G16c one unmapped row refuses the whole return — never a silent omission', $e instanceof UnmappedTaxCodeException && $e->taxCode === 'UX', $e?->getMessage() ?? 'nothing thrown');

$missing = [];
foreach (TaxCategory::cases() as $category) {
    if (FieldKind::forCategory($category->value) === null) {
        $missing[] = $category->value;
    }
}
check('R21 every TaxCategory has a field kind (a new category cannot slip past the mapping)', $missing === [], implode(', ', $missing));
check('R22 the two correction categories are NOT in TaxCategory yet (stage S4) and are known to the mapping only',
    TaxCategory::tryFrom('input-correction') === null && TaxCategory::tryFrom('input-reduction') === null
    && FieldKind::forCategory('input-correction') === FieldKind::InputCorrection && FieldKind::forCategory('input-reduction') === FieldKind::InputCorrection);

// A broken rule file is refused when it is read — every variant below starts from the real file.
$real = json_decode(file_get_contents(FormMapping::PACK_DIR . '/ch.json'), true, 32, JSON_THROW_ON_ERROR);
$broken = static function (string $name, callable $edit) use ($real, $work): ?\Throwable {
    $data = $real;
    $edit($data);
    $path = $work . "/{$name}.json";
    file_put_contents($path, is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return thrown(fn() => FormMapping::fromFile($path));
};
$ruleIndex = static function (array $data, string $category, int|string $rate): int {
    foreach ($data['editions'][0]['rules'] as $i => $rule) {
        if ($rule['category'] === $category && $rule['rate'] === $rate) {
            return $i;
        }
    }
    throw new \LogicException("no rule {$category} {$rate}");
};
foreach ([
    ['R23', 'not valid JSON', 'json', static function (&$d) { $d = '{"country": "CH", '; }, 'not valid JSON'],
    ['R24', 'a standard-rate rule pointing at the export field', 'kind', static function (&$d) use ($ruleIndex) { $d['editions'][0]['rules'][$ruleIndex($d, 'standard', 810)]['field'] = '220'; }, "needs 'rate'"],
    ['R25', 'a rule at 8.1 % pointing at the 7.7 % field', 'rate', static function (&$d) use ($ruleIndex) { $d['editions'][0]['rules'][$ruleIndex($d, 'standard', 810)]['field'] = '302'; }, 'field of rate 770'],
    ['R26', 'a rule given twice', 'twice', static function (&$d) { $d['editions'][0]['rules'][] = ['category' => 'zero', 'rate' => 'any', 'field' => '220']; }, 'mapped twice'],
    ['R27', '«every rate» and one rate for the same category', 'any', static function (&$d) { $d['editions'][0]['rules'][] = ['category' => 'zero', 'rate' => 0, 'field' => '220']; }, 'one of them is wrong'],
    ['R28', 'a form without its payable field', 'structural', static function (&$d) { $d['editions'][0]['fields'] = array_values(array_filter($d['editions'][0]['fields'], fn($f) => $f['field'] !== '500')); }, "kind 'payable'"],
    ['R29', 'a float rate in a rule', 'float', static function (&$d) use ($ruleIndex) { $d['editions'][0]['rules'][$ruleIndex($d, 'standard', 810)]['rate'] = 8.1; }, 'an integer or "any"'],
    ['R30', 'an input field for a code that is not reverse charge', 'input', static function (&$d) { $d['editions'][0]['codes'][0]['input_field'] = '400'; }, 'input_field'],
    ['R31', 'a code exception choosing a rate field', 'ratefield', static function (&$d) { $d['editions'][0]['codes'][] = ['code' => 'UN', 'category' => 'standard', 'field' => '303']; }, 'chosen by the rate'],
    ['R32', 'two editions that overlap', 'overlap', static function (&$d) { $d['editions'][] = array_merge($d['editions'][0], ['edition' => 'copy', 'valid_from' => '2025-01-01']); }, 'overlap'],
    ['R33', 'a rule naming an unknown category', 'category', static function (&$d) { $d['editions'][0]['rules'][] = ['category' => 'luxury', 'rate' => 'any', 'field' => '220']; }, "unknown tax category 'luxury'"],
] as [$id, $label, $name, $edit, $needle]) {
    $e = $broken($name, $edit);
    check("{$id} refused at load: {$label}", $e instanceof FormMappingException && str_contains($e->getMessage(), $needle), $e?->getMessage() ?? 'nothing thrown');
}

// ─────────────────────────────────────────────────────────────────────────
echo "X. Exact amount\n";

check('X1 11 837.31 × 8.1 % = 958.822110 exact (rounded per field it would be 958.82)', ExactAmount::taxOf(chf('11837.31'), 810)->toDecimal() === '958.822110');
check('X2 rounded once, half away from zero: 0.005 → 0.01, −0.005 → −0.01, 0.0049 → 0.00',
    ExactAmount::taxOf(chf('0.50'), 100)->rounded()->equals(chf('0.01'))
    && ExactAmount::taxOf(chf('-0.50'), 100)->rounded()->equals(chf('-0.01'))
    && ExactAmount::taxOf(chf('0.49'), 100)->rounded()->isZero());
check('X3 a money amount is whole; a product with a fraction of a Rappen is not', ExactAmount::ofMoney(chf('12.34'))->isWhole() && !ExactAmount::taxOf(chf('12.34'), 810)->isWhole());
check('X4 a float rate is a TypeError, not coerced (also on a return row)',
    thrown(fn() => ExactAmount::taxOf(chf('1.00'), 8.1)) instanceof \TypeError
    && thrown(fn() => new VatReturnRow('UN', 'standard', 810.0, RowOrigin::Ordinary, chf('1.00'), chf('0.08'))) instanceof \TypeError);
check('X5 currencies are not mixed', thrown(fn() => ExactAmount::zero('CHF')->add(ExactAmount::zero('EUR'))) instanceof \InvalidArgumentException);
check('X6 negative figures print with their sign', ExactAmount::taxOf(chf('-1010.99'), 810)->toDecimal() === '-81.890190');

// ─────────────────────────────────────────────────────────────────────────
echo "G. Golden cases — the form from the aggregated coded lines\n";

// rows: code, category, rate, origin, Σ signed base, Σ signed posted tax (per code + rate + origin)
// value: the expected figure of a field (six decimals); every field not listed is 0.000000
$cases = [
    'G1' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '11837.31', '958.83'],
            ['UR', 'reduced', 260, 'ordinary', '354.81', '9.23'],
            ['US', 'special', 380, 'ordinary', '456.75', '17.36'],
            ['VI', 'input-other', 810, 'ordinary', '279.40', '22.65'],
            ['VM', 'input-material', 810, 'ordinary', '3641.03', '294.92'],
        ],
        'value' => ['200' => '12648.870000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '12648.870000', '303' => '958.822110', '313' => '9.225060', '343' => '17.356500', '399' => '985.403670', '400' => '294.920000', '405' => '22.650000', '415' => '0.000000', '420' => '0.000000', '479' => '317.570000'],
        'base' => ['303' => '11837.31', '313' => '354.81', '343' => '456.75'],
        'payable' => '667.83', 'posted' => '667.85', 'rounding' => '0.02',
    ],
    'G2-Q2' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '3333.40', '270.01'],
        ],
        'value' => ['200' => '3333.400000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '3333.400000', '303' => '270.005400', '399' => '270.005400', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '3333.40'],
        'payable' => '270.01', 'posted' => '270.01', 'rounding' => '0.00',
    ],
    'G2-Q3' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '2655.74', '215.12'],
            ['UN', 'standard', 810, 'reduction', '-3666.73', '-297.01'],
        ],
        'value' => ['200' => '2655.740000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '3666.730000', '289' => '3666.730000', '299' => '-1010.990000', '303' => '-81.890190', '399' => '-81.890190', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '-1010.99'],
        'payable' => '-81.89', 'posted' => '-81.89', 'rounding' => '0.00',
    ],
    'G3' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '4675.17', '378.69'],
            ['UN', 'standard', 810, 'reduction', '-1860.76', '-150.72'],
            ['UR', 'reduced', 260, 'ordinary', '659.78', '17.15'],
            ['UR', 'reduced', 260, 'reduction', '-266.23', '-6.92'],
        ],
        'value' => ['200' => '5334.950000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '2126.990000', '289' => '2126.990000', '299' => '3207.960000', '303' => '227.967210', '313' => '10.232300', '399' => '238.199510', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '2814.41', '313' => '393.55'],
        'payable' => '238.20', 'posted' => '238.20', 'rounding' => '0.00',
    ],
    'G4-Q3' => [
        'rows' => [
            ['UA', 'exempt', 0, 'ordinary', '3000.00', '0.00'],
            ['UA', 'exempt', 0, 'reduction', '-60.00', '0.00'],
            ['UE', 'zero', 0, 'ordinary', '5000.00', '0.00'],
            ['UE', 'zero', 0, 'reduction', '-800.00', '0.00'],
            ['UN', 'standard', 810, 'ordinary', '1000.00', '81.00'],
            ['UN', 'standard', 810, 'reduction', '-100.00', '-8.10'],
        ],
        'value' => ['200' => '8140.000000', '220' => '4200.000000', '221' => '0.000000', '230' => '2940.000000', '235' => '100.000000', '289' => '7240.000000', '299' => '900.000000', '303' => '72.900000', '399' => '72.900000', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '900.00'],
        'payable' => '72.90', 'posted' => '72.90', 'rounding' => '0.00',
    ],
    'G4-Q4' => [
        'rows' => [
            ['UE', 'zero', 0, 'reduction', '-500.00', '0.00'],
            ['UN', 'standard', 810, 'ordinary', '2000.00', '162.00'],
        ],
        'value' => ['200' => '1500.000000', '220' => '-500.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '-500.000000', '299' => '2000.000000', '303' => '162.000000', '399' => '162.000000', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '2000.00'],
        'payable' => '162.00', 'posted' => '162.00', 'rounding' => '0.00',
    ],
    'G5-Q3' => [
        'rows' => [
            ['UN', 'standard', 770, 'ordinary', '2876.45', '221.49'],
            ['UN', 'standard', 810, 'ordinary', '1000.00', '81.00'],
        ],
        'value' => ['200' => '3876.450000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '3876.450000', '302' => '221.486650', '303' => '81.000000', '399' => '302.486650', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['302' => '2876.45', '303' => '1000.00'],
        'payable' => '302.49', 'posted' => '302.49', 'rounding' => '0.00',
    ],
    'G5-Q4' => [
        'rows' => [
            ['UN', 'standard', 770, 'reduction', '-500.00', '-38.50'],
            ['UN', 'standard', 810, 'ordinary', '800.00', '64.80'],
        ],
        'value' => ['200' => '800.000000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '500.000000', '289' => '500.000000', '299' => '300.000000', '302' => '-38.500000', '303' => '64.800000', '399' => '26.300000', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['302' => '-500.00', '303' => '800.00'],
        'payable' => '26.30', 'posted' => '26.30', 'rounding' => '0.00',
    ],
    'G6' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '2418.92', '195.93'],
            ['UN', 'standard', 810, 'reduction', '-1718.92', '-139.23'],
            ['UN', 'standard', 810, 'reversal', '0.00', '0.00'],
        ],
        'value' => ['200' => '2418.920000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '1718.920000', '289' => '1718.920000', '299' => '700.000000', '303' => '56.700000', '399' => '56.700000', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '700.00'],
        'payable' => '56.70', 'posted' => '56.70', 'rounding' => '0.00',
    ],
    'G7' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '329.35', '26.68'],
            ['UN', 'standard', 810, 'reduction', '-1.93', '-0.16'],
            ['UR', 'reduced', 260, 'ordinary', '45.12', '1.17'],
            ['UR', 'reduced', 260, 'reduction', '-0.89', '-0.02'],
        ],
        'value' => ['200' => '374.470000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '2.820000', '289' => '2.820000', '299' => '371.650000', '303' => '26.521020', '313' => '1.149980', '399' => '27.671000', '400' => '0.000000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '0.000000'],
        'base' => ['303' => '327.42', '313' => '44.23'],
        'payable' => '27.67', 'posted' => '27.67', 'rounding' => '0.00',
    ],
    'G8' => [
        'rows' => [
            ['BI', 'reverse-charge', 810, 'ordinary', '1234.55', '100.00'],
        ],
        'value' => ['200' => '0.000000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '0.000000', '383' => '99.998550', '399' => '99.998550', '400' => '0.000000', '405' => '99.998550', '415' => '0.000000', '420' => '0.000000', '479' => '99.998550'],
        'base' => ['383' => '1234.55'],
        'payable' => '0.00', 'posted' => '0.00', 'rounding' => '0.00',
    ],
    'G8b' => [
        'rows' => [
            ['BI', 'reverse-charge', 810, 'ordinary', '300.15', '24.30'],
        ],
        'value' => ['200' => '0.000000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '0.000000', '383' => '24.312150', '399' => '24.312150', '400' => '0.000000', '405' => '24.312150', '415' => '0.000000', '420' => '0.000000', '479' => '24.312150'],
        'base' => ['383' => '300.15'],
        'payable' => '0.00', 'posted' => '0.00', 'rounding' => '0.00',
    ],
    'G9' => [
        'rows' => [
            ['VM', 'input-material', 810, 'ordinary', '120.00', '90.72'],
        ],
        'value' => ['200' => '0.000000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '0.000000', '399' => '0.000000', '400' => '90.720000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '90.720000'],
        'base' => [],
        'payable' => '-90.72', 'posted' => '-90.72', 'rounding' => '0.00',
    ],
    'G10' => [
        'rows' => [
            ['VM', 'input-material', 810, 'ordinary', '0.00', '40.50'],
        ],
        'value' => ['200' => '0.000000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '0.000000', '399' => '0.000000', '400' => '40.500000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '40.500000'],
        'base' => [],
        'payable' => '-40.50', 'posted' => '-40.50', 'rounding' => '0.00',
    ],
    'G11-declared' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '9072.57', '734.88'],
            ['UR', 'reduced', 260, 'ordinary', '543.20', '14.12'],
            ['VM', 'input-material', 810, 'ordinary', '3197.69', '259.01'],
        ],
        'value' => ['200' => '9615.770000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '9615.770000', '303' => '734.878170', '313' => '14.123200', '399' => '749.001370', '400' => '259.010000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '259.010000'],
        'base' => ['303' => '9072.57', '313' => '543.20'],
        'payable' => '489.99', 'posted' => '489.99', 'rounding' => '0.00',
    ],
    'G11-recomputed' => [
        'rows' => [
            ['UN', 'standard', 810, 'correction', '1500.88', '121.57'],
            ['UN', 'standard', 810, 'ordinary', '9072.57', '734.88'],
            ['UR', 'reduced', 260, 'correction', '1000.00', '26.00'],
            ['UR', 'reduced', 260, 'ordinary', '543.20', '14.12'],
            ['VM', 'input-material', 810, 'ordinary', '3197.69', '259.01'],
        ],
        'value' => ['200' => '12116.650000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '12116.650000', '303' => '856.449450', '313' => '40.123200', '399' => '896.572650', '400' => '259.010000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '259.010000'],
        'base' => ['303' => '10573.45', '313' => '1543.20'],
        'payable' => '637.56', 'posted' => '637.56', 'rounding' => '0.00',
    ],
    'G13' => [
        'rows' => [
            ['UN', 'standard', 810, 'ordinary', '47901.15', '3880.00'],
            ['UR', 'reduced', 260, 'ordinary', '6843.00', '177.92'],
            ['VM', 'input-material', 810, 'ordinary', '14770.61', '1196.42'],
        ],
        'value' => ['200' => '54744.150000', '220' => '0.000000', '221' => '0.000000', '230' => '0.000000', '235' => '0.000000', '289' => '0.000000', '299' => '54744.150000', '303' => '3879.993150', '313' => '177.918000', '399' => '4057.911150', '400' => '1196.420000', '405' => '0.000000', '415' => '0.000000', '420' => '0.000000', '479' => '1196.420000'],
        'base' => ['303' => '47901.15', '313' => '6843.00'],
        'payable' => '2861.49', 'posted' => '2861.50', 'rounding' => '0.01',
    ],
];

$calculator = new VatReturnCalculator($edition);
/** @var array<string, VatReturnForm> $forms */
$forms = [];
foreach ($cases as $name => $case) {
    $form = $forms[$name] = $calculator->calculate('CHF', rowsOf($case['rows']));
    $diff = [];
    foreach ($form->values() as $field => $value) {
        $expected = $case['value'][$field] ?? match ((string) $field) {
            '500'   => str_starts_with($case['payable'], '-') ? '0.000000' : $case['payable'] . '0000',
            '510'   => str_starts_with($case['payable'], '-') ? substr($case['payable'], 1) . '0000' : '0.000000',
            default => '0.000000',
        };
        if ($value->toDecimal() !== $expected) {
            $diff[] = "{$field} {$value->toDecimal()} ≠ {$expected}";
        }
    }
    foreach ($case['base'] as $field => $base) {
        if ($form->base($field)->toDecimal() !== $base) {
            $diff[] = "base {$field} {$form->base($field)->toDecimal()} ≠ {$base}";
        }
    }
    check("{$name} every field (bases, exact taxes, input as posted, totals, 500 / 510)", $diff === [], implode('; ', $diff));
    check("{$name} payable {$case['payable']}, posted {$case['posted']}, rounding account {$case['rounding']}",
        $form->payable->toDecimal() === $case['payable'] && $form->postedTax->toDecimal() === $case['posted'] && $form->roundingDifference()->toDecimal() === $case['rounding'],
        "payable {$form->payable->toDecimal()}, posted {$form->postedTax->toDecimal()}, rounding {$form->roundingDifference()->toDecimal()}");
    $failed = array_map(static fn(VatReturnCheck $c) => $c->id, array_filter($form->checks, static fn(VatReturnCheck $c) => $c->severity === VatReturnCheck::ERROR && !$c->passed));
    check("{$name} the eCH checks pass (289, 299, 299 = Σ rate bases, 399, 479, payable)", $form->isPlausible() && count($form->checks) - count($form->warnings()) === 6, implode(', ', $failed));
}

$f = $forms['G1'];
check('G1 payable 667.83 — rounded ONCE from 985.403670 − 317.57 (the per-field sum would give 667.84)', $f->payable->equals(chf('667.83')) && $f->value('399')->toDecimal() === '985.403670');
check('G1 the 0.02 the posted tax exceeds the declared amount goes to the rounding account (gain)', $f->roundingDifference()->equals(chf('0.02')));
check('G1 a rebate line inside an invoice is netted (ordinary origin), not a reduction: 200 = 12 648.87, 235 = 0',
    $f->value('200')->toDecimal() === '12648.870000' && $f->value('235')->isZero());
check('G1 no warnings', $f->warnings() === []);
check('G1 the mapping snapshot: each code + rate with its field, by code',
    array_map(static fn($a) => "{$a->taxCode}/{$a->rate}→{$a->field}", $f->assignments) === ['UN/810→303', 'UR/260→313', 'US/380→343', 'VI/810→405', 'VM/810→400']);

$warned = static fn(VatReturnForm $form): array => array_map(static fn(VatReturnCheck $c) => $c->id . ':' . $c->field, $form->warnings());
check('G2 a credit note for a SETTLED quarter is a reduction of THIS one: 235 = 3 666.73, 303 base −1 010.99, credit 81.89 in 510',
    $forms['G2-Q3']->value('235')->toDecimal() === '3666.730000' && $forms['G2-Q3']->value('510')->toDecimal() === '81.890000');
check('G2 the negative fields are warnings (299, 303, 399), not a refusal (E9)',
    $warned($forms['G2-Q3']) === ['negative-field:299', 'negative-field:303', 'negative-field:399'] && $forms['G2-Q3']->isPlausible(), implode(', ', $warned($forms['G2-Q3'])));
check('G3 discount and loss are reductions (235 = 2 126.99) over two rates', $forms['G3']->value('235')->toDecimal() === '2126.990000' && $forms['G3']->value('299')->toDecimal() === '3207.960000');
check('G4 reductions of zero-rated and exempt codes are netted into 200 and their field, not 235 (220 = 4 200.00, 230 = 2 940.00, 235 = 100.00)',
    $forms['G4-Q3']->value('220')->toDecimal() === '4200.000000' && $forms['G4-Q3']->value('230')->toDecimal() === '2940.000000' && $forms['G4-Q3']->value('235')->toDecimal() === '100.000000');
check('G4 exempt turnover without an input-tax correction raises the warning (230)', $warned($forms['G4-Q3']) === ['exempt-without-correction:230'], implode(', ', $warned($forms['G4-Q3'])));
check('G4 an export credit note in a quarter without exports: 220 = −500.00 with a warning, 299 still = Σ rate bases',
    $forms['G4-Q4']->value('220')->toDecimal() === '-500.000000' && in_array('negative-field:220', $warned($forms['G4-Q4']), true) && $forms['G4-Q4']->isPlausible());
check('G5 a 2023 service invoiced in 2026 lands in 302 (7.7 %), its credit note in Q4 makes 302 negative (warning)',
    $forms['G5-Q3']->base('302')->equals(chf('2876.45')) && $forms['G5-Q4']->base('302')->equals(chf('-500.00')) && in_array('negative-field:302', $warned($forms['G5-Q4']), true));
check('G6 a reversal pair is netted into 200 (no turnover, no reduction); the same-period full credit note stays a reduction by origin',
    $forms['G6']->value('200')->toDecimal() === '2418.920000' && $forms['G6']->value('235')->toDecimal() === '1718.920000' && $forms['G6']->value('299')->toDecimal() === '700.000000');
check('G7 a Skonto over two rates: 235 = 2.82, 313 tax exact 1.149980', $forms['G7']->value('235')->toDecimal() === '2.820000' && $forms['G7']->value('313')->toDecimal() === '1.149980');
check('G8 reverse charge: 383 = 1 234.55 × 8.1 % exact, the same exact tax deducted in 405 — payable 0.00 to the Rappen',
    $forms['G8']->value('383')->toDecimal() === '99.998550' && $forms['G8']->value('405')->equals($forms['G8']->value('383')) && $forms['G8']->payable->isZero() && $forms['G8']->roundingDifference()->isZero());
check('G8b three reverse-charge invoices of 100.05: declared 24.312150 on both sides — 0.00 payable, not 0.01 (C5)',
    $forms['G8b']->value('405')->toDecimal() === '24.312150' && $forms['G8b']->payable->isZero());
check('G9 import VAT as a tax-only line (base 0.00): 400 = the posted 90.72, credit 90.72', $forms['G9']->value('400')->toDecimal() === '90.720000' && $forms['G9']->value('510')->toDecimal() === '90.720000');
check('G10 «Vorsteuer nachholen»: 400 = 40.50 in the running period', $forms['G10']->value('400')->toDecimal() === '40.500000' && $forms['G10']->payable->equals(chf('-40.50')));
$declared = $forms['G11-declared'];
$now      = $forms['G11-recomputed'];
check('G11 correction entries are netted into 200 per code + rate: 200 +2 500.88, 235 unchanged, payable 489.99 → 637.56',
    $now->value('200')->subtract($declared->value('200'))->toDecimal() === '2500.880000' && $now->value('235')->isZero()
    && $declared->payable->equals(chf('489.99')) && $now->payable->equals(chf('637.56')));
check('G13 the yearly return: payable 2 861.49, posted 2 861.50, 0.01 to the rounding account', $forms['G13']->payable->equals(chf('2861.49')) && $forms['G13']->roundingDifference()->equals(chf('0.01')));

$f = $calculator->calculate('CHF', rowsOf([
    ['UN', 'standard', 810, 'ordinary', '1000.00', '81.00'],
    ['UL', 'zero', 0, 'ordinary', '400.00', '0.00'],
    ['UE', 'zero', 0, 'ordinary', '300.00', '0.00'],
    ['VM', 'input-material', 810, 'ordinary', '500.00', '40.50'],
    ['KV', 'input-correction', 810, 'ordinary', '100.00', '8.10'],
    ['KS', 'input-reduction', 0, 'ordinary', '0.00', '2.00'],
]));
check('G18 supplies abroad (UL) in 221, deducted in 289 beside 220: 200 = 1 700.00, 289 = 700.00, 299 = 1 000.00',
    $f->value('221')->toDecimal() === '400.000000' && $f->value('220')->toDecimal() === '300.000000' && $f->value('289')->toDecimal() === '700.000000' && $f->value('299')->toDecimal() === '1000.000000');
check('G18 the input-tax correction (415) and reduction (420) are subtracted: 479 = 40.50 − 8.10 − 2.00 = 30.40, payable 50.60',
    $f->value('415')->toDecimal() === '8.100000' && $f->value('420')->toDecimal() === '2.000000' && $f->value('479')->toDecimal() === '30.400000' && $f->payable->equals(chf('50.60')) && $f->roundingDifference()->isZero());
check('G19 a row in another currency is refused', thrown(fn() => $calculator->calculate('CHF', [new VatReturnRow('UN', 'standard', 810, RowOrigin::Ordinary, Money::of(100, 'EUR'), Money::of(8, 'EUR'))])) instanceof \InvalidArgumentException);
$f = $calculator->calculate('CHF', rowsOf([['UA', 'exempt', 0, 'ordinary', '500.00', '0.00'], ['KS', 'input-reduction', 0, 'ordinary', '0.00', '3.00']]));
check('G18a exempt turnover with only an input-tax REDUCTION (420) still warns — the warning asks for the mixed-use correction (415)', $warned($f) === ['negative-field:479', 'exempt-without-correction:230'], implode(', ', $warned($f)));
$f = $calculator->calculate('CHF', rowsOf([['UA', 'exempt', 0, 'ordinary', '500.00', '0.00'], ['KV', 'input-correction', 810, 'ordinary', '0.00', '3.00']]));
check('G18b exempt turnover WITH an input-tax correction (415) does not raise that warning (479 = −3.00 is a negative-field warning of its own)', $warned($f) === ['negative-field:479'], implode(', ', $warned($f)));
$f = $calculator->calculate('CHF', []);
check('G20 a period without coded lines: every field 0.00, plausible, no warning', $f->payable->isZero() && $f->isPlausible() && $f->warnings() === [] && $f->value('299')->isZero());

// ─────────────────────────────────────────────────────────────────────────
echo "P. Settlement periods\n";

$q = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-01-01'));
check('P1 quarters: the first from «Abrechnung ab» 1.1.', span($q->first()) === '2026-01-01..2026-03-31');
check('P2 the period after Q1 is Q2, after Q4 the next year\'s Q1', span($q->next($q->periodOn(day('2026-03-31')))) === '2026-04-01..2026-06-30' && span($q->next($q->periodOn(day('2026-11-15')))) === '2027-01-01..2027-03-31');
check('P3 the period of a date: 15.8. → Q3', span($q->periodOn(day('2026-08-15'))) === '2026-07-01..2026-09-30');
check('P4 nothing before «Abrechnung ab»', $q->periodOn(day('2025-12-31')) === null);

$m = new VatPeriodCalendar([2026 => VatRhythm::Month], day('2026-01-01'));
check('P5 months: February 2026 ends on the 28th, the period after is March', span($m->periodOn(day('2026-02-10'))) === '2026-02-01..2026-02-28' && span($m->next($m->periodOn(day('2026-02-10')))) === '2026-03-01..2026-03-31');
check('P5a a leap February ends on the 29th', span($m->periodOn(day('2028-02-01'))) === '2028-02-01..2028-02-29');

$y = new VatPeriodCalendar([2026 => VatRhythm::Year], day('2026-01-01'));
check('P6 the yearly rhythm settles the calendar year', span($y->first()) === '2026-01-01..2026-12-31' && span($y->next($y->first())) === '2027-01-01..2027-12-31');

$s = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-05-15'));
check('P7 liability begun on 15.5.: a SHORT first period 15.5.–30.6., then Q3 in full', span($s->first()) === '2026-05-15..2026-06-30 short' && span($s->next($s->first())) === '2026-07-01..2026-09-30');
$s = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-07-01'));
check('P7a «Abrechnung ab» on a grid start (system change at a period end): the first period is a full quarter', span($s->first()) === '2026-07-01..2026-09-30');

$b = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-01-01'), day('2026-08-15'));
check('P8 «Abrechnung bis» 15.8.: the last period 1.7.–15.8. (short), nothing after it', span($b->periodOn(day('2026-08-01'))) === '2026-07-01..2026-08-15 short' && $b->next($b->periodOn(day('2026-08-01'))) === null && $b->periodOn(day('2026-08-16')) === null);

$c = new VatPeriodCalendar([2026 => VatRhythm::Quarter, 2027 => VatRhythm::Month], day('2026-01-01'));
check('P9 a change of rhythm on 1 January: Q4 2026, then January 2027 as a month', span($c->next($c->periodOn(day('2026-10-01')))) === '2027-01-01..2027-01-31' && $c->rhythmOn(day('2026-12-31')) === VatRhythm::Quarter);
$c = new VatPeriodCalendar([2026 => VatRhythm::Month, 2027 => VatRhythm::Year], day('2026-11-01'));
check('P9a months to the yearly rhythm: December 2026, then the year 2027', span($c->next($c->first())) === '2026-12-01..2026-12-31' && span($c->next($c->next($c->first()))) === '2027-01-01..2027-12-31');
check('P9b the rhythm of a year before the first one given is refused', thrown(fn() => new VatPeriodCalendar([2027 => VatRhythm::Quarter], day('2026-01-01'))) instanceof \InvalidArgumentException);
check('P9c «Abrechnung bis» before «ab» is refused', thrown(fn() => new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-05-01'), day('2026-04-30'))) instanceof \InvalidArgumentException);

$q1 = $q->first();
$q2 = $q->next($q1);
check('P10 contiguity: Q1 first, Q2 after Q1; Q3 after Q1 is a gap, Q1 again an overlap',
    $q->follows(null, $q1) && $q->follows($q1, $q2) && !$q->follows($q1, $q->next($q2)) && !$q->follows($q1, $q1) && !$q->follows(null, $q2));
check('P10a a period cut differently (1.4.–31.5.) does not follow', !$q->follows($q1, new VatPeriod(day('2026-04-01'), day('2026-05-31'), VatRhythm::Quarter)));

$fy = new VatPeriodCalendar([2025 => VatRhythm::Year], day('2025-01-01'));
$ending = static fn(VatPeriodCalendar $cal, string $from, string $to): array => array_map('span', $cal->periodsEndingBetween(day($from), day($to)));
check('P11 a yearly period spans the fiscal years 1.7.–30.6.: the close of 2025/26 waits for 2025 only, 2026 belongs to 2026/27',
    $ending($fy, '2025-07-01', '2026-06-30') === ['2025-01-01..2025-12-31'] && $ending($fy, '2026-07-01', '2027-06-30') === ['2026-01-01..2026-12-31']);
check('P11a quarters in a fiscal year from 1.2.: the close of 1.2.2026–31.1.2027 waits for Q1–Q4 2026, not Q1 2027 that began in it',
    $ending($q, '2026-02-01', '2027-01-31') === ['2026-01-01..2026-03-31', '2026-04-01..2026-06-30', '2026-07-01..2026-09-30', '2026-10-01..2026-12-31']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
