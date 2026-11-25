<?php

/**
 * VAT return harness against a real ledger (CLI) — build stage S1a: the READ-ONLY data layer
 * between the journal and the form calculator (`VatReturn\VatReturnService`, ADR-041 / ADR-042
 * addenda 2026-11-24, docs/03-development/vat-return-review-2026-11-24.md). The pure core (rule
 * file, calculator, period calendar) is `tests/financial-vat-return.php`.
 *
 *   O. (offline) the origin registry `VatReturnSources` from the shipped module configs (debtor,
 *      receipt, creditor, financial): credit notes and debtor's `payment` allocations are
 *      reductions, a reversal pair wins, a manual entry is ordinary; bad declarations refused at
 *      registration; the line search refuses an unknown sort; the field labels of the rule file;
 *      source guards (no float, no superglobal, financial names no module);
 *   M. (database) a throwaway schema: vat, mandator, financial migrated; KMU chart, fiscal year
 *      2026, a liable mandator (effective, agreed, quarter, «Abrechnung ab» 1.1.2026);
 *   B. Q3 2026 posted through the ledger — debtor's documents in the shapes its builders post
 *      (invoice at two rates with a rounding line, an export, an exempt one, a credit note, a
 *      dunning fee, a discount and a loss allocation of origin `payment`), a receipt and a
 *      creditor invoice through `GrossEntryBuilder`, a manual one-line sale through
 *      `ManualEntryService`, an uncoded default interest, a bank posting and its reversal
 *      through `LedgerService::reverse()` — then: the rows by origin, the form = the calculator
 *      on those rows = the hand-computed figures, the fields shown, the tree sums = the form, the
 *      entry lists = their tree nodes, the reconciliation explained to 0.00, the findings;
 *   C. deliberate defects in Q3: an uncoded line on the VAT account (BLOCKING), a coded revenue
 *      line whose base is not its amount (turnover remainder, warning), an uncoded credit on a
 *      fixed-asset account (warning), a voucher tax off by 0.40 (rounding warning), the open-work
 *      registry passed through (blocking first); a credit note alone in Q4 (negative fields);
 *   D. a VAT quarter spanning two fiscal years (2027 short year to 15.2., then 2027-28);
 *   E. the periods of a fiscal year for quarter / month / year, «Neue Abrechnung», every
 *      refusal reason of the gate, no period, no form edition, reverse charge, an unmapped and
 *      an unknown code — and the list shows a refused period with its reason;
 *   F. the screens of stage S1b (`Ui\VatReturnControllerTrait` through a host double, the templates
 *      through the real TemplateRenderer, on the ledger of B–E): the list of a fiscal year with
 *      «Neue Abrechnung», a refused row, the settings refusal; «Formular» with only the shown
 *      fields, the posted tax at two rows, the open points and their links, no «MWST buchen»;
 *      «Herkunft» (the tree, collapsed, its links); the entries list (total = node, chips, a
 *      fetch, a column search); «Abstimmung» (the alert colour only on a remainder); the refusal
 *      page; the PDF and its text; the menu seed, the mount, the ACL; source guards.
 *
 * P. the screens of stage S2 (owner decisions of 2026-11-25) through the same host double: the action cell
 *    «Neue Abrechnung» / «MWST buchen …» / empty with the reason as the first open point; the window
 *    «MWST buchen» (a nil return, a refused click, a blocked period, warnings with the one tick); the posted
 *    page (stored figures, «Beleg», «Aufheben …» for who may lift); the list (states, the ⋮ on the newest
 *    posted row, «Gebucht von / am», the protocol); the window «Aufheben» (not the latest, no reason,
 *    lifted); the lifted revision; a revision 2; the journal (the voucher icon on the settlement entry and
 *    its reversal, the grey lock, the read-only window with the one sentence, «Zur Abrechnung»).
 *
 * Checkpoints: `php tests/financial-vat-return-ledger.php --from=C|D|E|F|P` (a full run saves them).
 * Every name and amount here is synthetic.
 *
 * Run: php tests/run.php financial-vat-return-ledger
 * O needs the monorepo's vendor/; M–E a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-return-ledger: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;
require __DIR__ . '/support/HarnessCheckpoint.php';

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\VatReturnLineSearch;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\VatReturn\ReconciliationLine;
use Z77\Module\Financial\VatReturn\RowOrigin;
use Z77\Module\Financial\VatReturn\VatAccountReconciliation;
use Z77\Module\Financial\VatReturn\VatReturnBreakdown;
use Z77\Module\Financial\VatReturn\VatReturnCalculator;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnRefusedException;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatReturnSources;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Shared\Money\Money;

$checkpoint  = new HarnessCheckpoint('financial-vat-return-ledger', __FILE__, ['C', 'D', 'E', 'F', 'P']);
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

/** The reason of a refusal thrown by $fn, or what else happened. */
function refusalOf(callable $fn): string
{
    $e = thrown($fn);

    return $e instanceof VatReturnRefusedException ? $e->refusal->reason : ($e === null ? 'nothing thrown' : get_class($e) . ': ' . $e->getMessage());
}

/** The names of the parts of a check that are false — its detail. */
function falseOf(array $parts): string
{
    return implode(", ", array_keys(array_filter($parts, static fn(bool $ok): bool => !$ok)));
}

