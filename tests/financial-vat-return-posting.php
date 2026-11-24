<?php

/**
 * VAT return, stage S2 — the POSTED return (CLI): `vat_return`, `vat_return_figure`,
 * `vat_return_log`, the reader of «settled» `VatReturn\VatSettlement`, and what
 * `VatReturnService` reports from them (ADR-042 addendum 2026-11-24 points 1, 2, 4, 5; ADR-041
 * addendum points 1, 2, 5, 14; docs/temp/vat-return-s2-plan.md §1, §2, §3.6–§3.8). Slice 1 «model +
 * read» and slice 2 «the freeze»: no production code writes a return yet — every row here is
 * inserted BY THE HARNESS.
 *
 *   O. (offline) `ExactAmount::fromDecimal()` (integers only), `VatPeriod::stored()` (the short
 *      flag of a snapshotted period), the entities' invariants and transitions in memory (lift,
 *      «Eingereicht am»), source guards (one reader of «settled», no writer yet);
 *   M. (database) the schema: the three tables, their columns, the unique indexes with `kind`
 *      leading (owner O11) and how they behave — at most ONE live return per kind and period, a
 *      lifted row (live NULL) beside it, a correction / annual row beside an ordinary one —, the
 *      foreign keys, `diff` clean, down() / up(); then the books (KMU chart, 2026, a liable mandator,
 *      a coded invoice and the settlement-like entries the rows point at);
 *   A. rows inserted by the harness, and what the service then reports: a posted Q1 (settled,
 *      «gebucht», the declared payable, the figures read back exact, the PDF's state line), lifted
 *      (open again, the note, the protocol), a revision 2, a nil return with a filed date (the
 *      latest), only ORDINARY returns settle, the locking read sees a commit the snapshot does not,
 *      the unique index under the entity, the foreign key to the settlement entry;
 *   F. the FREEZE (ADR-042 addendum point 3): post / amend / retract / update / delete / reverse ×
 *      tax line / none × a settled / an open VAT period — refused `vat-period-settled` exactly where
 *      a tax line meets a settled date (old or new), nothing written, no number drawn; a lifted
 *      return and a correction row freeze nothing; `LedgerService::periodRefusal()` and its German
 *      text; VR-R9 — the settled check under lock is a LOCKING read (a return committed after the
 *      unit of work's first read is seen by post / amend / retract); VR-R2s – R4s — a stand-in for
 *      «MWST buchen» in flight (a second process holding the locks of plan §2.1 with an uncommitted
 *      return): a tax posting and a manual edit WAIT and are refused, a posting without a tax line
 *      waits and is accepted; VR-R10 — source guards (the locked check follows the period lock).
 *
 * Checkpoints: `php tests/financial-vat-return-posting.php --from=A` / `--from=F` (a full run saves
 * them; `--from=A` runs A and F).
 * Every name and amount here is synthetic.
 *
 * Run: php tests/run.php financial-vat-return-posting
 * O needs the monorepo's vendor/; M and A a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-return-posting: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/support/HarnessCheckpoint.php';

// ── worker mode (section F, the two-process probes) ─────────────────────────
// `--worker {base} holdreturn {yearCode} {from} {to}` — a STAND-IN for «MWST buchen» in flight (no
// production code posts a return before slice 5): one transaction takes the locks of plan §2.1 in
// their order — the year's journal number range (X), every fiscal-year row (S), the period rows of
// the year (X), the latest live return (X) —, inserts a live ordinary return {from}..{to} and holds
// it UNCOMMITTED. It writes `{base}/holdreturn.ready` and commits only once another session is seen
// waiting at the number range (a posting) or at a period row (a manual edit), or after 20 s.
// Prints `ok waited` / `ok alone`, or the exception.
if (($argv[1] ?? '') === '--worker') {
    [$workerBase, $workerMode] = [$argv[2], $argv[3]];
    if ($workerMode !== 'holdreturn') {
        echo "unknown worker mode {$workerMode}";
        exit(2);
    }
    [$workerYear, $workerFrom, $workerTo] = [$argv[4], $argv[5], $argv[6]];
    $cfg     = require $workerBase . '/config/client/database.inc.php';
    $connect = static fn() => \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $cfg['host'], 'dbname' => $cfg['name'], 'user' => $cfg['user'], 'password' => $cfg['password']]);
    $holder  = $connect();
    $watcher = $connect();
    try {
        $holder->beginTransaction();
        $holder->fetchOne('SELECT last_number FROM number_range WHERE name = ? FOR UPDATE', ['journal-entry.' . $workerYear]);
        $holder->fetchFirstColumn('SELECT id FROM fiscal_year LOCK IN SHARE MODE');
        $yearId = $holder->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$workerYear]);
        $holder->fetchFirstColumn('SELECT id FROM fiscal_period WHERE fiscal_year_id = ? ORDER BY start_date FOR UPDATE', [$yearId]);
        $holder->fetchOne("SELECT id FROM vat_return WHERE kind = 'ordinary' AND live = 1 ORDER BY period_to DESC LIMIT 1 FOR UPDATE");
        $holder->insert('vat_return', [
            'kind' => 'ordinary', 'period_from' => $workerFrom, 'period_to' => $workerTo, 'revision' => 1, 'state' => 'posted', 'live' => 1,
            'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
            'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
            'rounding_difference' => '0.00', 'posted_by' => 'worker', 'posted_at' => '2027-01-10 10:00:00', 'figures_fingerprint' => str_repeat('b', 64),
            'voucher_path' => 'data/archive/financial/vat-return/2026/worker.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'worker.pdf',
        ]);
        file_put_contents($workerBase . '/holdreturn.ready', '1');
        $waited = false;
        for ($t = 0; $t < 200; $t++) {
            // A statement still executing = it waits (PROCESSLIST shows the same user's sessions without the PROCESS privilege).
            if ((int) $watcher->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND (INFO LIKE '%INTO number\\_range%' OR INFO LIKE '%FROM fiscal\\_period WHERE id =%LOCK IN SHARE MODE%')") > 0) {
                usleep(200000);   // let the waiter settle in its wait, then commit
                $waited = true;
                break;
            }
            usleep(100000);
        }
        $holder->commit();
        echo $waited ? 'ok waited' : 'ok alone';
    } catch (\Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnFigure;
use Z77\Module\Financial\Entities\VatReturnKind;
use Z77\Module\Financial\Entities\VatReturnLog;
use Z77\Module\Financial\Entities\VatReturnLogAction;
use Z77\Module\Financial\Entities\VatReturnState;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Pdf\VatReturnPdf;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\Services\EntryNotEditableException;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FieldAssignment;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Shared\Money\Money;

$checkpoint  = new HarnessCheckpoint('financial-vat-return-posting', __FILE__, ['A', 'F']);
$fromSection = $checkpoint->requested($argv);

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

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

/** The names of the parts of a check that are false — its detail. */
function falseOf(array $parts): string
{
    return implode(', ', array_keys(array_filter($parts, static fn(bool $ok): bool => !$ok)));
}

/** A posted ordinary return in memory — the defaults of a harness row, each overridable. */
function memoryReturn(array $o = []): VatReturn
{
    $o += [
        'period' => new VatPeriod(day('2026-01-01'), day('2026-03-31'), VatRhythm::Quarter), 'revision' => 1,
        'hash' => hash('sha256', 'rules'), 'snapshot' => [['code' => 'UN', 'category' => 'standard', 'rate' => 810, 'field' => '303', 'kind' => 'rate', 'input' => null]],
        'payable' => chf('81.00'), 'postedTax' => chf('81.00'), 'rounding' => chf('0.00'), 'entry' => null, 'filedOn' => null,
        'figures' => hash('sha256', 'figures'), 'warningsFp' => null, 'warnings' => [], 'path' => 'data/archive/financial/vat-return/2026/x.pdf',
        'pdfHash' => hash('sha256', 'pdf'), 'name' => 'mwst-abrechnung-1-quartal-2026.pdf', 'country' => 'CH',
    ];

    return new VatReturn($o['period'], $o['revision'], VatMethod::Effective, VatConsideration::Agreed, $o['country'], 'CH 2024', $o['hash'], $o['snapshot'],
        $o['payable'], $o['postedTax'], $o['rounding'], $o['entry'], $o['filedOn'], 'harness', day('2026-04-10 09:30:00'), $o['figures'],
        $o['warningsFp'], $o['warnings'], $o['path'], $o['pdfHash'], $o['name']);
}

// ─────────────────────────────────────────────────────────────────────────
echo "O. Exact decimals, stored periods, the entities in memory, source guards (offline)\n";

$roundTrip = static fn(string $d): bool => ExactAmount::fromDecimal($d, 'CHF')->toDecimal() === $d;
check('O1 ExactAmount::fromDecimal() reads back every toDecimal() exactly — sub-Rappen, negative, zero, large — and pads fewer decimals; it refuses a seventh decimal, an exponent, text',
    $roundTrip('958.822110') && $roundTrip('-0.000001') && $roundTrip('0.000000') && $roundTrip('-12345678.123456') && $roundTrip('80.999190')
    && ExactAmount::fromDecimal('12.5', 'CHF')->toDecimal() === '12.500000' && ExactAmount::fromDecimal('7', 'CHF')->equals(ExactAmount::ofMoney(chf('7.00')))
    && ExactAmount::taxOf(chf('999.99'), 810)->equals(ExactAmount::fromDecimal(ExactAmount::taxOf(chf('999.99'), 810)->toDecimal(), 'CHF'))
    && thrown(fn() => ExactAmount::fromDecimal('1.0000001', 'CHF')) instanceof \InvalidArgumentException
    && thrown(fn() => ExactAmount::fromDecimal('1e5', 'CHF')) instanceof \InvalidArgumentException
    && thrown(fn() => ExactAmount::fromDecimal('abc', 'CHF')) instanceof \InvalidArgumentException);
