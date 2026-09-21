<?php

/**
 * module-vat harness (CLI) — tax codes with dated rates, rate resolution by
 * service date and the VatCalculator (ADR-041, ADR-042), on the real File
 * driver in a throwaway data directory seeded from the package's own
 * `data/**\/*.default.json` (what the installer deploys on first install).
 *
 * What is load-bearing here:
 *
 *   - the CH seed: seven codes, every one with its rates since 2018, and the
 *     2024 change as a SECOND row — every seed row passes its validator;
 *   - rate resolution on the boundaries: the day before a change, the day
 *     itself, the day after; a date before the first row is a loud
 *     NoRateException, an unknown code an UnknownTaxCodeException — never a
 *     silent 0 %; a deactivated code still resolves (history);
 *   - the calculator computes on the SUM per code (the wdv smear case:
 *     100 lines of 0.65 at 7.7 % give 5.01, not 5.00), rounds half away from
 *     zero in the edge cases, in gross mode and for negative amounts (a credit
 *     note), and refuses a float at every entry point;
 *   - validators: overlapping validity (same code, same validFrom) rejected,
 *     negative or non-integer rates rejected, code format and uniqueness;
 *   - deactivate vs delete: the write side has no delete for a code, the
 *     backend trait exposes none, the code string is immutable through the
 *     write service, and a rate in effect cannot be removed.
 *
 * Run: php tests/module-vat.php
 * Needs nothing but PHP: no database, no vendor/. The throwaway data directory
 * in the system temp is removed at the end.
 */

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-vat-' . getmypid();
@mkdir($work . '/data', 0777, true);
define('ABS_BASE_PATH', $work);

// Minimal PSR-4 autoloader over the packages — the harnesses run without a
// composer install (see tests/country-blocklist.php).
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'      => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => __DIR__ . '/../packages/kernel/persistence/src/',
        'Z77\\Module\\Vat\\' => __DIR__ . '/../packages/module-vat/src/',
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

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\TaxSummary;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Module\Vat\Calculation\VatLine;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Repositories\TaxRateRepository;
use Z77\Module\Vat\Services\InvalidRateException;
use Z77\Module\Vat\Services\NoRateException;
use Z77\Module\Vat\Services\RateInEffectException;
use Z77\Module\Vat\Services\TaxCodeChangedException;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
use Z77\Module\Vat\Services\VatMasterData;
use Z77\Module\Vat\Services\VatRates;
use Z77\Module\Vat\Validators\TaxCodeValidator;
use Z77\Module\Vat\Validators\TaxRateValidator;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

// The file entity manager asks the container for the cache; nothing else in
// this harness touches DI.
DI::getInstance()->set('CacheManager', new CacheManager(), true);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function day(string $iso): \DateTimeImmutable
{
    return new \DateTimeImmutable($iso);
}

$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static fn() => $rm($work));

// ── seed the throwaway installation the way the installer does ───────────
// (`*.default.json` under the package's data dir → `data/…` with the marker stripped)

$seedDir = __DIR__ . '/../packages/module-vat/data/framework/vat';
@mkdir($work . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $name) {
    copy($seedDir . '/' . $name . '.default.json', $work . '/data/framework/vat/' . $name . '.json');
}

$em    = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
/** @var TaxCodeRepository $codes convention repository, resolved by the File driver */
$codes = $em->getRepository(TaxCode::class);
/** @var TaxRateRepository $rates */
$rates = $em->getRepository(TaxRate::class);
$vat   = VatRates::from($em);
$calc  = new VatCalculator($vat);

// ── 1. seed contents ─────────────────────────────────────────────────────

