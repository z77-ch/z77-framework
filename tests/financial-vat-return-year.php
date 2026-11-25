<?php

/**
 * The yearly overview of the VAT return (CLI) — «MWST-Jahresübersicht», stage S7 slice 7.1: the READ
 * MODEL (ADR-042 addendum 2 of 2026-11-25, point 8; addendum 3, points 4 to 9; ADR-041 addendum
 * 2026-11-25, point 11). It posts nothing, locks nothing, blocks nothing; calendar year only.
 *
 *   O. (offline) the deadlines (day 180 / 240 only when the fiscal year is the calendar year; due
 *      dates; before / after day 240), the tax year a fiscal year opens (B-3), the periods of a tax
 *      year, the rule «final» (B-4 truth table), the per-account classification on synthetic movement
 *      rows (Art. 128 items, correction entries apart), the turnover equation, «neu» rounded once, and
 *      source guards (nothing written, no year-span period, no input-tax rule, no docs/temp);
 *   M. (database) a throwaway schema through the modules' migrations; the books: KMU chart, fiscal
 *      years 2026 / 2027, a liable mandator (quarter from 1.1.2026);
 *   Y. read goldens on fixture rows (returns, figures and stored block rows inserted the way the
 *      posting harness does; the journal posted through the ledger, so the lock and the freeze judge
 *      it): Y1 the nil year with the rounding trap (and «vorläufig» while the 4th quarter is open);
 *      the fingerprint changing with one posting; case B (a forgotten cash sale of August 2026, the
 *      year-end through 2209, the correction entry in February 2027 — first «erfasst, noch nicht
 *      abgerechnet», then reported by the 1st quarter 2027, before day 240; the overview 2027 with the
 *      item «für frühere Steuerjahre»); case C (a second block after day 240); B-4 on the database;
 *      a red VAT-account remainder, a red turnover remainder and 1179 not at 0.00, made by raw SQL —
 *      information only, nothing blocking.
 *   D. (database, stage S7 slice 7.5) the table `vat_year_overview` agrees with its mapping, NO unique
 *      key on the tax year;
 *   E. end to end on rows the REAL «MWST buchen» wrote (tax year 2028, `VatReturnPostingService`):
 *      the overview reads the stored figures and payable amounts exactly as posted;
 *   S. the screen through `VatYearControllerTrait` and the real templates: the three tabs, the head
 *      («vorläufig» / «abgelegt», «Seit der Ablage geändert …»), the exact values (B-2), the 2209 line
 *      (B-1), «PDF» always, «Ablegen …» only when final and changed (B-4, B-5), the window and its
 *      POST (refusals: provisional, unchanged, changed under the window, an expired token), the
 *      stored PDF (write-once, SHA-256 verified), storing again keeps the first, the protocol rows
 *      (C-D: the fifth kind, the year filter by the tax year), and that storing posts nothing.
 *
 * Every name and amount here is synthetic.
 *
 * Run: php tests/financial-vat-return-year.php (or through tests/run.php once registered)
 * O needs the monorepo's vendor/; M and Y a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-return-year: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\DI;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Entities\StoredVatYearOverview;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatPeriodCalendar;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatTaxYear;
use Z77\Module\Financial\VatReturn\VatYearClassifier;
use Z77\Module\Financial\VatReturn\VatYearDeadlines;
use Z77\Module\Financial\VatReturn\VatYearField;
use Z77\Module\Financial\VatReturn\VatYearOverview;
use Z77\Module\Financial\VatReturn\VatYearPeriod;
use Z77\Module\Financial\VatReturn\VatYearService;
use Z77\Module\Financial\VatReturn\VatYearTurnover;
use Z77\Module\Financial\VatReturn\VatYearTurnoverItem;
use Z77\Module\Financial\VatReturn\YearOverviewStore;
use Z77\Module\Financial\Ui\VatYearControllerTrait;
use Z77\Module\Financial\Pdf\VatYearArchive;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/** The exception thrown by $fn, or null. */
function thrown(callable $fn): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e; }
    return null;
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function dr(string $account, string $amount, ?string $code = null, ?int $rate = null, ?string $base = null, ?string $tax = null): PostingLine
{
    return PostingLine::debit($account, chf($amount), null, $code, $rate, $base === null ? null : chf($base), $tax === null ? null : chf($tax));
}

function cr(string $account, string $amount, ?string $code = null, ?int $rate = null, ?string $base = null, ?string $tax = null): PostingLine
{
    return PostingLine::credit($account, chf($amount), null, $code, $rate, $base === null ? null : chf($base), $tax === null ? null : chf($tax));
}

function quarter(int $year, int $q): VatPeriod
{
    $from = day(sprintf('%d-%02d-01', $year, ($q - 1) * 3 + 1));

    return new VatPeriod($from, $from->modify('+3 months')->modify('-1 day'), VatRhythm::Quarter);
}

/** A fiscal year built offline, its months closed or not. */
function fiscalYear(string $code, string $from, string $to, bool $closed): FiscalYear
{
    $year = new FiscalYear($code, day($from), day($to));
    for ($m = day($from); $m <= day($to); $m = $m->modify('first day of next month')) {
        $period = new Period($year, $m, min($m->modify('last day of this month'), day($to)));
        if ($closed) {
            $period->transitionTo(PeriodState::Closed);
        }
        $year->addPeriod($period);
    }

    return $year;
}