check('O2 VatPeriod::stored() derives `short` from the grid of the rhythm: a full quarter, a month, a year are not short; 15.5.–30.6. (quarter) and 1.7.–31.12. (year) are',
    !VatPeriod::stored(day('2026-01-01'), day('2026-03-31'), VatRhythm::Quarter)->short && !VatPeriod::stored(day('2026-02-01'), day('2026-02-28'), VatRhythm::Month)->short
    && !VatPeriod::stored(day('2026-01-01'), day('2026-12-31'), VatRhythm::Year)->short && VatPeriod::stored(day('2026-05-15'), day('2026-06-30'), VatRhythm::Quarter)->short
    && VatPeriod::stored(day('2026-07-01'), day('2026-12-31'), VatRhythm::Year)->short && VatPeriod::stored(day('2026-04-01 15:30'), day('2026-06-30'), VatRhythm::Quarter)->from->format('H:i') === '00:00');

$r = memoryReturn(['warnings' => ["Ertragskonto «3400»:\n zwei Buchungen  ohne Code", 'Rundung'], 'warningsFp' => hash('sha256', 'w'), 'name' => "mwst\x01abrechnung.pdf", 'filedOn' => day('2026-04-12 13:00')]);
check('O3 a new return is ORDINARY, posted and live, revision as given; the period, rhythm, method and consideration read back as enums; the mapping snapshot and the confirmed warnings (one line each) round-trip; the voucher name loses control characters; the filed date is a day',
    $r->getKind() === VatReturnKind::Ordinary && $r->getState() === VatReturnState::Posted && $r->isLive() && $r->getRevision() === 1 && $r->isNil()
    && $r->period()->equals(new VatPeriod(day('2026-01-01'), day('2026-03-31'), VatRhythm::Quarter)) && !$r->period()->short
    && $r->getRhythm() === VatRhythm::Quarter && $r->getMethod() === VatMethod::Effective && $r->getConsideration() === VatConsideration::Agreed
    && $r->getMappingSnapshot()[0]['field'] === '303' && $r->getMappingSnapshot()[0]['input'] === null
    && $r->getConfirmedWarnings() === ['Ertragskonto «3400»: zwei Buchungen ohne Code', 'Rundung'] && $r->getVoucherName() === 'mwst abrechnung.pdf'
    && $r->getFiledOn()?->format('Y-m-d H:i') === '2026-04-12 00:00' && $r->getCurrency() === 'CHF' && $r->getPayable()->equals(chf('81.00')));
check('O4 the constructor refuses a revision 0, a hash that is not lower-case SHA-256 hex, amounts in two currencies, an empty voucher path, a country that is not an ISO code',
    thrown(fn() => memoryReturn(['revision' => 0])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['hash' => strtoupper(hash('sha256', 'x'))])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['figures' => 'abc'])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['rounding' => Money::fromDecimal('0.00', 'EUR')])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['path' => ''])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['country' => 'che'])) instanceof \LogicException);

$year  = new FiscalYear('2026', day('2026-01-01'), day('2026-12-31'));
$entry = new JournalEntry($year, 1, day('2026-03-31'), 'MWST-Abrechnung', EntryKind::Generated, 'vat-return', '2026-01-01/2026-03-31/r1', 'vat-return:2026-01-01:2026-03-31:r1', 'harness', day('2026-04-10'));
$nil   = memoryReturn(['filedOn' => day('2026-04-12')]);
$filedRefused = thrown(fn() => $nil->lift(null, 'admin', day('2026-04-20'), 'Irrtum')) instanceof \LogicException;
$nil->setFiledOn(null);
$nil->lift(null, 'admin', day('2026-04-20 10:00'), '  Irrtümlich gebucht  ');
$withEntry = memoryReturn(['entry' => $entry]);
check('O5 lift(): refused while a filed date is set; after clearing it a nil return is lifted without a reversal — state lifted, no longer live, who / when / the trimmed reason; a second lift, a filed date on a lifted return, a return WITH a settlement entry lifted without a reversal, an empty or too long reason are refused',
    $filedRefused && $nil->getState() === VatReturnState::Lifted && !$nil->isLive() && $nil->getLiftedBy() === 'admin' && $nil->getLiftReason() === 'Irrtümlich gebucht'
    && $nil->getLiftedAt()?->format('Y-m-d H:i') === '2026-04-20 10:00' && $nil->getReversalEntry() === null && $nil->isNil()
    && thrown(fn() => $nil->lift(null, 'admin', day('2026-04-21'), 'nochmals')) instanceof \LogicException
    && thrown(fn() => $nil->setFiledOn(day('2026-04-22'))) instanceof \LogicException
    && thrown(fn() => $withEntry->lift(null, 'admin', day('2026-04-21'), 'x')) instanceof \LogicException
    && thrown(fn() => memoryReturn()->lift($entry, 'admin', day('2026-04-21'), 'x')) instanceof \LogicException
    && thrown(fn() => $withEntry->lift($entry, 'admin', day('2026-04-21'), '   ')) instanceof \LogicException
    && thrown(fn() => $withEntry->lift($entry, 'admin', day('2026-04-21'), str_repeat('x', 501))) instanceof \LogicException
    && $withEntry->getState() === VatReturnState::Posted);

$figure = new VatReturnFigure($r, '303', FieldKind::Rate, ExactAmount::taxOf(chf('999.99'), 810), chf('999.99'), 1);
check('O6 a declared figure keeps the exact value (read back through integers) and its Rappen; a base only for a rate / acquisition field, and there always; a field number of at most eight characters; a figure in another currency is refused',
    $figure->getValueExact()->toDecimal() === '80.999190' && $figure->getValueRounded()->equals(chf('81.00')) && $figure->getBase()?->equals(chf('999.99'))
    && $figure->getKind() === FieldKind::Rate && $figure->getLineCount() === 1
    && thrown(fn() => new VatReturnFigure($r, '200', FieldKind::Total, ExactAmount::zero('CHF'), chf('1.00'), 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnFigure($r, '303', FieldKind::Rate, ExactAmount::zero('CHF'), null, 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnFigure($r, '123456789', FieldKind::Total, ExactAmount::zero('CHF'), null, 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnFigure($r, '200', FieldKind::Total, ExactAmount::zero('EUR'), null, 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnLog($r, VatReturnLogAction::Post, 'harness', day('2026-04-10'))) instanceof \LogicException);

// Source guards — the literal paths are this harness's coverage for the runner's --changed.
$sources = [];
foreach (glob($root . '/packages/*/src/{,*/,*/*/}*.php', GLOB_BRACE) as $file) {
    $sources[str_replace('\\', '/', substr($file, strlen($root) + 1))] = (string) file_get_contents($file);
}
/** The source without its comments — a docblock may name the table, code may not. */
$codeOf = static fn(string $src): string => implode('', array_map(static fn($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($src)));
$tableUsers = array_keys(array_filter($sources, static fn(string $src): bool => str_contains($src, 'vat_return') && preg_match('/\bvat_return\b/', $codeOf($src)) === 1));
sort($tableUsers);
$writers = array_keys(array_filter($sources, static fn(string $src): bool => preg_match('/new\s+(\\\\?[\w\\\\]*\\\\)?VatReturn(Figure|Log)?\s*\(/', $src) === 1));
$serviceSrc = $sources['packages/module-financial/src/VatReturn/VatReturnService.php'] ?? '';
check('O7 «settled» has ONE reader: only the entity mapping and VatReturnRepository name the table `vat_return` in the code of packages/*/src; VatReturnService asks VatSettlement (no constant false left); no production code constructs a VatReturn, a figure or a log row yet (slices 1 and 2 — no writer)',
    $tableUsers === ['packages/module-financial/src/Entities/VatReturn.php', 'packages/module-financial/src/Repositories/VatReturnRepository.php']
    && $writers === [] && str_contains($serviceSrc, '$this->settlement()->isSettled($period->to)') && !preg_match('/function isSettled\(VatPeriod \$period\): bool\s*\{\s*return false;/', $serviceSrc)
    && !preg_match('/\(float\)|floatval\(/', implode("\n", array_filter($sources, static fn(string $k): bool => str_contains($k, 'VatSettlement') || str_contains($k, 'Entities/VatReturn'), ARRAY_FILTER_USE_KEY))),
    json_encode([$tableUsers, $writers]));

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
    echo "\n{$pass} passed, {$fail} failed (M skipped)\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database — the schema of the posted returns (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-return-posting-' . getmypid());
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
    'Financial' => $root . '/packages/module-financial',
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

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map. */
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
    \Z77\Core\DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
};
$em = $wire();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

// ── shared helpers — up front, so a section started from the checkpoint has them too ──

$settings = static fn(): VatSettings => new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, day('2026-01-01'), null);
/** A service on the given request (fresh by default): the harness's settings, no open work. */
$service = static fn(?\Z77\Persistence\Resolver\UnifiedEntityManager $on = null): VatReturnService => new VatReturnService($on ?? $wire(), $settings(), new OpenWorkChecks([]));
$quarter = static fn(int $q): VatPeriod => new VatPeriod(day(sprintf('2026-%02d-01', $q * 3 - 2)), day(sprintf('2026-%02d-01', $q * 3 - 2))->modify('+3 months -1 day'), VatRhythm::Quarter);
$post    = static function (PostingRequest $request) use ($wire): EntryRef {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->post($request));
};
$count   = static fn(string $table, string $where = '1 = 1'): int => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$where}");
/** A raw row of `vat_return` — every NOT NULL column filled, each overridable. */
$rawReturn = static function (array $o = []) use ($db): int {
    $db->insert('vat_return', $o + [
        'kind' => 'ordinary', 'period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'revision' => 1, 'state' => 'posted', 'live' => 1,
        'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
        'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
        'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => '2026-04-10 10:00:00', 'figures_fingerprint' => str_repeat('b', 64),
        'voucher_path' => 'data/archive/financial/vat-return/2026/harness.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness.pdf',
    ]);

    return (int) $db->lastInsertId();
};
/** The message of a unique / foreign-key violation $fn raises, '' when none. */
$violation = static function (callable $fn): string {
    try { $fn(); } catch (\Doctrine\DBAL\Exception\ConstraintViolationException $e) { return $e->getMessage(); }
    return '';
};
/** Runs $fn in a transaction of the harness connection and rolls it back — the probe leaves nothing. */
$probe = static function (callable $fn) use ($db): mixed {
    $db->beginTransaction();
    try { return $fn(); } finally { $db->rollBack(); }
};
$entryByKey = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $on, string $key): ?JournalEntry => $on->getRepository(JournalEntry::class)->findOneBy(['idempotencyKey' => $key]);
/** A posted ordinary return built the way «MWST buchen» will build it: from the preview of the period, every field a figure. */
$persistReturn = static function (VatPeriod $period, int $revision, ?string $entryKey, ?string $filedOn = null, array $warnings = []) use ($wire, $service, $entryByKey): int {
    $em = $wire();

    return $em->getTransaction(VatReturn::class)->run(function () use ($em, $period, $revision, $entryKey, $filedOn, $warnings, $service, $entryByKey): int {
        $preview = $service($em)->preview($period);
        $form    = $preview->form;
        $mapping = array_map(static fn(FieldAssignment $a): array => ['code' => $a->taxCode, 'category' => $a->category, 'rate' => $a->rate, 'field' => $a->field, 'kind' => $a->kind->value, 'input' => $a->inputField], $form->assignments);
        $return  = new VatReturn($period, $revision, $preview->method, $preview->consideration, VatReturnService::COUNTRY, $preview->edition,
            hash('sha256', 'rule file'), $mapping, $form->payable, $form->postedTax, $form->roundingDifference(),
            $entryKey === null ? null : $entryByKey($em, $entryKey), $filedOn === null ? null : day($filedOn),
            'harness', day('2026-04-10 09:30:00'), hash('sha256', "figures {$revision}"), $warnings === [] ? null : hash('sha256', implode('|', $warnings)), $warnings,
            sprintf('data/archive/financial/vat-return/2026/mwst-abrechnung-%s-%s-r%d.pdf', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $revision),
            hash('sha256', "pdf {$revision}"), sprintf('mwst-abrechnung-%s-r%d.pdf', $period->from->format('Y-m-d'), $revision));
        $em->persist($return);
        foreach ($preview->fields as $f) {
            $em->persist(new VatReturnFigure($return, $f->field, $f->kind, $f->value, $f->base, $f->lines));
        }
        $em->flush();
        $em->persist(new VatReturnLog($return, VatReturnLogAction::Post, 'harness', day('2026-04-10 09:30:00'), null, $warnings === [] ? [] : ['warnings' => $warnings]));

        return (int) $return->getId();
    });
};
$y2026 = static fn() => $wire()->getRepository(FiscalYear::class)->findOneBy(['code' => '2026']);
$span  = static fn(?VatPeriod $p): string => $p === null ? 'none' : $p->from->format('Y-m-d') . '..' . $p->to->format('Y-m-d');