echo "1. CH seed\n";
check('convention repositories resolve', $codes instanceof TaxCodeRepository && $rates instanceof TaxRateRepository);
$all = $codes->allSorted();
check('seven codes', count($all) === 7);
check('codes UA UE UN UR US VI VM', array_map(fn(TaxCode $c) => $c->getCode(), $all) === ['UA', 'UE', 'UN', 'UR', 'US', 'VI', 'VM']);
check('every code is CH and active', array_reduce($all, fn($ok, TaxCode $c) => $ok && $c->getCountry() === 'CH' && $c->isActive(), true));
check('every code has a model category', array_reduce($all, fn($ok, TaxCode $c) => $ok && $c->category() !== null, true));
check('categories: UN standard, UR reduced, US special, UE zero, UA exempt, VM input-material, VI input-other',
    $codes->findByCode('UN')?->category() === TaxCategory::Standard
    && $codes->findByCode('UR')?->category() === TaxCategory::Reduced
    && $codes->findByCode('US')?->category() === TaxCategory::Special
    && $codes->findByCode('UE')?->category() === TaxCategory::Zero
    && $codes->findByCode('UA')?->category() === TaxCategory::Exempt
    && $codes->findByCode('VM')?->category() === TaxCategory::InputMaterial
    && $codes->findByCode('VI')?->category() === TaxCategory::InputOther);
check('every seed code passes its validator', array_reduce($all, fn($ok, TaxCode $c) => $ok && (new TaxCodeValidator($c, $codes))->isValid(), true));
check('twelve rates', count($rates->findAll()) === 12);
check('every seed rate passes its validator', array_reduce($rates->findAll(), fn($ok, TaxRate $r) => $ok && (new TaxRateValidator($r, $codes, $rates))->isValid(), true));
$history = fn(string $code) => array_map(fn(TaxRate $r) => [$r->getValidFrom(), $r->getRate()], $rates->findByCode($code));
check('UN: 810 from 2024-01-01, 770 from 2018-01-01', $history('UN') === [['2024-01-01', 810], ['2018-01-01', 770]]);
check('UR: 260 / 250', $history('UR') === [['2024-01-01', 260], ['2018-01-01', 250]]);
check('US: 380 / 370', $history('US') === [['2024-01-01', 380], ['2018-01-01', 370]]);
check('UE and UA: 0 from 2018-01-01', $history('UE') === [['2018-01-01', 0]] && $history('UA') === [['2018-01-01', 0]]);
check('VM and VI follow the standard rate', $history('VM') === $history('UN') && $history('VI') === $history('UN'));
check('grouped view carries every code', array_keys($rates->allGroupedByCode()) === ['UA', 'UE', 'UN', 'UR', 'US', 'VI', 'VM']);
check('label with umlaut survives the seed (UTF-8, no BOM)', str_contains($codes->findByCode('VI')?->getLabel() ?? '', 'übriger'));

// ── 2. rate resolution by service date ───────────────────────────────────

echo "2. Rate resolution\n";
check('UN on 2023-12-31 → 770 (day before the change)', $vat->resolve('UN', day('2023-12-31'))->rate === 770);
check('UN on 2024-01-01 → 810 (the day itself)',        $vat->resolve('UN', day('2024-01-01'))->rate === 810);
check('UN on 2024-01-02 → 810 (day after)',             $vat->resolve('UN', day('2024-01-02'))->rate === 810);
check('UN on 2018-01-01 → 770 (first row starts)',      $vat->resolve('UN', day('2018-01-01'))->rate === 770);
check('UN on 2017-12-31 → NoRateException (before the first row)', throws(fn() => $vat->resolve('UN', day('2017-12-31')), NoRateException::class));
check('UN far in the future → latest row', $vat->resolve('UN', day('2099-06-30'))->rate === 810);
check('unknown code → UnknownTaxCodeException', throws(fn() => $vat->resolve('XX', day('2024-06-01')), UnknownTaxCodeException::class));
check('empty code → UnknownTaxCodeException', throws(fn() => $vat->resolve('', day('2024-06-01')), UnknownTaxCodeException::class));
check('code lookup is case-insensitive (" un " → UN)', $vat->resolve(' un ', day('2024-06-01'))->code === 'UN');
check('there is no «try» variant — a missing rate is always an exception', !method_exists(VatRates::class, 'tryResolve'));
$snap = $vat->resolve('UR', day('2024-06-01'));
check('ResolvedRate carries code, label, category, country, rate, validFrom', $snap->code === 'UR' && $snap->label === 'Umsatz reduzierter Satz' && $snap->category === 'reduced' && $snap->country === 'CH' && $snap->rate === 260 && $snap->validFrom === '2024-01-01');