/** The source without its comments — a docblock may name what code may not do. */
$codeOf = static fn(string $src): string => implode('', array_map(static fn($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($src)));
$fin    = $root . '/packages/module-financial';
$d      = static fn(?\DateTimeImmutable $day): ?string => $day?->format('d.m.Y');

// ─────────────────────────────────────────────────────────────────────────
echo "O. Deadlines, tax year, the rule «final», the classification — offline\n";

$cy26 = VatYearDeadlines::of(2026, fiscalYear('2026', '2026-01-01', '2026-12-31', false));
$cy25 = VatYearDeadlines::of(2025, fiscalYear('2025', '2025-01-01', '2025-12-31', false));
check('O1 fiscal year = calendar year, tax year 2026: day 180 = 29.06.2027, day 240 = 28.08.2027 (31.12. + 180 / 240 days); tax year 2025: 29.06.2026 / 28.08.2026',
    $cy26->known && $d($cy26->day180()) === '29.06.2027' && $d($cy26->day240()) === '28.08.2027'
    && $d($cy25->day180()) === '29.06.2026' && $d($cy25->day240()) === '28.08.2026',
    json_encode([$d($cy26->day180()), $d($cy26->day240()), $d($cy25->day180()), $d($cy25->day240())]));
$other = [
    VatYearDeadlines::of(2026, fiscalYear('2026-27', '2026-07-01', '2027-06-30', false)),
    VatYearDeadlines::of(2026, fiscalYear('2026', '2026-03-15', '2026-12-31', false)),
    VatYearDeadlines::of(2026, null),
];
check('O2 S3-8: a business year from 1 July, a short first year (15.03.–31.12.) and no fiscal year at all → no day 180 / 240, «Frist bei der ESTV klären», no «vor / nach»',
    array_filter($other, static fn(VatYearDeadlines $x): bool => $x->known || $x->day180() !== null || $x->day240() !== null || $x->beforeDay240(day('2027-05-25')) !== null) === []
    && VatYearDeadlines::UNKNOWN === 'Frist bei der ESTV klären');
check('O3 due date = end of period + 60 days (Art. 71): 1st quarter 2026 → 30.05.2026, 3rd → 29.11.2026, 4th → 01.03.2027; February 2028 (leap year) → 29.04.2028',
    $d(VatYearDeadlines::dueDate(quarter(2026, 1))) === '30.05.2026' && $d(VatYearDeadlines::dueDate(quarter(2026, 3))) === '29.11.2026'
    && $d(VatYearDeadlines::dueDate(quarter(2026, 4))) === '01.03.2027'
    && $d(VatYearDeadlines::dueDate(new VatPeriod(day('2028-02-01'), day('2028-02-29'), VatRhythm::Month))) === '29.04.2028');
check('O4 before / after day 240 of 2026: reported 25.05.2027 and on 28.08.2027 → «vor dem 240. Tag»; 29.08.2027 and 20.02.2028 → «nach dem 240. Tag»',
    $cy26->beforeDay240(day('2027-05-25')) === true && $cy26->beforeDay240(day('2027-08-28')) === true
    && $cy26->beforeDay240(day('2027-08-29')) === false && $cy26->beforeDay240(day('2028-02-20')) === false
    && VatYearDeadlines::relationLabel(true) === 'vor dem 240. Tag' && VatYearDeadlines::relationLabel(false) === 'nach dem 240. Tag');

$opens = [
    VatTaxYear::forFiscalYear(fiscalYear('2026', '2026-01-01', '2026-12-31', false)),
    VatTaxYear::forFiscalYear(fiscalYear('2025-26', '2025-07-01', '2026-06-30', false)),
    VatTaxYear::forFiscalYear(fiscalYear('2025-27', '2025-07-01', '2027-06-30', false)),
    VatTaxYear::forFiscalYear(fiscalYear('2026k', '2026-01-01', '2026-06-30', false)),
    VatTaxYear::forFiscalYear(fiscalYear('2026g', '2026-03-15', '2026-12-31', false)),
];
check('O5 B-3 (ADR-042 addendum 3, point 7 — the tax year whose 31.12. lies in the fiscal year): 2026 → 2026; 01.07.2025–30.06.2026 → 2025; 01.07.2025–30.06.2027 (two 31.12.) → 2026; a short year 01.01.–30.06.2026 (none) → 2026; 15.03.–31.12.2026 → 2026',
    $opens === [2026, 2025, 2026, 2026, 2026], json_encode($opens));

$q26    = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-01-01'));
$mid    = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-07-01'));
$until  = new VatPeriodCalendar([2026 => VatRhythm::Quarter], day('2026-01-01'), day('2027-05-31'));
$change = new VatPeriodCalendar([2026 => VatRhythm::Quarter, 2027 => VatRhythm::Month], day('2026-01-01'));
$span   = static fn(VatTaxYear $y): string => count($y->periods) . ' ' . $d($y->from()) . '–' . $d($y->to());
check('O6 the periods of a tax year (cut by «Abrechnung ab» / «bis», rhythm per year): 2026 quarterly → 4, 01.01.–31.12.; liable from 01.07.2026 → 2; «bis» 31.05.2027 → 2027 holds 2 (the second short, to 31.05.); months from 2027 → 12; 2025 / 2028 → none',
    $span(VatTaxYear::of(2026, $q26)) === '4 01.01.2026–31.12.2026' && $span(VatTaxYear::of(2026, $mid)) === '2 01.07.2026–31.12.2026'
    && $span(VatTaxYear::of(2027, $until)) === '2 01.01.2027–31.05.2027' && VatTaxYear::of(2027, $until)->periods[1]->short
    && count(VatTaxYear::of(2027, $change)->periods) === 12 && !VatTaxYear::of(2025, $q26)->hasPeriods() && !VatTaxYear::of(2028, $until)->hasPeriods());

$posted = (new \ReflectionClass(VatReturn::class))->newInstanceWithoutConstructor();
$four   = static fn(int $open): array => array_map(static fn(int $q): VatYearPeriod => new VatYearPeriod(quarter(2026, $q), $q === $open ? null : $posted, VatYearDeadlines::dueDate(quarter(2026, $q))), [1, 2, 3, 4]);
$truth  = [
    VatYearService::finalWhen($four(0), fiscalYear('2026', '2026-01-01', '2026-12-31', true)),
    VatYearService::finalWhen($four(4), fiscalYear('2026', '2026-01-01', '2026-12-31', true)),
    VatYearService::finalWhen($four(0), fiscalYear('2026', '2026-01-01', '2026-12-31', false)),
    VatYearService::finalWhen($four(4), fiscalYear('2026', '2026-01-01', '2026-12-31', false)),
    VatYearService::finalWhen($four(0), null),
    VatYearService::finalWhen([], fiscalYear('2026', '2026-01-01', '2026-12-31', true)),
];
check('O7 B-4 truth table: final ONLY with every period posted AND the fiscal year holding 31.12. closed — (posted, closed) yes; (4th quarter open, closed), (posted, year open), (open, open), (posted, no fiscal year), (no period) → «vorläufig»',
    $truth === [true, false, false, false, false, false], json_encode($truth));

$row = static fn(string $number, string $type, ?string $code, string $debit, string $credit, string $base = '0.00', ?int $correction = null, int $creditLines = 0, int $id = 0): array => [
    'account_id' => $id, 'number' => $number, 'name' => "Konto {$number}", 'type' => $type, 'tax_code' => $code, 'correction_entry' => $correction,
    'lines' => 1, 'credit_lines' => $creditLines, 'debit' => $debit, 'credit' => $credit, 'base' => $base, 'tax' => '0.00',
];
$classes = VatYearClassifier::turnover([
    $row('1500', 'asset', 'UN', '0.00', '4625.35', '4625.35', null, 1, 15),
    $row('1500', 'asset', null, '0.00', '200.00', '0.00', null, 1, 15),
    $row('3200', 'revenue', 'UN', '0.00', '4000.24', '4000.24'),
    $row('3200', 'revenue', null, '499.54', '0.00', '0.00', 77),
    $row('3200', 'revenue', 'UN', '0.00', '499.54', '499.54', 77),
    $row('3400', 'revenue', null, '0.00', '12.50'),
    $row('4000', 'expense', 'UN', '0.00', '100.00', '100.00'),
    $row('4000', 'expense', 'VM', '493.83', '0.00', '493.83'),
], ['UN' => 'standard', 'VM' => 'input-material'], 'CHF', [15 => true]);
$flat = static fn(array $byAccount): array => array_map(static fn(array $a): string => $a['amount']->toDecimal(), $byAccount);
check('O8 the classification (shared with the return\'s «Abstimmung»): revenue per account over ALL its rows (3200 4000.24, 3400 12.50); uncoded revenue without the correction entry\'s line (3400 12.50 only); output-coded turnover on other types as the NEGATED base (1500 −4625.35, 4000 −100.00 — an input code is none); uncoded credits on a fixed asset (1500 200.00); the correction entry 77 apart, coded +499.54 / uncoded −499.54',
    $flat($classes['revenue']) === ['3200' => '4000.24', '3400' => '12.50'] && $flat($classes['uncoded']) === ['3400' => '12.50']
    && $flat($classes['otherType']) === ['1500' => '-4625.35', '4000' => '-100.00'] && $flat($classes['assetCredits']) === ['1500' => '200.00']
    && array_keys($classes['corrections']) === [77] && $classes['corrections'][77]['coded']->toDecimal() === '499.54' && $classes['corrections'][77]['uncoded']->toDecimal() === '-499.54',
    json_encode([$flat($classes['revenue']), $flat($classes['uncoded']), $flat($classes['otherType']), $flat($classes['assetCredits'])]));

$item = static fn(?string $letter, string $kind, ?string $amount, bool $counted): VatYearTurnoverItem => new VatYearTurnoverItem($letter, $kind, $kind, '', '', 0, $amount === null ? null : chf($amount), $counted);
$y5   = new VatYearTurnover([], chf('4012.74'), chf('8625.59'), [
    $item('j', VatYearTurnoverItem::UNCODED, '12.50', true), $item('d', VatYearTurnoverItem::FIXED_ASSET, '-4625.35', true),
    $item('h', VatYearTurnoverItem::REDUCTION, '46.26', false), $item('c', VatYearTurnoverItem::BY_HAND, null, false),
], chf('0.00'), chf('8625.59'));
check('O9 the turnover equation (golden Y5): revenue 4012.74 − declared 8625.59 − j 12.50 − d (−4625.35) = remainder 0.00; an item for information (h 46.26) and a line «von Hand prüfen» (no amount) do not count',
    $y5->counted()->toDecimal() === '-4612.85' && $y5->remainder()->toDecimal() === '0.00');

$tax303  = new VatYearField('303', 'Normalsatz', FieldKind::Rate, ExactAmount::fromDecimal('324.019440', 'CHF'), chf('4000.24'), ExactAmount::fromDecimal('40.462740', 'CHF'), chf('499.54'), true);
$payable = new VatYearField('500', 'Zahllast', FieldKind::Payable, ExactAmount::ofMoney(chf('164.00')), null, ExactAmount::fromDecimal('40.462740', 'CHF'), null, true);
check('O10 B-2 «neu» rounded ONCE from the exact sum: 303 324.01944 + 40.46274 = 364.48218 → 364.48, base 4499.78; the payable amount 164.00 as posted + 40.46274 → 204.46 (never round(4 × 41.00486) = 164.02)',
    $tax303->newValue()->toDecimal() === '364.482180' && $tax303->newValue()->rounded()->toDecimal() === '364.48' && $tax303->newBase()->toDecimal() === '4499.78'
    && $payable->newValue()->rounded()->toDecimal() === '204.46' && $payable->hasCorrection()
    && ExactAmount::fromDecimal('41.004860', 'CHF')->add(ExactAmount::fromDecimal('41.004860', 'CHF'))->add(ExactAmount::fromDecimal('41.004860', 'CHF'))->add(ExactAmount::fromDecimal('41.004860', 'CHF'))->rounded()->toDecimal() === '164.02');

$sources = [];
foreach (array_merge(glob($fin . '/src/VatReturn/VatYear*.php') ?: [], [$fin . '/src/VatReturn/VatTaxYear.php']) as $file) {
    $sources[basename($file)] = (string) file_get_contents($file);
}
$writes = [];
foreach ($sources as $name => $src) {
    if (preg_match('/->(persist|flush|remove|post|reverse|amend|executeStatement|insert|update|delete|getTransaction|lock\w*)\s*\(|FOR UPDATE|LOCK IN SHARE/i', $codeOf($src), $m)) {
        $writes[] = "{$name}: {$m[0]}";
    }
}
$service = $codeOf($sources['VatYearService.php'] ?? '');
check('O11 source guards: the VatYear* classes and VatTaxYear write nothing and lock nothing (no persist / flush / post / statement / unit of work / row lock); VatYearService builds no year-span VatPeriod, never asks the input-tax rule (inputTaxDeferral / claimableFrom only for «erfasst, noch nicht abgerechnet»: the running period), and no file cites docs/temp',
    count($sources) >= 11 && $writes === [] && !str_contains($service, 'new VatPeriod(') && !str_contains($service, 'inputTaxDeferral')
    && array_filter($sources, static fn(string $src): bool => str_contains($src, 'docs/temp')) === [],
    json_encode([array_keys($sources), $writes]));

// ── M. database ──────────────────────────────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $credLine) {
        if (str_starts_with($credLine, $credentials['user'] . '=')) {
            $credentials['password'] = substr($credLine, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '' || !class_exists(\Doctrine\DBAL\DriverManager::class)) {
    echo "M. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database — the books (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-return-year-' . getmypid());
define('DEBUG', false);
$base = ABS_BASE_PATH;

$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $fin,
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['vat' => [], 'mandator' => [], 'financial' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
$write('data/framework/vat/tax_codes.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'));
$write('data/framework/vat/tax_rates.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'));

/**
 * A fresh request: new DI, new UnifiedEntityManager, empty identity map. The previous request's
 * connection is CLOSED — the finance group runs DB harnesses side by side (1040 «Too many connections»).
 */
$wire = static function () use ($base): \Z77\Persistence\Resolver\UnifiedEntityManager {
    gc_collect_cycles();
    static $previous = null;
    if ($previous !== null) {
        foreach ((new \ReflectionProperty($previous, 'managerCache'))->getValue($previous) as $manager) {
            if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
                (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection()->close();
            }
        }
    }
    DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)
        ->set('SessionManager', \Z77\Core\Session\SessionManager::class, true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true)
        // The request doubles of the screen (sections E / S): GET parameters, the fetch body, the entity tokens.
        ->set('Request', fn() => new class {
            public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
            public function isPost(): bool { return false; }
            public function getJsonBody(): array { return $GLOBALS['harnessBody'] ?? []; }
            public function getMode(): \Z77\Core\Http\RequestMode { return \Z77\Core\Http\RequestMode::Page; }
        }, true)
        ->set('CsrfService', fn() => new class {
            public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
            public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
        }, true);
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = DI::getUnifiedEntityManager();
};
$em = $wire();
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
    DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
);
$app->setAutoExit(false);
$migrateOut  = new \Symfony\Component\Console\Output\BufferedOutput();
$migrateCode = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $migrateOut);
check('M1 migrate exits 0 (vat, mandator, financial)', $migrateCode === 0, trim($migrateOut->fetch()));