// `--from=A` / `--from=F`: the fresh schema and installation take the saved state, and the run jumps to the section.
if ($fromSection !== null) {
    echo "Checkpoint run: from section {$fromSection}\n";
    extract($checkpoint->restore($fromSection, $db, $base));
    $em = $wire();
    switch ($fromSection) {
        case 'A': goto section_A;
        case 'F': goto section_F;
    }
}

$run = static function (array $input): array {
    $app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
        \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
        \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
        \Z77\Core\DI::getCacheManager()->generatedPhp(),
        \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
    );
    $app->setAutoExit(false);
    $out  = new \Symfony\Component\Console\Output\BufferedOutput();
    $code = $app->run(new \Symfony\Component\Console\Input\ArrayInput($input + ['--no-interaction' => true]), $out);

    return [$code, $out->fetch()];
};
[$code, $out] = $run(['command' => 'migrate']);
check('M1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out));

$migration = 'Z77\\Module\\Financial\\Migrations\\Version20261124182954';
$columns   = static fn(string $table): array => array_map('strval', $db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$dbName, $table]));
$nullable  = static function (string $table) use ($db, $dbName): array {
    $names = array_map('strval', $db->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND IS_NULLABLE = 'YES'", [$dbName, $table]));
    sort($names, SORT_STRING);   // byte order — the collation sorts «lift_reason» after «lifted_by»

    return $names;
};
$type      = static fn(string $table, string $column): string => strtolower((string) $db->fetchOne('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, $table, $column]));
$indexes   = static function (string $table) use ($db, $dbName): array {
    $out = [];
    foreach ($db->fetchAllAssociative('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$dbName, $table]) as $row) {
        $key = ((int) $row['NON_UNIQUE'] === 0 ? 'U ' : 'I ') . $row['INDEX_NAME'];
        $out[$key] = isset($out[$key]) ? $out[$key] . ',' . $row['COLUMN_NAME'] : $row['COLUMN_NAME'];
    }

    return $out;
};
$fks = static fn(string $table): array => array_map('strval', $db->fetchFirstColumn('SELECT CONCAT(COLUMN_NAME, \'>\', REFERENCED_TABLE_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, $table]));
$vatTables = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'vat\\_return%' ORDER BY 1", [$dbName]));

$engines = $db->fetchAllKeyValue("SELECT TABLE_NAME, CONCAT(ENGINE, ' ', TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'vat\\_return%' ORDER BY 1", [$dbName]);
check('M2 the three tables exist — vat_return, vat_return_figure, vat_return_log — InnoDB, utf8mb4_unicode_ci (the database default is general_ci), the migration recorded',
    $engines === ['vat_return' => 'InnoDB utf8mb4_unicode_ci', 'vat_return_figure' => 'InnoDB utf8mb4_unicode_ci', 'vat_return_log' => 'InnoDB utf8mb4_unicode_ci']
    && in_array($migration, $db->fetchFirstColumn('SELECT version FROM schema_migration'), true), json_encode($engines));
check('M3 vat_return carries the columns of the plan §1.1 plus `kind` (O11), `write_count` (DOCTRINE-VERSION-001) — and NO channel, corrected_from / corrected_to, replaces_id (stage S3 adds what it needs)',
    $columns('vat_return') === ['id', 'kind', 'period_from', 'period_to', 'revision', 'state', 'live', 'rhythm', 'method', 'consideration', 'country', 'form_edition', 'mapping_hash',
        'mapping_snapshot', 'currency', 'payable', 'posted_tax', 'rounding_difference', 'filed_on', 'posted_by', 'posted_at', 'lifted_by', 'lifted_at', 'lift_reason',
        'figures_fingerprint', 'warnings_fingerprint', 'confirmed_warnings', 'voucher_path', 'voucher_sha256', 'voucher_name', 'version', 'write_count',
        'settlement_entry_id', 'reversal_entry_id']
    && $columns('vat_return_figure') === ['id', 'field', 'kind', 'value_exact', 'value_rounded', 'base', 'line_count', 'vat_return_id']
    && $columns('vat_return_log') === ['id', 'vat_return_id', 'period_from', 'period_to', 'revision', 'action', 'actor', 'acted_at', 'reason', 'detail'],
    json_encode([$columns('vat_return'), $columns('vat_return_figure'), $columns('vat_return_log')]));
check('M4 nullable are exactly: on vat_return the settlement entry (a NIL return has none), the reversal, live, the filed date, the lift, the warnings — on the figure only the base — on the log the reason and the detail',
    $nullable('vat_return') === ['confirmed_warnings', 'filed_on', 'lift_reason', 'lifted_at', 'lifted_by', 'live', 'reversal_entry_id', 'settlement_entry_id', 'warnings_fingerprint']
    && $nullable('vat_return_figure') === ['base'] && $nullable('vat_return_log') === ['detail', 'reason'],
    json_encode([$nullable('vat_return'), $nullable('vat_return_figure'), $nullable('vat_return_log')]));
check('M5 types: the exact figure DECIMAL(21,6), every amount DECIMAL(15,2), `live` TINYINT, the hashes CHAR(64), country CHAR(2), currency CHAR(3), the snapshot and the warnings text',
    $type('vat_return_figure', 'value_exact') === 'decimal(21,6)' && $type('vat_return_figure', 'value_rounded') === 'decimal(15,2)' && $type('vat_return_figure', 'base') === 'decimal(15,2)'
    && $type('vat_return', 'payable') === 'decimal(15,2)' && $type('vat_return', 'posted_tax') === 'decimal(15,2)' && $type('vat_return', 'rounding_difference') === 'decimal(15,2)'
    && str_starts_with($type('vat_return', 'live'), 'tinyint') && $type('vat_return', 'mapping_hash') === 'char(64)' && $type('vat_return', 'figures_fingerprint') === 'char(64)'
    && $type('vat_return', 'voucher_sha256') === 'char(64)' && $type('vat_return', 'country') === 'char(2)' && $type('vat_return', 'currency') === 'char(3)'
    && str_ends_with($type('vat_return', 'mapping_snapshot'), 'text') && str_ends_with($type('vat_return', 'confirmed_warnings'), 'text'));
$ix = $indexes('vat_return');
check('M6 indexes: UNIQUE (kind, period_from, revision), UNIQUE (kind, period_from, live) — `kind` LEADS both (O11) —, INDEX (kind, live, period_to) for «settled» and «the latest»; the figure UNIQUE (vat_return_id, field); the log indexed by return',
    ($ix['U ' . VatReturn::UNIQUE_REVISION] ?? '') === 'kind,period_from,revision' && ($ix['U ' . VatReturn::UNIQUE_LIVE] ?? '') === 'kind,period_from,live'
    && ($ix['I idx_vat_return_live_to'] ?? '') === 'kind,live,period_to' && ($indexes('vat_return_figure')['U ' . VatReturnFigure::UNIQUE_FIELD] ?? '') === 'vat_return_id,field'
    && ($indexes('vat_return_log')['I idx_vat_return_log_return'] ?? '') === 'vat_return_id', json_encode([$ix, $indexes('vat_return_figure'), $indexes('vat_return_log')]));
check('M7 foreign keys: vat_return → journal_entry twice (settlement, reversal), vat_return_figure → vat_return; the protocol has NONE (it outlives nothing, the close log\'s model)',
    $fks('vat_return') === ['reversal_entry_id>journal_entry', 'settlement_entry_id>journal_entry'] && $fks('vat_return_figure') === ['vat_return_id>vat_return'] && $fks('vat_return_log') === []);
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']);
check('M8 diff after migrate reports NO change — the three entities and the migration agree', $code !== 0 && str_contains($out, 'No changes detected'), trim($out));

// The unique indexes at work — raw rows in a rolled-back transaction each.
$liveTwice = $probe(fn() => $violation(function () use ($rawReturn): void { $rawReturn(); $rawReturn(['revision' => 2]); }));
$liftedBeside = $probe(fn() => $violation(function () use ($rawReturn): void {
    $rawReturn(['state' => 'lifted', 'live' => null]); $rawReturn(['state' => 'lifted', 'live' => null, 'revision' => 2]); $rawReturn(['revision' => 3]);
}));
$revisionTwice = $probe(fn() => $violation(function () use ($rawReturn): void { $rawReturn(['state' => 'lifted', 'live' => null]); $rawReturn(['state' => 'lifted', 'live' => null]); }));
$kindsBeside = $probe(fn() => $violation(function () use ($rawReturn): void {
    $rawReturn(); $rawReturn(['kind' => 'correction']); $rawReturn(['kind' => 'annual', 'period_to' => '2026-12-31', 'rhythm' => 'year']);
}));
$figureTwice = $probe(fn() => $violation(function () use ($rawReturn, $db): void {
    $id = $rawReturn();
    foreach ([1, 2] as $_) {
        $db->insert('vat_return_figure', ['vat_return_id' => $id, 'field' => '200', 'kind' => 'total', 'value_exact' => '0.000000', 'value_rounded' => '0.00', 'line_count' => 0]);
    }
}));
check('M9 the unique indexes: a SECOND live return of the same kind and period is refused (uniq_vat_return_live — at most one posted return per kind + period); two lifted rows (live NULL) and a live revision 3 beside them are accepted; the same kind + period + revision twice is refused; a correction and an annual row beside the live ordinary one (same period_from) are accepted — `kind` leads; a field declared twice on one return is refused',
    str_contains($liveTwice, VatReturn::UNIQUE_LIVE) && $liftedBeside === '' && str_contains($revisionTwice, VatReturn::UNIQUE_REVISION) && $kindsBeside === ''
    && str_contains($figureTwice, VatReturnFigure::UNIQUE_FIELD) && $count('vat_return') === 0,
    json_encode([$liveTwice, $liftedBeside, $revisionTwice, $kindsBeside, $figureTwice]));

// down() / up() of the migration: BY NAME first (a newer one of another module may follow), then `prev` runs exactly its down().
$run(['command' => 'migrate', 'version' => $migration]);
[$downCode, $downOut] = $run(['command' => 'migrate', 'version' => 'prev']);
$afterDown = $vatTables();
[$upCode, $upOut] = $run(['command' => 'migrate']);
check('M10 down() drops the three tables (figure first — its key points at the return), the journal untouched; up() again creates them',
    $downCode === 0 && $afterDown === [] && in_array('journal_entry', array_map('strval', $db->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$dbName])), true)
    && $upCode === 0 && $vatTables() === ['vat_return', 'vat_return_figure', 'vat_return_log'], trim($downOut) . ' / ' . trim($upOut));

// The books: KMU chart, fiscal year 2026, a liable mandator; a coded invoice in Q1 (a tax with a sub-Rappen exact
// value); the settlement-like entries the rows of A point at (source `vat-return`, left out of the reconciliation).
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$post(PostingRequest::generated(day('2026-02-10'), 'Rechnung 1 · Beispiel AG', 'invoice', '1', 'invoice:1:final', [
    PostingLine::debit('1100', chf('1080.99')),
    PostingLine::credit('3200', chf('999.99'), 'Ware', 'UN', 810, chf('999.99'), chf('81.00')),
    PostingLine::credit('2200', chf('81.00'), 'MWST UN'),
]));
$settle = static fn(int $revision) => PostingRequest::generated(day('2026-03-31'), 'MWST-Abrechnung 1. Quartal 2026', VatReturnService::SOURCE_TYPE, "2026-01-01/2026-03-31/r{$revision}",
    "vat-return:2026-01-01:2026-03-31:r{$revision}", [PostingLine::debit('2200', chf('81.00')), PostingLine::credit('2201', chf('81.00'))]);
$r1Ref = $post($settle(1));
$emRev = $wire();
$emRev->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($emRev, 'harness'))->reverse($r1Ref, day('2026-03-31'), 'Aufgehoben: MWST-Abrechnung 1. Quartal 2026'));
$post($settle(2));
check('M11 the books: KMU chart (2200, 2201), fiscal year 2026, the mandator liable (quarter from 1.1.2026); one coded invoice in Q1; the settlement entries r1, its reversal and r2 of source vat-return; no return row yet',
    $count('journal_entry') === 4 && $count('journal_entry', "source_type = 'vat-return'") === 3 && $count('vat_return') === 0
    && $service()->settingsRefusal() === null);

$checkpoint->save('A', $db, $base, []);
section_A:
// ─────────────────────────────────────────────────────────────────────────
echo "A. Rows inserted by the harness — settled, lifted, revision, latest — and what the service reports\n";

$q1 = $quarter(1);
$q2 = $quarter(2);
$q3 = $quarter(3);
$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
check('A1 no return: nothing is settled — Q1 open, «Nächste Abrechnung» Q1 on 5.10.2026, the list all «offen» with no posted and no lifted return, the preview «offen», no latest return',
    !$svc->isSettled($q1) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-01-01..2026-03-31'
    && array_map(static fn($s) => $s->state . '/' . $s->stateLabel, $sum) === array_fill(0, 4, 'open/offen')
    && array_filter($sum, static fn($s) => $s->posted !== null || $s->lifted !== null) === []
    && $svc->preview($q1)->state === VatReturnPreview::STATE_OPEN && $svc->preview($q1)->posted === null && $svc->settlement()->latest() === null
    && $svc->settlement()->liveReturns() === []);

$q1Payable = $service()->preview($q1)->payable();
$r1 = $persistReturn($q1, 1, 'vat-return:2026-01-01:2026-03-31:r1', null, ['Ertragskonto «3400 Dienstleistungsertrag»: 1 Buchung ohne MWST-Code']);
$editionFields = count($service()->preview($q1)->fields);
check('A2 Q1 posted (revision 1, the settlement entry r1): one row — ordinary, posted, live = 1 —, one figure per field of the edition (' . $editionFields . '), one protocol row «post» with the confirmed warning in its detail',
    $count('vat_return') === 1 && $db->fetchAssociative('SELECT kind, state, live, revision FROM vat_return WHERE id = ?', [$r1]) === ['kind' => 'ordinary', 'state' => 'posted', 'live' => 1, 'revision' => 1]
    && $count('vat_return_figure', "vat_return_id = {$r1}") === $editionFields && $editionFields > 10
    && $db->fetchAssociative('SELECT action, actor, reason FROM vat_return_log WHERE vat_return_id = ?', [$r1]) === ['action' => 'post', 'actor' => 'harness', 'reason' => null]
    && json_decode((string) $db->fetchOne('SELECT detail FROM vat_return_log WHERE vat_return_id = ?', [$r1]), true) === ['warnings' => ['Ertragskonto «3400 Dienstleistungsertrag»: 1 Buchung ohne MWST-Code']]);

$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
$set = $svc->settlement();
check('A3 settled is READ from the row: Q1 settled, Q2 not; «Nächste Abrechnung» Q2; the list shows Q1 «gebucht» with the DECLARED payable and its return, Q2–Q4 «offen»; the preview of Q1 «gebucht» naming the return; VatSettlement: the period covering 1.1. and 31.3. is Q1 (not short), 1.4. none; the latest return is r1',
    $svc->isSettled($q1) && !$svc->isSettled($q2) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-04-01..2026-06-30'
    && $sum[0]->state === VatReturnPreview::STATE_POSTED && $sum[0]->stateLabel === 'gebucht' && $sum[0]->posted?->getId() === $r1 && $sum[0]->lifted === null
    && $sum[0]->payable?->equals($q1Payable) && $sum[0]->refusal === null
    && array_map(static fn($s) => $s->stateLabel, array_slice($sum, 1)) === ['offen', 'offen', 'offen']
    && $svc->preview($q1)->state === VatReturnPreview::STATE_POSTED && $svc->preview($q1)->stateLabel() === 'gebucht' && $svc->preview($q1)->posted?->getId() === $r1
    && $span($set->periodCovering(day('2026-01-01'))) === '2026-01-01..2026-03-31' && $set->periodCovering(day('2026-03-31'))?->short === false
    && $set->periodCovering(day('2026-04-01')) === null && $set->isSettled(day('2026-02-15')) && $set->latest()?->getId() === $r1,
    falseOf(['settled' => $svc->isSettled($q1), 'next' => $span($svc->nextReturn(day('2026-10-05'))) === '2026-04-01..2026-06-30', 'list' => $sum[0]->stateLabel === 'gebucht', 'payable' => (bool) $sum[0]->payable?->equals($q1Payable)]));

$preview = $service()->preview($q1);
$emRead  = $wire();
$read    = $emRead->getRepository(VatReturn::class)->find($r1);
$figures = $emRead->getRepository(VatReturn::class)->figuresOf($read);
$exactOk = count($figures) === count($preview->fields);
foreach ($preview->fields as $i => $f) {
    $g = $figures[$i] ?? null;
    $exactOk = $exactOk && $g !== null && $g->getField() === $f->field && $g->getKind() === $f->kind && $g->getValueExact()->equals($f->value)
        && $g->getValueRounded()->equals($f->rounded) && (($g->getBase() === null && $f->base === null) || ($g->getBase()?->equals($f->base) ?? false)) && $g->getLineCount() === $f->lines;
}
$rateField = array_values(array_filter($preview->fields, static fn($f) => $f->kind === FieldKind::Rate && !$f->value->isZero()))[0] ?? null;
check('A4 the row reads back: the repository is VatReturnRepository; the period, settings, edition, the resolved mapping (code UN at 8.1 %), the payable / posted tax / rounding difference as computed, the settlement entry r1, the warnings; every figure in form order equals the preview field — EXACT (the rate field ' . ($rateField?->field ?? '?') . ' stored as ' . ($rateField === null ? '?' : $db->fetchOne('SELECT value_exact FROM vat_return_figure WHERE vat_return_id = ? AND field = ?', [$r1, $rateField->field])) . ', a fraction of a Rappen kept), rounded, base, line count',
    $emRead->getRepository(VatReturn::class) instanceof VatReturnRepository && $read instanceof VatReturn
    && $read->period()->equals($q1) && $read->getMethod() === VatMethod::Effective && $read->getConsideration() === VatConsideration::Agreed && $read->getRhythm() === VatRhythm::Quarter
    && $read->getFormEdition() === $preview->edition && $read->getCountry() === 'CH' && $read->getCurrency() === 'CHF'
    && in_array(['code' => 'UN', 'category' => 'standard', 'rate' => 810], array_map(static fn(array $a) => array_intersect_key($a, ['code' => 1, 'category' => 1, 'rate' => 1]), $read->getMappingSnapshot()), true)
    && $read->getPayable()->equals($preview->form->payable) && $read->getPostedTax()->equals($preview->form->postedTax) && $read->getRoundingDifference()->equals($preview->form->roundingDifference())
    && $read->getSettlementEntry()?->getIdempotencyKey() === 'vat-return:2026-01-01:2026-03-31:r1' && $read->getVersion() === 1
    && $read->getConfirmedWarnings() === ['Ertragskonto «3400 Dienstleistungsertrag»: 1 Buchung ohne MWST-Code'] && $exactOk
    && $rateField !== null && $db->fetchOne('SELECT value_exact FROM vat_return_figure WHERE vat_return_id = ? AND field = ?', [$r1, $rateField->field]) === $rateField->value->toDecimal()
    && !$rateField->value->isWhole());

$facts = static function (VatReturnPreview $p): string {
    $rows = (new \ReflectionMethod(VatReturnPdf::class, 'facts'))->invoke(null, $p, '1. Quartal 2026', '01.01.–31.03.2026', null)['rows'];
    foreach ($rows as $row) {
        if ($row['cells'][0] === 'Zustand') {
            return $row['cells'][1];
        }
    }

    return '';
};
check('A5 the PDF names the state of a posted period: «gebucht am 10.04.2026 von harness» (an open one keeps «offen — nicht gebucht»)',
    $facts($service()->preview($q1)) === 'gebucht am 10.04.2026 von harness' && $facts($service()->preview($q2)) === 'offen — nicht gebucht', $facts($service()->preview($q1)));

// Lift r1 — the entity transition, the reversal entry the ledger wrote, a protocol row.
$emLift = $wire();
$emLift->getTransaction(VatReturn::class)->run(function () use ($emLift, $r1, $entryByKey): void {
    $return = $emLift->getRepository(VatReturn::class)->find($r1);
    $return->lift($entryByKey($emLift, 'reversal:2026/' . $return->getSettlementEntry()->getNumber()), 'admin', day('2026-04-20 11:00:00'), 'Irrtümlich gebucht — Umsatz fehlte');
    $emLift->persist(new VatReturnLog($return, VatReturnLogAction::Lift, 'admin', day('2026-04-20 11:00:00'), 'Irrtümlich gebucht — Umsatz fehlte'));
});
$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
check('A6 Q1 lifted: the row stays — state lifted, live NULL, the reversal entry, who / when / why, its figures and its voucher —; Q1 is open again: not settled, «Nächste Abrechnung» Q1, the list «offen» with the note of the lifted r1, the payable computed from the journal (the settlement entries are no movement), the preview «offen»; no latest return; the protocol post + lift',
    $db->fetchAssociative('SELECT state, live, lifted_by, lift_reason, voucher_path IS NOT NULL AS voucher FROM vat_return WHERE id = ?', [$r1]) === ['state' => 'lifted', 'live' => null, 'lifted_by' => 'admin', 'lift_reason' => 'Irrtümlich gebucht — Umsatz fehlte', 'voucher' => 1]
    && (int) $db->fetchOne('SELECT reversal_entry_id FROM vat_return WHERE id = ?', [$r1]) === (int) $db->fetchOne("SELECT id FROM journal_entry WHERE idempotency_key LIKE 'reversal:%'")
    && $count('vat_return_figure', "vat_return_id = {$r1}") === $editionFields
    && !$svc->isSettled($q1) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-01-01..2026-03-31'
    && $sum[0]->state === VatReturnPreview::STATE_OPEN && $sum[0]->posted === null && $sum[0]->lifted?->getId() === $r1 && $sum[0]->lifted?->getLiftedBy() === 'admin'
    && $sum[0]->payable?->equals($q1Payable) && $svc->preview($q1)->state === VatReturnPreview::STATE_OPEN && $svc->settlement()->latest() === null
    && $db->fetchFirstColumn('SELECT CONCAT(action, \'/\', COALESCE(reason, \'-\')) FROM vat_return_log WHERE vat_return_id = ? ORDER BY id', [$r1]) === ['post/-', 'lift/Irrtümlich gebucht — Umsatz fehlte']);

$r2 = $persistReturn($q1, 2, 'vat-return:2026-01-01:2026-03-31:r2');
$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
check('A7 Q1 posted again as revision 2: settled again; the revisions of Q1 latest first (r2 live, r1 lifted); the list «gebucht» with r2 and no lifted note; the preview names r2, the PDF «(Neuerstellung 2)»',
    $svc->isSettled($q1) && array_map(static fn(VatReturn $r) => $r->getRevision() . ($r->isLive() ? ' live' : ' ' . $r->getState()->value), $svc->settlement()->revisionsOf($q1)) === ['2 live', '1 lifted']
    && $sum[0]->posted?->getId() === $r2 && $sum[0]->lifted === null && $svc->preview($q1)->posted?->getId() === $r2
    && $facts($svc->preview($q1)) === 'gebucht am 10.04.2026 von harness (Neuerstellung 2)' && $span($svc->nextReturn(day('2026-10-05'))) === '2026-04-01..2026-06-30');

$r3 = $persistReturn($q2, 1, null, '2026-07-15');
$svc = $service();
$set = $svc->settlement();
$emFile = $wire();
[$liftFiled, $versions] = $emFile->getTransaction(VatReturn::class)->run(function () use ($emFile, $r3): array {
    $return = $emFile->getRepository(VatReturn::class)->find($r3);
    $refused = thrown(fn() => $return->lift(null, 'admin', day('2026-07-20'), 'x')) instanceof \LogicException;
    $before  = $return->getVersion();
    $return->setFiledOn(day('2026-07-15'));   // the same date again — the write still counts (DOCTRINE-VERSION-001)

    return [$refused, $before];
});
check('A8 Q2 posted as a NIL return (no settlement entry) with «Eingereicht am» 15.7.: settled; the latest return is Q2, the live returns Q1 r2 + Q2 in period order; «Nächste Abrechnung» Q3; a lift is refused while the filed date is set; setting the same filed date again still moves the optimistic lock (1 → 2)',
    $svc->isSettled($q2) && $set->latest()?->getId() === $r3 && $set->latest()?->isNil() && $set->latest()?->getFiledOn()?->format('Y-m-d') === '2026-07-15'
    && array_map(static fn(VatReturn $r) => $r->getId(), $set->liveReturns()) === [$r2, $r3] && $span($svc->nextReturn(day('2026-10-05'))) === '2026-07-01..2026-09-30'
    && $liftFiled && $versions === 1 && (int) $db->fetchOne('SELECT version FROM vat_return WHERE id = ?', [$r3]) === 2
    && $db->fetchOne('SELECT settlement_entry_id FROM vat_return WHERE id = ?', [$r3]) === null);

$correction = $rawReturn(['kind' => VatReturnKind::Correction->value, 'period_from' => '2026-07-01', 'period_to' => '2026-09-30']);
$annual     = $rawReturn(['kind' => VatReturnKind::Annual->value, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'rhythm' => 'year']);
$svc = $service();
$onlyOrdinary = !$svc->isSettled($q3) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-07-01..2026-09-30' && $svc->settlement()->periodCovering(day('2026-08-01')) === null
    && $svc->settlement()->latest()?->getId() === $r3 && count($svc->settlement()->liveReturns()) === 2;
$db->executeStatement('DELETE FROM vat_return WHERE id IN (?, ?)', [$correction, $annual]);
check('A9 only an ORDINARY return settles: a live correction row of Q3 and a live annual row of 2026 settle nothing — Q3 stays open and next, the latest stays Q2', $onlyOrdinary);

// The locking read: inside a unit of work, a plain read is the snapshot of its first read; a return committed
// meanwhile (another connection) is seen only by the locking read — the trap of plan §0.1.
$outside = thrown(fn() => (new VatSettlement($wire()))->lockedPeriodCovering(day('2026-08-01')));
$emLock  = $wire();
[$plainBefore, $plainAfter, $locked] = $emLock->getTransaction(VatReturn::class)->run(function () use ($emLock, $rawReturn): array {
    $before = (new VatSettlement($emLock))->isSettled(day('2026-08-01'));   // the first plain read fixes the snapshot
    $rawReturn(['period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'payable' => '12.30']);   // committed by the harness connection

    return [$before, (new VatSettlement($emLock))->isSettled(day('2026-08-01')), (new VatSettlement($emLock))->lockedPeriodCovering(day('2026-08-01'))];
});
$db->executeStatement("DELETE FROM vat_return WHERE period_from = '2026-07-01'");
check('A10 lockedPeriodCovering() refuses outside a unit of work; inside one it sees a return committed after the first read (Q3) while a plain read still answers from the snapshot (not settled)',
    $outside instanceof \LogicException && !$plainBefore && !$plainAfter && $span($locked) === '2026-07-01..2026-09-30' && $locked?->short === false);

$dup   = thrown(fn() => $persistReturn($q1, 3, null));
check('A11 the database is the backstop under the entity: a third Q1 return persisted while r2 is live is refused by uniq_vat_return_live; nothing is written',
    $dup instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException && str_contains($dup->getMessage(), VatReturn::UNIQUE_LIVE)
    && $count('vat_return') === 3 && $count('vat_return_figure') === 3 * $editionFields && $count('vat_return_log') === 4, $dup === null ? 'nothing thrown' : get_class($dup) . ': ' . $dup->getMessage());

$entryR2 = (int) $db->fetchOne("SELECT id FROM journal_entry WHERE idempotency_key = 'vat-return:2026-01-01:2026-03-31:r2'");
$fk      = $probe(fn() => $violation(function () use ($db, $entryR2): void {
    $db->executeStatement('DELETE FROM journal_line WHERE entry_id = ?', [$entryR2]);
    $db->executeStatement('DELETE FROM journal_entry WHERE id = ?', [$entryR2]);
}));
check('A12 the settlement entry of a posted return cannot be deleted under it — the foreign key refuses', $fk !== '' && $count('journal_entry', "id = {$entryR2}") === 1, $fk);

$checkpoint->save('F', $db, $base, []);
section_F:
// ─────────────────────────────────────────────────────────────────────────
echo "F. The freeze — post / amend / retract / update / delete / reverse × tax line / none × a settled / an open VAT period\n";

// From A: Q1 (revision 2) and Q2 (nil) are settled, Q3 and Q4 open. Q3 is the playground: its entries are
// written while it is open, then a live return row settles it; Q4 stays open (the other column of the matrix).
/** Runs $fn(LedgerService, em) in a unit of work of a fresh request — the way a module posts. */
$inLedger = static function (callable $fn) use ($wire): mixed {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(static fn() => $fn(new LedgerService($em, 'harness'), $em));
};
/** 'ok', or the reason of the posting / editing refusal $fn raised, or «class: message» of anything else. */
$outcome = static function (callable $fn): string {
    try {
        $fn();
    } catch (PostingRefusedException | EntryNotEditableException $e) {
        return $e->reason;
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'ok';
};
$gTax = static fn(string $date, string $key, string $text = 'F Verkauf'): PostingRequest => PostingRequest::generated(day($date), $text, 'harness', $key, $key, [
    PostingLine::debit('1100', chf('108.10')),
    PostingLine::credit('3200', chf('100.00'), 'Ware', 'UN', 810, chf('100.00'), chf('8.10')),
    PostingLine::credit('2200', chf('8.10'), 'MWST UN'),
]);
$gPlain = static fn(string $date, string $key, string $text = 'F Umbuchung'): PostingRequest => PostingRequest::generated(day($date), $text, 'harness', $key, $key, [
    PostingLine::debit('1000', chf('50.00')), PostingLine::credit('1020', chf('50.00')),
]);
$mTax = static fn(string $date, string $text = 'F Spesen'): PostingRequest => PostingRequest::manual(day($date), $text, [
    PostingLine::debit('6500', chf('200.00'), null, 'VM', 810, chf('200.00'), chf('16.20')), PostingLine::debit('1170', chf('16.20')), PostingLine::credit('1020', chf('216.20')),
]);
$mPlain = static fn(string $date, string $text = 'F Umbuchung von Hand'): PostingRequest => PostingRequest::manual(day($date), $text, [
    PostingLine::debit('1000', chf('30.00')), PostingLine::credit('1020', chf('30.00')),
]);
$manual = static fn(): ManualEntryService => new ManualEntryService($wire(), 'harness');
/** id and current version of a manual entry — what its form carries. */
$at     = static fn(EntryRef $ref): array => array_map('intval', array_values($db->fetchAssociative('SELECT id, version FROM journal_entry WHERE number = ?', [$ref->number])));
$refOf  = static fn(string $key): EntryRef => new EntryRef('2026', (int) $db->fetchOne('SELECT number FROM journal_entry WHERE idempotency_key = ?', [$key]));
$rangeAt = static fn(): int => (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'journal-entry.2026'");
/** What a refusal must leave untouched: entries, lines, change rows, the number range. */
$books  = static fn(): array => [$count('journal_entry'), $count('journal_line'), $count('journal_entry_change'), $rangeAt()];
$post1  = static fn(PostingRequest $r) => $inLedger(static fn(LedgerService $l) => $l->post($r));
$amend  = static fn(string $key, PostingRequest $r) => $inLedger(static fn(LedgerService $l) => $l->amend($key, $r));
$retract = static fn(string $key) => $inLedger(static fn(LedgerService $l) => $l->retract($key, 'harness'));
$reverse = static fn(string $key, string $date, string $reason) => $inLedger(static fn(LedgerService $l) => $l->reverse($refOf($key), day($date), $reason));
$only    = static fn(array $outcomes, string $expected): string => implode(', ', array_keys(array_filter($outcomes, static fn(string $o): bool => $o !== $expected)));

// The entries of the matrix — all written while Q3 and Q4 are open.
foreach (['amend', 'out', 'loses', 'retract', 'reverse', 'reverse-later'] as $k) { $post1($gTax('2026-08-10', "f:s-tax-{$k}")); }
foreach (['amend', 'gets-tax', 'retract', 'reverse'] as $k) { $post1($gPlain('2026-08-11', "f:s-plain-{$k}")); }
foreach (['into', 'amend', 'retract', 'reverse', 'r9-amend', 'r9-retract'] as $k) { $post1($gTax('2026-11-10', "f:o-tax-{$k}")); }
$sTaxUpd   = $manual()->create($mTax('2026-08-12', 'F s tax update'));
$sTaxOut   = $manual()->create($mTax('2026-08-12', 'F s tax out'));
$sTaxDel   = $manual()->create($mTax('2026-08-12', 'F s tax delete'));
$sPlainUpd = $manual()->create($mPlain('2026-08-13', 'F s plain update'));
$sPlainGet = $manual()->create($mPlain('2026-08-13', 'F s plain gets tax'));
$sPlainDel = $manual()->create($mPlain('2026-08-13', 'F s plain delete'));
$oTaxInto  = $manual()->create($mTax('2026-11-11', 'F o tax into'));
$oPlainInto = $manual()->create($mPlain('2026-11-11', 'F o plain into'));
$oTaxUpd   = $manual()->create($mTax('2026-11-12', 'F o tax update'));
$oTaxDel   = $manual()->create($mTax('2026-11-12', 'F o tax delete'));
$oTaxHeld  = $manual()->create($mTax('2026-11-12', 'F o tax held'));
check('F1 the entries of the matrix are in the books while Q3 and Q4 are open: 16 generated (10 in Q3, 6 in Q4) and 11 manual ones — a tax line is accepted in an unsettled period',
    $count('journal_entry', "idempotency_key LIKE 'f:%'") === 16 && $count('journal_entry', "kind = 'manual' AND text LIKE 'F %'") === 11
    && !(new VatSettlement($wire()))->isSettled(day('2026-08-10')) && !(new VatSettlement($wire()))->isSettled(day('2026-11-10')));

$q3Return = $rawReturn(['period_from' => '2026-07-01', 'period_to' => '2026-09-30']);   // Q3 is settled from here on
$before   = $books();
$caught   = thrown(fn() => $post1($gTax('2026-08-20', 'f:s-new-tax')));
$refusedSettled = [
    'post tax'              => $outcome(fn() => $post1($gTax('2026-08-20', 'f:s-new-tax'))),
    'reverse tax, dated in' => $outcome(fn() => $reverse('f:s-tax-reverse', '2026-08-25', 'F Storno im abgerechneten Quartal')),
    'amend tax (text)'      => $outcome(fn() => $amend('f:s-tax-amend', $gTax('2026-08-10', 'f:s-tax-amend', 'F Verkauf geändert'))),
    'amend tax out of it'   => $outcome(fn() => $amend('f:s-tax-out', $gTax('2026-11-15', 'f:s-tax-out'))),
    'amend tax into it'     => $outcome(fn() => $amend('f:o-tax-into', $gTax('2026-08-15', 'f:o-tax-into'))),
    'amend gets a tax line' => $outcome(fn() => $amend('f:s-plain-gets-tax', $gTax('2026-08-11', 'f:s-plain-gets-tax'))),
    'amend loses its tax'   => $outcome(fn() => $amend('f:s-tax-loses', $gPlain('2026-08-10', 'f:s-tax-loses'))),
    'retract tax'           => $outcome(fn() => $retract('f:s-tax-retract')),
    'update tax (text)'     => $outcome(fn() => $manual()->update(...[...$at($sTaxUpd), $mTax('2026-08-12', 'F geändert')])),
    'update tax out of it'  => $outcome(fn() => $manual()->update(...[...$at($sTaxOut), $mTax('2026-11-14', 'F s tax out')])),
    'update tax into it'    => $outcome(fn() => $manual()->update(...[...$at($oTaxInto), $mTax('2026-08-14', 'F o tax into')])),
    'update gets a tax line' => $outcome(fn() => $manual()->update(...[...$at($sPlainGet), $mTax('2026-08-13', 'F s plain gets tax')])),
    'delete tax'            => $outcome(fn() => $manual()->delete(...$at($sTaxDel))),
];
check('F2 SETTLED × TAX LINE — every operation is refused `vat-period-settled`: post, a reversal DATED in the period, amend (text, moved out, moved in, gets / loses a tax line), retract, manual update (text, out, in, gets a tax line), manual delete; nothing written — entries, lines, change rows and the number range as before',
    $only($refusedSettled, 'vat-period-settled') === '' && $books() === $before, $only($refusedSettled, 'vat-period-settled') . ' ' . json_encode([$before, $books()]));
check('F3 the refusal names the VAT period, not the month: reason `vat-period-settled` (the old key `period-vat-settled` is gone), the exception carries the period 1.7.–30.9.2026, the message its span; the period rows are all `open` — nothing is stored on them',
    $caught instanceof PostingRefusedException && $caught->reason === 'vat-period-settled' && PostingRefusedException::VAT_PERIOD_SETTLED === EntryNotEditableException::VAT_PERIOD_SETTLED
    && !defined(PostingRefusedException::class . '::PERIOD_VAT_SETTLED') && !defined(EntryNotEditableException::class . '::PERIOD_VAT_SETTLED')
    && $span($caught->vatPeriod) === '2026-07-01..2026-09-30' && str_contains($caught->getMessage(), '01.07.2026–30.09.2026')
    && $count('fiscal_period', "state <> 'open'") === 0);

$acceptedSettled = [
    'post plain'                    => $outcome(fn() => $post1($gPlain('2026-08-21', 'f:s-new-plain'))),
    'amend plain (date, text)'      => $outcome(fn() => $amend('f:s-plain-amend', $gPlain('2026-08-22', 'f:s-plain-amend', 'F Umbuchung geändert'))),
    'retract plain'                 => $outcome(fn() => $retract('f:s-plain-retract')),
    'reverse plain, dated in'       => $outcome(fn() => $reverse('f:s-plain-reverse', '2026-08-26', 'F Storno ohne MWST')),
    'reverse tax, dated in Q4'      => $outcome(fn() => $reverse('f:s-tax-reverse-later', '2026-11-20', 'F Storno in der laufenden Periode')),
    'update plain (date, text)'     => $outcome(fn() => $manual()->update(...[...$at($sPlainUpd), $mPlain('2026-08-23', 'F geändert')])),
    'update plain into it'          => $outcome(fn() => $manual()->update(...[...$at($oPlainInto), $mPlain('2026-08-24', 'F o plain into')])),
    'delete plain'                  => $outcome(fn() => $manual()->delete(...$at($sPlainDel))),
];
check('F4 SETTLED × NO TAX LINE — everything stays possible while the fiscal year is open: post, amend, retract, a reversal dated in the period, manual update (also moved INTO the period), manual delete; and a tax entry of the settled period is reversed with a date in the RUNNING period (the later event belongs there)',
    $only($acceptedSettled, 'ok') === '' && $db->fetchOne("SELECT entry_date FROM journal_entry WHERE idempotency_key = 'f:s-plain-amend'") === '2026-08-22'
    && $count('journal_entry', "idempotency_key = 'f:s-plain-retract'") === 0 && $count('journal_entry', "idempotency_key = 'reversal:2026/" . $refOf('f:s-tax-reverse-later')->number . "' AND entry_date = '2026-11-20'") === 1
    && $count('journal_entry', "text = 'F s plain delete'") === 0 && $db->fetchOne("SELECT entry_date FROM journal_entry WHERE text = 'F o plain into'") === '2026-08-24',
    $only($acceptedSettled, 'ok'));

$acceptedOpen = [
    'post tax'              => $outcome(fn() => $post1($gTax('2026-11-21', 'f:o-new-tax'))),
    'post plain'            => $outcome(fn() => $post1($gPlain('2026-11-21', 'f:o-new-plain'))),
    'amend tax'             => $outcome(fn() => $amend('f:o-tax-amend', $gTax('2026-11-22', 'f:o-tax-amend', 'F Verkauf geändert'))),
    'retract tax'           => $outcome(fn() => $retract('f:o-tax-retract')),
    'reverse tax'           => $outcome(fn() => $reverse('f:o-tax-reverse', '2026-11-23', 'F Storno offen')),
    'update tax'            => $outcome(fn() => $manual()->update(...[...$at($oTaxUpd), $mTax('2026-11-24', 'F o geändert')])),
    'delete tax'            => $outcome(fn() => $manual()->delete(...$at($oTaxDel))),
];
check('F5 OPEN — the same operations with a tax line pass in the unsettled Q4 beside the settled Q3: post, amend, retract, reverse, manual update, manual delete', $only($acceptedOpen, 'ok') === '', $only($acceptedOpen, 'ok'));

// Only a LIVE ORDINARY return freezes: lifted, Q3 takes a tax line again; a live correction row over Q4 freezes nothing.
$db->executeStatement("UPDATE vat_return SET state = 'lifted', live = NULL, lifted_by = 'admin', lifted_at = '2026-10-20 10:00:00', lift_reason = 'F' WHERE id = ?", [$q3Return]);
$afterLift  = $outcome(fn() => $post1($gTax('2026-08-27', 'f:s-after-lift')));
$correction = $rawReturn(['kind' => VatReturnKind::Correction->value, 'period_from' => '2026-10-01', 'period_to' => '2026-12-31']);
$underCorrection = $outcome(fn() => $post1($gTax('2026-11-26', 'f:o-under-correction')));
$db->executeStatement('DELETE FROM vat_return WHERE id = ?', [$correction]);
$db->executeStatement("UPDATE vat_return SET state = 'posted', live = 1, lifted_by = NULL, lifted_at = NULL, lift_reason = NULL WHERE id = ?", [$q3Return]);
check('F6 only a LIVE ORDINARY return freezes: once the Q3 return is lifted a tax line posts into Q3 again; a live CORRECTION row over Q4 freezes nothing; the Q3 return live again, Q3 refuses again',
    $afterLift === 'ok' && $underCorrection === 'ok' && $outcome(fn() => $post1($gTax('2026-08-28', 'f:s-again'))) === 'vat-period-settled');

$ledger = new LedgerService($wire(), 'harness');
check('F7 the public answer of financial, lock-free — LedgerService::periodRefusal(date, withTaxLine): `vat-period-settled` in Q1 / Q3 only WITH a tax line, null without and in Q4, `no-fiscal-year` for 2025; periodRefusalText() is the one German sentence and names the VAT period (looked up, or the one handed in)',
    $ledger->periodRefusal(day('2026-02-01'), true) === 'vat-period-settled' && $ledger->periodRefusal(day('2026-08-01'), true) === 'vat-period-settled'
    && $ledger->periodRefusal(day('2026-08-01'), false) === null && $ledger->periodRefusal(day('2026-11-01'), true) === null
    && $ledger->periodRefusal(day('2025-12-31'), true) === PostingRefusedException::NO_FISCAL_YEAR && $ledger->periodRefusal(day('2025-12-31'), false) === PostingRefusedException::NO_FISCAL_YEAR
    && $ledger->periodRefusalText('vat-period-settled', day('2026-02-01')) === 'Die MWST-Periode 01.01.2026 – 31.03.2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr'
    && $ledger->periodRefusalText('vat-period-settled', day('2026-11-01'), $quarter(3)) === 'Die MWST-Periode 01.07.2026 – 30.09.2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr'
    && $ledger->periodRefusalText('vat-period-settled', day('2026-11-01')) === 'Die MWST-Periode des 01.11.2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr'
    && thrown(fn() => $ledger->periodRefusalText('account-unknown', day('2026-02-01'))) instanceof \InvalidArgumentException);

// VR-R9 — ONE process: the unit of work makes a plain read (its snapshot starts), the harness connection commits a return
// for Q4, then the ledger is asked. Only a LOCKING read can refuse — the lock-free check still answers «not settled».
$r9 = static function (callable $write) use ($wire, $rawReturn, $db): array {
    $em    = $wire();
    $plain = [];
    $error = null;
    try {
        $em->getTransaction(JournalEntry::class)->run(function () use ($em, $rawReturn, $write, &$plain): void {
            $plain[] = (new VatSettlement($em))->isSettled(day('2026-11-27'));                 // the first plain read fixes the snapshot
            $rawReturn(['period_from' => '2026-10-01', 'period_to' => '2026-12-31']);          // committed meanwhile, by another connection
            $plain[] = (new VatSettlement($em))->isSettled(day('2026-11-27'));                 // … the snapshot does not see it
            $plain[] = (new LedgerService($em, 'harness'))->periodRefusal(day('2026-11-27'), true);
            $write(new LedgerService($em, 'harness'));
        });
    } catch (\Throwable $e) {
        $error = $e;
    }
    $db->executeStatement("DELETE FROM vat_return WHERE kind = 'ordinary' AND period_from = '2026-10-01'");

    return [$plain, $error];
};
$before = $books();
$plainRef = null;
[$r9Plain, $r9Post] = $r9(function (LedgerService $l) use ($gTax, $gPlain, &$plainRef): void {
    $plainRef = $l->post($gPlain('2026-11-27', 'f:r9-plain'));   // no tax line: accepted, no read of the returns
    $l->post($gTax('2026-11-27', 'f:r9-tax'));
});
[, $r9Amend]   = $r9(fn(LedgerService $l) => $l->amend('f:o-tax-r9-amend', $gTax('2026-11-10', 'f:o-tax-r9-amend', 'F R9 geändert')));
[, $r9Retract] = $r9(fn(LedgerService $l) => $l->retract('f:o-tax-r9-retract', 'harness'));
check('VR-R9 the settled check under lock is a LOCKING read: in a unit of work whose snapshot predates a return committed meanwhile, the plain reads still answer «not settled» (also periodRefusal()) — post() with a tax line is refused `vat-period-settled` all the same (a line without tax passes before it), and so are amend() and retract(); the whole unit of work rolls back: no entry, no change row, the number range as before',
    $r9Plain === [false, false, null] && $plainRef instanceof EntryRef
    && $r9Post instanceof PostingRefusedException && $r9Post->reason === 'vat-period-settled' && $span($r9Post->vatPeriod) === '2026-10-01..2026-12-31'
    && $r9Amend instanceof EntryNotEditableException && $r9Amend->reason === 'vat-period-settled'
    && $r9Retract instanceof EntryNotEditableException && $r9Retract->reason === 'vat-period-settled'
    && $books() === $before && $count('journal_entry', "idempotency_key IN ('f:r9-plain', 'f:r9-tax')") === 0 && $count('journal_entry', "idempotency_key = 'f:o-tax-r9-retract'") === 1,
    json_encode([$r9Plain, $r9Post === null ? null : get_class($r9Post) . ' ' . $r9Post->getMessage(), $r9Amend === null ? null : get_class($r9Amend), $r9Retract === null ? null : get_class($r9Retract), $before, $books()]));

// VR-R2s / R3s / R4s — two processes. The worker stands in for «MWST buchen» of Q4 in flight (the locks of plan §2.1, an
// uncommitted return); the write here must WAIT for it and then meet the committed return on its locking read.
$againstReturnInFlight = static function (callable $write) use ($base, $db, $outcome): array {
    @unlink($base . '/holdreturn.ready');
    $worker = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'holdreturn', '2026', '2026-10-01', '2026-12-31'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($t = 0; $t < 300 && !is_file($base . '/holdreturn.ready'); $t++) { usleep(100000); }
    $uncommitted = (int) $db->fetchOne("SELECT COUNT(*) FROM vat_return WHERE kind = 'ordinary' AND period_from = '2026-10-01'");
    $result = $outcome($write);
    $out    = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]); proc_close($worker);
    $db->executeStatement("DELETE FROM vat_return WHERE kind = 'ordinary' AND period_from = '2026-10-01'");

    return [$out, $result, $uncommitted];
};
$before = $books();
$r2 = $againstReturnInFlight(fn() => $post1($gTax('2026-11-28', 'f:r2-tax')));
check('VR-R2s a return in flight (stand-in, uncommitted), then a tax posting into its period: the posting WAITED at the number range (worker: ' . $r2[0] . ') and was refused `vat-period-settled` (' . $r2[1] . ') — no deadlock, nothing written, the number went back',
    $r2 === ['ok waited', 'vat-period-settled', 0] && $books() === $before, json_encode([$r2, $before, $books()]));
$r4 = $againstReturnInFlight(fn() => $manual()->update(...[...$at($oTaxHeld), $mTax('2026-11-12', 'F o tax held — geändert')]));
check('VR-R4s a return in flight, then a manual edit of a tax entry in its period (no number range — it meets the return at the PERIOD row): it WAITED (worker: ' . $r4[0] . ') and was refused `vat-period-settled` (' . $r4[1] . '); the entry is unchanged, no change row',
    $r4 === ['ok waited', 'vat-period-settled', 0] && $books() === $before && $db->fetchOne('SELECT text FROM journal_entry WHERE number = ?', [$oTaxHeld->number]) === 'F o tax held', json_encode($r4));
$r3 = $againstReturnInFlight(fn() => $post1($gPlain('2026-11-28', 'f:r3-plain')));
check('VR-R3s a return in flight, then a posting WITHOUT a tax line: it waited (worker: ' . $r3[0] . ') and was accepted — a settled period takes it',
    $r3 === ['ok waited', 'ok', 0] && $count('journal_entry', "idempotency_key = 'f:r3-plain'") === 1, json_encode($r3));

// VR-R10 — source guards (the literal paths are this harness's coverage): the settled check that decides is asked AFTER
// the period's share lock, in every write of the ledger.
$bodyOf = static function (string $file, string $function) use ($root): string {
    $src   = (string) file_get_contents($root . '/' . $file);
    $start = strpos($src, 'function ' . $function . '(');
    if ($start === false) {
        return '';
    }
    $next = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $src, $m, PREG_OFFSET_CAPTURE, $start + 10) === 1 ? $m[0][1] : strlen($src);

    return substr($src, $start, $next - $start);
};
/** In $body: the LAST period lock comes before $call, and $call is there. */
$after = static function (string $body, string $call): bool {
    $lock = strrpos($body, 'lockedPeriodState(');
    $at   = strpos($body, $call);

    return $lock !== false && $at !== false && $lock < $at;
};
$ledgerFile = 'packages/module-financial/src/Services/LedgerService.php';
$manualFile = 'packages/module-financial/src/Services/ManualEntryService.php';
$rulesSrc   = (string) file_get_contents($root . '/packages/module-financial/src/Services/PostingRules.php');
$allSrc     = implode("\n", $sources);
check('VR-R10 source guards: in LedgerService (postInternal, amend, retract) and ManualEntryService (update, delete) the LOCKED settled check follows the last lockedPeriodState(); PostingRules asks VatSettlement::lockedPeriodCovering() for it and decides what is frozen in ONE place (vatFrozenDates); no code in packages/*/src names PeriodState::VatSettled or the old reason key; creditor and receipt ask LedgerService::periodRefusal() and read no period state',
    $after($bodyOf($ledgerFile, 'postInternal'), 'assertVatAccepts($request, locked: true)')
    && $after($bodyOf($ledgerFile, 'amend'), 'assertVatAllowsChange($entry, $new, locked: true)')
    && $after($bodyOf($ledgerFile, 'retract'), 'assertVatAllowsChange($entry, null, locked: true)')
    && $after($bodyOf($manualFile, 'update'), 'assertVatAllowsChange($entry, $new, locked: true)')
    && $after($bodyOf($manualFile, 'delete'), 'assertVatAllowsChange($entry, null, locked: true)')
    && str_contains($rulesSrc, '$settlement->lockedPeriodCovering($date)') && substr_count($rulesSrc, 'self::vatFrozenDates(') === 2 && substr_count($rulesSrc, 'hasTaxLine()') === 2
    && !str_contains($allSrc, 'VatSettled') && !str_contains($allSrc, 'PERIOD_VAT_SETTLED') && !str_contains($allSrc, 'period-vat-settled')
    && array_filter(['packages/module-creditor/src/Services/CreditorJournalPostingService.php', 'packages/module-receipt/src/Services/ReceiptJournalPostingService.php'],
        static fn(string $f): bool => !str_contains($sources[$f] ?? '', '->periodRefusal(') || str_contains($sources[$f] ?? '', 'PeriodState') || str_contains($sources[$f] ?? '', '->getState()')) === []);

echo "\n{$pass} passed, {$fail} failed" . ($fromSection !== null ? " (from section {$fromSection}, its checkpoint)" : '') . "\n";
exit($fail === 0 ? 0 : 1);