// ── 3. the calculator ────────────────────────────────────────────────────

echo "3. VatCalculator — net mode\n";
$r = $calc->calculate('CHF', [
    new VatLine('a', chf('100.00'), 'UN'),
    new VatLine('b', chf('12.35'),  'UR'),
    new VatLine('c', chf('50.00'),  'UN'),
], day('2024-03-01'), PriceMode::Net);
check('per line: the resolved rate, in input order', array_map(fn($l) => [$l->line->ref, $l->rate->rate], $r->lines) === [['a', 810], ['b', 260], ['c', 810]]);
check('summary per code, ordered by code', array_map(fn($e) => $e->code, $r->summary->entries()) === ['UN', 'UR']);
check('UN: base 150.00, tax 12.15 (150 × 8.1 %)', $r->summary->byCode('UN')->base->equals(chf('150.00')) && $r->summary->byCode('UN')->tax->equals(chf('12.15')));
check('UR: base 12.35, tax 0.32 (0.3211)', $r->summary->byCode('UR')->base->equals(chf('12.35')) && $r->summary->byCode('UR')->tax->equals(chf('0.32')));
check('totals: net 162.35, tax 12.47, gross 174.82', $r->net()->equals(chf('162.35')) && $r->tax()->equals(chf('12.47')) && $r->gross()->equals(chf('174.82')));
check('byCode of an absent code is null', $r->summary->byCode('US') === null);
check('result carries currency, date, mode', $r->currency === 'CHF' && $r->serviceDate->format('Y-m-d') === '2024-03-01' && $r->priceMode === PriceMode::Net);

echo "3b. Sum per code, then round (the wdv smear case)\n";
$lines = [];
for ($i = 0; $i < 100; $i++) {
    $lines[] = new VatLine('l' . $i, chf('0.65'), 'UN');
}
$r = $calc->calculate('CHF', $lines, day('2023-06-01'), PriceMode::Net);
check('100 × 0.65 at 7.7 %: tax on the sum = 5.01 (65.00 × 7.7 % = 5.005)', $r->tax()->equals(chf('5.01')));
check('…where per line 0.65 × 7.7 % = 0.05 summed would give 5.00', VatCalculator::taxOf(chf('0.65'), 770)->multiply(100)->equals(chf('5.00')));
check('no tax per line — the line carries only its rate', !property_exists($r->lines[0], 'tax'));

echo "3c. Rounding edge cases (half away from zero)\n";
check('12.35 at 8.1 % = 1.00 (1.00035)',   VatCalculator::taxOf(chf('12.35'), 810)->equals(chf('1.00')));
check('0.65 at 7.7 % = 0.05 (0.05005)',    VatCalculator::taxOf(chf('0.65'), 770)->equals(chf('0.05')));
check('0.06 at 8.1 % = 0.00 (0.00486)',    VatCalculator::taxOf(chf('0.06'), 810)->equals(chf('0.00')));
check('0.07 at 8.1 % = 0.01 (0.00567)',    VatCalculator::taxOf(chf('0.07'), 810)->equals(chf('0.01')));
check('exact half: 0.50 at 1 % = 0.01 (0.005 → up)', VatCalculator::taxOf(chf('0.50'), 100)->equals(chf('0.01')));
check('0 % gives zero', VatCalculator::taxOf(chf('999.99'), 0)->isZero());
check('rate 10000 (100 %) doubles', VatCalculator::taxOf(chf('1.00'), 10000)->equals(chf('1.00')));