$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
foreach (['2026', '2027'] as $code) {
    $em = $wire();
    (new FiscalYearService($em))->open(new FiscalYear($code, day("{$code}-01-01"), day("{$code}-12-31")));
}
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Jahresübersicht AG', 'country' => 'CH', 'zip' => '3000', 'city' => 'Bern', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$yearId = static fn(string $code): int => (int) $db->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$code]);
check('M2 the books: the KMU chart, fiscal years 2026 and 2027, a liable mandator (quarter from 01.01.2026) naming 2200 / 1170 / 1171 / 2201 / 1179 / 2209',
    (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year') === 2
    && $db->fetchAssociative('SELECT account_vat_owed, account_vat_input_material, account_vat_settlement, account_vat_input_pending, account_vat_correction_pending FROM mandator WHERE id = 1')
        == ['account_vat_owed' => '2200', 'account_vat_input_material' => '1170', 'account_vat_settlement' => '2201', 'account_vat_input_pending' => '1179', 'account_vat_correction_pending' => '2209']);

// ── the fixture helpers ──────────────────────────────────────────────────

$post = static function (PostingRequest $request) use ($wire): void {
    $em = $wire();
    $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->post($request));
};
$entryNumber = static fn(string $date, string $text): string => (string) $db->fetchOne(
    'SELECT CONCAT(y.code, \'/\', e.number) FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE e.entry_date = ? AND e.text = ?', [$date, $text]);

/** All fields of the CH form, zero, overridden by $figures (field => [exact, base?, lines?]). */
$formFields = [
    '200' => 'total', '220' => 'deduction', '221' => 'deduction', '225' => 'deduction', '230' => 'deduction', '235' => 'reduction', '280' => 'deduction',
    '289' => 'deductions-total', '299' => 'taxable-total', '302' => 'rate', '303' => 'rate', '312' => 'rate', '313' => 'rate', '342' => 'rate', '343' => 'rate',
    '382' => 'acquisition', '383' => 'acquisition', '399' => 'tax-total', '400' => 'input', '405' => 'input', '410' => 'input', '415' => 'input-correction',
    '420' => 'input-correction', '479' => 'input-total', '500' => 'payable', '510' => 'credit',
];
/** Inserts a LIVE posted return with its figures — the way the posting harness does; returns its id. */
$insertReturn = static function (VatPeriod $period, string $postedAt, string $payable, array $figures, ?string $settlementRef = null) use ($db, $formFields): int {
    $db->insert('vat_return', [
        'period_from' => $period->from->format('Y-m-d'), 'period_to' => $period->to->format('Y-m-d'), 'revision' => 1, 'state' => 'posted', 'live' => 1,
        'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
        'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => $payable, 'posted_tax' => '0.00',
        'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => $postedAt, 'figures_fingerprint' => str_repeat('b', 64),
        'voucher_path' => 'data/archive/financial/vat-return/harness-' . $period->from->format('Ymd') . '.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness.pdf',
        'settlement_entry_id' => $settlementRef,
    ]);
    $id = (int) $db->lastInsertId();
    foreach ($formFields as $field => $kind) {
        [$exact, $base, $lines] = ($figures[$field] ?? []) + ['0.000000', in_array($kind, ['rate', 'acquisition'], true) ? '0.00' : null, 0];
        $db->insert('vat_return_figure', [
            'vat_return_id' => $id, 'field' => $field, 'kind' => $kind, 'value_exact' => $exact,
            'value_rounded' => ExactAmount::fromDecimal($exact, 'CHF')->rounded()->toDecimal(), 'base' => $base, 'line_count' => $lines,
        ]);
    }

    return $id;
};
/** Inserts the stored block rows of a carrying return for one corrected return — the shape `VatReturnPostingService::write()` stores. */
$insertBlock = static function (int $carrying, int $corrected, array $entries, array $rows) use ($db): void {
    $period = $db->fetchAssociative('SELECT period_from, period_to FROM vat_return WHERE id = ?', [$corrected]);
    foreach (array_values($rows) as $position => [$field, $kind, $original, $correction, $bases]) {
        $db->insert('vat_return_correction', [
            'vat_return_id' => $carrying, 'corrected_return_id' => $corrected, 'corrected_from' => $period['period_from'], 'corrected_to' => $period['period_to'],
            'position' => $position, 'field' => $field, 'kind' => $kind, 'original_exact' => $original, 'earlier_exact' => '0.000000', 'correction_exact' => $correction,
            'original_base' => $bases[0] ?? null, 'earlier_base' => $bases === null ? null : '0.00', 'correction_base' => $bases[1] ?? null,
            'entries' => json_encode($entries),
        ]);
    }
};
/** The overview of a year on a fresh request. */
$overview    = static fn(int $year): VatYearOverview => (new VatYearService($wire()))->overview($year);
$fingerprint = static fn(int $year): string => (new VatYearService($wire()))->fingerprint($year);
$field       = static fn(VatYearOverview $o, string $f): array => [
    $o->field($f)?->declared->toDecimal(), $o->field($f)?->declaredBase?->toDecimal(), $o->field($f)?->corrections->toDecimal(), $o->field($f)?->newValue()->rounded()->toDecimal(),
];
$items  = static fn(VatYearOverview $o): array => array_map(static fn(VatYearTurnoverItem $i): string => ($i->letter ?? '-') . ' ' . $i->kind . ($i->accountNumber !== '' ? ' ' . $i->accountNumber : '') . ' ' . ($i->amount?->toDecimal() ?? 'by hand') . ($i->counted ? '' : ' (info)'), $o->turnover->items);
$account = static function (VatYearOverview $o, string $number): ?array {
    foreach ($o->accounts as $a) {
        if ($a->accountNumber === $number) {
            return [$a->movement->toDecimal(), $a->expected->toDecimal(), $a->remainder()->toDecimal(), $a->declared->toDecimal(), $a->difference()->toDecimal(), implode(',', $a->fields)];
        }
    }

    return null;
};
$codes = static fn(VatYearOverview $o): array => array_map(static fn(VatReturnFinding $f): string => ($f->blocking ? 'BLOCKING ' : '') . $f->code . ' ' . $f->amount?->toDecimal(), $o->findings);

// ─────────────────────────────────────────────────────────────────────────
echo "Y. Read goldens on fixture rows\n";

// The four quarters of 2026: an invoice UN 8.1 % base 1000.06 (tax exact 81.00486, posted 81.00), input tax VM 40.00; each settled.
$returnIds = [];
foreach ([1, 2, 3, 4] as $q) {
    $mid = sprintf('2026-%02d-15', ($q - 1) * 3 + 2);
    $post(PostingRequest::manual(day($mid), "Verkauf Q{$q}", [dr('1000', '1081.06'), cr('3200', '1000.06', 'UN', 810, '1000.06', '81.00'), cr('2200', '81.00')]));
    $post(PostingRequest::manual(day($mid), "Einkauf Q{$q}", [dr('4000', '493.83', 'VM', 810, '493.83', '40.00'), dr('1170', '40.00'), cr('1000', '533.83')]));
}
$settle = static function (int $q) use ($post, $insertReturn, $db, &$returnIds): void {
    $period = quarter(2026, $q);
    $post(PostingRequest::generated($period->to, "MWST-Abrechnung Q{$q} 2026", 'vat-return', "2026-q{$q}", "vat-return:2026-q{$q}", [dr('2200', '81.00'), cr('1170', '40.00'), cr('2201', '41.00')]));
    $settlement = (string) $db->fetchOne('SELECT id FROM journal_entry WHERE idempotency_key = ?', ["vat-return:2026-q{$q}"]);
    $returnIds[$q] = $insertReturn($period, $period->to->modify('+20 days')->format('Y-m-d') . ' 10:00:00', '41.00', [
        '200' => ['1000.060000', null, 1], '299' => ['1000.060000'], '303' => ['81.004860', '1000.06', 1], '399' => ['81.004860'],
        '400' => ['40.000000', null, 1], '479' => ['40.000000'], '500' => ['41.004860'],
    ], $settlement);
};
foreach ([1, 2, 3] as $q) {
    $settle($q);
}
foreach ([1, 2, 3] as $q) {   // the payments of the first three quarters
    $post(PostingRequest::manual(VatYearDeadlines::dueDate(quarter(2026, $q)), "Zahlung MWST Q{$q}", [dr('2201', '41.00'), cr('1000', '41.00')]));
}