/** An open-work check of the harness for the scope `vat-return`: one blocking finding, one warning. */
final class VatReturnHarnessOpenWork implements OpenWorkCheckInterface
{
    public function check(string $scope, array $parameters): iterable
    {
        yield Finding::warning('Harness: ein Hinweis zur Periode', '');
        yield Finding::blocking('Harness: offene Arbeit bis ' . $parameters['to']->format('d.m.Y'), 'invoice-journal');
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "O. Origin registry, line search, rule-file labels, source guards (offline)\n";

// The shipped declarations, literal paths (the runner's --changed reads them as this harness's coverage).
$declared = [];
foreach ([
    'module-financial' => $root . '/packages/module-financial/src/App/Config/financialConfig.inc.php',
    'module-debtor'    => $root . '/packages/module-debtor/src/App/Config/debtorConfig.inc.php',
    'module-receipt'   => $root . '/packages/module-receipt/src/App/Config/receiptConfig.inc.php',
    'module-creditor'  => $root . '/packages/module-creditor/src/App/Config/creditorConfig.inc.php',
] as $package => $configFile) {
    $declared[VatReturnSources::CONFIG_KEY . " of {$package}"] = (require $configFile)[VatReturnSources::CONFIG_KEY] ?? [];
}
$sources = new VatReturnSources($declared);
check('O1 debtor declares credit notes and its `payment` allocations (Skonto, Verlust) as REDUCTIONS; invoices and dunning fees ordinary; receipts, creditor invoices, bank ordinary',
    $sources->rowOrigin('credit-note', false) === RowOrigin::Reduction && $sources->rowOrigin('payment', false) === RowOrigin::Reduction
    && $sources->rowOrigin('invoice', false) === RowOrigin::Ordinary && $sources->rowOrigin('fee', false) === RowOrigin::Ordinary
    && $sources->rowOrigin('receipt', false) === RowOrigin::Ordinary && $sources->rowOrigin('creditor', false) === RowOrigin::Ordinary
    && $sources->rowOrigin('bank', false) === RowOrigin::Ordinary);
check('O2 a reversal pair wins over every declaration; a manual entry (no source type) and an undeclared source type are ordinary',
    $sources->rowOrigin('credit-note', true) === RowOrigin::Reversal && $sources->rowOrigin('invoice', true) === RowOrigin::Reversal
    && $sources->rowOrigin(null, false) === RowOrigin::Ordinary && $sources->rowOrigin('import', false) === RowOrigin::Ordinary);
check('O3 the display origins: key and German label per source type, «Manuelle Buchungen» for a manual entry (sorted last), an undeclared type shown as itself',
    $sources->key(null) === 'manual' && $sources->label('manual') === 'Manuelle Buchungen' && $sources->label('invoice') === 'Rechnungen'
    && $sources->label('credit-note') === 'Gutschriften' && $sources->label('receipt') === 'Belege' && $sources->label('creditor') === 'Kreditoren'
    && $sources->label('bank') === 'Bank' && $sources->label('import') === 'import' && $sources->sourceTypeOf('manual') === null
    && $sources->position('manual') > $sources->position('import') && $sources->position('import') > $sources->position('creditor'));
$bad = static fn(array $declarations): ?\Throwable => thrown(fn() => new VatReturnSources($declarations));
check('O4 a bad declaration fails at registration naming its origin: a list, no label, origin «reversal», the key «manual», one source type declared twice differently',
    str_contains((string) $bad(['x' => [['label' => 'A']]])?->getMessage(), 'x must be an array')
    && str_contains((string) $bad(['x' => ['invoice' => ['label' => '']]])?->getMessage(), "'invoice' needs a German 'label'")
    && str_contains((string) $bad(['x' => ['invoice' => ['label' => 'R', 'origin' => 'reversal']]])?->getMessage(), "only 'ordinary' or 'reduction'")
    && str_contains((string) $bad(['x' => ['manual' => ['label' => 'M']]])?->getMessage(), "other than 'manual'")
    && str_contains((string) $bad(['a' => ['invoice' => ['label' => 'R']], 'b' => ['invoice' => ['label' => 'Rechnungen']]])?->getMessage(), "'invoice' is declared twice")
    && $bad(['a' => ['invoice' => ['label' => 'R']], 'b' => ['invoice' => ['label' => 'R']]]) === null);
check('O5 the line search refuses an unknown sort (the ORDER BY is a fixed map) and a reversal flag other than 0 / 1',
    thrown(fn() => new VatReturnLineSearch([1], '2026-07-01', '2026-09-30', [], sort: 'id; DROP')) instanceof \InvalidArgumentException
    && thrown(fn() => new VatReturnLineSearch([1], '2026-07-01', '2026-09-30', [['code' => 'UN', 'rate' => 810, 'source' => null, 'reversal' => [2]]])) instanceof \InvalidArgumentException);
$edition = FormMapping::forCountry('CH')->edition(day('2026-07-01'), day('2026-09-30'));
check('O6 the form edition keeps the labels of the rule file (what «Formular» and «Herkunft» show) and the kind of a field',
    $edition->label('303') === 'Normalsatz 8,1 %' && $edition->label('235') === 'Entgeltsminderungen' && $edition->kindOf('400') === FieldKind::Input
    && thrown(fn() => $edition->label('999')) instanceof \InvalidArgumentException);
$vatReturnSrc = implode("\n", array_map('file_get_contents', glob($root . '/packages/module-financial/src/VatReturn/*.php')));
check('O7 the read path: no float, no superglobal, names no other business module (debtor, receipt, creditor, order) — financial names no module; module-vat still names no financial class',
    !preg_match('/\(float\)|\(double\)|floatval\(|\bfloat\b\s*\$/', $vatReturnSrc) && !preg_match('/\$_(POST|GET|SERVER|REQUEST)\b/', $vatReturnSrc)
    && !preg_match('/Z77\\\\Module\\\\(Debtor|Receipt|Creditor|Order)\\\\/', $vatReturnSrc)
    && array_filter(glob($root . '/packages/module-vat/src/*/*.php'), static fn(string $f) => preg_match('/^use Z77\\\\Module\\\\Financial\\\\/m', (string) file_get_contents($f)) === 1) === []);

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

echo "M. Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-return-ledger-' . getmypid());
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
// The seed codes plus BI (reverse charge, refused until S6) and UX (standard at 5 %, a rate the form does not map).
$codes   = json_decode((string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'), true);
$rates   = json_decode((string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'), true);
$codes[] = ['id' => 101, 'code' => 'BI', 'country' => 'CH', 'category' => 'reverse-charge', 'label' => 'Bezugsteuer übriger Aufwand', 'active' => true];
$codes[] = ['id' => 102, 'code' => 'UX', 'country' => 'CH', 'category' => 'standard', 'label' => 'Umsatz zu 5 %', 'active' => true];
$rates[] = ['id' => 101, 'code' => 'BI', 'valid_from' => '2024-01-01', 'rate' => 810];
$rates[] = ['id' => 102, 'code' => 'UX', 'valid_from' => '2024-01-01', 'rate' => 500];
$write('data/framework/vat/tax_codes.json', json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$write('data/framework/vat/tax_rates.json', json_encode($rates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map — and NO AuthService (the actor is passed). */
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

// ── shared helpers — up front, so a section started from a checkpoint (`--from=…`) has them too ──

/** The mandator's settings as the harness wants them (the real record says quarter from 1.1.2026). */
$settings = static fn(?VatRhythm $rhythm = VatRhythm::Quarter, ?string $from = '2026-01-01', bool $liable = true, ?VatMethod $method = VatMethod::Effective, ?VatConsideration $consideration = VatConsideration::Agreed, ?string $until = null): VatSettings
    => new VatSettings($liable, $method, $consideration, $rhythm, $from === null ? null : day($from), $until === null ? null : day($until));
/** A service on a fresh request: the given settings (null = the mandator record's), no open work unless asked. */
$service = static fn(?VatSettings $s = null, ?OpenWorkChecks $checks = null): VatReturnService => new VatReturnService($wire(), $s, $checks ?? new OpenWorkChecks([]), $sources);
$post = static function (PostingRequest $request) use ($wire): EntryRef {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->post($request));
};
$manual = static fn(string $date, string $text, array $lines) => (new ManualEntryService($wire(), 'harness'))->create(PostingRequest::manual(day($date), $text, $lines));
$gross  = static fn(string $date, string $counter, array $parts) => GrossEntryBuilder::of($wire())->build(day($date), $counter, $parts, true)->lines;
$doc    = static fn(string $kind, int $id, string $date, string $text, array $lines) => $post(PostingRequest::generated(day($date), $text, $kind, (string) $id, "{$kind}:{$id}:final", $lines));
$acc    = static fn(string $number): int => (int) $db->fetchOne('SELECT id FROM account WHERE number = ?', [$number]);
$q      = static fn(VatReturnService $svc, string $day) => $svc->periodOn(day($day));
$fig    = static fn($preview, string $field): string => $preview->form->value($field)->toDecimal();
/** Every node's amount = the sum of its children's, all the way down. */
$adds = static function (VatReturnBreakdown $node) use (&$adds): bool {
    if ($node->children === []) {
        return true;
    }
    $sum = array_reduce($node->children, static fn(Money $s, VatReturnBreakdown $c) => $s->add($c->amount), Money::zero($node->amount->currency));
    $lines = array_sum(array_map(static fn(VatReturnBreakdown $c) => $c->lines, $node->children));

    return $sum->equals($node->amount) && $lines === $node->lines && array_reduce($node->children, static fn(bool $ok, VatReturnBreakdown $c) => $ok && $adds($c), true);
};
$years   = static fn(string $code) => $wire()->getRepository(FiscalYear::class)->findOneBy(['code' => $code]);
$span    = static fn(array $summaries): array => array_map(static fn($s) => $s->period->from->format('m-d') . '..' . $s->period->to->format('Y-m-d'), $summaries);
$codesOf = static fn(array $findings): array => array_map(static fn(VatReturnFinding $f) => ($f->blocking ? 'B:' : 'W:') . $f->code, $findings);

// `--from=<SECTION>`: the fresh schema and installation take the saved state, and the run jumps to the section.
if ($fromSection !== null) {
    echo "Checkpoint run: only section {$fromSection}\n";
    extract($checkpoint->restore($fromSection, $db, $base));
    $em = $wire();
    switch ($fromSection) {
        case 'C': goto section_C;
        case 'D': goto section_D;
        case 'E': goto section_E;
        case 'F': goto section_F;
        case 'P': goto section_P;
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
check('M2 the books: KMU chart, fiscal year 2026, the mandator liable (effective, agreed, quarter, «Abrechnung ab» 1.1.2026); the VAT gate of the record passes',
    $acc('3809') > 0 && $acc('1510') > 0 && $service()->settingsRefusal() === null && $service()->methodLabel() === 'Effektive Methode · Vereinbartes Entgelt');

// ─────────────────────────────────────────────────────────────────────────
echo "B. Q3 2026 through the ledger — rows by origin, form, tree, lists, reconciliation, findings\n";

// debtor's documents, in the shapes its PostingBuilder / PaymentPostingBuilder post (the code of the net line, the tax line per code)
$doc('invoice', 1, '2026-07-10', 'Rechnung 1 · Beispiel AG', [
    PostingLine::debit('1100', chf('1594.00')),
    PostingLine::credit('3200', chf('1000.00'), 'Ware', 'UN', 810, chf('1000.00'), chf('81.00')),
    PostingLine::credit('3000', chf('500.00'), 'Buch', 'UR', 260, chf('500.00'), chf('13.00')),
    PostingLine::credit('2200', chf('81.00'), 'MWST UN'),
    PostingLine::credit('2200', chf('13.00'), 'MWST UR'),
]);
$doc('invoice', 2, '2026-07-15', 'Rechnung 2 · Beispiel AG', [
    PostingLine::debit('1100', chf('108.10')),
    PostingLine::credit('3200', chf('99.99'), 'Ware', 'UN', 810, chf('99.99'), chf('8.10')),
    PostingLine::credit('2200', chf('8.10'), 'MWST UN'),
    PostingLine::credit('3809', chf('0.01'), 'Rundung'),
]);
$doc('invoice', 3, '2026-08-03', 'Rechnung 3 · Export GmbH', [PostingLine::debit('1100', chf('400.00')), PostingLine::credit('3200', chf('400.00'), 'Export', 'UE', 0, chf('400.00'), chf('0.00'))]);
$doc('invoice', 4, '2026-08-04', 'Rechnung 4 · Verein', [PostingLine::debit('1100', chf('100.00')), PostingLine::credit('3000', chf('100.00'), 'Kurs', 'UA', 0, chf('100.00'), chf('0.00'))]);
$doc('credit-note', 5, '2026-08-20', 'Gutschrift 5 · Beispiel AG', [
    PostingLine::credit('1100', chf('216.20')),
    PostingLine::debit('3200', chf('200.00'), 'Ware zurück', 'UN', 810, chf('-200.00'), chf('-16.20')),
    PostingLine::debit('2200', chf('16.20'), 'MWST UN'),
]);
$doc('fee', 6, '2026-09-01', 'Gebühr 6 · Beispiel AG', [
    PostingLine::debit('1100', chf('21.62')),
    PostingLine::credit('6950', chf('20.00'), 'Mahngebühr', 'UN', 810, chf('20.00'), chf('1.62')),
    PostingLine::credit('2200', chf('1.62'), 'MWST UN'),
]);
$post(PostingRequest::generated(day('2026-08-25'), 'Skonto Rechnung 1 · Beispiel AG', 'payment', '1', 'allocation:1', [
    PostingLine::debit('3800', chf('50.00'), 'Skonto UN', 'UN', 810, chf('-50.00'), chf('-4.05')),
    PostingLine::debit('2200', chf('4.05'), 'MWST UN'),
    PostingLine::credit('1100', chf('54.05')),
]));
$post(PostingRequest::generated(day('2026-09-10'), 'Verlust Rechnung 1 · Beispiel AG', 'payment', '2', 'allocation:2', [
    PostingLine::debit('3805', chf('30.00'), 'Verlust UR', 'UR', 260, chf('-30.00'), chf('-0.78')),
    PostingLine::debit('2200', chf('0.78'), 'MWST UR'),
    PostingLine::credit('1100', chf('30.78')),
]));
// receipt and creditor invoice through the real gross split; a manual one-line sale; an uncoded default interest
$post(PostingRequest::generated(day('2026-07-20'), 'Beleg 1 · Papeterie', 'receipt', '1', 'receipt:1', $gross('2026-07-20', '1000', [new GrossPart('6500', chf('108.10'), 'VM')])));
$post(PostingRequest::generated(day('2026-08-12'), 'Kreditor 1 · Lieferant AG', 'creditor', '1', 'creditor:1', $gross('2026-08-12', '1020', [new GrossPart('4200', chf('540.50'), 'VM'), new GrossPart('6200', chf('216.20'), 'VI')])));
$manual('2026-09-05', 'Barverkauf Beratung', $gross('2026-09-05', '3400', [new GrossPart('1020', chf('324.30'), 'UN')]));
$manual('2026-09-06', 'Verzugszins Beispiel AG', [PostingLine::debit('1020', chf('30.00')), PostingLine::credit('6950', chf('30.00'))]);
// a bank posting and its reversal — a pair, netted into 200
$bankRef = $post(PostingRequest::generated(day('2026-09-15'), 'Barverkauf Bank', 'bank', 'haus/1 · auszug.xml', 'bank-posting:1', $gross('2026-09-15', '3200', [new GrossPart('1020', chf('216.20'), 'UN')])));
$em = $wire();
$em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->reverse($bankRef, day('2026-09-16'), 'Zurückgenommen: Barverkauf Bank'));

$svc = $service();   // the record's settings — through CurrentMandator::vatSettings()
$q3  = $q($svc, '2026-08-15');
$p   = $svc->preview($q3);
$rows = [];
foreach ($p->rows as $row) {
    $rows["{$row->taxCode}|{$row->rate}|{$row->origin->value}"] = $row->base->toDecimal() . '/' . $row->tax->toDecimal();
}
ksort($rows);
$expectedRows = [
    'UA|0|ordinary' => '100.00/0.00', 'UE|0|ordinary' => '400.00/0.00',
    'UN|810|ordinary' => '1419.99/115.02', 'UN|810|reduction' => '-250.00/-20.25', 'UN|810|reversal' => '0.00/0.00',
    'UR|260|ordinary' => '500.00/13.00', 'UR|260|reduction' => '-30.00/-0.78',
    'VI|810|ordinary' => '200.00/16.20', 'VM|810|ordinary' => '600.00/48.60',
];
check('B1 the period: Q3 2026 from the calendar; the aggregate by code + rate + ORIGIN — invoice, fee, manual sale ordinary; credit note + Skonto (UN) and the loss (UR) reductions; the bank posting and its reversal a pair netting to 0.00',
    $q3->from->format('Y-m-d') === '2026-07-01' && $q3->to->format('Y-m-d') === '2026-09-30' && $rows === $expectedRows, json_encode($rows));
$byOrigin = [];
foreach ($p->groups as $g) {
    $byOrigin[$g->sourceKey][] = $g->origin->value;
}
check('B2 by what marks them in the journal, not by sign: credit-note and payment are reductions, bank lines (posting AND reversal) a reversal pair, invoice / fee / receipt / creditor / manual ordinary',
    array_unique($byOrigin['credit-note']) === ['reduction'] && array_unique($byOrigin['payment']) === ['reduction'] && array_unique($byOrigin['bank']) === ['reversal']
    && array_unique($byOrigin['invoice']) === ['ordinary'] && array_unique($byOrigin['fee']) === ['ordinary'] && array_unique($byOrigin['receipt']) === ['ordinary']
    && array_unique($byOrigin['creditor']) === ['ordinary'] && array_unique($byOrigin['manual']) === ['ordinary'], json_encode($byOrigin));

$calc = (new VatReturnCalculator($edition))->calculate('CHF', $p->rows);
check('B3 the form IS the calculator\'s answer for those rows (every field, payable, posted tax)',
    array_map(static fn($v) => $v->toDecimal(), $calc->values()) === array_map(static fn($v) => $v->toDecimal(), $p->form->values())
    && $calc->payable->equals($p->form->payable) && $calc->postedTax->equals($p->form->postedTax));
$hand = ['200' => '2419.990000', '220' => '400.000000', '230' => '100.000000', '235' => '280.000000', '289' => '780.000000', '299' => '1639.990000',
    '303' => '94.769190', '313' => '12.220000', '399' => '106.989190', '400' => '48.600000', '405' => '16.200000', '479' => '64.800000', '500' => '42.190000', '510' => '0.000000'];
$diff = [];
foreach ($hand as $field => $expected) {
    if ($fig($p, (string) $field) !== $expected) {
        $diff[] = "{$field} {$fig($p, (string) $field)} ≠ {$expected}";
    }
}
check('B4 … and the hand-computed figures: 200 = 2 419.99 (before reductions), 235 = 280.00, 299 = 1 639.99 = 1 169.99 + 470.00, 303 = 1 169.99 × 8.1 % = 94.769190 exact, 313 = 12.22, 400 = 48.60 / 405 = 16.20 as posted, payable 42.19 rounded once, posted 42.19',
    $diff === [] && $p->form->base('303')->equals(chf('1169.99')) && $p->form->base('313')->equals(chf('470.00')) && $p->payable()->equals(chf('42.19'))
    && $p->form->postedTax->equals(chf('42.19')) && $p->form->isPlausible(), implode('; ', $diff));
$shown = array_values(array_map(static fn($f) => $f->field, array_filter($p->fields, static fn($f) => $f->shown)));
check('B5 every field of the edition comes with its label, rounded figure and the flag «shown» — 0.00 fields not shown, the totals always (289, 299, 399, 479), 500 (payable ≥ 0) and not 510',
    count($p->fields) === count($edition->fields()) && $shown === ['200', '220', '230', '235', '289', '299', '303', '313', '399', '400', '405', '479', '500']
    && $p->field('303')->rounded->equals(chf('94.77')) && $p->field('303')->label === 'Normalsatz 8,1 %' && $p->field('303')->lines === 8
    && $p->field('302')->base !== null && $p->field('200')->base === null && $p->state === 'open' && $p->stateLabel() === 'offen'
    && $p->edition === 'ESTV 0535, edition 01.24' && $p->methodLabel === 'Effektive Methode · Vereinbartes Entgelt', implode(',', $shown));

$treeFields = array_map(static fn(VatReturnBreakdown $n) => $n->key, $p->breakdown);
$treeOk = [];
foreach (['200' => $p->form->value('200')->rounded(), '220' => $p->form->value('220')->rounded(), '230' => $p->form->value('230')->rounded(), '235' => $p->form->value('235')->rounded(),
    '303' => $p->form->base('303'), '313' => $p->form->base('313'), '400' => $p->form->value('400')->rounded(), '405' => $p->form->value('405')->rounded()] as $field => $figure) {
    $node = $p->breakdownOf((string) $field);
    $treeOk[$field] = $node !== null && $node->amount->equals($figure) && $adds($node);
}
check('B6 the tree «Herkunft» (field › origin › account): one node per field filled by lines, in form order; each field node\'s amount IS the form\'s figure (200/220/230 the base, 235 the negated base, 303/313 the field\'s base, 400/405 the posted tax) and every level adds up',
    $treeFields === ['200', '220', '230', '235', '303', '313', '400', '405'] && !in_array(false, $treeOk, true), json_encode([$treeFields, $treeOk]));
$n200 = $p->breakdownOf('200');
$n235 = $p->breakdownOf('235');
check('B7 field 200 by origin: Rechnungen 2 099.99, Mahngebühren 20.00, Bank 0.00 (the pair, 2 lines), Manuelle Buchungen 300.00 — no credit note, no Skonto; field 235: Gutschriften 200.00, «Skonto und Verluste» 80.00 on 3800 / 3805',
    $n200->child('invoice')?->amount->equals(chf('2099.99')) && $n200->child('invoice')->label === 'Rechnungen' && $n200->child('fee')?->amount->equals(chf('20.00'))
    && $n200->child('bank')?->amount->isZero() && $n200->child('bank')->lines === 2 && $n200->child('manual')?->amount->equals(chf('300.00'))
    && $n200->child('credit-note') === null && $n200->child('payment') === null
    && $n235->child('credit-note')?->amount->equals(chf('200.00')) && $n235->child('payment')?->amount->equals(chf('80.00'))
    && $n235->child('payment')->label === 'Skonto und Verluste' && $n235->child('payment')->child('3800')?->amount->equals(chf('50.00'))
    && $n235->child('payment')->child('3805')?->amount->equals(chf('30.00')) && $n235->child('payment')->child('3805')->accountId === $acc('3805'),
    json_encode(array_map(static fn($c) => [$c->key, $c->amount->toDecimal(), $c->lines], $n200->children)));

$search = $svc->lineSearch($q3, '200', 'invoice', $acc('3200'));
$t      = $svc->lineTotals($search);
$node   = $n200->child('invoice')->child('3200');
check('B8 the entries of 200 › Rechnungen › 3200: count and Σ base = the tree node (3 lines, 1 499.99), the pager count agrees',
    $t['lines'] === 3 && $t['lines'] === $node->lines && $t['base']->equals($node->base) && $t['base']->equals(chf('1499.99')) && $svc->countLines($search) === 3,
    json_encode([$t['lines'], $t['base']->toDecimal(), $node->lines, $node->base->toDecimal()]));
$s235 = $svc->lineSearch($q3, '235', 'payment', $acc('3800'));
$s303 = $svc->lineSearch($q3, '303');
$t303 = $svc->lineTotals($s303);
check('B9 the entries of 235 › Skonto und Verluste › 3800 (Σ base −50.00 = −node) and of the whole field 303 over every origin (8 lines, Σ base 1 169.99 = the field\'s base)',
    $svc->lineTotals($s235)['base']->equals(chf('-50.00')) && $svc->lineTotals($s235)['lines'] === 1
    && $t303['lines'] === 8 && $t303['base']->equals($p->form->base('303')) && $t303['tax']->equals($p->breakdownOf('303')->tax));
$page  = $svc->lines($s303->narrowed(null, null, null, null, 'date', false), 0, 3);
$all   = $svc->lines($s303, 0, 50);
$bankLines = array_values(array_filter($all, static fn($l) => $l->sourceType === 'bank'));
check('B10 a page of lines: date order, the limit, each line with entry «2026/n», account, code, rate, base, tax, its origin, and the document query EntryDocuments takes ({id, sourceType, sourceRef})',
    count($page) === 3 && $page[0]->date->format('Y-m-d') === '2026-07-10' && $page[0]->entryRef() === '2026/1' && $page[0]->accountNumber === '3200'
    && $page[0]->taxCode === 'UN' && $page[0]->rate === 810 && $page[0]->base->equals(chf('1000.00')) && $page[0]->tax->equals(chf('81.00'))
    && $page[0]->documentQuery() === ['id' => $page[0]->entryId, 'sourceType' => 'invoice', 'sourceRef' => '1']
    && count($bankLines) === 2 && $bankLines[0]->origin === RowOrigin::Reversal && $bankLines[1]->base->equals(chf('-200.00')),
    json_encode(array_map(static fn($l) => [$l->entryRef(), $l->date->format('Y-m-d'), $l->base->toDecimal()], $page)));
$byBase = $svc->lines($s303->narrowed(null, null, null, null, 'base', true), 0, 1);
$byText = $svc->lines($s303->narrowed('2026-09-01', '2026-09-30', null, 'Gebühr', 'date', false), 0, 50);
check('B11 the list\'s own sort and search: Σ by base descending starts at 1 000.00; a day range inside the period plus a text narrows to the fee (1 line)',
    $byBase[0]->base->equals(chf('1000.00')) && count($byText) === 1 && $byText[0]->accountNumber === '6950'
    && $svc->countLines($s303->narrowed(null, null, 6, null, 'date', false)) === 1);

$r = $svc->reconciliation($q3);
$explained = array_map(static fn(ReconciliationLine $l) => "{$l->kind}:{$l->accountNumber}:{$l->amount->toDecimal()}", $r->explained);
$revenue   = array_map(static fn(ReconciliationLine $l) => "{$l->accountNumber}:{$l->amount->toDecimal()}", $r->revenueAccounts);
check('B12 «Abstimmung», turnover: the revenue MOVEMENTS per account (2 170.00) against 200 − 235 (2 139.99); the difference 30.01 is named — uncoded 3809 (rounding) 0.01 and 6950 (interest) 30.00 — the remainder is 0.00',
    $r->revenueTotal->equals(chf('2170.00')) && $r->declared()->equals(chf('2139.99')) && $r->difference()->equals(chf('30.01'))
    && $explained === ['uncoded:3809:0.01', 'uncoded:6950:30.00'] && $r->remainder()->isZero()
    && $revenue === ['3000:600.00', '3200:1299.99', '3400:300.00', '3800:-50.00', '3805:-30.00', '3809:0.01', '6950:50.00'], json_encode([$revenue, $explained]));
$vat = array_map(static fn(VatAccountReconciliation $a) => "{$a->role}:{$a->accountNumber}:" . implode('+', $a->fields) . ":{$a->expected->toDecimal()}:{$a->movement->toDecimal()}", $r->vatAccounts);
check('B13 «Abstimmung», VAT accounts: 2200 against the posted tax of 303 + 313 (106.99), 1170 against 400 (48.60), 1171 against 405 (16.20) — every movement agrees',
    $vat === ['owed:2200:303+313:106.99:106.99', 'input:1170:400:48.60:48.60', 'input:1171:405:16.20:16.20'] && $r->vatAccountsAgree(), json_encode($vat));
$found = $svc->findings($q3);
check('B14 the findings of the clean period: two warnings — exempt turnover without input-tax correction (naming the CHF 250 000 interest exception) and the uncoded interest on 6950; NOT the rounding account 3809',
    $codesOf($found) === ['W:exempt-without-correction', 'W:uncoded-revenue'] && str_contains($found[0]->message, 'CHF 250 000')
    && $found[1]->reference === 'account:6950' && $found[1]->amount->equals(chf('30.00')) && str_contains($found[1]->message, '1 Buchung ohne MWST-Code'),
    json_encode(array_map(static fn($f) => $f->message, $found)));
check('B15 the preview writes nothing: the journal counts the same entries before and after',
    (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry') === 14);

$checkpoint->save('C', $db, $base, []);
section_C:
// ─────────────────────────────────────────────────────────────────────────
echo "C. Deliberate defects in Q3; a credit note alone in Q4\n";

$manual('2026-09-20', 'Falsch: MWST von Hand', [PostingLine::debit('1020', chf('5.00')), PostingLine::credit('2200', chf('5.00'))]);
$svc   = $service($settings());
$q3    = $q($svc, '2026-08-15');
$r     = $svc->reconciliation($q3);
$owed  = $r->vatAccounts[0];
$found = $svc->findings($q3);
check('C1 an uncoded line on the VAT-owed account: 2200 moves 111.99 against 106.99 coded — difference 5.00, a BLOCKING finding naming the account; the turnover remainder stays 0.00',
    $owed->accountNumber === '2200' && $owed->difference()->equals(chf('5.00')) && !$r->vatAccountsAgree() && $r->remainder()->isZero()
    && $codesOf($found)[0] === 'B:vat-account-remainder' && $found[0]->reference === 'account:2200' && str_contains($found[0]->message, 'Differenz 5.00'),
    json_encode($codesOf($found)));

$manual('2026-09-21', 'Falsch: Basis ungleich Betrag', [
    PostingLine::debit('1020', chf('108.10')),
    PostingLine::credit('3200', chf('100.00'), null, 'UN', 810, chf('90.00'), chf('8.10')),
    PostingLine::credit('2200', chf('8.10')),
]);
$manual('2026-09-22', 'Verkauf Büromöbel', [PostingLine::debit('1020', chf('1000.00')), PostingLine::credit('1510', chf('1000.00'))]);
$manual('2026-09-23', 'Abschreibung Mobiliar', [PostingLine::debit('6800', chf('200.00')), PostingLine::credit('1519', chf('200.00'))]);
$svc   = $service($settings());
$r     = $svc->reconciliation($q3);
$found = $svc->findings($q3);
$codes = $codesOf($found);
$assetFindings = array_values(array_filter($found, static fn($f) => $f->code === VatReturnFinding::UNCODED_ASSET_CREDIT));
check('C2 a coded revenue line whose base (90.00) is not its amount (100.00): the turnover remainder becomes 10.00 — a WARNING (screen decision 6), the VAT account difference stays 5.00',
    $r->remainder()->equals(chf('10.00')) && in_array('W:turnover-remainder', $codes, true) && $r->vatAccounts[0]->difference()->equals(chf('5.00')), json_encode($codes));
check('C3 credit lines without a tax code on fixed-asset accounts (KMU group 14): the sale on 1510 is a warning with the account and the credit total; the depreciation on 1519 «Wertberichtigungen …» (number ending in 9 AND the chart\'s name) is NOT (S1b)',
    count($assetFindings) === 1 && $assetFindings[0]->reference === 'account:1510' && $assetFindings[0]->amount->equals(chf('1000.00'))
    && str_contains($assetFindings[0]->message, '1 Haben-Buchung ohne MWST-Code'), json_encode($codes));

$manual('2026-09-24', 'Beratung, Steuer falsch', [
    PostingLine::debit('1020', chf('108.50')),
    PostingLine::credit('3400', chf('100.00'), null, 'UN', 810, chf('100.00'), chf('8.50')),
    PostingLine::credit('2200', chf('8.50')),
]);
$svc   = $service($settings(), new OpenWorkChecks(['vat-return' => [\Z77\Module\Financial\Close\BankReconciliationCheck::class, VatReturnHarnessOpenWork::class]]));
$p     = $svc->preview($q3);
$found = $svc->findings($q3);
$codes = $codesOf($found);
check('C4 the posted tax now exceeds the declared by 1.21 (0.81 of the C2 line taxed on 100.00 with a base of 90.00, 0.40 of a voucher tax above base × rate) — more than half a Rappen per coded document: the rounding warning',
    $p->form->roundingDifference()->equals(chf('1.21')) && in_array('W:rounding-difference', $codes, true), json_encode([$p->form->roundingDifference()->toDecimal(), $codes]));
check('C5 the open-work registry (scope vat-return, from / to of the period) is passed through with its message and reference — every BLOCKING finding before every warning',
    $codes[0] === 'B:open-work' && $found[0]->message === 'Harness: offene Arbeit bis 30.09.2026' && $found[0]->reference === 'invoice-journal'
    && in_array('W:open-work', $codes, true) && $codes === array_merge(array_filter($codes, static fn($c) => str_starts_with($c, 'B:')), array_filter($codes, static fn($c) => str_starts_with($c, 'W:'))),
    json_encode($codes));

$doc('credit-note', 7, '2026-10-15', 'Gutschrift 7 · Beispiel AG', [
    PostingLine::credit('1100', chf('108.10')),
    PostingLine::debit('3200', chf('100.00'), 'Ware zurück', 'UN', 810, chf('-100.00'), chf('-8.10')),
    PostingLine::debit('2200', chf('8.10'), 'MWST UN'),
]);
$svc   = $service($settings());
$q4    = $q($svc, '2026-11-01');
$p     = $svc->preview($q4);
$codes = $codesOf($svc->findings($q4));
check('C6 a credit note alone in Q4: 235 = 100.00, 303 / 299 / 399 negative — warnings, not a refusal (E9); the credit stands in 510, which is shown instead of 500',
    $fig($p, '235') === '100.000000' && $p->form->base('303')->equals(chf('-100.00')) && $p->payable()->equals(chf('-8.10'))
    && $codes === ['W:negative-field', 'W:negative-field', 'W:negative-field'] && $p->field('510')->shown && !$p->field('500')->shown, json_encode($codes));

$checkpoint->save('D', $db, $base, []);
section_D:
// ─────────────────────────────────────────────────────────────────────────
echo "D. A VAT quarter spanning two fiscal years\n";

(new FiscalYearService($wire()))->open(new FiscalYear('2027', day('2027-01-01'), day('2027-02-15')));
(new FiscalYearService($wire()))->open(new FiscalYear('2027-28', day('2027-02-16'), day('2028-02-15')));
$doc('invoice', 8, '2027-02-01', 'Rechnung 8 · Beispiel AG', [PostingLine::debit('1100', chf('108.10')), PostingLine::credit('3200', chf('100.00'), 'Ware', 'UN', 810, chf('100.00'), chf('8.10')), PostingLine::credit('2200', chf('8.10'))]);
$doc('invoice', 9, '2027-03-01', 'Rechnung 9 · Beispiel AG', [PostingLine::debit('1100', chf('216.20')), PostingLine::credit('3200', chf('200.00'), 'Ware', 'UN', 810, chf('200.00'), chf('16.20')), PostingLine::credit('2200', chf('16.20'))]);
$svc   = $service($settings());
$q127  = $q($svc, '2027-01-01');
$p     = $svc->preview($q127);
$lines = $svc->lines($svc->lineSearch($q127, '303'), 0, 10);
$r     = $svc->reconciliation($q127);
check('D1 Q1 2027 spans the short year 2027 (to 15.2.) and 2027-28: both invoices are read — 303 base 300.00, payable 24.30, the lines carry their own year («2027/1», «2027-28/1»)',
    $p->form->base('303')->equals(chf('300.00')) && $p->payable()->equals(chf('24.30')) && $p->breakdownOf('200')->lines === 2
    && array_map(static fn($l) => $l->entryRef(), $lines) === ['2027/1', '2027-28/1'], json_encode(array_map(static fn($l) => $l->entryRef(), $lines)));
check('D2 … and the reconciliation over both years agrees: 3200 moves 300.00 = 200 − 235, 2200 moves 24.30 = the coded tax',
    $r->revenueTotal->equals(chf('300.00')) && $r->remainder()->isZero() && $r->vatAccountsAgree());
check('D3 the list of a fiscal year shows the periods that END in it (screen decision 3): none for the short year 2027, Q1–Q4 2027 for 2027-28 (Q1 2028 ends after it)',
    $svc->periods($years('2027')) === [] && $span($svc->periods($years('2027-28'))) === ['01-01..2027-03-31', '04-01..2027-06-30', '07-01..2027-09-30', '10-01..2027-12-31']);

$checkpoint->save('E', $db, $base, []);
section_E:
// ─────────────────────────────────────────────────────────────────────────
echo "E. Periods per rhythm, «Neue Abrechnung», every refusal\n";

$y2026 = $wire()->getRepository(FiscalYear::class)->findOneBy(['code' => '2026']);
$sum   = $service($settings())->periods($y2026, day('2026-10-05'));
check('E1 quarter: four periods in 2026, each «offen» with the method label; Q1–Q3 elapsed on 5.10.; the payable of Q3 is the preview\'s',
    $span($sum) === ['01-01..2026-03-31', '04-01..2026-06-30', '07-01..2026-09-30', '10-01..2026-12-31']
    && array_map(static fn($s) => $s->elapsed, $sum) === [true, true, true, false] && $sum[0]->stateLabel === 'offen' && $sum[0]->state === 'open'
    && $sum[0]->methodLabel === 'Effektive Methode · Vereinbartes Entgelt' && $sum[0]->payable->isZero()
    && $sum[2]->payable->equals($service($settings())->preview($sum[2]->period)->payable()) && $sum[3]->payable->equals(chf('-8.10')));
check('E2 month: twelve periods in 2026; year: one (1.1.–31.12.); a short first period from «Abrechnung ab» 15.5. (15.5.–30.6., then Q3, Q4)',
    count($service($settings(VatRhythm::Month))->periods($y2026)) === 12 && $span($service($settings(VatRhythm::Year))->periods($y2026)) === ['01-01..2026-12-31']
    && $span($service($settings(VatRhythm::Quarter, '2026-05-15'))->periods($y2026)) === ['05-15..2026-06-30', '07-01..2026-09-30', '10-01..2026-12-31']
    && $service($settings(VatRhythm::Quarter, '2026-05-15'))->periods($y2026)[0]->period->short);
$next = static fn(VatSettings $s, string $today): string => ($n = $service($s)->nextReturn(day($today))) === null ? 'none' : $n->from->format('Y-m-d') . '..' . $n->to->format('Y-m-d');
check('E3 «Neue Abrechnung»: the first elapsed period without a posted return (no return is posted here — the posted ones are tests/financial-vat-return-posting.php) — Q1 on 5.10.2026; Q3 when «Abrechnung ab» is 1.7.; none before the first period has ended; Q1 is not settled while `vat_return` holds no row',
    $next($settings(), '2026-10-05') === '2026-01-01..2026-03-31' && $next($settings(VatRhythm::Quarter, '2026-07-01'), '2026-10-05') === '2026-07-01..2026-09-30'
    && $next($settings(), '2026-03-31') === 'none' && (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return') === 0
    && $service($settings())->isSettled($q($service($settings()), '2026-02-01')) === false);

$gate = static fn(VatSettings $s): ?string => $service($s)->settingsRefusal()?->reason;
check('E4 the gate refuses with a typed reason: not liable; method not set / net tax rate / flat tax rate; consideration not set / received; rhythm not set; «Abrechnung ab» not set — each sentence naming the mandator\'s tab, each fix «mandator:vat»',
    $gate($settings(liable: false)) === VatReturnRefusal::NOT_LIABLE
    && $gate($settings(method: null)) === VatReturnRefusal::METHOD_NOT_SET && $gate($settings(method: VatMethod::NetTaxRate)) === VatReturnRefusal::METHOD_UNSUPPORTED
    && $gate($settings(method: VatMethod::FlatTaxRate)) === VatReturnRefusal::METHOD_UNSUPPORTED
    && $gate($settings(consideration: null)) === VatReturnRefusal::CONSIDERATION_NOT_SET && $gate($settings(consideration: VatConsideration::Received)) === VatReturnRefusal::CONSIDERATION_UNSUPPORTED
    && $gate($settings(rhythm: null)) === VatReturnRefusal::RHYTHM_NOT_SET && $gate($settings(from: null)) === VatReturnRefusal::FROM_NOT_SET
    && str_contains((string) $service($settings(rhythm: null))->settingsRefusal()?->message, 'Reiter «UID und MWST»')
    && str_contains((string) $service($settings(method: VatMethod::NetTaxRate))->settingsRefusal()?->message, '«Saldosteuersatzmethode» wird nicht unterstützt')
    && $service($settings(from: null))->settingsRefusal()?->fix === 'mandator:vat');
check('E5 not liable but «Abrechnung bis» set: the screen stays for the last periods (point 3); every reading method behind the gate refuses with the same reason',
    $gate($settings(liable: false, until: '2026-08-15')) === null
    && $span($service($settings(liable: false, until: '2026-08-15'))->periods($y2026)) === ['01-01..2026-03-31', '04-01..2026-06-30', '07-01..2026-08-15']
    && refusalOf(fn() => $service($settings(liable: false))->periods($y2026)) === VatReturnRefusal::NOT_LIABLE
    && refusalOf(fn() => $service($settings(method: null))->nextReturn()) === VatReturnRefusal::METHOD_NOT_SET
    && refusalOf(fn() => $service($settings(from: null))->preview($q($service($settings()), '2026-08-15'))) === VatReturnRefusal::FROM_NOT_SET);
check('E6 a day outside the liability has no period; a period before 2024 has no form edition',
    refusalOf(fn() => $service($settings())->periodOn(day('2025-12-31'))) === VatReturnRefusal::NO_PERIOD
    && refusalOf(fn() => $service($settings(VatRhythm::Quarter, '2023-01-01'))->preview($service($settings(VatRhythm::Quarter, '2023-01-01'))->periodOn(day('2023-11-01')))) === VatReturnRefusal::NO_FORM);

$manual('2027-05-10', 'Umsatz zu 5 %', [PostingLine::debit('1020', chf('105.00')), PostingLine::credit('3200', chf('100.00'), null, 'UX', 500, chf('100.00'), chf('5.00')), PostingLine::credit('2200', chf('5.00'))]);
$manual('2027-08-10', 'Beratung aus dem Ausland', [
    PostingLine::debit('6200', chf('1000.00'), null, 'BI', 810, chf('1000.00'), chf('81.00')),
    PostingLine::debit('1171', chf('81.00')),
    PostingLine::credit('1020', chf('1000.00')),
    PostingLine::credit('2200', chf('81.00')),
]);
$doc('invoice', 10, '2027-11-10', 'Rechnung 10 · Beispiel AG', [PostingLine::debit('1100', chf('108.10')), PostingLine::credit('3200', chf('100.00'), 'Ware', 'UN', 810, chf('100.00'), chf('8.10')), PostingLine::credit('2200', chf('8.10'))]);
$db->executeStatement("UPDATE journal_line SET tax_code = 'QQ' WHERE tax_code = 'UN' AND entry_id = (SELECT id FROM journal_entry WHERE source_type = 'invoice' AND source_ref = '10')");
$svc = $service($settings());
$unmapped = thrown(fn() => $svc->preview($q($svc, '2027-05-01')));
$rc       = thrown(fn() => $svc->preview($q($svc, '2027-08-01')));
$unknown  = thrown(fn() => $svc->preview($q($svc, '2027-11-01')));
check('E7 an unmapped code + rate refuses the period (UX at 5 %, the code named, fix «tax-code:UX»); reverse-charge lines refuse it until stage S6 (BI); a code module-vat does not know refuses it (QQ)',
    $unmapped instanceof VatReturnRefusedException && $unmapped->refusal->reason === VatReturnRefusal::UNMAPPED_CODE && $unmapped->refusal->fix === 'tax-code:UX' && str_contains($unmapped->getMessage(), 'Satz 5 %')
    && $rc instanceof VatReturnRefusedException && $rc->refusal->reason === VatReturnRefusal::REVERSE_CHARGE && str_contains($rc->getMessage(), 'Code BI')
    && $unknown instanceof VatReturnRefusedException && $unknown->refusal->reason === VatReturnRefusal::UNKNOWN_TAX_CODE && str_contains($unknown->getMessage(), '«QQ»'),
    implode(' | ', array_map(static fn($e) => $e?->getMessage() ?? 'nothing', [$unmapped, $rc, $unknown])));
$list = $svc->periods($years('2027-28'));
check('E8 the list keeps a refused period as a row — its payable null and its refusal named (Q2 unmapped, Q3 reverse charge, Q4 unknown code), Q1 computed',
    $list[0]->payable?->equals(chf('24.30')) && $list[0]->refusal === null
    && $list[1]->payable === null && $list[1]->refusal?->reason === VatReturnRefusal::UNMAPPED_CODE
    && $list[2]->refusal?->reason === VatReturnRefusal::REVERSE_CHARGE && $list[3]->refusal?->reason === VatReturnRefusal::UNKNOWN_TAX_CODE);
check('E9 the field list asked of a field that is not on the form is a programming error',
    thrown(fn() => $svc->lineSearch($q($svc, '2027-01-01'), '999')) instanceof \InvalidArgumentException);

$checkpoint->save('F', $db, $base, []);
section_F:
// ─────────────────────────────────────────────────────────────────────────
echo "F. The screens (stage S1b) — list, Formular, Herkunft, Abstimmung, entries, PDF, menu, ACL\n";
// The trait runs as the backend mounts it: a host double provides html() / redirect() / bytes() / em() and the
// layout and help objects; DI gets a Request and a session. The templates render through the REAL TemplateRenderer.
// The ledger is the one sections B–E left: Q3 2026 with the defects of C, Q4 2026 a credit note alone, 2027-28 with
// Q1 computed and Q2–Q4 refused.
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

$useRequest = static function (array $get, bool $fetch = false) use ($wire): void {
    $wire();
    $_SESSION = [];
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $fetch) {
        public function __construct(private array $get, private bool $fetch) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
    }, true);
    \Z77\Core\DI::getInstance()->set('SessionManager', \Z77\Core\Session\SessionManager::class, true);
};
/** A host double of the backend mount: the given settings (null = the mandator record's), no open work. */
$screen = static function (array $get, ?VatSettings $s = null, bool $fetch = false) use ($useRequest, $sources) {
    $useRequest($get, $fetch);

    return new class($s, $sources) {
        use \Z77\Module\Financial\Ui\VatReturnControllerTrait { listAction as public; formAction as public; originAction as public; reconciliationAction as public; entriesAction as public; pdfAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $help;
        public ?string $redirectedTo = null;
        public array $sent = [];
        private ?VatReturnService $svc = null;
        public function __construct(private ?VatSettings $settings, private VatReturnSources $sourcesIn)
        {
            $this->help          = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = ['main' => ['Backend/VatReturnController/listAction']];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
        }
        protected function vatReturns(): VatReturnService { return $this->svc ??= new VatReturnService($this->em(), $this->settings, new OpenWorkChecks([]), $this->sourcesIn); }
        protected function vatReturnPoster(): \Z77\Module\Financial\VatReturn\VatReturnPostingService { return new \Z77\Module\Financial\VatReturn\VatReturnPostingService($this->em(), 'harness', new OpenWorkChecks([]), $this->settings, $this->sourcesIn); }
        protected function vatReturnCanReach(string $action): bool { return true; }
        protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        protected function bytes(string $content, string $filename, string $mimeType, bool $inline = true): \Z77\Core\Http\Response\BytesResponse { $this->sent = [$content, $filename, $mimeType, $inline]; return new \Z77\Core\Http\Response\BytesResponse($content, $filename, $mimeType, $inline); }
    };
};
$render = static fn(string $template, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial('Backend/VatReturnController/' . $template, $context);
/** The page as the shell shows it: its main template (the last one added). */
$page = static fn(object $host): string => $render(basename((string) end($host->layoutManager->sections['main'])), $host->context);

// ── the list ──
$host = $screen(['year' => '2026']);
$host->listAction();
$list = $page($host);
$act  = $render('act', $host->context);
check('F1 the list of 2026: the four quarters that end in it, each a row with the state icon first (opens «Formular» of the period) and the PDF icon; «Neue Abrechnung» (owner decision 3 of 2026-11-25) in the action cell opens Q1 2026 — accent, NO «+»; the year at the top of the rail; the help in the toolbar; the column «Gebucht von / am» (empty — nothing posted), no ⋮, the protocol card «Noch keine MWST-Abrechnung gebucht.» with «Ganzes Protokoll»; nowhere «Eingereicht» (decision 1)',
    array_map(static fn($r) => $r['label'], $host->context['rows']) === ['1. Quartal 2026', '2. Quartal 2026', '3. Quartal 2026', '4. Quartal 2026']
    && substr_count($list, 'data-vat-period=') === 4 && str_contains($list, '/backend/finance/vat-return/form?from=2026-07-01') && substr_count($list, '/backend/finance/vat-return/pdf?from=') === 4
    && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/VatReturnController/act'] && str_contains($act, 'Neue Abrechnung') && !str_contains($act, 'Nächste') && str_contains($act, 'be-btn--primary')
    && !str_contains($act, 'icon-plus') && str_contains($act, 'form?from=2026-01-01') && ($host->layoutManager->sections['railSelect'] ?? []) === ['Backend/partials/fiscalYearSelect']
    && $host->help->has() && $host->help->inToolbar() && !str_contains($list, 'Eingereicht') && str_contains($list, 'Gebucht von / am') && !str_contains($list, 'data-menu-open')
    && str_contains($list, 'Noch keine MWST-Abrechnung gebucht.') && str_contains($list, 'href="/backend/finance/change-log/closes-and-vat"') && substr_count($list, 'data-vat-state="open"') === 4);
$q3sum = $host->context['rows'][2]['summary'];
check('F2 … each row: Periode, Zeitraum, Methode, Zustand «offen», Zahllast = the preview\'s payable amount',
    str_contains($list, '01.07.–30.09.2026') && str_contains($list, 'Effektive Methode · Vereinbartes Entgelt') && str_contains($list, '>offen</span>')
    && $q3sum->payable->equals($service()->preview($q3sum->period)->payable()) && str_contains($list, \Z77\Shared\Money\AmountFormat::of($q3sum->payable)));
$host  = $screen(['year' => '2027-28']);
$host->listAction();
$list2 = $page($host);
check('F3 a refused period stays a row (2027-28: Q2 unmapped code, Q3 reverse charge, Q4 unknown code) — no PDF, no amount, its reason under it with the link to the place that clears it; Q1 computed',
    substr_count($list2, 'data-vat-refused=') === 3 && str_contains($list2, 'data-vat-refused="unmapped-code"') && str_contains($list2, 'data-vat-refused="reverse-charge"')
    && substr_count($list2, '/backend/finance/vat-return/pdf?from=') === 1 && str_contains($list2, 'MWST-Codes öffnen — Code UX') && str_contains($list2, '/backend/finance/tax-code/list')
    && str_contains($list2, 'Bezugsteuer (Code BI)') && str_contains($list2, '>24.30</span>'));
$host = $screen(['year' => '2026'], $settings(liable: false));
$host->listAction();
$off  = $page($host);
check('F4 the settings gate: a mandator not liable to VAT gets that one message with the link to the mandator\'s tab — no list, no action cell, no year dropdown',
    str_contains($off, 'data-vat-return-refusal="not-liable"') && str_contains($off, 'nicht mehrwertsteuerpflichtig') && str_contains($off, '/backend/finance/mandator/edit')
    && str_contains($off, 'Reiter «UID und MWST»') && !str_contains($off, 'data-vat-period=') && !isset($host->layoutManager->sections['hc1']) && $host->context['fySelection'] === null);

// ── Formular ──
$host = $screen(['from' => '2026-08-15']);
$host->formAction();
$formHtml = $page($host);
$p3       = $service()->preview($q($service(), '2026-08-15'));
$shownQ3  = array_values(array_map(static fn($f) => $f->field, array_filter($p3->fields, static fn($f) => $f->shown)));
preg_match_all('/data-vat-field="(\d+)"/', $formHtml, $m);
$findingCodes = [];
preg_match_all('/data-vat-finding="([a-z-]+)" data-vat-finding-blocking="([01])"/', $formHtml, $fm, PREG_SET_ORDER);
foreach ($fm as $hit) { $findingCodes[] = ($hit[2] === '1' ? 'B:' : 'W:') . $hit[1]; }
check('F5 «Formular» of Q3 2026 (any day of the period in ?from=): ONLY the shown fields, in form order; the head with period, «offen», method, form edition and «Deklaration netto (ohne MWST)»; the tabs in the toolbar; the period is not postable (blocked) — the action cell EMPTY, no «MWST buchen» anywhere (owner decision 5 of 2026-11-25: no disabled button)',
    $m[1] === $shownQ3 && str_contains($formHtml, 'Deklaration netto (ohne MWST)') && str_contains($formHtml, 'ESTV 0535') && str_contains($formHtml, 'data-vat-return-state="open"')
    && !isset($host->layoutManager->sections['hc1']) && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/VatReturnController/tabs']
    && ($host->layoutManager->sections['main'] ?? []) === ['Backend/VatReturnController/form'] && !str_contains($formHtml . $render('tabs', $host->context), 'MWST buchen')
    && str_contains($render('tabs', $host->context), 'aria-current="page">Formular') && str_contains($render('crumb', $host->context), '3. Quartal 2026'), json_encode([$m[1], $shownQ3]));
$posted399 = $p3->postedTaxOwed();
check('F6 the posted tax and the difference stand ONLY at the total owed and at the payable amount (decision 5): 399 «gebucht» = Σ posted output tax, 500 «gebucht» = the posted tax of the form',
    substr_count($formHtml, 'data-vat-posted') === 2 && preg_match('/data-vat-field="399".*?data-vat-posted>([^<]*)</s', $formHtml, $g399) === 1 && $g399[1] === \Z77\Shared\Money\AmountFormat::of($posted399)
    && $posted399->equals($p3->form->postedTax->add($p3->form->value('479')->rounded())), json_encode([$g399[1] ?? null, $posted399->toDecimal()]));
check('F7 «Offene Punkte» are the PLAN\'s (stage S2): blocking first («sperrt») — the posting precondition `not-next` first (Q1 2026 is not posted), then the return\'s own (the gap on 2200) —, then the warnings; each with the link its reference names — the return to settle first, the account statement of 2200 over the period, the tab «Abstimmung», the account statement of 1510',
    ($findingCodes[0] ?? '') === 'B:not-next' && ($findingCodes[1] ?? '') === 'B:vat-account-remainder' && str_contains($formHtml, 'href="/backend/finance/vat-return/form?from=2026-01-01">Zu dieser Abrechnung<') && in_array('W:turnover-remainder', $findingCodes, true) && in_array('W:uncoded-asset-credit', $findingCodes, true)
    && str_contains($formHtml, '/backend/finance/report/account-statement?year=2026&amp;from=2026-07-01&amp;to=2026-09-30&amp;account=2200') && str_contains($formHtml, '>Kontoblatt 2200<')
    && str_contains($formHtml, '/backend/finance/vat-return/reconciliation?from=2026-07-01') && str_contains($formHtml, 'account=1510') && str_contains($formHtml, 'badge--danger">sperrt'),
    json_encode($findingCodes));
$host = $screen(['from' => '2027-01-01']);
$host->formAction();
$q127Form = $page($host);
check('F8 a period whose return has no findings of its own (Q1 2027, not ended yet) still lists the plan\'s precondition — ONE open point, `not-elapsed` (`not-next` once it has ended and 2026 is not settled) (owner decision 5 of 2026-11-25: the reason is the first row), the action cell empty',
    substr_count($q127Form, 'data-vat-finding=') === 1 && preg_match('/data-vat-finding="(not-elapsed|not-next)" data-vat-finding-blocking="1"/', $q127Form) === 1 && !isset($host->layoutManager->sections['hc1']),
    json_encode([preg_match_all('/data-vat-finding="([a-z-]+)"/', $q127Form, $f8) ? $f8[1] : [], $host->layoutManager->sections['hc1'] ?? null]));

// ── Herkunft ──
$host = $screen(['from' => '2026-07-01']);
$host->originAction();
$tree = $page($host);
$treeOk = true;
foreach ($p3->breakdown as $node) {
    $treeOk = $treeOk && preg_match('/data-vat-node="' . $node->key . '">.*?data-vat-amount>([^<]*)</s', $tree, $hit) === 1
        && ($hit[1] === (in_array($node->key, ['400', '405'], true) ? '' : e(\Z77\Shared\Money\AmountFormat::of($node->amount))));
}
$n3200 = $p3->breakdownOf('200')->child('invoice')->child('3200');
$parts = [
    'figures'   => $treeOk,
    'tree'      => str_contains($tree, 'be-list__table--tree'),
    'collapsed' => !str_contains($tree, 'checked') && !str_contains($tree, '<script'),
    'node'      => str_contains($tree, 'data-vat-node="200/invoice/3200"'),
    'link'      => str_contains($tree, 'entries?from=2026-07-01&amp;field=200&amp;origin=invoice&amp;account=' . $acc('3200')),
    'tab'       => str_contains($render('tabs', $host->context), 'aria-current="page">Herkunft'),
];
check('F9 «Herkunft»: the tree field › origin › account — every field node with its figure, every level collapsed when the page opens (no input checked, no script), the account row links to its entries (field, origin, account); the tab «Herkunft» active',
    !in_array(false, $parts, true), falseOf($parts));

// ── the entries of a node ──
$host = $screen(['from' => '2026-07-01', 'field' => '200', 'origin' => 'invoice', 'account' => (string) $acc('3200')]);
$host->entriesAction();
$entries = $page($host);
$t = $host->context['totals'];
check('F10 the entries of 200 › Rechnungen › 3200: a standard list (fetch region, the search form, the column head) — its total = the tree node (lines, Σ Umsatz); the chips «Herkunft: Rechnungen» and «Konto: 3200 …» each with an × that drops it; the tabs stay with «Herkunft»; the help in the toolbar; the action cell empty',
    !in_array(false, $parts = [
        'total'  => $t['lines'] === $n3200->lines && $t['base']->equals($n3200->base) && count($host->context['lines']) === $n3200->lines,
        'region' => str_contains($entries, 'data-fetch-region="vat-return-entries-list"') && str_contains($entries, 'id="vat-return-entries"'),
        'chips'  => str_contains($entries, 'data-vat-chip="origin"') && str_contains($entries, 'Herkunft: Rechnungen') && str_contains($entries, 'Konto: 3200 '),
        'row'    => preg_match('/data-vat-total-base>' . preg_quote(e(\Z77\Shared\Money\AmountFormat::of($n3200->base)), '/') . '</', $entries) === 1 && str_contains($entries, '/backend/finance/journal/detail?id='),
        'shell'  => $host->help->inToolbar() && !isset($host->layoutManager->sections['hc1']),
        'crumb'  => str_contains($render('crumb', $host->context), 'Ziffer 200'),
        'tabs'   => str_contains($render('tabs', $host->context), 'is-active" href="/backend/finance/vat-return/origin'),
    ], true), falseOf($parts) . ' ' . json_encode([$t['lines'], $t['base']->toDecimal(), $n3200->lines, $n3200->base->toDecimal()]));
preg_match('/data-vat-chip="origin">.*?href="([^"]+)"/s', $entries, $drop);
$host = $screen(['from' => '2026-07-01', 'field' => '200', 'account' => (string) $acc('3200')], null, true);
$host->entriesAction();
$all3200 = $host->context['totals'];
$host = $screen(['from' => '2026-07-01', 'field' => '200', 'f_text' => 'Rechnung 2'], null, true);
$host->entriesAction();
check('F11 the × of «Herkunft» keeps the account and the field (the list then holds every origin on 3200); a fetch of the region answers the list alone (no help, no slots); a column search narrows within the period',
    isset($drop[1]) && !str_contains(html_entity_decode($drop[1]), 'origin=') && str_contains(html_entity_decode($drop[1]), 'field=200') && str_contains(html_entity_decode($drop[1]), 'account=')
    && $all3200['lines'] >= $n3200->lines && !$host->help->has() && !isset($host->layoutManager->sections['hc2'])
    && ($host->layoutManager->sections['main'] ?? []) === ['Backend/VatReturnController/entries'] && $host->context['totals']['lines'] === 1);
$host = $screen(['from' => '2026-07-01', 'field' => '999']);
$host->entriesAction();
check('F12 a field that is not on the form → back to «Herkunft»', $host->redirectedTo === '/backend/finance/vat-return/origin?from=2026-07-01');

// ── Abstimmung ──
$host = $screen(['from' => '2026-07-01']);
$host->reconciliationAction();
$reco = $page($host);
$host = $screen(['from' => '2027-01-01']);
$host->reconciliationAction();
$recoClean = $page($host);
check('F13 «Abstimmung»: three cards — Umsatz (revenue accounts as a disclosure, 200 − 235, Unterschied, «davon erklärt», «Nicht erklärt»), Vorsteuer, Geschuldete MWST; ONLY «Nicht erklärt» takes the alert colour and only when not 0.00 — Q3 2026: the turnover remainder 10.00 and 2200 (5.00) red, 1170 / 1171 quiet; Q1 2027: no alert at all; account numbers link to the account statement',
    str_contains($reco, 'data-vat-reco="turnover"') && str_contains($reco, 'data-vat-reco="input"') && str_contains($reco, 'data-vat-reco="owed"')
    && str_contains($reco, 'Formular: Ziffer 200 abzüglich Ziffer 235') && str_contains($reco, 'davon erklärt') && str_contains($reco, 'Nicht erklärt')
    && preg_match('/data-vat-remainder><span class="badge badge--danger" data-vat-rest="1">10.00</', $reco) === 1
    && preg_match('/data-vat-account="2200">.*?data-vat-rest="1">5.00</s', $reco) === 1 && preg_match('/data-vat-account="1170">.*?data-vat-rest="0">0.00</s', $reco) === 1
    && substr_count($reco, 'badge--danger') === substr_count($reco, 'data-vat-rest="1"') && !str_contains($recoClean, 'badge--danger') && str_contains($recoClean, 'data-vat-rest="0"')
    && str_contains($reco, 'account-statement?year=2026&amp;from=2026-07-01&amp;to=2026-09-30&amp;account=3200'));

// ── refusals of one return ──
$host = $screen(['from' => '2027-08-01']);
$host->formAction();
$rc = $page($host);
$hostNo = $screen(['from' => '2025-12-31']);
$hostNo->originAction();
$hostNone = $screen([]);
$hostNone->formAction();
check('F14 a refused period (reverse charge, until S6) shows the refusal and its link instead of the form — no tabs; a day outside the liability names it; no day at all → the list',
    ($host->layoutManager->sections['main'] ?? []) === ['Backend/VatReturnController/refused'] && str_contains($rc, 'data-vat-return-refused="reverse-charge"') && str_contains($rc, 'MWST-Codes öffnen')
    && !isset($host->layoutManager->sections['hc2']) && ($hostNo->context['refusal']?->reason ?? '') === VatReturnRefusal::NO_PERIOD
    && $hostNone->redirectedTo === '/backend/finance/vat-return/list');

// ── the PDF ──
$host = $screen(['from' => '2026-07-01']);
$host->pdfAction();
$pdf  = \Z77\Module\Financial\Pdf\VatReturnPdf::of($p3, $service()->findings($q($service(), '2026-08-15')), '3. Quartal 2026', null, '24.11.2026 12:00')->withoutCompression()->output();
$cp   = static fn(string $s): string => str_replace(['(', ')'], ['\\(', '\\)'], (string) iconv('UTF-8', 'Windows-1252', $s));
$host2 = $screen(['from' => '2027-08-01']);
$host2->pdfAction();
check('F15 the PDF of Q3 2026: application/pdf, «mwst-abrechnung-3-quartal-2026.pdf»; its text carries the title, the period, the method, the form edition, «Deklaration netto (ohne MWST)», the exact tax of 303, the payable amount, the open points and «Keine Einreichung …»; a refused period → its page',
    !in_array(false, $parts = [
        'action'   => ($host->sent[1] ?? '') === 'mwst-abrechnung-3-quartal-2026.pdf' && ($host->sent[2] ?? '') === 'application/pdf' && str_starts_with((string) ($host->sent[0] ?? ''), '%PDF-'),
        'title'    => str_contains($pdf, $cp('MWST-Abrechnung')) && str_contains($pdf, $cp('3. Quartal 2026')),
        'method'   => str_contains($pdf, $cp('Effektive Methode · Vereinbartes Entgelt')),
        'edition'  => str_contains($pdf, $cp('ESTV 0535')),
        'note'     => str_contains($pdf, $cp('Deklaration netto (ohne MWST)')),
        'exact'    => str_contains($pdf, $cp(rtrim($p3->form->value('303')->toDecimal(), '0'))),
        'payable'  => str_contains($pdf, $cp(\Z77\Shared\Money\AmountFormat::of($p3->payable()))),
        'findings' => str_contains($pdf, $cp('Offene Punkte')),
        'filing'   => str_contains($pdf, $cp('Keine Einreichung — die Abrechnung wird im ePortal der ESTV eingereicht.')),
        'refused'  => $host2->redirectedTo === '/backend/finance/vat-return/form?from=2027-07-01',
    ], true), falseOf($parts) . ' ' . $p3->form->value('303')->toDecimal() . ' ' . json_encode([$host->redirectedTo, $host->sent[1] ?? null, $host->sent[2] ?? null, substr((string) ($host->sent[0] ?? ''), 0, 5)]));

// ── menu, mount, ACL, guards ──
$seed  = json_decode((string) file_get_contents($root . '/packages/module-financial/data/framework/routing/navigation.d/module-financial.json'), true);
$byKey = array_column($seed, null, 'key');
$order = array_map(static fn($e) => $e['key'], array_values(array_filter($seed, static fn($e) => ($e['parent_key'] ?? null) === 'finanzen')));
usort($order, static fn($a, $b) => $byKey[$a]['sort_key'] <=> $byKey[$b]['sort_key']);
$backendConfig = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
check('F16 the menu: «MWST-Abrechnungen» at the end of the Finanzen rail, the existing order untouched (backend/finance/vat-return/list); the mount uses the trait and its layout delegates to VatReturnLayout; the ACL: the finance module role ADMIN for everything, «Aufheben» (confirm-lift, lift) PINNED to ADMIN like «Wieder öffnen» (owner decision 2 of 2026-11-25)',
    ($byKey['mwst-abrechnungen']['controller'] ?? '') === 'vat-return' && ($byKey['mwst-abrechnungen']['action'] ?? '') === 'list' && ($byKey['mwst-abrechnungen']['parent_key'] ?? '') === 'finanzen'
    && $order === ['journal', 'auswertungen', 'aenderungsprotokoll', 'bankkonto', 'mwst-abrechnungen']
    && in_array(\Z77\Module\Financial\Ui\VatReturnControllerTrait::class, class_uses(\Z77\Module\Backend\Ui\Controllers\Finance\VatReturnController::class), true)
    && (require $root . '/packages/module-backend/src/Ui/Config/Finance/vatReturnControllerConfig.inc.php') === \Z77\Module\Financial\Ui\VatReturnLayout::config()
    && ($backendConfig['controllers']['finance']['VatReturnController'] ?? null) === ['actions' => ['confirmLiftAction' => \Z77\Core\Config\AuthRole::ADMIN, 'liftAction' => \Z77\Core\Config\AuthRole::ADMIN]]
    && $backendConfig['moduleRole'] === \Z77\Core\Config\AuthRole::ADMIN, json_encode($order));
$screenSrc = implode("\n", array_map('file_get_contents', array_merge(
    [$root . '/packages/module-financial/src/Ui/VatReturnControllerTrait.php', $root . '/packages/module-financial/src/Ui/VatReturnLayout.php', $root . '/packages/module-financial/src/Ui/VatReturnEntriesListing.php', $root . '/packages/module-financial/src/Pdf/VatReturnPdf.php'],
    glob($root . '/packages/module-financial/res/view/templates/Backend/VatReturnController/*.php')
)));
check('F17 the screens: no superglobal, no float, no script / inline handler / innerHTML, no response instantiated; they write ONLY through the posting service (`vatReturnPoster()->post()` / `->lift()`, no persist / flush / reverse of their own); exactly two POST actions (post, lift), each a Fetch POST',
    !in_array(false, $parts = [
        'superglobal' => !preg_match('/\$_(POST|GET|SERVER|REQUEST|SESSION)\b/', $screenSrc),
        'float'       => !preg_match('/\(float\)|floatval\(|\bfloat\s+\$|number_format\(|\bround\(/', $screenSrc),
        'script'      => !preg_match('/<script|onclick=|innerHTML/i', $screenSrc),
        'response'    => !preg_match('/new\s+(Html|Redirect|Bytes|Json|Fetch)Response/', $screenSrc),
        'write'       => !preg_match('/->(persist|flush|reverse|remove)\(|isPost\(|#\[Csrf/', $screenSrc)
            && preg_match_all('/->(post|lift)\(/', $screenSrc) === 2 && preg_match_all('/vatReturnPoster\(\)->(post|lift)\(/', $screenSrc) === 2
            && preg_match_all('/#\[Fetch, HttpMethod\(\'POST\'\)\]/', $screenSrc) === 2,
    ], true), falseOf($parts));

$checkpoint->save('P', $db, $base, []);
section_P:
// ─────────────────────────────────────────────────────────────────────────
echo "P. The screens of stage S2 — «MWST buchen», «Aufheben», the posted return, the journal\n";
// The owner decisions of 2026-11-25 on the ledger of B–E (Q1 / Q2 2026 without a coded line, Q3 2026 with the defects of C,
// Q4 2026 a credit note alone). The trait runs through a host double as in F; POSTs carry a JSON body, the CSRF service is a
// double, the writer is the REAL VatReturnPostingService (actor «harness», no open work, the archive under the harness root).
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

$useBody = static function (array $get, array $body = [], bool $fetch = false) use ($wire): void {
    $wire();
    $_SESSION = [];
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $body, $fetch) {
        public function __construct(private array $get, private array $body, private bool $fetch) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function getJsonBody(): array { return $this->body; }
        public function isPost(): bool { return $this->body !== []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
    }, true);
    \Z77\Core\DI::getInstance()->set('SessionManager', \Z77\Core\Session\SessionManager::class, true);
    \Z77\Core\DI::getInstance()->set('CsrfService', fn() => new class {
        public function generateEntityToken(string $context, int|string $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int|string $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
};
/** The host double of stage S2: $admin = who may reach `lift` (the access config's answer). */
$s2 = static function (array $get, array $body = [], bool $admin = true, bool $fetch = false) use ($useBody, $sources) {
    $useBody($get, $body, $fetch);

    return new class($sources, $admin) {
        use \Z77\Module\Financial\Ui\VatReturnControllerTrait {
            listAction as public; formAction as public; originAction as public; reconciliationAction as public; revisionAction as public; voucherAction as public; pdfAction as public;
            actionsAction as public; confirmPostAction as public; postAction as public; confirmLiftAction as public; liftAction as public;
        }
        public array $context = [];
        public object $layoutManager;
        public object $help;
        public object $messageService;
        public ?string $redirectedTo = null;
        public array $sent = [];
        private ?VatReturnService $svc = null;
        public function __construct(private VatReturnSources $sourcesIn, private bool $admin)
        {
            $this->help          = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = ['main' => ['Backend/VatReturnController/listAction']];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
            };
        }
        protected function vatReturns(): VatReturnService { return $this->svc ??= new VatReturnService($this->em(), null, new OpenWorkChecks([]), $this->sourcesIn); }
        protected function vatReturnPoster(): \Z77\Module\Financial\VatReturn\VatReturnPostingService { return new \Z77\Module\Financial\VatReturn\VatReturnPostingService($this->em(), 'harness', new OpenWorkChecks([]), null, $this->sourcesIn); }
        protected function vatReturnCanReach(string $action): bool { return $this->admin; }
        protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        protected function bytes(string $content, string $filename, string $mimeType, bool $inline = true): \Z77\Core\Http\Response\BytesResponse { $this->sent = [$content, $filename, $mimeType, $inline]; return new \Z77\Core\Http\Response\BytesResponse($content, $filename, $mimeType, $inline); }
    };
};
$render = static fn(string $template, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial('Backend/VatReturnController/' . $template, $context);
$page   = static fn(object $host): string => $render(basename((string) end($host->layoutManager->sections['main'])), $host->context);
$slot   = static fn(object $host, string $section): string => implode('', array_map(static fn(string $p): string => $render(basename($p), $host->context), $host->layoutManager->sections[$section] ?? []));
/** The envelope a Fetch answer sends (status, flashes, commands, redirect). */
$envelope = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => (fn() => $this->build())->call($r);
$cmdHtml  = static fn(array $env, string $target): string => implode('', array_map(static fn(array $c): string => $c['html'] ?? '', array_filter($env['commands'] ?? [], static fn(array $c): bool => ($c['action'] ?? '') === 'replace-html' && ($c['target'] ?? '') === $target)));
$returns  = static fn(): array => $db->fetchAllAssociative("SELECT id, period_from, revision, state, live, settlement_entry_id, reversal_entry_id, version FROM vat_return ORDER BY id");
/** The body the window «MWST buchen» posts for $from, as rendered — the hidden fields, the tick when asked. */
$postBody = static function (string $from, bool $tick = false) use ($s2): array {
    $host = $s2(['from' => $from]);
    $host->confirmPostAction();
    $plan = $host->context['plan'];

    return ['from' => $plan->period->from->format('Y-m-d'), 'to' => $plan->period->to->format('Y-m-d'), 'rhythm' => $plan->period->rhythm->value,
        'figures_fingerprint' => $plan->figuresFingerprint, 'warnings_fingerprint' => (string) $plan->warningsFingerprint,
        'entity_csrf' => 'tok-vatReturn.post-' . $plan->period->from->format('Y-m-d')] + ($tick ? ['confirm_warnings' => '1'] : []);
};

// ── the list and the page of an open period ──
$host = $s2(['year' => '2026']);
$host->listAction();
$list = $page($host);
$host = $s2(['from' => '2026-04-01']);
$host->formAction();
$q2Form = $page($host);
check('P1 an open period that is not next (Q2 2026 while Q1 is open): the action cell EMPTY, the reason the FIRST row of «Offene Punkte» — `not-next`, «sperrt», with the link to the return to settle first (owner decision 5: no disabled button, no tooltip)',
    !isset($host->layoutManager->sections['hc1']) && preg_match('/data-vat-finding="([a-z-]+)" data-vat-finding-blocking="1"/', $q2Form, $first) === 1 && $first[1] === 'not-next'
    && str_contains($q2Form, 'Zuerst die MWST-Periode 1. Quartal 2026 abrechnen') && str_contains($q2Form, 'href="/backend/finance/vat-return/form?from=2026-01-01">Zu dieser Abrechnung<'), json_encode($first ?? null));
$host = $s2(['from' => '2026-01-01']);
$host->formAction();
$postAct = $slot($host, 'hc1');
$sprite = (string) file_get_contents($root . '/packages/module-backend/res/view/templates/partials/icon-sprite.tpl.php');
check('P2 the next period, nothing blocking (Q1 2026, no coded line): the action cell «MWST buchen …» — green (`be-btn--confirm`) WITHOUT the bare check mark, its glyph the document with a check mark `icon-file-check` (owner 2026-11-25 — the phone shows only the glyph; the label in `be-btn__label`), the three dots, it opens the window confirm-post of the period (owner decision 4); the glyph is a symbol of the backend\'s own sprite (viewBox 0 0 24 24)',
    ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/VatReturnController/postAct'] && str_contains($postAct, 'MWST buchen …') && str_contains($postAct, 'be-btn--confirm')
    && !str_contains($postAct, '#icon-check"') && str_contains($postAct, '<use href="#icon-file-check"/>') && str_contains($postAct, '<span class="be-btn__label">MWST buchen …</span>')
    && str_contains($sprite, '<symbol id="icon-file-check" viewBox="0 0 24 24">')
    && str_contains($postAct, 'data-fetch-get="/backend/finance/vat-return/confirm-post?from=2026-01-01"'), $postAct);

// ── the window «MWST buchen» — a nil return ──
$host = $s2(['from' => '2026-02-10']);
$host->confirmPostAction();
$win  = $page($host);
$plan = $host->context['plan'];
check('P3 the window of a NIL return (owner decisions 7, 8): the action row on top «MWST jetzt buchen» (confirm, no check mark) and «Schliessen»; the two hint lines; «Zahllast» 0.00, «Buchung: keine»; no warnings, no tick, no entry table; the hidden fields carry from / to / rhythm and both fingerprints of the plan',
    !in_array(false, $parts = [
        'nil'     => $plan->isNil() && str_contains($win, 'data-vat-post-window="nil"') && str_contains($win, 'keine — es gibt nichts auszugleichen'),
        'button'  => str_contains($win, '>MWST jetzt buchen</button>') && str_contains($win, 'be-btn--confirm') && !str_contains($win, 'icon-check') && strpos($win, 'MWST jetzt buchen') < strpos($win, 'be-modal__body'),
        'hints'   => str_contains($win, 'Zuerst im ePortal einreichen, dann hier buchen. Gebucht heisst eingereicht.') && str_contains($win, 'Danach sind Buchungen mit MWST-Code vom 01.01. bis 31.03.2026 gesperrt.'),
        'payable' => preg_match('/data-vat-post-payable><strong>0.00</', $win) === 1 && !str_contains($win, 'confirm_warnings'),
        'hidden'  => str_contains($win, 'name="figures_fingerprint" value="' . $plan->figuresFingerprint . '"') && str_contains($win, 'name="from" value="2026-01-01"')
            && str_contains($win, 'name="to" value="2026-03-31"') && str_contains($win, 'name="rhythm" value="quarter"') && str_contains($win, 'data-fetch-post="/backend/finance/vat-return/post"'),
    ], true), falseOf($parts));

// ── a refused click: the window stays, nothing is posted ──
$body = $postBody('2026-01-01');
$host = $s2([], ['figures_fingerprint' => str_repeat('0', 64)] + $body);
$env  = $envelope($host->postAction());
$refusalHtml = $cmdHtml($env, '[data-vat-post-refusal]');
$host2 = $s2([], ['entity_csrf' => 'forged'] + $body);
$env2  = $envelope($host2->postAction());
check('P4 a refused click (the figures fingerprint no longer matches — a posting landed meanwhile): status error, the window is NOT closed, its alert slot gets the service\'s sentence (`figures-changed`) and keeps its marker; a forged token is refused the same way; nothing written — no return, no file',
    ($env['status'] ?? '') === 'error' && !in_array('close-modal', array_column($env['commands'] ?? [], 'action'), true) && !isset($env['redirect'])
    && str_contains($refusalHtml, 'haben sich seit der Anzeige geändert') && str_contains($refusalHtml, 'data-vat-post-refusal') && str_contains($refusalHtml, 'role="alert"')
    && ($env2['status'] ?? '') === 'error' && str_contains($cmdHtml($env2, '[data-vat-post-refusal]'), 'Das Fenster ist abgelaufen')
    && $returns() === [] && glob($base . '/data/archive/financial/vat-return/2026/*.pdf') === [], json_encode([$env, $env2], JSON_UNESCAPED_UNICODE));

$host = $s2([], $body);
$env  = $envelope($host->postAction());
$rows = $returns();
check('P5 «MWST jetzt buchen» of the nil return: success — the window closes, the answer leads to the period\'s page; the flash «… gebucht — Nullabrechnung, keine Buchung.»; one row, revision 1, posted, live, NO settlement entry',
    ($env['status'] ?? '') === 'success' && in_array('close-modal', array_column($env['commands'] ?? [], 'action'), true) && ($env['redirect']['url'] ?? '') === '/backend/finance/vat-return/form?from=2026-01-01'
    && $host->messageService->flashes === [['success', 'MWST-Abrechnung 1. Quartal 2026 gebucht — Nullabrechnung, keine Buchung.']]
    && count($rows) === 1 && $rows[0]['state'] === 'posted' && (int) $rows[0]['live'] === 1 && $rows[0]['settlement_entry_id'] === null, json_encode([$env, $rows], JSON_UNESCAPED_UNICODE));
$q1Id = (int) $rows[0]['id'];

// ── the posted return ──
$host = $s2(['from' => '2026-01-01']);
$host->formAction();
$q1Page  = $page($host);
$q1Tools = $slot($host, 'hc2');
$hostNo  = $s2(['from' => '2026-01-01'], [], false);
$hostNo->formAction();
$hostO   = $s2(['from' => '2026-01-01']);
$hostO->originAction();
check('P6 the page of a POSTED period (owner decision 6): the action cell empty; «Formular» the STORED figures (`data-vat-return-stored`), the head «gebucht», «Gebucht … von harness», «keine Buchung (Nullabrechnung)», no open points; the tools «Beleg» (the archived voucher) and «Aufheben …» (danger) — only for who may lift; no «PDF»; «Herkunft» still works and carries «Beleg»',
    !in_array(false, $parts = [
        'cell'   => !isset($host->layoutManager->sections['hc1']),
        'stored' => str_contains($q1Page, 'data-vat-return-stored="' . $q1Id . '"') && str_contains($q1Page, 'data-vat-return-state="posted"') && str_contains($q1Page, 'von harness')
            && str_contains($q1Page, 'keine Buchung (Nullabrechnung)') && !str_contains($q1Page, 'data-vat-findings'),
        'tools'  => str_contains($q1Tools, 'href="/backend/finance/vat-return/voucher?id=' . $q1Id . '"') && str_contains($q1Tools, 'data-fetch-get="/backend/finance/vat-return/confirm-lift?id=' . $q1Id . '"')
            && str_contains($q1Tools, 'be-btn--danger') && !str_contains($q1Tools, 'data-vat-return-pdf') && str_contains($q1Tools, 'aria-current="page">Formular'),
        'admin'  => !str_contains($slot($hostNo, 'hc2'), 'confirm-lift') && str_contains($slot($hostNo, 'hc2'), 'voucher?id='),
        'origin' => str_contains($page($hostO), 'data-vat-return-view="origin"') && str_contains($slot($hostO, 'hc2'), 'voucher?id=' . $q1Id) && str_contains($slot($hostO, 'hc2'), 'aria-current="page">Herkunft'),
    ], true), falseOf($parts));
$host = $s2(['id' => (string) $q1Id]);
$host->voucherAction();
check('P7 «Beleg» answers the ARCHIVED voucher, verified by its SHA-256: application/pdf, «mwst-abrechnung-2026-01-01-2026-03-31-r1.pdf», the bytes of the file whose hash the row names; the computed PDF of a posted period leads to it',
    ($host->sent[1] ?? '') === 'mwst-abrechnung-2026-01-01-2026-03-31-r1.pdf' && ($host->sent[2] ?? '') === 'application/pdf'
    && hash('sha256', (string) ($host->sent[0] ?? '')) === $db->fetchOne('SELECT voucher_sha256 FROM vat_return WHERE id = ?', [$q1Id])
    && (function () use ($s2, $q1Id): bool { $h = $s2(['from' => '2026-01-01']); $h->pdfAction(); return $h->redirectedTo === '/backend/finance/vat-return/voucher?id=' . $q1Id; })());

// Q2 2026 — a second nil return, through the window as well.
$host = $s2([], $postBody('2026-04-01'));
$envQ2 = $envelope($host->postAction());
$host = $s2(['year' => '2026']);
$host->listAction();
$list   = $page($host);
$q2Id   = (int) $db->fetchOne("SELECT id FROM vat_return WHERE period_from = '2026-04-01'");
$hostNo = $s2(['year' => '2026'], [], false);
$hostNo->listAction();
$listNo = $page($hostNo);
check('P8 the list after two returns (owner decision 9): Q1 and Q2 «gebucht» with a lock, the PDF icon of each the archived voucher, «Gebucht von / am» «harness · …»; the ⋮ ONLY on the newest posted row (Q2) and only for who may lift — without that right no ⋮ column at all; «Neue Abrechnung» opens Q3; the protocol under the list: «MWST gebucht» twice, newest first',
    !in_array(false, $parts = [
        'posted' => ($envQ2['status'] ?? '') === 'success' && substr_count($list, 'data-vat-state="posted"') === 2 && substr_count($list, 'data-vat-state="open"') === 2
            && str_contains($list, 'voucher?id=' . $q1Id . '"') && str_contains($list, 'voucher?id=' . $q2Id . '"') && substr_count($list, 'data-vat-pdf="voucher"') === 2 && str_contains($list, '>harness · '),
        'menu'   => substr_count($list, 'data-menu-open=') === 1 && str_contains($list, 'data-menu-open="/backend/finance/vat-return/actions?id=' . $q2Id . '"') && !str_contains($listNo, 'data-menu-open') && !str_contains($listNo, 'aria-label="Aktionen"'),
        'next'   => str_contains($slot($host, 'hc1'), 'form?from=2026-07-01'),
        'log'    => substr_count($list, 'data-vat-log="post"') === 2 && str_contains($list, 'MWST gebucht')
            && strpos(strstr($list, 'data-vat-return-log'), '01.04.–30.06.2026') < strpos(strstr($list, 'data-vat-return-log'), '01.01.–31.03.2026'),
    ], true), falseOf($parts));
$host = $s2(['id' => (string) $q2Id]);
$host->actionsAction();
$menu = $page($host);
$host = $s2(['id' => (string) $q1Id]);
$host->actionsAction();
check('P9 the ⋮ is a context menu (`.be-menu`, no dialog): «Aufheben …» (danger) opening confirm-lift of the latest return; on an earlier return no item, a muted line',
    str_contains($menu, 'class="be-menu"') && str_contains($menu, 'data-fetch-get="/backend/finance/vat-return/confirm-lift?id=' . $q2Id . '"') && str_contains($menu, 'Aufheben …')
    && str_contains($page($host), 'be-menu__note') && !str_contains($page($host), 'confirm-lift'));

// ── a blocked period, then warnings to confirm ──
$host = $s2(['from' => '2026-07-01']);
$host->formAction();
$q3Form = $page($host);
$host2  = $s2(['from' => '2026-07-01']);
$host2->confirmPostAction();
$blockedWin = $page($host2);
$planQ3 = (new \Z77\Module\Financial\VatReturn\VatReturnPostingService($wire(), 'harness', new OpenWorkChecks([]), null, $sources))->plan($q($service(), '2026-07-01'));
$host3  = $s2([], ['from' => '2026-07-01', 'to' => '2026-09-30', 'rhythm' => 'quarter', 'figures_fingerprint' => $planQ3->figuresFingerprint, 'warnings_fingerprint' => (string) $planQ3->warningsFingerprint, 'confirm_warnings' => '1', 'entity_csrf' => 'tok-vatReturn.post-2026-07-01']);
$env3   = $envelope($host3->postAction());
check('P10 Q3 2026 is next but BLOCKED (the uncoded 5.00 on 2200 of C1): the action cell empty, the first open point the gap on 2200; the window says «Buchen nicht möglich» with the blocking points and no button; a POST anyway is refused `blocked` — the window lists the blocking findings the service names; nothing written',
    !isset($host->layoutManager->sections['hc1']) && preg_match('/data-vat-finding="([a-z-]+)" data-vat-finding-blocking="1"/', $q3Form, $f3) === 1 && $f3[1] === 'vat-account-remainder'
    && str_contains($blockedWin, 'data-vat-post-window="refused"') && str_contains($blockedWin, 'Buchen nicht möglich') && str_contains($blockedWin, 'data-vat-post-blocking') && !str_contains($blockedWin, 'MWST jetzt buchen')
    && ($env3['status'] ?? '') === 'error' && str_contains($cmdHtml($env3, '[data-vat-post-refusal]'), 'sperrt das Buchen der MWST-Abrechnung 3. Quartal 2026')
    && str_contains($cmdHtml($env3, '[data-vat-post-refusal]'), 'data-vat-window-findings') && str_contains($cmdHtml($env3, '[data-vat-post-refusal]'), 'MWST-Konto «2200')
    && count($returns()) === 2, json_encode([$f3 ?? null, $env3], JSON_UNESCAPED_UNICODE));

// The gap is cleared: the hand entry on 2200 (no tax code — the freeze does not hold it) is deleted — Q3 has warnings only now.
$wrong = $db->fetchAssociative("SELECT id, version FROM journal_entry WHERE text = 'Falsch: MWST von Hand'");
(new ManualEntryService($wire(), 'harness'))->delete((int) $wrong['id'], (int) $wrong['version']);
$host = $s2(['from' => '2026-07-01']);
$host->formAction();
$host2 = $s2(['from' => '2026-07-01']);
$host2->confirmPostAction();
$warnWin  = $page($host2);
$planW    = $host2->context['plan'];
$bodyQ3   = $postBody('2026-07-01');
$hostNoTick = $s2([], $bodyQ3);
$envNoTick  = $envelope($hostNoTick->postAction());
check('P11 Q3 2026 with warnings only: «MWST buchen …» in the action cell; the window lists the warnings and ONE mandatory tick «Warnungen gelesen — trotzdem buchen», the settlement entry line by line (the shared entry snapshot: 2200, 1170, 1171, 2201, the rounding account); a click WITHOUT the tick is refused `warnings-unconfirmed` — the warnings listed in the window, nothing written',
    ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/VatReturnController/postAct'] && $planW->warnings() !== [] && !$planW->isBlocked()
    && substr_count($warnWin, 'name="confirm_warnings"') === 1 && str_contains($warnWin, 'Warnungen gelesen — trotzdem buchen') && str_contains($warnWin, ' required>')
    && str_contains($warnWin, 'Warnungen (' . count($planW->warnings()) . ')') && str_contains($warnWin, 'data-vat-post-window="entry"')
    && preg_match_all('/<span class="be-list__cell be-list__cell--mono">(\d+)<\/span>/', $warnWin, $acctM) >= 4 && in_array('2200', $acctM[1], true) && in_array('2201', $acctM[1], true)
    && ($envNoTick['status'] ?? '') === 'error' && str_contains($cmdHtml($envNoTick, '[data-vat-post-refusal]'), 'nicht bestätigt') && str_contains($cmdHtml($envNoTick, '[data-vat-post-refusal]'), 'data-vat-window-findings')
    && count($returns()) === 2, json_encode([$acctM[1] ?? null, $envNoTick], JSON_UNESCAPED_UNICODE));
$host = $s2([], $postBody('2026-07-01', true));
$envQ3 = $envelope($host->postAction());
$q3Row = $db->fetchAssociative("SELECT r.id, r.settlement_entry_id, y.code, e.number, e.text FROM vat_return r JOIN journal_entry e ON e.id = r.settlement_entry_id JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE r.period_from = '2026-07-01' AND r.live = 1");
check('P12 … with the tick: posted — the flash names the settlement entry («… gebucht — Buchung 2026/n.»), the page of Q3 is next; the entry «MWST-Abrechnung 3. Quartal 2026» dated 30.09.2026',
    ($envQ3['status'] ?? '') === 'success' && is_array($q3Row) && $host->messageService->flashes === [['success', 'MWST-Abrechnung 3. Quartal 2026 gebucht — Buchung ' . $q3Row['code'] . '/' . $q3Row['number'] . '.']]
    && $q3Row['text'] === 'MWST-Abrechnung 3. Quartal 2026' && ($envQ3['redirect']['url'] ?? '') === '/backend/finance/vat-return/form?from=2026-07-01', json_encode([$envQ3, $q3Row], JSON_UNESCAPED_UNICODE));
$q3Id = (int) $q3Row['id'];

// ── «Aufheben» ──
$host = $s2(['id' => (string) $q1Id]);
$host->confirmLiftAction();
$notLatest = $page($host);
$host = $s2(['id' => (string) $q3Id]);
$host->confirmLiftAction();
$liftWin = $page($host);
$q3Version = (int) $db->fetchOne('SELECT version FROM vat_return WHERE id = ?', [$q3Id]);
check('P13 the window «Aufheben» (owner decision 2): an earlier return → «Aufheben nicht möglich» with the sentence «nur die neueste»; the latest (Q3): the action row «Aufheben» (danger) + «Schliessen», what is lifted (Fassung 1, the entry and its date, the payable amount, the reversal «Aufgehoben: … — Grund» on the same day), the mandatory reason (max 500), the reversal line by line (every line on its other side), id and version hidden',
    str_contains($notLatest, 'Aufheben nicht möglich') && str_contains($notLatest, 'Aufgehoben wird nur die neueste MWST-Abrechnung — zuerst die Abrechnung 3. Quartal 2026 aufheben, dann diese (1. Quartal 2026).')
    && str_contains($liftWin, 'data-fetch-post="/backend/finance/vat-return/lift"') && str_contains($liftWin, '>Aufheben</button>') && str_contains($liftWin, 'be-btn--danger')
    && str_contains($liftWin, 'Fassung 1') && str_contains($liftWin, 'Buchung ' . $q3Row['code'] . '/' . $q3Row['number'] . ' vom 30.09.2026') && str_contains($liftWin, '«Aufgehoben: MWST-Abrechnung 3. Quartal 2026 — Grund»')
    && str_contains($liftWin, 'maxlength="500" required') && str_contains($liftWin, 'name="version" value="' . $q3Version . '"') && str_contains($liftWin, 'name="id" value="' . $q3Id . '"')
    && str_contains($liftWin, 'Danach ist die Periode wieder offen'), $notLatest);
$liftBody = ['id' => (string) $q3Id, 'version' => (string) $q3Version, 'entity_csrf' => 'tok-vatReturn.lift-' . $q3Id];
$host = $s2([], $liftBody + ['reason' => '   ']);
$envNo = $envelope($host->liftAction());
$host = $s2([], $liftBody + ['reason' => 'Gutschrift an Beispiel AG fehlte']);
$envLift = $envelope($host->liftAction());
$q3After = $db->fetchAssociative('SELECT state, live, lift_reason, reversal_entry_id FROM vat_return WHERE id = ?', [$q3Id]);
check('P14 «Aufheben» without a reason: refused `no-reason` — the sentence in the window, the field marked, nothing lifted; with the reason: lifted — the window closes, the page of Q3 (open again), the flash names the reversal',
    ($envNo['status'] ?? '') === 'error' && ($envNo['fields']['reason']['valid'] ?? true) === false && str_contains($cmdHtml($envNo, '[data-vat-lift-refusal]'), 'nur mit einem Grund aufgehoben')
    && ($envLift['status'] ?? '') === 'success' && ($envLift['redirect']['url'] ?? '') === '/backend/finance/vat-return/form?from=2026-07-01'
    && $q3After['state'] === 'lifted' && $q3After['live'] === null && $q3After['lift_reason'] === 'Gutschrift an Beispiel AG fehlte' && $q3After['reversal_entry_id'] !== null
    && str_starts_with($host->messageService->flashes[0][1] ?? '', 'MWST-Abrechnung 3. Quartal 2026 aufgehoben — Storno '), json_encode([$envNo, $envLift, $q3After], JSON_UNESCAPED_UNICODE));
$host = $s2(['year' => '2026']);
$host->listAction();
$list = $page($host);
$host = $s2(['id' => (string) $q3Id]);
$host->revisionAction();
$rev      = $page($host);
$revTools = $slot($host, 'hc2');
check('P15 after the lift: the list shows Q3 «offen» with the small hint «Fassung 1 aufgehoben» (its read-only page), the ⋮ moved to Q2 (now the latest), the protocol «MWST aufgehoben» with the reason first; the revision page: the head «aufgehoben» with posted, lifted, the reversal and the reason, the STORED figures, no view tabs, only «Beleg»',
    str_contains($list, 'href="/backend/finance/vat-return/revision?id=' . $q3Id . '" data-vat-lifted-hint>Fassung 1 aufgehoben</a>') && preg_match('/data-vat-period="2026-07-01" data-vat-state="open"/', $list) === 1
    && str_contains($list, 'data-menu-open="/backend/finance/vat-return/actions?id=' . $q2Id . '"') && strpos($list, 'data-vat-log="lift"') < strpos($list, 'data-vat-log="post"') && str_contains($list, 'Gutschrift an Beispiel AG fehlte')
    && str_contains($rev, 'data-vat-return-state="lifted"') && str_contains($rev, 'data-vat-return-lifted') && str_contains($rev, 'Grund: Gutschrift an Beispiel AG fehlte') && str_contains($rev, 'data-vat-return-stored="' . $q3Id . '"')
    && !str_contains($revTools, 'be-viewtabs') && str_contains($revTools, 'voucher?id=' . $q3Id) && !str_contains($revTools, 'confirm-lift'), $revTools);

// Q3 posted anew — revision 2, its own voucher.
$host = $s2([], $postBody('2026-07-01', true));
$envR2 = $envelope($host->postAction());
$r2 = $db->fetchAssociative("SELECT r.id, y.code, e.number, e.text, e.id AS entry_id FROM vat_return r JOIN journal_entry e ON e.id = r.settlement_entry_id JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE r.period_from = '2026-07-01' AND r.live = 1");
check('P16 Q3 posted anew through the same window: revision 2, «MWST-Abrechnung 3. Quartal 2026 (Neuerstellung 2)», the page head names «Fassung 2»',
    ($envR2['status'] ?? '') === 'success' && is_array($r2) && $r2['text'] === 'MWST-Abrechnung 3. Quartal 2026 (Neuerstellung 2)'
    && (function () use ($s2, $page): bool { $h = $s2(['from' => '2026-07-01']); $h->formAction(); return str_contains($page($h), '· Fassung 2'); })(), json_encode([$envR2, $r2], JSON_UNESCAPED_UNICODE));

// ── the journal (owner decision 11) ──
$journal = static function (array $get, bool $fetch = false) use ($useBody) {
    $useBody($get, [], $fetch);

    return new class {
        use \Z77\Module\Financial\Ui\JournalControllerTrait { listAction as public; detailAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public ?string $redirectedTo = null;
        public function __construct()
        {
            $this->help          = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
            };
        }
        protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    };
};
$journalTpl = static fn(string $template, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial('Backend/JournalController/' . $template, $context);
/** The SELECT statements the entity manager's connection ran for $fn (MariaDB's session counter). */
$selects = static function (callable $fn): int {
    $em    = \Z77\Core\DI::getUnifiedEntityManager();
    $em->getRepository(JournalEntry::class);
    $dbal  = null;
    foreach ((new \ReflectionProperty($em, 'managerCache'))->getValue($em) as $manager) {
        if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
            $dbal = (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection();
        }
    }
    $count = static fn(): int => (int) $dbal->fetchAssociative("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
    $before = $count();
    $fn();

    return $count() - $before;
};
$jHost = $journal(['year' => '2026']);
$queries = $selects(fn() => $jHost->listAction());
$jList = $journalTpl('listAction', $jHost->context);
$rowsOnPage = count($jHost->context['rows']);
$states = [];
foreach ($jHost->context['rows'] as $row) {
    $states[$row['entry']->getText()] = $row['state'];
}
$docs = $jHost->context['entryDocuments'];
$settlementIds = array_map('intval', $db->fetchFirstColumn("SELECT id FROM journal_entry WHERE source_type = 'vat-return' ORDER BY id"));
$r1Voucher = '/backend/finance/vat-return/voucher?id=' . $q3Id;
$r2Voucher = '/backend/finance/vat-return/voucher?id=' . $r2['id'];
check('P17 the journal list of 2026: the settlement entry (r1), its reversal and the new entry (r2) carry the generated mark (zap) and the voucher icon in front — r1 and its reversal the lifted revision\'s voucher, r2 its own (`Ui\VatReturnEntryDocuments`, verified by the SHA-256); every entry with a VAT code in Q3 — manual or generated (an invoice) — the GREY lock `vat-settled` with its title; an entry without a code in Q3 stays editable; the page\'s SELECT count stays below its row count (no per-row query)',
    !in_array(false, $parts = [
        'three'   => count($settlementIds) === 3,
        'zap'     => ($states['MWST-Abrechnung 3. Quartal 2026'] ?? '') === 'generated' && ($states['MWST-Abrechnung 3. Quartal 2026 (Neuerstellung 2)'] ?? '') === 'generated',
        'docs'    => ($docs[$settlementIds[0]]->url ?? '') === $r1Voucher && ($docs[$settlementIds[1]]->url ?? '') === $r1Voucher && ($docs[$settlementIds[2]]->url ?? '') === $r2Voucher
            && ($docs[$settlementIds[0]]->label ?? '') === 'MWST-Beleg' && str_contains($jList, 'href="' . e($r2Voucher) . '"'),
        'lock'    => ($states['Barverkauf Beratung'] ?? '') === 'vat-settled' && ($states['Rechnung 1 · Beispiel AG'] ?? '') === 'vat-settled'
            && str_contains($jList, 'be-list__state--vat-settled') && str_contains($jList, 'MWST-Periode abgerechnet — die Buchung trägt einen MWST-Code und ist gesperrt'),
        'uncoded' => ($states['Verzugszins Beispiel AG'] ?? '') === 'editable',
        'queries' => $rowsOnPage >= 20 && $queries < $rowsOnPage,
    ], true), falseOf($parts) . ' ' . json_encode(['queries' => $queries, 'rows' => $rowsOnPage, 'ids' => $settlementIds, 'states' => $states], JSON_UNESCAPED_UNICODE));
$locked = (int) $db->fetchOne("SELECT id FROM journal_entry WHERE text = 'Barverkauf Beratung'");
$jHost  = $journal(['id' => (string) $locked], true);
$jHost->detailAction();
$lockedWin = $journalTpl('detail', $jHost->context);
$jHost  = $journal(['id' => (string) $r2['entry_id']], true);
$jHost->detailAction();
$settleWin = $journalTpl('detail', $jHost->context);
check('P18 the window of a locked entry (a manual sale with a VAT code in Q3): read only — financial\'s ONE sentence on top (`periodRefusalText()`), no «Bearbeiten», no «Löschen …»; the window of the settlement entry: «MWST-Beleg öffnen» (the voucher) and «Zur Abrechnung» (the return page of Q3)',
    str_contains($lockedWin, 'data-journal-locked>Die MWST-Periode 3. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr.<') && !str_contains($lockedWin, '/edit?id=') && !str_contains($lockedWin, 'confirm-delete')
    && str_contains($settleWin, 'href="' . e($r2Voucher) . '"') && str_contains($settleWin, 'MWST-Beleg öffnen') && str_contains($settleWin, 'href="/backend/finance/vat-return/form?from=2026-07-01" data-journal-vat-return>Zur Abrechnung<')
    && !str_contains($settleWin, 'data-journal-locked'), $lockedWin);

$s2Src = (string) file_get_contents($root . '/packages/module-financial/src/Ui/VatReturnEntryDocuments.php');
check('P19 the posting action reads nothing through the entity manager before the service (no mandator, fiscal year or tax code in the identity map — the service\'s step 6 reads fresh): postAction() builds the period from the body and calls the poster; the entry-documents provider names no module and verifies through the archive',
    preg_match('/function postAction\(\): FetchResponse\s*\{(.*?)\n    \}/s', (string) file_get_contents($root . '/packages/module-financial/src/Ui/VatReturnControllerTrait.php'), $postSrc) === 1
    && !preg_match('/vatReturns\(\)|getRepository|em\(\)->|CurrentMandator|vatReturnPeriod\(/', substr($postSrc[1], 0, (int) strpos($postSrc[1], '->post('))) && str_contains($postSrc[1], 'VatPeriod::stored(')
    && str_contains($s2Src, 'VatReturnArchive') && str_contains($s2Src, '->bytes(') && !preg_match('/Z77\\\\Module\\\\(Debtor|Receipt|Creditor|Order)\\\\/', $s2Src));

// ── a posted return changed since posting (FIN-VAT-POSTED-VIEWS-001, owner 2026-11-25) ──
// «Herkunft» and «Abstimmung» of a posted return stay computed from the journal; one hint line on top when the computed
// view differs from what was posted — decided by `VatReturnService::changedSincePosting()` from the stored figures and the
// confirmed warnings. Q3 2026 is posted (revision 2, warnings confirmed — among them the uncoded default interest).
$views = static function (string $from) use ($s2, $page): array {
    $out = [];
    foreach (['form', 'origin', 'reconciliation'] as $tab) {
        $h = $s2(['from' => $from]);
        $h->{$tab . 'Action'}();
        $out[$tab] = ['html' => $page($h), 'since' => $h->context['changedSince'] ?? null];
    }

    return $out;
};
$hinted   = static fn(array $v): array => array_map(static fn(array $t): bool => str_contains($t['html'], 'data-vat-return-changed'), $v);
/** The service's answer for the stored return $id (default: Q3 revision 2) — service and row on ONE fresh request. */
$changed  = static function (string $view, ?int $id = null) use ($wire, $sources, $r2): bool {
    $em = $wire();

    return (new VatReturnService($em, null, new OpenWorkChecks([]), $sources))
        ->changedSincePosting($em->getRepository(\Z77\Module\Financial\Entities\VatReturn::class)->find($id ?? (int) $r2['id']), $view);
};
$postedOn = (new \DateTimeImmutable((string) $db->fetchOne('SELECT posted_at FROM vat_return WHERE id = ?', [(int) $r2['id']])))->format('d.m.Y');
$hintLine = 'Seit dem Buchen geändert — der <a href="/backend/finance/vat-return/voucher?id=' . (int) $r2['id'] . '" target="_blank" rel="noopener" data-no-pending>Beleg</a> zeigt den Stand vom ' . $postedOn . '.';
$dropEntry = static function (string $text) use ($db, $wire): void {
    $row = $db->fetchAssociative('SELECT id, version FROM journal_entry WHERE text = ?', [$text]);
    (new ManualEntryService($wire(), 'harness'))->delete((int) $row['id'], (int) $row['version']);
};

$unchanged = $views('2026-07-01');
$nil       = $views('2026-01-01');
$open      = $views('2026-10-01');
check('P20 a posted return, unchanged since posting (Q3 revision 2 with confirmed warnings, Q1 a nil return): no hint on any tab, the service answers false for both views; an OPEN period (Q4) never carries the hint',
    $hinted($unchanged) === ['form' => false, 'origin' => false, 'reconciliation' => false] && $unchanged['reconciliation']['since'] === null
    && $hinted($nil) === ['form' => false, 'origin' => false, 'reconciliation' => false]
    && $hinted($open) === ['form' => false, 'origin' => false, 'reconciliation' => false] && $open['origin']['since'] === null && $open['reconciliation']['since'] === null
    && !$changed('origin') && !$changed('reconciliation'), json_encode([$hinted($unchanged), $hinted($nil), $hinted($open)]));

$manual('2026-09-26', 'Nachtrag Beratung ohne MWST', [PostingLine::debit('1020', chf('100.00')), PostingLine::credit('3400', chf('100.00'))]);
$revenue = $views('2026-07-01');
check('P21 an entry WITHOUT a tax code on a revenue account dated into the posted Q3 (allowed — the freeze holds tax lines only): «Abstimmung» shows the hint line on top, verbatim, «Beleg» the archived voucher of revision 2, the date the posting day; «Herkunft» (coded lines only — the figures are unchanged) and «Formular» (the stored figures) none',
    $hinted($revenue) === ['form' => false, 'origin' => false, 'reconciliation' => true] && str_contains($revenue['reconciliation']['html'], $hintLine)
    && strpos($revenue['reconciliation']['html'], 'data-vat-return-changed') < strpos($revenue['reconciliation']['html'], 'data-vat-reco="turnover"')
    && $changed('reconciliation') && !$changed('origin'), json_encode([$hinted($revenue), $hintLine]));
$dropEntry('Nachtrag Beratung ohne MWST');
$back = $views('2026-07-01');
$manual('2026-09-27', 'Nachtrag MWST von Hand', [PostingLine::debit('1020', chf('7.00')), PostingLine::credit('2200', chf('7.00'))]);
$vatAccount = $views('2026-07-01');
check('P22 the entry deleted → no hint again (the comparison is against what was posted, not against a seen state); an entry without a tax code on the VAT account 2200 dated into Q3 — a gap the return could never have been posted with (blocking) → the hint on «Abstimmung» only',
    $hinted($back) === ['form' => false, 'origin' => false, 'reconciliation' => false]
    && $hinted($vatAccount) === ['form' => false, 'origin' => false, 'reconciliation' => true] && str_contains($vatAccount['reconciliation']['html'], $hintLine), json_encode([$hinted($back), $hinted($vatAccount)]));
$dropEntry('Nachtrag MWST von Hand');

// The figures: tax lines of a settled period are frozen, so only a changed rule file or tax-code category moves them — stood
// in for by one stored figure altered by hand (the stored side is what the computed side is compared with).
$fig200 = $db->fetchAssociative("SELECT id, value_exact FROM vat_return_figure WHERE vat_return_id = ? AND field = '200'", [(int) $r2['id']]);
$db->executeStatement('UPDATE vat_return_figure SET value_exact = value_exact + 1 WHERE id = ?', [(int) $fig200['id']]);
$figures = $views('2026-07-01');
$db->executeStatement('UPDATE vat_return_figure SET value_exact = ? WHERE id = ?', [$fig200['value_exact'], (int) $fig200['id']]);
check('P23 the computed form fields differ from the stored `vat_return_figure` rows (field 200 off by 1.00): the hint on «Herkunft» AND «Abstimmung» (its turnover compares field 200 − 235), none on «Formular»; restored → none',
    $hinted($figures) === ['form' => false, 'origin' => true, 'reconciliation' => true] && str_contains($figures['origin']['html'], $hintLine)
    && $hinted($views('2026-07-01')) === ['form' => false, 'origin' => false, 'reconciliation' => false], json_encode($hinted($figures)));

$host = $s2(['id' => (string) $q3Id]);
$host->revisionAction();
check('P24 a LIFTED revision (Q3 revision 1): its page shows the stored figures only, no view tabs and no hint — there is no recomputed view of it; the service answers false for it (the period\'s views belong to the live revision 2)',
    ($host->context['stored'] ?? null)?->isLive() === false && !str_contains($page($host), 'data-vat-return-changed') && !str_contains($slot($host, 'hc2'), 'be-viewtabs')
    && !$changed('origin', $q3Id) && !$changed('reconciliation', $q3Id));

echo "\n{$pass} passed, {$fail} failed" . ($fromSection !== null ? " (section {$fromSection} from its checkpoint)" : '') . "\n";
exit($fail === 0 ? 0 : 1);