echo "3d. Negative amounts (credit note)\n";
$r = $calc->calculate('CHF', [new VatLine('cn', chf('-12.35'), 'UN'), new VatLine('cn2', chf('-0.65'), 'UR')], day('2023-06-01'), PriceMode::Net);
check('-12.35 at 7.7 % = -0.95 (-0.95095)', $r->summary->byCode('UN')->tax->equals(chf('-0.95')));
check('-0.65 at 2.5 % = -0.02 (-0.01625)',  $r->summary->byCode('UR')->tax->equals(chf('-0.02')));
check('exact half away from zero: -0.50 at 1 % = -0.01', VatCalculator::taxOf(chf('-0.50'), 100)->equals(chf('-0.01')));
check('totals negative: net -13.00, gross -13.97', $r->net()->equals(chf('-13.00')) && $r->gross()->equals(chf('-13.97')));
$mixed = $calc->calculate('CHF', [new VatLine('a', chf('100.00'), 'UN'), new VatLine('b', chf('-100.00'), 'UN')], day('2024-06-01'), PriceMode::Net);
check('a line and its reversal cancel to zero tax', $mixed->tax()->isZero() && $mixed->net()->isZero());

echo "3e. Gross mode\n";
$r = $calc->calculate('CHF', [new VatLine('a', chf('108.10'), 'UN'), new VatLine('b', chf('100.00'), 'UR')], day('2024-06-01'), PriceMode::Gross);
check('108.10 gross at 8.1 %: tax 8.10, base 100.00', $r->summary->byCode('UN')->tax->equals(chf('8.10')) && $r->summary->byCode('UN')->base->equals(chf('100.00')));
check('100.00 gross at 2.6 %: tax 2.53 (100 × 260 / 10260 = 2.5341), base 97.47', $r->summary->byCode('UR')->tax->equals(chf('2.53')) && $r->summary->byCode('UR')->base->equals(chf('97.47')));
check('gross total = the line amounts', $r->gross()->equals(chf('208.10')));
check('taxIn(100.00, 810) = 7.49 (7.4930)', VatCalculator::taxIn(chf('100.00'), 810)->equals(chf('7.49')));
check('taxIn negative: -108.10 → -8.10', VatCalculator::taxIn(chf('-108.10'), 810)->equals(chf('-8.10')));
check('taxIn at 0 % is zero', VatCalculator::taxIn(chf('100.00'), 0)->isZero());
$roundTrip = $calc->calculate('CHF', [new VatLine('a', chf('100.00'), 'UN')], day('2024-06-01'), PriceMode::Net);
$back      = $calc->calculate('CHF', [new VatLine('a', $roundTrip->gross(), 'UN')], day('2024-06-01'), PriceMode::Gross);
check('net → gross → net round-trips for 100.00', $back->net()->equals(chf('100.00')) && $back->tax()->equals($roundTrip->tax()));

echo "3f. Zero, exempt, empty, refused\n";
$r = $calc->calculate('CHF', [new VatLine('x', chf('100.00'), 'UE'), new VatLine('y', chf('50.00'), 'UA')], day('2024-06-01'), PriceMode::Net);
check('zero-rated and exempt lines: base counted, tax 0', $r->net()->equals(chf('150.00')) && $r->tax()->isZero() && $r->summary->byCode('UE')->rate === 0);
$empty = $calc->calculate('CHF', [], day('2024-06-01'), PriceMode::Net);
check('no lines (text-only document): empty summary, zero totals in the document currency', $empty->summary->entries() === [] && $empty->gross()->equals(Money::zero('CHF')) && $empty->lines === []);
check('a line in another currency is refused', throws(fn() => $calc->calculate('CHF', [new VatLine('a', Money::fromDecimal('1', 'EUR'), 'UN')], day('2024-06-01'), PriceMode::Net), \InvalidArgumentException::class));
check('a line with an unknown code is refused', throws(fn() => $calc->calculate('CHF', [new VatLine('a', chf('1'), 'XX')], day('2024-06-01'), PriceMode::Net), UnknownTaxCodeException::class));
check('a line without a rate on the date is refused', throws(fn() => $calc->calculate('CHF', [new VatLine('a', chf('1'), 'UN')], day('2017-06-01'), PriceMode::Net), NoRateException::class));
check('a line without a code is refused at construction', throws(fn() => new VatLine('a', chf('1'), '  '), \InvalidArgumentException::class));
check('something that is not a VatLine is refused', throws(fn() => $calc->calculate('CHF', ['not a line'], day('2024-06-01'), PriceMode::Net), \InvalidArgumentException::class));