$o = $overview(2026);
check('Y1a «vorläufig» while the 4th quarter is open: not final, unposted = [4. Quartal 2026]; its turnover is the item «noch nicht gebucht» as computed now (1000.06) — the remainder stays 0.00; declared counts the three posted returns only (3000.18)',
    !$o->final && array_map(static fn(VatPeriod $p): string => $p->label(), $o->unposted()) === ['4. Quartal 2026']
    && $o->turnover->declared->toDecimal() === '3000.18' && in_array('- open-periods 1000.06', $items($o), true) && $o->turnover->remainder()->toDecimal() === '0.00',
    json_encode([$o->turnover->declared->toDecimal(), $items($o), $o->turnover->remainder()->toDecimal()]));

$settle(4);
$o   = $overview(2026);
$fp1 = $fingerprint(2026);
check('Y1b the nil year 2026 (golden Y1): four periods posted, due 30.05. / 29.08. / 29.11.2026 / 01.03.2027; deadlines 29.06.2027 / 28.08.2027; no correction («Keine Korrekturen»), nothing pending; the fiscal year 2026 open → still not final',
    !$o->final && $o->unposted() === [] && !$o->hasCorrections() && $o->pending === [] && $o->deadlines->known
    && array_map(static fn(VatYearPeriod $p): string => $p->due->format('d.m.Y'), $o->periods) === ['30.05.2026', '29.08.2026', '29.11.2026', '01.03.2027']
    && $d($o->deadlines->day180()) === '29.06.2027' && $d($o->deadlines->day240()) === '28.08.2027'
    && $o->yearEndFiscalYear?->getCode() === '2026' && array_map(static fn(FiscalYear $y): string => $y->getCode(), $o->fiscalYears) === ['2026']);
check('Y1c the sum per field — stored figures, nothing recomputed: 200 4000.24; 303 tax 324.019440 exact → 324.02, base 4000.24; 399 324.019440; 400 / 479 160.00; the payable amount 164.00 = Σ AS POSTED, never round(4 × 41.00486) = 164.02 (no invented Rappen); every correction 0',
    $field($o, '200') === ['4000.240000', null, '0.000000', '4000.24'] && $field($o, '303') === ['324.019440', '4000.24', '0.000000', '324.02']
    && $field($o, '399') === ['324.019440', null, '0.000000', '324.02'] && $field($o, '400') === ['160.000000', null, '0.000000', '160.00']
    && $field($o, '479') === ['160.000000', null, '0.000000', '160.00'] && $field($o, '500') === ['164.000000', null, '0.000000', '164.00']
    && $o->field('510') === null && $o->payables->toDecimal() === '164.00' && $o->rounding->toDecimal() === '0.00',
    json_encode([$field($o, '200'), $field($o, '303'), $field($o, '399'), $field($o, '400'), $field($o, '500')]));
check('Y1d the turnover of 2026: 3200 moved 4000.24 = declared (200 − 235) 4000.24, remainder 0.00; the Art. 128 lines j / b / d at 0.00, h 0.00 for information, c / e / f / g «von Hand prüfen» without amount; «später gemeldet» 0.00, «neu deklariert» 4000.24',
    $o->turnover->revenueTotal->toDecimal() === '4000.24' && $o->turnover->declared->toDecimal() === '4000.24' && $o->turnover->remainder()->toDecimal() === '0.00'
    && $items($o) === ['j uncoded 0.00', 'b coded-expense 0.00', 'd fixed-asset 0.00', 'h reduction 0.00 (info)', 'c by-hand by hand (info)', 'e by-hand by hand (info)', 'f by-hand by hand (info)', 'g by-hand by hand (info)']
    && $o->turnover->laterReported->toDecimal() === '0.00' && $o->turnover->correctedDeclared->toDecimal() === '4000.24',
    json_encode($items($o)));
check('Y1e the VAT accounts of 2026 (settlement entries left out): 2200 moved 324.00 = the tax of the coded lines 324.00 = declared Σ 399 as posted 4 × 81.00, rounding 0.00, remainder 0.00; 1170 160.00 = 160.00 = 400; 1171 nothing; no finding',
    $account($o, '2200') === ['324.00', '324.00', '0.00', '324.00', '0.00', '399'] && $account($o, '1170') === ['160.00', '160.00', '0.00', '160.00', '0.00', '400']
    && $account($o, '1171') !== null && $account($o, '1171')[2] === '0.00' && $o->accounts[0]->accountNumber === '2200' && $codes($o) === [],
    json_encode([$account($o, '2200'), $account($o, '1170'), $account($o, '1171'), $codes($o)]));
check('Y1f the balances per 31.12.2026: 2201 −41.00 (the 4th quarter\'s payable, owed — statement sign) after three payments; 1179 0.00; 2209 0.00, open 0.00',
    $o->settlement?->accountNumber === '2201' && $o->settlement->balance->toDecimal() === '-41.00'
    && $o->inputPending?->balance->toDecimal() === '0.00' && $o->correctionPending?->accountNumber === '2209'
    && $o->correctionPending->balance->toDecimal() === '0.00' && $o->correctionPending->open?->toDecimal() === '0.00',
    json_encode([$o->settlement?->balance->toDecimal(), $o->inputPending?->balance->toDecimal(), $o->correctionPending?->balance->toDecimal()]));
check('Y1g the fingerprint is stable: a second request over unchanged books gives the same SHA-256',
    preg_match('/^[0-9a-f]{64}$/', $fp1) === 1 && $fingerprint(2026) === $fp1);

// Case B: a forgotten cash sale of 15.08.2026, posted WITHOUT a code into the settled 3rd quarter.
$post(PostingRequest::manual(day('2026-08-15'), 'Barverkauf vergessen', [dr('1000', '540.00'), cr('3200', '540.00')]));
$fp2 = $fingerprint(2026);
$o   = $overview(2026);
check('Y2a one posting changes the fingerprint (B-5); the overview shows it: 3200 4540.24, j +540.00 on 3200 explains it (remainder 0.00), and the information «Ertrag ohne MWST-Code» (a warning, never blocking)',
    $fp2 !== $fp1 && $o->turnover->revenueTotal->toDecimal() === '4540.24' && in_array('j uncoded 3200 540.00', $items($o), true)
    && $o->turnover->remainder()->toDecimal() === '0.00' && $codes($o) === ['uncoded-revenue 540.00'],
    json_encode([$items($o), $codes($o)]));

// The year end (C-E step 1): on 31.12.2026 the VAT part to 2209, without a code.
$post(PostingRequest::manual(day('2026-12-31'), 'MWST auf Barverkauf vergessen', [dr('3200', '40.46'), cr('2209', '40.46')]));
// The correction entry dated 22.02.2027 (1st quarter 2027, not posted), «Korrigiert Periode: 3. Quartal 2026».
$post(PostingRequest::manual(day('2027-02-22'), 'Korrektur Barverkauf August', [
    dr('3200', '499.54'), dr('2209', '40.46'), cr('3200', '499.54', 'UN', 810, '499.54', '40.46'), cr('2200', '40.46'),
], quarter(2026, 3)));
$corrRef = $entryNumber('2027-02-22', 'Korrektur Barverkauf August');
$o       = $overview(2026);
$pending = $o->pending[0] ?? null;
check('Y2b «erfasst, noch nicht abgerechnet» (small rule 5): the correction entry ' . $corrRef . ' for the 3rd quarter 2026 comes with the 1st quarter 2027 — 200 / 303 base +499.54, tax +40.46274 exact, payable +40.46274; NOT in the sum per field, no reported correction yet',
    count($o->pending) === 1 && $pending->carryingPeriod->label() === '1. Quartal 2027' && $pending->block->period->label() === '3. Quartal 2026'
    && $pending->block->entryRefs() === $corrRef && $pending->block->row('200')['correction']->toDecimal() === '499.540000'
    && $pending->block->row('303')['correction']->toDecimal() === '40.462740' && $pending->block->row('303')['correctionBase']->toDecimal() === '499.54'
    && $pending->block->row('500')['correction']->toDecimal() === '40.462740'
    && !$o->hasCorrections() && $field($o, '303')[2] === '0.000000',
    json_encode([count($o->pending), $pending?->carryingPeriod->label(), $pending?->block->entryRefs(), $pending?->block->rows === null ? null : array_column($pending->block->rows, 'field')]));
check('Y2c 2026 after the year end: 3200 4499.78 (4000.24 + 540.00 − 40.46), j 499.54, remainder 0.00; B-1 the line 2209 «Saldo 31.12.2026» −40.46 and still OPEN today 0.00 (the correction entry of 22.02.2027 cleared it)',
    $o->turnover->revenueTotal->toDecimal() === '4499.78' && in_array('j uncoded 3200 499.54', $items($o), true) && $o->turnover->remainder()->toDecimal() === '0.00'
    && $o->correctionPending->balance->toDecimal() === '-40.46' && $o->correctionPending->open->toDecimal() === '0.00',
    json_encode([$o->turnover->revenueTotal->toDecimal(), $o->correctionPending?->balance->toDecimal(), $o->correctionPending?->open?->toDecimal()]));