echo "3g. No float gets in\n";
check('VatLine with a float amount is a TypeError', throws(fn() => new VatLine('a', 12.5, 'UN'), \TypeError::class));
check('taxOf with a float rate is a TypeError',      throws(fn() => VatCalculator::taxOf(chf('1'), 8.1), \TypeError::class));
check('taxOf with rate "810" is a TypeError',        throws(fn() => VatCalculator::taxOf(chf('1'), '810'), \TypeError::class));
check('taxIn with a float rate is a TypeError',      throws(fn() => VatCalculator::taxIn(chf('1'), 8.1), \TypeError::class));
check('a negative rate is refused',                  throws(fn() => VatCalculator::taxOf(chf('1'), -1), \InvalidArgumentException::class));
check('TaxRate::setRate(8.1) is a TypeError',        throws(fn() => (new TaxRate())->setRate(8.1), \TypeError::class));
check('TaxRate::setRate("810") is a TypeError',      throws(fn() => (new TaxRate())->setRate('810'), \TypeError::class));

echo "3h. Tax summary invariants\n";
$r = $calc->calculate('CHF', [new VatLine('a', chf('100.00'), 'UN'), new VatLine('b', chf('12.35'), 'UR')], day('2024-03-01'), PriceMode::Net);
check('a summary with two entries of one code is refused', throws(fn() => new TaxSummary('CHF', [$r->summary->byCode('UN'), $r->summary->byCode('UN')]), \InvalidArgumentException::class));
check('a summary entry in another currency is refused', throws(fn() => new TaxSummary('EUR', [$r->summary->byCode('UN')]), \InvalidArgumentException::class));

// ── 4. validators and percent parsing ────────────────────────────────────

echo "4. Validators\n";
$newCode = new TaxCode(['code' => 'un', 'country' => 'ch', 'category' => 'standard', 'label' => 'Doppelt']);
check('setter normalizes code and country to upper case', $newCode->getCode() === 'UN' && $newCode->getCountry() === 'CH');
$v = new TaxCodeValidator($newCode, $codes);
check('duplicate code rejected', !$v->isValid() && $v->hasFieldError('code'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'U', 'country' => 'CH', 'category' => 'standard', 'label' => 'x']), $codes);
check('code shorter than 2 rejected', !$v->isValid() && $v->hasFieldError('code'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'ABCDEFGHI', 'country' => 'CH', 'category' => 'standard', 'label' => 'x']), $codes);
check('code longer than 8 rejected', !$v->isValid() && $v->hasFieldError('code'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'U-N', 'country' => 'CH', 'category' => 'standard', 'label' => 'x']), $codes);
check('code with a hyphen rejected', !$v->isValid() && $v->hasFieldError('code'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'UX', 'country' => 'CHE', 'category' => 'standard', 'label' => 'x']), $codes);
check('three-letter country rejected', !$v->isValid() && $v->hasFieldError('country'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'UX', 'country' => 'CH', 'category' => 'luxury', 'label' => 'x']), $codes);
check('unknown category rejected', !$v->isValid() && $v->hasFieldError('category'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'UX', 'country' => 'CH', 'category' => 'standard', 'label' => '']), $codes);
check('empty label rejected', !$v->isValid() && $v->hasFieldError('label'));
$v = new TaxCodeValidator(new TaxCode(['code' => 'UX', 'country' => 'CH', 'category' => 'reverse-charge', 'label' => 'Bezugsteuer']), $codes);
check('a fresh valid code passes', $v->isValid());
check('the category set is the model\'s eight', array_map(fn(TaxCategory $c) => $c->value, TaxCategory::cases()) === ['standard', 'reduced', 'special', 'zero', 'exempt', 'reverse-charge', 'input-material', 'input-other']);

$dup = new TaxRate(['code' => 'UN', 'valid_from' => '2024-01-01', 'rate' => 800]);
$v   = new TaxRateValidator($dup, $codes, $rates);
check('overlapping validity (UN already has 2024-01-01) rejected', !$v->isValid() && $v->hasFieldError('valid_from'));
$v = new TaxRateValidator(new TaxRate(['code' => 'UN', 'valid_from' => '2025-01-01', 'rate' => -1]), $codes, $rates);
check('negative rate rejected', !$v->isValid() && $v->hasFieldError('rate'));
$v = new TaxRateValidator(new TaxRate(['code' => 'UN', 'valid_from' => '2025-01-01', 'rate' => 10001]), $codes, $rates);
check('rate above 100 % rejected', !$v->isValid() && $v->hasFieldError('rate'));
$v = new TaxRateValidator(new TaxRate(['code' => 'UN', 'valid_from' => '2025-02-30', 'rate' => 810]), $codes, $rates);
check('impossible date rejected', !$v->isValid() && $v->hasFieldError('valid_from'));
$v = new TaxRateValidator(new TaxRate(['code' => 'UN', 'valid_from' => '1.1.2025', 'rate' => 810]), $codes, $rates);
check('non-ISO date rejected', !$v->isValid() && $v->hasFieldError('valid_from'));
$v = new TaxRateValidator(new TaxRate(['code' => 'XX', 'valid_from' => '2025-01-01', 'rate' => 810]), $codes, $rates);
check('rate for an unknown code rejected', !$v->isValid() && $v->hasFieldError('code'));
$v = new TaxRateValidator(new TaxRate(['code' => 'UN', 'valid_from' => '2030-01-01', 'rate' => 900]), $codes, $rates);
check('a new future rate passes', $v->isValid());

echo "4b. Percent ↔ hundredths (the form's bridge, no float)\n";
foreach (['8.1' => 810, '8.10' => 810, '8,1' => 810, ' 2.6 % ' => 260, '2.6%' => 260, "\t8.1\n" => 810, '0' => 0, '0.0' => 0, '3.8' => 380, '10' => 1000, '8.25' => 825, '100' => 10000] as $in => $out) {
    check("'" . addcslashes((string) $in, "\t\n") . "' → {$out}", TaxRate::percentToHundredths((string) $in) === $out);
}
check("'8 1' refused (inner whitespace — would silently become 81 %)", throws(fn() => TaxRate::percentToHundredths('8 1'), \InvalidArgumentException::class));
check("'8. 1' refused", throws(fn() => TaxRate::percentToHundredths('8. 1'), \InvalidArgumentException::class));
check("'8 %1' refused (percent sign not at the end)", throws(fn() => TaxRate::percentToHundredths('8 %1'), \InvalidArgumentException::class));
check("'8.123' refused (three decimals)", throws(fn() => TaxRate::percentToHundredths('8.123'), \InvalidArgumentException::class));
check("'abc' refused", throws(fn() => TaxRate::percentToHundredths('abc'), \InvalidArgumentException::class));
check("'' refused", throws(fn() => TaxRate::percentToHundredths(''), \InvalidArgumentException::class));
check("'-1' refused", throws(fn() => TaxRate::percentToHundredths('-1'), \InvalidArgumentException::class));
foreach ([810 => '8.1', 260 => '2.6', 0 => '0', 1000 => '10', 825 => '8.25', 5 => '0.05'] as $in => $out) {
    check("formatPercent({$in}) = '{$out}'", TaxRate::formatPercent($in) === $out);
}

// ── 5. deactivate, never delete ──────────────────────────────────────────