// The 1st quarter 2027 posted on 25.05.2027, carrying the block (the stored rows as the posting service writes them).
$q1y27 = $insertReturn(quarter(2027, 1), '2027-05-25 09:00:00', '40.46', []);
$insertBlock($q1y27, $returnIds[3], [['ref' => $corrRef, 'date' => '2027-02-22']], [
    ['200', 'total', '1000.060000', '499.540000', null],
    ['299', 'taxable-total', '1000.060000', '499.540000', null],
    ['303', 'rate', '81.004860', '40.462740', ['1000.06', '499.54']],
    ['399', 'tax-total', '81.004860', '40.462740', null],
    ['500', 'payable', '41.004860', '40.462740', null],
]);
$fp3 = $fingerprint(2026);
$o   = $overview(2026);
$c   = $o->corrections[0] ?? null;
check('Y3a case B reported: «Korrekturen für 2026» — 3rd quarter 2026, ' . $corrRef . ', «gemeldet mit Abrechnung 1. Quartal 2027 am 25.05.2027 — vor dem 240. Tag», in a LATER year; nothing pending; the fingerprint changed',
    count($o->corrections) === 1 && $c->block->period->label() === '3. Quartal 2026' && $c->carryingReturn->period()->label() === '1. Quartal 2027'
    && $c->reportedOn->format('d.m.Y') === '25.05.2027' && $c->beforeDay240 === true && $c->laterYear && $c->block->entryRefs() === $corrRef
    && $c->block->row('303')['new']->toDecimal() === '121.467600' && $o->pending === [] && $fp3 !== $fp2,
    json_encode([count($o->corrections), $c?->reportedOn->format('d.m.Y'), $c?->beforeDay240, count($o->pending)]));
check('Y3b the sum per field = stored figures + the block: 200 4000.24 + 499.54 = 4499.78; 303 tax 324.01944 + 40.46274 = 364.48218 → 364.48 (rounded once), base 4499.78; 399 likewise; payable 164.00 as posted + 40.46274 → 204.46 («Differenz Zahllast» 40.46); 400 unchanged',
    $field($o, '200') === ['4000.240000', null, '499.540000', '4499.78'] && $field($o, '303') === ['324.019440', '4000.24', '40.462740', '364.48']
    && $o->field('303')->newBase()->toDecimal() === '4499.78' && $field($o, '399') === ['324.019440', null, '40.462740', '364.48']
    && $field($o, '500') === ['164.000000', null, '40.462740', '204.46'] && $o->field('500')->corrections->rounded()->toDecimal() === '40.46'
    && $field($o, '400') === ['160.000000', null, '0.000000', '160.00'],
    json_encode([$field($o, '200'), $field($o, '303'), $field($o, '399'), $field($o, '500')]));
check('Y3c the turnover of 2026 beside the correction (z77 ties no uncoded line to it): remainder 0.00 as before; «Korrekturen für 2026, später gemeldet» 499.54; «Neu deklariert 2026» 4499.78',
    $o->turnover->remainder()->toDecimal() === '0.00' && $o->turnover->laterReported->toDecimal() === '499.54' && $o->turnover->correctedDeclared->toDecimal() === '4499.78');

$o27 = $overview(2027);
check('Y3d the overview 2027: the correction entry is the item «Korrekturbuchungen für frühere Steuerjahre — nicht im Formular 2027» +499.54 and its uncoded line −499.54, remainder 0.00; 2200 moved 40.46 = coded tax 40.46 = declared (the block of the 1st quarter 2027) → rounding 0.00; the 2027 periods 2–4 not posted → «vorläufig»',
    in_array('- correction-earlier-year 499.54', $items($o27), true) && in_array('- correction-uncoded -499.54', $items($o27), true)
    && $o27->turnover->remainder()->toDecimal() === '0.00' && $account($o27, '2200') === ['40.46', '40.46', '0.00', '40.46', '0.00', '399']
    && !$o27->final && count($o27->unposted()) === 3 && !$o27->hasCorrections() && $codes($o27) === [],
    json_encode([$items($o27), $account($o27, '2200'), $codes($o27)]));

// Case C: found 15.11.2027, after day 240 — the 2nd and 3rd quarter 2027 posted (nil), the correction entry in the 4th, reported 20.02.2028.
$insertReturn(quarter(2027, 2), '2027-08-20 09:00:00', '0.00', []);
$insertReturn(quarter(2027, 3), '2027-11-10 09:00:00', '0.00', []);
$post(PostingRequest::manual(day('2027-11-15'), 'Korrektur Barverkauf Mai 2026', [
    dr('3200', '1081.00'), cr('3200', '1000.00', 'UN', 810, '1000.00', '81.00'), cr('2200', '81.00'),
], quarter(2026, 2)));
$corrRefC = $entryNumber('2027-11-15', 'Korrektur Barverkauf Mai 2026');
$q4y27    = $insertReturn(quarter(2027, 4), '2028-02-20 09:00:00', '81.00', []);
$insertBlock($q4y27, $returnIds[2], [['ref' => $corrRefC, 'date' => '2027-11-15']], [
    ['200', 'total', '1000.060000', '1000.000000', null],
    ['299', 'taxable-total', '1000.060000', '1000.000000', null],
    ['303', 'rate', '81.004860', '81.000000', ['1000.06', '1000.00']],
    ['399', 'tax-total', '81.004860', '81.000000', null],
    ['500', 'payable', '41.004860', '81.000000', null],
]);
$o = $overview(2026);
check('Y4a case C: two blocks for 2026, in order of the corrected period — 2nd quarter (' . $corrRefC . ', reported 20.02.2028, «nach dem 240. Tag») before 3rd quarter (before); the sum: 200 +1499.54 → 5499.78, 303 tax +121.46274 → 445.48, payable +121.46274 → 285.46',
    array_map(static fn($x): string => $x->block->period->label() . ' ' . $x->reportedOn->format('d.m.Y') . ' ' . var_export($x->beforeDay240, true), $o->corrections)
        === ['2. Quartal 2026 20.02.2028 false', '3. Quartal 2026 25.05.2027 true']
    && $field($o, '200') === ['4000.240000', null, '1499.540000', '5499.78'] && $field($o, '303') === ['324.019440', '4000.24', '121.462740', '445.48']
    && $field($o, '500') === ['164.000000', null, '121.462740', '285.46'] && $o->turnover->laterReported->toDecimal() === '1499.54'
    && $o->turnover->remainder()->toDecimal() === '0.00',
    json_encode([$field($o, '200'), $field($o, '303'), $field($o, '500')]));
$o27 = $overview(2027);
check('Y4b the overview 2027 after case C: both correction entries «für frühere Steuerjahre» (+1499.54 coded, −1580.54 uncoded), remainder 0.00; 2200 moved 121.46 = coded 121.46 = declared by the two blocks; every period posted, the fiscal year 2027 open → not final',
    in_array('- correction-earlier-year 1499.54', $items($o27), true) && in_array('- correction-uncoded -1580.54', $items($o27), true)
    && $o27->turnover->remainder()->toDecimal() === '0.00' && $account($o27, '2200') === ['121.46', '121.46', '0.00', '121.46', '0.00', '399']
    && $o27->unposted() === [] && !$o27->final,
    json_encode([$items($o27), $account($o27, '2200')]));

// B-4 on the database: close the fiscal year 2026 (its months, as the close leaves them).
$before   = (new VatYearService($wire()))->isFinal(2026);
$fpOpen   = $fingerprint(2026);
$db->executeStatement("UPDATE fiscal_period SET state = 'closed' WHERE fiscal_year_id = ?", [$yearId('2026')]);
$after    = (new VatYearService($wire()))->isFinal(2026);
$fpClosed = $fingerprint(2026);
check('Y5 B-4 on the books: 2026 with every period posted is «vorläufig» while its fiscal year is open, FINAL once it is closed — and the fingerprint follows the state (the overview shows it); 2027 (fiscal year open) stays «vorläufig»',
    $before === false && $after === true && $overview(2026)->final && $fpOpen !== $fpClosed && $fpOpen !== $fp3 && (new VatYearService($wire()))->isFinal(2027) === false);

// Red, never blocking: a posting past the lock straight into the database (2nd quarter 2026).
$db->insert('journal_entry', [
    'fiscal_year_id' => $yearId('2026'), 'number' => 9001, 'entry_date' => '2026-05-10', 'text' => 'Direkt in der Datenbank', 'kind' => 'manual',
    'created_by' => 'harness', 'created_at' => '2026-05-10 12:00:00', 'version' => 1,
]);
$raw = (int) $db->lastInsertId();
$acc = static fn(string $n): int => (int) $db->fetchOne('SELECT id FROM account WHERE number = ?', [$n]);
foreach ([[1, '1000', '165.00', '0.00', null, null, null], [2, '1179', '5.00', '0.00', null, null, null], [3, '2200', '0.00', '120.00', null, null, null], [4, '3200', '0.00', '50.00', 'UN', '0.00', '0.00']] as [$pos, $n, $debit, $credit, $code, $base, $tax]) {
    $db->insert('journal_line', ['entry_id' => $raw, 'account_id' => $acc($n), 'position' => $pos, 'debit' => $debit, 'credit' => $credit,
        'tax_code' => $code, 'tax_rate' => $code === null ? null : 810, 'tax_base' => $base, 'tax_amount' => $tax]);
}
$o = $overview(2026);
check('Y6 a leak past the lock shows RED and blocks nothing (small rule 3): 2200 moved 444.00 against coded 324.00 — remainder 120.00; turnover remainder 50.00 (a coded line whose base is not its amount); 1179 stands at 5.00 on 31.12.; three red findings beside the uncoded-revenue information, none blocking; the overview stays final',
    $account($o, '2200')[2] === '120.00' && $o->turnover->remainder()->toDecimal() === '50.00' && $o->inputPending->balance->toDecimal() === '5.00'
    && $codes($o) === ['uncoded-revenue 499.54', 'vat-account-remainder 120.00', 'turnover-remainder 50.00', 'input-pending-balance 5.00']
    && array_filter($o->findings, static fn(VatReturnFinding $f): bool => $f->blocking) === [] && $o->final,
    json_encode([$account($o, '2200'), $o->turnover->remainder()->toDecimal(), $codes($o)]));