echo "5. Deactivation vs deletion\n";
$master = new VatMasterData($em);
$us     = $codes->findByCode('US');
$master->setActive($us, false);
$em2    = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));   // fresh read from disk
$usRead = $em2->getRepository(TaxCode::class)->findByCode('US');
check('deactivation is persisted', $usRead !== null && !$usRead->isActive());
check('a deactivated code still resolves for history', $vat->resolve('US', day('2023-06-01'))->rate === 370);
check('…and the calculator still computes with it', $calc->calculate('CHF', [new VatLine('a', chf('100'), 'US')], day('2024-06-01'), PriceMode::Net)->tax()->equals(chf('3.80')));
check('the write side has no delete for a code', !method_exists(VatMasterData::class, 'removeCode') && !method_exists(VatMasterData::class, 'deleteCode'));
$traitMethods = array_map(fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(\Z77\Module\Vat\Ui\TaxCodeControllerTrait::class))->getMethods());
check('the backend trait exposes no remove/delete action for a code', !in_array('removeAction', $traitMethods, true) && !in_array('confirmDeleteAction', $traitMethods, true) && !in_array('deleteAction', $traitMethods, true));
check('…only the rate removal (future rows) and the active switch', in_array('removeRateAction', $traitMethods, true) && in_array('toggleActiveAction', $traitMethods, true));
$master->setActive($us, true);

echo "5b. The code string is immutable through the write service\n";
$ur = $codes->findByCode('UR');
$ur->setCode('URX');
check('renaming an existing code is refused', throws(fn() => $master->saveCode($ur), TaxCodeChangedException::class));
check('…and nothing was written', $codes->findByCode('URX') === null && $codes->findByCode('UR') !== null);
$ur = $codes->findByCode('UR');
$ur->setLabel('Umsatz reduzierter Satz (Lebensmittel, Bücher)');
$master->saveCode($ur);
check('changing label on an existing code saves', $codes->findByCode('UR')?->getLabel() === 'Umsatz reduzierter Satz (Lebensmittel, Bücher)');
$fresh = new TaxCode(['code' => 'BZ', 'country' => 'CH', 'category' => 'reverse-charge', 'label' => 'Bezugsteuer']);
$master->saveCode($fresh);
check('a new code saves and gets an id', $fresh->getId() !== null && $codes->findByCode('BZ') !== null);
check('the trait binds to the service rule, not to a re-set of its own', !str_contains(file_get_contents(__DIR__ . '/../packages/module-vat/src/Ui/TaxCodeControllerTrait.php'), '$originalCode'));

$today   = day('2024-06-01');
$inForce = $rates->findByCodeAndValidFrom('UN', '2024-01-01');
check('a rate in effect cannot be removed', !$master->canRemoveRate($inForce, $today) && throws(fn() => $master->removeRate($inForce, $today), RateInEffectException::class));
check('…and is still there', $rates->findByCodeAndValidFrom('UN', '2024-01-01') !== null);
check('a seed rate starting today counts as in effect (no createdOn)', !$master->canRemoveRate($inForce, day('2024-01-01')));
$future = new TaxRate(['code' => 'UN', 'valid_from' => '2030-01-01', 'rate' => 900]);
$master->addRate($future, $today);
check('a future rate was written with an id and createdOn = today', $future->getId() !== null && $future->getCreatedOn() === '2024-06-01' && $rates->findByCodeAndValidFrom('UN', '2030-01-01')?->getCreatedOn() === '2024-06-01');
check('the future rate does not affect today', $vat->resolve('UN', $today)->rate === 810);
check('…but applies from its day', $vat->resolve('UN', day('2030-01-01'))->rate === 900);
check('a future rate can be removed', $master->canRemoveRate($future, $today));
$master->removeRate($future, $today);
check('…and is gone', $rates->findByCodeAndValidFrom('UN', '2030-01-01') === null && $vat->resolve('UN', day('2030-01-01'))->rate === 810);
check('addRate refuses a row that already has an id (rates are never edited)', throws(fn() => $master->addRate($inForce, $today), \LogicException::class));

echo "5c. Backdating (owner 2026-09-21): refused, except as backfill before the earliest row or as the first rate of a code without rows\n";
$refused = fn(array $row, string $day) => throws(fn() => $master->addRate(new TaxRate($row), day($day)), InvalidRateException::class);
check('validFrom yesterday refused', $refused(['code' => 'UN', 'valid_from' => '2024-05-31', 'rate' => 900], '2024-06-01'));
check('validFrom between two existing rows refused', $refused(['code' => 'UN', 'valid_from' => '2020-01-01', 'rate' => 900], '2024-06-01'));
check('…the field error sits on valid_from and names the earliest row', (function () use ($master) {
    try { $master->addRate(new TaxRate(['code' => 'UN', 'valid_from' => '2020-01-01', 'rate' => 900]), day('2024-06-01')); }
    catch (InvalidRateException $e) { return $e->validator->hasFieldError('valid_from') && str_contains($e->validator->getFieldError('valid_from'), '2018-01-01'); }
    return false;
})());
check('…nothing was written in any of these', $rates->findByCodeAndValidFrom('UN', '2020-01-01') === null && $rates->findByCodeAndValidFrom('UN', '2024-05-31') === null);

// VAT-RATE-002 (owner 2026-09-21): no existing row, no range to reach into.
check('a brand-new code has no rate yet', $rates->findByCode('BZ') === []);
$firstBz = new TaxRate(['code' => 'BZ', 'valid_from' => '2019-01-01', 'rate' => 770]);
$master->addRate($firstBz, $today);
check('the backdated FIRST rate of a code without rows is accepted', $firstBz->getId() !== null && $vat->resolve('BZ', day('2019-01-01'))->rate === 770 && $vat->resolve('BZ', $today)->rate === 770);
check('…a stored only row re-validated is not a «first rate» (the rule counts every row, itself included)', !(new TaxRateValidator($rates->findByCodeAndValidFrom('BZ', '2019-01-01'), $codes, $rates, $today))->isValid());
check('…the next backdated rate after the earliest is refused again', $refused(['code' => 'BZ', 'valid_from' => '2024-01-01', 'rate' => 810], '2024-06-01'));
$bzBackfill = new TaxRate(['code' => 'BZ', 'valid_from' => '2011-01-01', 'rate' => 800]);
$master->addRate($bzBackfill, $today);
check('…and one before the earliest is backfill, accepted', $bzBackfill->getId() !== null && $vat->resolve('BZ', day('2018-12-31'))->rate === 800 && $vat->resolve('BZ', day('2019-01-01'))->rate === 770);
$backfill = new TaxRate(['code' => 'UN', 'valid_from' => '2011-01-01', 'rate' => 800]);
$master->addRate($backfill, $today);
check('backfill before the earliest row (2011 rate for migration) accepted', $backfill->getId() !== null && $vat->resolve('UN', day('2017-12-31'))->rate === 800);
check('…and 2018 still resolves 770', $vat->resolve('UN', day('2018-01-01'))->rate === 770);
check('backfill is in effect and stays', !$master->canRemoveRate($backfill, $today));
$sameDay = new TaxRate(['code' => 'UN', 'valid_from' => '2024-06-01', 'rate' => 850]);
$master->addRate($sameDay, $today);
check('validFrom = today accepted', $sameDay->getId() !== null && $vat->resolve('UN', $today)->rate === 850);
check('a row entered today for today is still removable today', $master->canRemoveRate($sameDay, $today));
check('…but not tomorrow', !$master->canRemoveRate($sameDay, day('2024-06-02')));
$master->removeRate($sameDay, $today);
check('…removed, today resolves 810 again', $vat->resolve('UN', $today)->rate === 810);
$enteredEarlier = new TaxRate(['code' => 'UN', 'valid_from' => '2024-06-01', 'rate' => 850, 'created_on' => '2024-05-20']);
check('a row valid from today but created earlier is not removable today', !$master->canRemoveRate($enteredEarlier, $today));
check('the seed validator run (no today) does not apply the backdating rule', (new TaxRateValidator(new TaxRate(['code' => 'UN', 'valid_from' => '2020-01-01', 'rate' => 900]), $codes, $rates))->isValid());
check('created_on is a persisted JSON key', array_key_exists('created_on', $backfill->mapToArray()));

echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
exit($fail === 0 ? 0 : 1);