check('Y7 nothing was written by reading: no vat_return, figure, block row or journal entry more than the fixture made',
    (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return') === 8 && (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return_correction') === 10
    && (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry') === 8 + 4 + 3 + 1 + 1 + 1 + 1 + 1,
    json_encode([$db->fetchOne('SELECT COUNT(*) FROM vat_return'), $db->fetchOne('SELECT COUNT(*) FROM vat_return_correction'), $db->fetchOne('SELECT COUNT(*) FROM journal_entry')]));


// ─────────────────────────────────────────────────────────────────────────
echo "D. The stored overview's table (stage S7 slice 7.5)\n";

$dem      = \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager();
$schemaUp = array_values(array_filter((new \Doctrine\ORM\Tools\SchemaTool($dem))->getUpdateSchemaSql([$dem->getClassMetadata(StoredVatYearOverview::class)]),
    static fn(string $sql): bool => str_contains($sql, 'vat_year_overview')));
$uniques  = $db->fetchFirstColumn('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> ? GROUP BY INDEX_NAME', [$dbName, 'vat_year_overview', 'PRIMARY']);
$indexes  = $db->fetchFirstColumn('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME <> ?', [$dbName, 'vat_year_overview', 'PRIMARY']);
check('D1 `vat_year_overview` (Version20261128100000) agrees with the mapping of StoredVatYearOverview — the schema tool proposes nothing for it; utf8mb4_unicode_ci; the index on the tax year and NO unique key (owner B-5: stored again, every storing stays)',
    $schemaUp === [] && $uniques === [] && $indexes === ['idx_vat_year_overview_year']
    && $db->fetchOne('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, 'vat_year_overview']) === 'utf8mb4_unicode_ci',
    json_encode([$schemaUp, $uniques, $indexes]));

// ─────────────────────────────────────────────────────────────────────────
echo "E. End to end: the tax year 2028 on rows the REAL «MWST buchen» wrote\n";

$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2028', day('2028-01-01'), day('2028-12-31')));
foreach ([1, 2, 3, 4] as $q) {
    $mid = sprintf('2028-%02d-15', ($q - 1) * 3 + 2);
    $post(PostingRequest::manual(day($mid), "Verkauf 2028 Q{$q}", [dr('1000', '2162.03'), cr('3200', '2000.03', 'UN', 810, '2000.03', '162.00'), cr('2200', '162.00')]));
    $post(PostingRequest::manual(day($mid), "Einkauf 2028 Q{$q}", [dr('4000', '617.28', 'VM', 810, '617.28', '50.00'), dr('1170', '50.00'), cr('1000', '667.28')]));
}
$poster      = static fn(): \Z77\Module\Financial\VatReturn\VatReturnPostingService => new \Z77\Module\Financial\VatReturn\VatReturnPostingService($wire(), 'harness', new \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks([]));
$postQuarter = static function (int $q) use ($poster): void {
    $period = quarter(2028, $q);
    $plan   = $poster()->plan($period, day('2029-02-15'));
    $poster()->post($period, $plan->figuresFingerprint, $plan->warningsFingerprint, day('2029-02-15'));
};
foreach ([1, 2, 3] as $q) {
    $postQuarter($q);
}
$stored303 = static fn(): string => (string) $db->fetchOne("SELECT SUM(f.value_exact) FROM vat_return_figure f JOIN vat_return r ON r.id = f.vat_return_id WHERE r.live = 1 AND r.period_to BETWEEN '2028-01-01' AND '2028-12-31' AND f.field = '303'");
$storedPay = static fn(): string => (string) $db->fetchOne("SELECT SUM(r.payable) FROM vat_return r WHERE r.live = 1 AND r.period_to BETWEEN '2028-01-01' AND '2028-12-31'");
$o28 = $overview(2028);
check('E1 three quarters of 2028 posted by «MWST buchen» (VatReturnPostingService): «vorläufig» — 4th quarter not posted, the fiscal year 2028 open; the sum per field = the STORED rows the service wrote (303 exact ' . $stored303() . '), the payable amounts as posted (' . $storedPay() . ')',
    !$o28->final && count($o28->unposted()) === 1 && $o28->field('303')?->declared->toDecimal() === ExactAmount::fromDecimal($stored303(), 'CHF')->toDecimal()
    && $o28->payables->toDecimal() === Money::fromDecimal($storedPay(), 'CHF')->toDecimal() && $o28->payables->toDecimal() === '336.00',
    json_encode([$o28->field('303')?->declared->toDecimal(), $stored303(), $o28->payables->toDecimal()]));

// ─────────────────────────────────────────────────────────────────────────
echo "S. The screen: «MWST-Jahresübersicht» through the trait and the real templates\n";

/** Renders a module-financial template with a partial() that resolves the module's own tree and the kernel's shared partials. */
$renderer = new class($fin . '/res/view/templates/', $root . '/packages/kernel/shared/res/view/templates/') {
    public function __construct(private string $dir, private string $sharedDir) {}
    public function partial(string $path, array $context = [], ?string $ns = null): string
    {
        $dir = $ns === 'Z77\\Shared' ? $this->sharedDir : $this->dir;
        return (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })->call($this, $dir . $path . '.tpl.php', $context);
    }
};
/** A host double of the trait: context, sections, help, bytes, redirect; the writer stores as «pru» under the harness root. */
$yHost = static function () {
    return new class {
        use VatYearControllerTrait {
            listAction as public; correctionsAction as public; turnoverAction as public; inputAction as public;
            pdfAction as public; voucherAction as public; confirmStoreAction as public; storeAction as public;
        }
        public array $context = [];
        public ?array $bytesOut = null;
        public ?string $redirectTo = null;
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public function __construct()
        {
            $this->help = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function bytes(string $content, string $filename, string $mimeType, bool $inline = true): \Z77\Core\Http\Response\BytesResponse { $this->bytesOut = [$content, $filename, $mimeType]; return new \Z77\Core\Http\Response\BytesResponse($content, $filename, $mimeType, $inline); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        protected function vatYearStore(): YearOverviewStore { return new YearOverviewStore($this->em(), 'pru', ABS_BASE_PATH); }
    };
};
/** One action: [host, the rendered sections by name]; a page action renders the layout's pin `page` as main. */
$yPage = static function (string $action, array $get) use ($wire, $yHost, $renderer): array {
    $wire();
    $_GET     = $get;
    $_SESSION = [];
    $h        = $yHost();
    $h->{$action . 'Action'}();
    $out = [];
    foreach ($h->layoutManager->sections as $section => $partials) {
        $out[$section] = implode('', array_map(static fn(string $p): string => $renderer->partial($p, $h->context), $partials));
    }
    if (in_array($action, ['list', 'corrections', 'turnover', 'input'], true)) {
        $out['main'] = $renderer->partial('Backend/VatYearController/page', $h->context);
    }

    return [$h, $out];
};
/** The window's POST: [host, the fetch envelope]. */
$yStore = static function (array $body) use ($wire, $yHost): array {
    $wire();
    $GLOBALS['harnessBody'] = $body;
    $h   = $yHost();
    $env = (fn() => $this->build())->call($h->storeAction());
    $GLOBALS['harnessBody'] = [];

    return [$h, $env];
};
$storedCount = static fn(): int => (int) $db->fetchOne('SELECT COUNT(*) FROM vat_year_overview');
$booksCount  = static fn(): array => [(int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry'), (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return'), (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return_log')];

[$h, $out] = $yPage('corrections', ['year' => '2028']);
$main = $out['main'];
check('S1 «vorläufig» (4th quarter 2028 open): the head «MWST-Jahresübersicht 2028» with the badge «vorläufig» and why («4. Quartal 2028 nicht gebucht · Geschäftsjahr 2028 offen»), the tools «PDF» but NO «Ablegen …» (B-4); the action cell EMPTY; tabs in the toolbar, the crumb «… › MWST-Abrechnungen › Jahresübersicht 2028», the year selection in the rail; the help in the top bar',
    str_contains($main, 'MWST-Jahresübersicht 2028</h2>') && str_contains($main, 'data-vat-year-state="provisional">vorläufig<')
    && str_contains($main, '4. Quartal 2028 nicht gebucht · Geschäftsjahr 2028 offen') && str_contains($out['hc2'] ?? '', 'data-vat-year-pdf') && !str_contains($out['hc2'] ?? '', 'data-vat-year-store')
    && empty($h->layoutManager->sections['hc1']) && str_contains($out['hc3'] ?? '', 'href="/backend/finance/vat-return/list">MWST-Abrechnungen</a>') && str_contains($out['hc3'] ?? '', 'Jahresübersicht 2028</span>')
    && ($h->layoutManager->sections['railSelect'] ?? []) === ['Backend/partials/fiscalYearSelect'] && str_contains($out['railSelect'] ?? '', 'href="/backend/finance/vat-year/corrections?year=2028" aria-current="true"')
    && $h->help->has() && !$h->help->inToolbar() && $h->help->title() === 'Hilfe: MWST-Jahresübersicht',
    json_encode(array_keys($h->layoutManager->sections)));
[$hw, $outW] = $yPage('confirmStore', ['year' => '2028']);
[$hp]        = $yPage('pdf', ['year' => '2028']);
$beforeBooks = $booksCount();
[$hs, $env]  = $yStore(['tax_year' => 2028, 'fingerprint' => (new VatYearService($wire()))->fingerprint(2028), 'entity_csrf' => 'tok-vatYear.store-2028']);
check('S2 while «vorläufig»: the window answers «Ablegen nicht möglich» with the reason; a POST anyway is REFUSED in the window\'s slot (nothing stored); «PDF» prints it, «VORLÄUFIG» in the file name',
    str_contains($outW['main'] ?? '', 'Ablegen nicht möglich') && str_contains($outW['main'] ?? '', 'ist vorläufig (4. Quartal 2028 nicht gebucht')
    && ($env['status'] ?? '') === 'error' && str_contains(json_encode($env, JSON_UNESCAPED_UNICODE), 'data-vat-year-store-refusal') && str_contains(json_encode($env, JSON_UNESCAPED_UNICODE), 'ist vorläufig')
    && $storedCount() === 0 && str_starts_with((string) ($hp->bytesOut[0] ?? ''), '%PDF') && ($hp->bytesOut[1] ?? '') === 'mwst-jahresuebersicht-2028-vorlaeufig.pdf' && $booksCount() === $beforeBooks,
    json_encode([$env, $hp->bytesOut[1] ?? null], JSON_UNESCAPED_UNICODE));

$postQuarter(4);
$db->executeStatement("UPDATE fiscal_period SET state = 'closed' WHERE fiscal_year_id IN (?, ?)", [$yearId('2027'), $yearId('2028')]);
$o28 = $overview(2028);
check('E2 every quarter of 2028 posted and the fiscal years 2027 / 2028 closed → FINAL; the payable amounts summed as posted (448.00 = Σ vat_return.payable), 303 = Σ of the stored exact figures (648.00972 → 648.01), no remainder',
    $o28->final && $o28->payables->toDecimal() === '448.00' && $storedPay() === '448.00' && $o28->field('303')->declared->toDecimal() === '648.009720'
    && $o28->field('303')->newValue()->rounded()->toDecimal() === '648.01' && $o28->turnover->remainder()->isZero()
    && array_filter($o28->accounts, static fn($a): bool => !$a->remainder()->isZero()) === [],
    json_encode([$o28->payables->toDecimal(), $o28->field('303')?->declared->toDecimal(), $codes($o28)]));

[$h, $out] = $yPage('corrections', ['year' => '2028']);
$main = $out['main'];
check('S3 tab «Korrekturen», final: no badge yet, «Geschäftsjahr 2028 abgeschlossen», «Stand …», the deadlines 29.06.2029 / 28.08.2029; «Ablegen …» offered (window by fetch); the four returns linked to their pages with «Gebucht am», the entry, «fällig bis»; total 448.00; «Keine Korrekturen — keine Jahresabstimmung einzureichen»; «Erfasst, noch nicht abgerechnet» — keine',
    !str_contains($main, 'data-vat-year-state=') && str_contains($main, 'Geschäftsjahr 2028 abgeschlossen') && str_contains($main, '180. Tag 29.06.2029 · 240. Tag 28.08.2029')
    && str_contains($out['hc2'], 'data-fetch-get="/backend/finance/vat-year/confirm-store?year=2028"') && str_contains($out['hc2'], '>Ablegen …</button>')
    && substr_count($main, 'data-vat-year-period=') === 4 && str_contains($main, '<a href="/backend/finance/vat-return/form?from=2028-04-01">2. Quartal 2028</a>')
    && str_contains($main, '01.03.2029') && str_contains($main, '448.00') && str_contains($main, 'Keine Korrekturen — keine Jahresabstimmung einzureichen')
    && preg_match('/data-vat-year-pending>.*?<p class="be-list__empty">keine<\/p>/s', $main) === 1 && !str_contains($main, 'data-vat-year-changed'));
check('S4 B-2 on the page: «Summe pro Ziffer» shows 303 with its base 8\'000.12 and the tax 648.01, the exact value 648.00972 small beneath; a whole amount (400 200.00) carries none; the payable row «Zahllast — Summe der gebuchten Abrechnungen» 448.00',
    preg_match('/data-vat-year-field="303".*?8&apos;000\.12.*?648\.01<br><small class="be-list__cell--muted" data-exact>648\.00972<\/small>/s', $main) === 1
    && preg_match('/data-vat-year-field="400"(.*?)<\/div>/s', $main, $m400) === 1 && str_contains($m400[1], '200.00') && !str_contains($m400[1], 'data-exact')
    && str_contains($main, 'Zahllast — Summe der gebuchten Abrechnungen'),
    preg_match('/data-vat-year-field="303".{0,900}/s', $main, $m303) ? $m303[0] : '');
[$h, $out] = $yPage('turnover', ['year' => '2028']);
$main = $out['main'];
check('S5 tab «Umsatz»: 3200 8\'000.12 linked to its account statement of 2028, «Total Ertrag 2028», «Deklariert …» 8\'000.12, the letters with «von Hand prüfen» (c, e, f, g), «Unerklärter Rest» 0.00 not red; «Neu deklariert 2028»',
    str_contains($main, 'href="/backend/finance/report/account-statement?year=2028&amp;from=2028-01-01&amp;to=2028-12-31&amp;account=3200">3200</a>')
    && str_contains($main, 'Total Ertrag 2028') && substr_count($main, 'von Hand prüfen') === 4 && str_contains($main, 'data-vat-year-remainder="0"')
    && str_contains($main, 'Neu deklariert 2028') && str_contains($h->layoutManager->sections['hc2'][0], 'tabs') && str_contains($out['hc2'], 'is-active" href="/backend/finance/vat-year/turnover?year=2028" aria-current="page">Umsatz</a>'));
[$h, $out] = $yPage('input', ['year' => '2028']);
$main = $out['main'];
check('S6 tab «Vorsteuer»: the VAT accounts 2200 / 1170 with their anchors, declared 648.00 / 200.00, remainder 0.00; B-1 — 1179 «Saldo 31.12.2028» and 2209 «MWST-Korrektur, noch nicht abgerechnet — Saldo 31.12.2028» with «offen heute 0.00»; the information (rounding, 2201 per 31.12., Σ payable 448.00)',
    str_contains($main, 'id="vat-account-2200"') && str_contains($main, 'id="vat-account-1170"') && str_contains($main, 'data-vat-year-transit="1179"')
    && preg_match('/data-vat-year-transit="2209".*?Saldo 31\.12\.2028.*?offen heute 0\.00/s', $main) === 1
    && str_contains($main, 'Rundungsdifferenzen MWST aus den Abrechnungen 2028') && str_contains($main, 'Saldo am 31.12.2028') && !str_contains($main, 'badge--danger'));
[$hp] = $yPage('pdf', ['year' => '2028']);
check('S7 «PDF» of the final year: a PDF «mwst-jahresuebersicht-2028.pdf», inline application/pdf, nothing stored',
    str_starts_with((string) ($hp->bytesOut[0] ?? ''), '%PDF') && ($hp->bytesOut[1] ?? '') === 'mwst-jahresuebersicht-2028.pdf' && ($hp->bytesOut[2] ?? '') === 'application/pdf' && $storedCount() === 0);

[$hw, $outW] = $yPage('confirmStore', ['year' => '2028']);
$win = $outW['main'] ?? '';
$fp  = (string) ($hw->context['fingerprint'] ?? '');
check('S8 the window «MWST-Jahresübersicht 2028 ablegen»: the action row «Ablegen», the facts (Steuerjahr 2028 · 01.01.2028 – 31.12.2028, Stand, Korrekturen keine, Geschäftsjahr 2028 abgeschlossen), the one sentence; it posts to /store with the tax year, the fingerprint it shows and the entity token',
    str_contains($win, 'MWST-Jahresübersicht 2028 ablegen</h2>') && str_contains($win, 'data-fetch-post="/backend/finance/vat-year/store"') && str_contains($win, 'name="tax_year" value="2028"')
    && str_contains($win, 'value="' . $fp . '"') && $fp === (new VatYearService($wire()))->fingerprint(2028) && str_contains($win, 'value="tok-vatYear.store-2028"')
    && str_contains($win, '>keine</span>') && str_contains($win, 'Geschäftsjahr 2028 abgeschlossen') && str_contains($win, 'Es wird nichts gebucht.') && str_contains($win, '2028 · 01.01.2028 – 31.12.2028'));
[$hs, $env] = $yStore(['tax_year' => 2028, 'fingerprint' => $fp, 'entity_csrf' => 'tok-wrong']);
[$hs2, $env2] = $yStore(['tax_year' => 2028, 'fingerprint' => str_repeat('0', 64), 'entity_csrf' => 'tok-vatYear.store-2028']);
check('S9 refused, nothing stored: an expired / foreign token («Das Fenster ist abgelaufen …»); a fingerprint that is not the current one («… hat sich eben geändert — das Fenster schliessen …»)',
    ($env['status'] ?? '') === 'error' && str_contains(json_encode($env, JSON_UNESCAPED_UNICODE), 'Das Fenster ist abgelaufen') && ($env2['status'] ?? '') === 'error'
    && str_contains(json_encode($env2, JSON_UNESCAPED_UNICODE), 'hat sich eben geändert') && $storedCount() === 0);

$beforeBooks = $booksCount();
[$hs, $env]  = $yStore(['tax_year' => 2028, 'fingerprint' => $fp, 'entity_csrf' => 'tok-vatYear.store-2028']);
$row1   = $db->fetchAssociative('SELECT * FROM vat_year_overview ORDER BY id LIMIT 1') ?: [];
$file1  = ABS_BASE_PATH . '/' . ($row1['pdf_path'] ?? 'x');
check('S10 «Ablegen» stores: success, the window closes, back to the page; ONE row (tax year 2028, the fingerprint shown, by «pru», detail «keine Korrekturen»), the PDF written once under data/archive/financial/vat-year/2028/ with its SHA-256; NOTHING posted (journal, returns, the return protocol unchanged)',
    ($env['status'] ?? '') === 'success' && str_contains(json_encode($env), 'close-modal') && str_contains(json_encode($env, JSON_UNESCAPED_SLASHES), '/backend/finance/vat-year/corrections?year=2028')
    && $storedCount() === 1 && (int) $row1['tax_year'] === 2028 && $row1['fingerprint'] === $fp && $row1['created_by'] === 'pru' && json_decode((string) $row1['detail'], true) === ['corrections' => 0, 'payable_difference' => '0.00']
    && str_starts_with((string) $row1['pdf_path'], VatYearArchive::DIR . '/2028/mwst-jahresuebersicht-2028-') && is_file($file1) && hash_file('sha256', $file1) === $row1['pdf_sha256']
    && $booksCount() === $beforeBooks && in_array(['success', 'MWST-Jahresübersicht 2028 abgelegt — im Protokoll «Abschlüsse und MWST» vermerkt.'], $hs->messageService->flashes, true),
    json_encode([$env, $row1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

[$h, $out] = $yPage('corrections', ['year' => '2028']);
$main = $out['main'];
[$hs, $env] = $yStore(['tax_year' => 2028, 'fingerprint' => $fp, 'entity_csrf' => 'tok-vatYear.store-2028']);
check('S11 after storing: the badge «abgelegt», «Abgelegt {Zeit} von pru» and «Abgelegtes PDF» (voucher?id=); «Ablegen …» GONE (unchanged, B-5), no «Seit der Ablage geändert»; storing again anyway is refused («… nicht geändert …»), still one row',
    str_contains($main, 'data-vat-year-state="stored">abgelegt<') && str_contains($main, 'Abgelegt ' . (new \DateTimeImmutable((string) $row1['created_at']))->format('d.m.Y H:i') . ' von pru')
    && str_contains($main, 'href="/backend/finance/vat-year/voucher?id=' . $row1['id'] . '"') && !str_contains($out['hc2'], 'data-vat-year-store') && !str_contains($main, 'data-vat-year-changed')
    && ($env['status'] ?? '') === 'error' && str_contains(json_encode($env, JSON_UNESCAPED_UNICODE), 'nicht geändert') && $storedCount() === 1);
[$hv] = $yPage('voucher', ['id' => (string) $row1['id']]);
$pdfText = (string) ($hv->bytesOut[0] ?? '');
check('S12 the stored PDF: served byte for byte (SHA-256 verified) under its own name «mwst-jahresuebersicht-2028-YYYYmmdd-HHiiss.pdf»',
    $pdfText !== '' && hash('sha256', $pdfText) === $row1['pdf_sha256'] && ($hv->bytesOut[1] ?? '') === $row1['pdf_name'] && preg_match('/^mwst-jahresuebersicht-2028-\d{8}-\d{6}\.pdf$/', (string) $row1['pdf_name']) === 1);
file_put_contents($file1, "\n", FILE_APPEND);
[$hv] = $yPage('voucher', ['id' => (string) $row1['id']]);
file_put_contents($file1, substr((string) file_get_contents($file1), 0, -1));
check('S13 an ALTERED stored PDF is never served: the flash names the path, back to the overview of the year',
    $hv->bytesOut === null && $hv->redirectTo === '/backend/finance/vat-year/corrections?year=2028'
    && str_contains((string) ($hv->messageService->flashes[0][1] ?? ''), 'fehlt im Archiv oder wurde verändert (' . $row1['pdf_path'] . ')') && hash_file('sha256', $file1) === $row1['pdf_sha256']);

// The year changes after the storing: an uncoded revenue line in 2028, straight into the database (the year is closed).
$db->insert('journal_entry', [
    'fiscal_year_id' => $yearId('2028'), 'number' => 9101, 'entry_date' => '2028-11-20', 'text' => 'Nachtrag direkt', 'kind' => 'manual',
    'created_by' => 'harness', 'created_at' => '2028-11-20 12:00:00', 'version' => 1,
]);
$late = (int) $db->lastInsertId();
$db->insert('journal_line', ['entry_id' => $late, 'account_id' => $acc('1000'), 'position' => 1, 'debit' => '50.00', 'credit' => '0.00']);
$db->insert('journal_line', ['entry_id' => $late, 'account_id' => $acc('3400'), 'position' => 2, 'debit' => '0.00', 'credit' => '50.00']);
[$h, $out] = $yPage('corrections', ['year' => '2028']);
$main = $out['main'];
check('S14 changed since the storing (B-5): the head «Seit der Ablage geändert — das abgelegte PDF zeigt den Stand vom ' . (new \DateTimeImmutable((string) $row1['stand_at']))->format('d.m.Y') . '.», «Ablegen …» offered again, the open point «Ertrag ohne MWST-Code» (a warning)',
    str_contains($main, '<p class="be-list__section-hint" role="status" data-vat-year-changed>Seit der Ablage geändert — das abgelegte PDF zeigt den Stand vom ' . (new \DateTimeImmutable((string) $row1['stand_at']))->format('d.m.Y') . '.</p>')
    && str_contains($out['hc2'], 'data-vat-year-store') && str_contains($main, 'data-vat-year-finding="uncoded-revenue"') && str_contains($main, 'badge--warning">Warnung<'));
[$hw, $outW] = $yPage('confirmStore', ['year' => '2028']);
sleep(1);   // a second storing in another second — its own file name
[$hs, $env] = $yStore(['tax_year' => 2028, 'fingerprint' => (string) $hw->context['fingerprint'], 'entity_csrf' => 'tok-vatYear.store-2028']);
$rows = $db->fetchAllAssociative('SELECT * FROM vat_year_overview ORDER BY id');
[$h, $out] = $yPage('corrections', ['year' => '2028']);
check('S15 storing again keeps the first (B-5): two rows of 2028, two files, both verified; the newest counts — the page shows its «Abgelegt …», no «Seit der Ablage geändert», no «Ablegen …»',
    ($env['status'] ?? '') === 'success' && count($rows) === 2 && $rows[0]['pdf_path'] !== $rows[1]['pdf_path'] && $rows[1]['fingerprint'] !== $rows[0]['fingerprint']
    && hash_file('sha256', ABS_BASE_PATH . '/' . $rows[0]['pdf_path']) === $rows[0]['pdf_sha256'] && hash_file('sha256', ABS_BASE_PATH . '/' . $rows[1]['pdf_path']) === $rows[1]['pdf_sha256']
    && str_contains($out['main'], 'voucher?id=' . $rows[1]['id'] . '"') && !str_contains($out['main'], 'data-vat-year-changed') && !str_contains($out['hc2'], 'data-vat-year-store'),
    json_encode($env, JSON_UNESCAPED_UNICODE));

$db->executeStatement("UPDATE fiscal_period SET state = 'open' WHERE fiscal_year_id = ? AND start_date >= '2028-12-01'", [$yearId('2028')]);
[$h, $out] = $yPage('corrections', ['year' => '2028']);
$reopened = $out['main'];
$reopenedTools = $out['hc2'] ?? '';
$db->executeStatement("UPDATE fiscal_period SET state = 'closed' WHERE fiscal_year_id = ?", [$yearId('2028')]);
[$h, $out] = $yPage('corrections', ['year' => '2028']);
check('S16 the fiscal year reopened after the storing: «vorläufig» again and «Seit der Ablage geändert», no «Ablegen …»; closed again → the same as stored, no hint',
    str_contains($reopened, 'data-vat-year-state="provisional"') && str_contains($reopened, 'data-vat-year-changed') && !str_contains($reopenedTools, 'data-vat-year-store')
    && !str_contains($out['main'], 'data-vat-year-changed') && str_contains($out['main'], 'data-vat-year-state="stored"'));

/** @var \Z77\Module\Financial\Repositories\FiscalYearCloseLogRepository $protocol */
$protocol = $wire()->getRepository(\Z77\Module\Financial\Entities\FiscalYearCloseLog::class);
$scoped   = static fn(string $code): array => array_map(static fn($e): string => $e->kind . ($e->taxYear !== null ? ' ' . $e->taxYear . '#' . $e->overviewId : ''),
    $protocol->protocolPage(new \Z77\Module\Financial\Repositories\CloseProtocolSearch($yearId($code), $code . '-01-01', $code . '-12-31'), 0, 50));
$p2028 = $scoped('2028');
check('S17 C-D: the protocol «Abschlüsse und MWST» of the fiscal year 2028 holds the two storings as «Jahresübersicht abgelegt» (tax year 2028, newest first) beside the four «MWST gebucht» of 2028; the fiscal year 2027 holds none of them (the year filter by the tax year)',
    array_slice($p2028, 0, 2) === ['vat-year-stored 2028#' . $rows[1]['id'], 'vat-year-stored 2028#' . $rows[0]['id']] && count(array_filter($p2028, static fn(string $k): bool => $k === 'vat-posted')) === 4
    && array_filter($scoped('2027'), static fn(string $k): bool => str_starts_with($k, 'vat-year-stored')) === [],
    json_encode([$p2028, $scoped('2027')]));
$sources = $codeOf((string) file_get_contents($fin . '/src/VatReturn/YearOverviewStore.php')) . $codeOf((string) file_get_contents($fin . '/src/Ui/VatYearControllerTrait.php'));
check('S18 source guards: «Ablegen …» posts nothing — the writer and the screen call no ledger, no posting service (only its writer-lock name), no journal write; the screen reads no superglobal',
    !preg_match('/LedgerService\([^)]*\)\)?->(post|amend|reverse|retract)|new VatReturnPostingService|->post\(|ManualEntryService|PostingRequest::/', $sources) && !preg_match('/\$_(SERVER|POST|GET)\b/', $sources));
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
