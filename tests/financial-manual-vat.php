<?php

/**
 * The Sammelbuchung under the lock of the VAT accounts (CLI) — `Ui\ManualEntryForm` from
 * «Abrechnung ab» on (ADR-041 addendum 2026-11-25, point 6; ADR-042 addendum 2 of 2026-11-25,
 * point 7; boards S4-1 «Sammelbuchung» and S4-2 «Bearbeiten», decided as drawn). What is
 * load-bearing here:
 *
 *   S. a NEW entry: net rows with a code → ONE computed tax line per VAT account and side
 *      («MWST-Zeilen (berechnet)», placed after the last row feeding it, text «MWST»); the column
 *      «Steuer» (empty = computed, a voucher tax within the one-line entry's limit, refusals
 *      beyond it); «nur Steuer» rows — base 0.00 and the code's rate, VM / VI on the typed input
 *      account, VE / VK / VN with the account left empty (z77 sets 1171), the signs VE debit /
 *      VK credit; reverse charge (both lines); an uncoded row on a VAT account and a coded row on
 *      the wrong one refused at the row; the balance with the computed lines; «Weitere Zeilen»
 *      (preview, no errors); before «Abrechnung ab» and without a map everything as before; the
 *      code select in groups (the three corrections offered here); every request the form builds
 *      passes the ledger's rule (`PostingRules::vatLinesRefusal()`) and posts; the screen and the
 *      help through the real TemplateRenderer; the controller wiring (source);
 *   E. an OLD entry (posted before «Abrechnung ab» was set, with a typed tax line): matching →
 *      taken over at its position, an unchanged save is a no-op; within the limit → the deviation
 *      becomes «Steuer», the save makes it consistent; not matching → the error at the row when
 *      the form opens; text or date changed with the rows unchanged → saved without the VAT
 *      check; a changed row → judged; a voucher tax of the one-line entry kept in «Steuer»; an
 *      entry before the day opens as before; a date moved across «Abrechnung ab» → judged.
 *
 * Run: php tests/financial-manual-vat.php
 * Needs the monorepo's vendor/ and a reachable MariaDB with the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_* — without them the harness is SKIPPED.
 * Every name and amount here is synthetic.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-manual-vat: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\Services\PostingRules;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Ui\ManualEntryForm;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Services\VatRates;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

/** Lines as «account D|C amount [code rate base tax] [| text]» — the shape compared. */
function shape(?array $lines): ?array
{
    return $lines === null ? null : array_map(static fn(PostingLine $l) => $l->account . ' ' . ($l->debit->isPositive() ? 'D ' . $l->debit->toDecimal() : 'C ' . $l->credit->toDecimal())
        . ($l->hasTax() ? " {$l->taxCode} {$l->taxRate} {$l->taxBase->toDecimal()} {$l->taxAmount->toDecimal()}" : '')
        . ($l->text !== null ? ' | ' . $l->text : ''), $lines);
}

/** Every message the form shows: row → field → text, and the general ones. */
function messages(ManualEntryForm $form): array
{
    $rows = [];
    foreach (array_keys($form->rows()) as $i) {
        foreach (['account', 'text', 'debit', 'credit', 'tax_code', 'tax_amount'] as $field) {
            if ($form->rowError($i, $field) !== '') {
                $rows[$i][$field] = $form->rowError($i, $field);
            }
        }
    }

    return ['rows' => $rows, 'general' => $form->generalErrors()];
}

// ── database ─────────────────────────────────────────────────────────────

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
    echo "S. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(0);
}

echo "Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-manual-vat-' . getmypid());
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
// The shipped codes and rates (VE / VK / VN, BM / BI / BM2 / BI2 among them).
foreach (['tax_codes', 'tax_rates'] as $file) {
    $write("data/framework/vat/{$file}.json", (string) file_get_contents($packages['Vat'] . "/data/framework/vat/{$file}.default.json"));
}

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
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
};
$em = $wire();
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
    \Z77\Core\DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
);
$app->setAutoExit(false);
$out  = new \Symfony\Component\Console\Output\BufferedOutput();
$code = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $out);
check('D1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out->fetch()));

// The books: the KMU chart, fiscal years 2026 and 2027, a liable mandator (effective, agreed, quarter) — «Abrechnung ab»
// NOT yet set, so the old entries of section E are posted the old way, with a typed tax line (R20: the only way an
// installation still gets one — entries from before the update); 6570 inactive.
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2027', day('2027-01-01'), day('2027-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$db->executeStatement("UPDATE account SET active = 0 WHERE number = '6570'");

$em      = $wire();
$manual  = new ManualEntryService($em, 'harness');
$old     = static fn(string $date, string $text, array $lines): EntryRef => $manual->create(PostingRequest::manual(day($date), $text, $lines));
$refs    = [
    'match'   => $old('2027-03-15', 'Büromaterial Papeterie Demo', [
        PostingLine::debit('6500', chf('100.00'), 'Büromaterial', 'VM', 810, chf('100.00'), chf('8.10')),
        PostingLine::debit('1170', chf('8.10'), 'Vorsteuer'),
        PostingLine::credit('1020', chf('108.10'), 'Zahlung'),
    ]),
    'within'  => $old('2027-03-16', 'Büromaterial Belegsteuer', [
        PostingLine::debit('6500', chf('100.00'), 'Büromaterial', 'VM', 810, chf('100.00'), chf('8.10')),
        PostingLine::debit('1170', chf('8.15'), 'Vorsteuer'),
        PostingLine::credit('1020', chf('108.15'), 'Zahlung'),
    ]),
    'off'     => $old('2027-03-17', 'Büromaterial Papeterie Demo', [
        PostingLine::debit('6500', chf('100.00'), 'Büromaterial', 'VM', 810, chf('100.00'), chf('8.10')),
        PostingLine::debit('1170', chf('18.10'), 'Vorsteuer'),
        PostingLine::credit('1020', chf('118.10'), 'Zahlung'),
    ]),
    'voucher' => $old('2027-03-18', 'Beleg einzeilig', [   // the one-line entry's shape with a voucher tax (30.00 ≠ 29.97)
        PostingLine::debit('6500', chf('370.00'), null, 'VM', 810, chf('370.00'), chf('30.00')),
        PostingLine::debit('1170', chf('30.00')),
        PostingLine::credit('1020', chf('400.00')),
    ]),
    'early'   => $old('2027-01-20', 'Vor dem Tag', [      // dated before «Abrechnung ab» 1.2.2027
        PostingLine::debit('6500', chf('100.00'), null, 'VM', 810, chf('100.00'), chf('8.10')),
        PostingLine::debit('1170', chf('18.10')),
        PostingLine::credit('1020', chf('118.10')),
    ]),
];
// «Abrechnung ab» 1.2.2027 — set on the record directly (the fixture of R20; the screen's guard is not under test here).
$db->executeStatement("UPDATE mandator SET vat_settlement_from = '2027-02-01'");
$em = $wire();
$map = new VatAccountMap($em);
check('D2 the books: KMU chart, 2026 and 2027, the mandator liable with «Abrechnung ab» 1.2.2027 — the lock applies from that day (1170 / 1171 / 2200), not before',
    array_map('strval', array_keys($map->lockedAccounts(day('2027-03-18')))) === ['2200', '1170', '1171'] && $map->lockedAccounts(day('2027-01-31')) === [],
    json_encode([$map->lockedAccounts(day('2027-03-18')), $map->lockedAccounts(day('2027-01-31'))]));

/** A fresh form — with the mandator's map (the screen), or without one ($withMap false: a caller without a mandator). */
$newForm = static function (bool $withMap = true): ManualEntryForm {
    $em = \Z77\Core\DI::getUnifiedEntityManager();

    return new ManualEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(TaxCode::class), VatRates::from($em), $withMap ? new VatAccountMap($em) : null);
};
/** The POST body of the Sammelbuchung — rows [account, debit, credit, code, Steuer, text]. */
$body = static fn(string $date, array $rows, string $text = 'Beleg'): array => [
    'date' => $date, 'text' => $text,
    'account' => array_column($rows, 0), 'debit' => array_column($rows, 1), 'credit' => array_column($rows, 2),
    'tax_code' => array_column($rows, 3), 'tax_amount' => array_map(static fn(array $r) => $r[4] ?? '', $rows), 'line_text' => array_map(static fn(array $r) => $r[5] ?? '', $rows),
];
/** The form after a POST of $rows on $date: [form, request]. */
$submit = static function (string $date, array $rows, bool $withMap = true) use ($newForm, $body): array {
    $form = $newForm($withMap)->fromPost($body($date, $rows));

    return [$form, $form->toRequest()];
};
/** The ledger's rule (W1-G) on a request: null = it passes the lock of the VAT accounts. */
$lockSays = static function (PostingRequest $request): ?string {
    $map = new VatAccountMap(\Z77\Core\DI::getUnifiedEntityManager());
    $e   = PostingRules::vatLinesRefusal($request, $map->lockedAccounts($request->date), $map->taxLines(...), $map->mayCarryCode(...));

    return $e?->reason;
};
$entries = $em->getRepository(JournalEntry::class);
$changes = $em->getRepository(EntryChange::class);
$lineRows = static fn(int $entryId): array => $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit, l.tax_code, l.tax_rate, l.tax_base, l.tax_amount, l.text FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.position', [$entryId]);

// ─────────────────────────────────────────────────────────────────────────
echo "S. A new Sammelbuchung from «Abrechnung ab» on — computed lines, «Steuer», «nur Steuer», refusals\n";

// Board S4-1, part 1: two VM rows, one with the voucher tax 8.15 → ONE line 1170 of 28.40.
[$f, $r] = $submit('2027-03-18', [['6500', '100.00', '', 'VM', '8.15', 'Büromaterial'], ['4400', '250.00', '', 'VM', '', 'Kurierdienst'], ['1020', '', '378.40', '', '', 'Zahlung'], ['', '', '', '', '', '']]);
check('S1 two VM rows (one with «Steuer» 8.15) → ONE computed line 1170 D 28.40 «MWST» after the last row feeding it; the net rows carry 8.15 and 20.25',
    shape($r?->lines) === ['6500 D 100.00 VM 810 100.00 8.15 | Büromaterial', '4400 D 250.00 VM 810 250.00 20.25 | Kurierdienst', '1170 D 28.40 | MWST', '1020 C 378.40 | Zahlung'],
    json_encode([shape($r?->lines), messages($f)], JSON_UNESCAPED_UNICODE));
$block = $f->computedLines();
check('S2 … the block «MWST-Zeilen (berechnet)»: 1170, its name, debit 28.40, the note «VM 8.1 % auf 350.00 · Belegsteuer 8.15 statt 8.10»; the total balanced 378.40 incl. the computed line',
    $f->showsVatBlock() && count($block) === 1 && $block[0]['account'] === '1170' && str_starts_with($block[0]['name'], 'Vorsteuer MWST Material') && $block[0]['side'] === 'debit'
    && $block[0]['amount']->toDecimal() === '28.40' && $block[0]['note'] === 'VM 8.1 % auf 350.00 · Belegsteuer 8.15 statt 8.10'
    && array_map(static fn(Money $m) => $m->toDecimal(), $f->sums()) === ['378.40', '378.40'] && $f->computedSummary() === '28.40 auf 1170', json_encode($block, JSON_UNESCAPED_UNICODE));
check('S3 … the request passes the ledger\'s lock rule and posts; the stored lines are the form\'s', $r !== null && $lockSays($r) === null && (function () use ($r, $em, $entries, $lineRows) {
    $ref = (new ManualEntryService($em, 'harness'))->create($r);
    $rows = $lineRows((int) $entries->findByRef($ref)->getId());
    return array_column($rows, 'number') === ['6500', '4400', '1170', '1020'] && $rows[2]['debit'] === '28.40' && $rows[2]['tax_code'] === null && $rows[0]['tax_amount'] === '8.15';
})());

$pv = $newForm()->fromPost($body('2027-03-18', [['6500', '100.00', '', 'VM', '', ''], ['1020', '', '', '', '', ''], ['', '', '', '', '', '']]));
$pv->addRows(ManualEntryForm::MORE_ROWS);
$pv->preview();
check('S4 «Weitere Zeilen» (preview): the computed line 1170 8.10 and the balance are shown, a half-typed row carries no error yet, nothing is posted',
    !$pv->hasErrors() && count($pv->rows()) === 6 && $pv->showsVatBlock() && $pv->computedLines()[0]['amount']->toDecimal() === '8.10'
    && array_map(static fn(Money $m) => $m->toDecimal(), $pv->sums()) === ['108.10', '0.00']);

$steuer = static fn(string $tax, string $code = 'VM', string $account = '6500') => $submit('2027-03-18', [[$account, '100.00', '', $code, $tax, ''], ['1020', '', '108.10', '', '', '']]);
[$f1] = $steuer('8.50');
[$f2] = $steuer('0.00');
[$f3] = $steuer('-1');
[$f4] = $submit('2027-03-18', [['6500', '100.00', '', '', '8.10', ''], ['1020', '', '100.00', '', '', '']]);
[$f5] = $submit('2027-03-18', [['1020', '400.00', '', '', '', ''], ['3200', '', '400.00', 'UE', '0.50', '']]);
check('S5 «Steuer» beyond the limit (8.50 against 8.10: 1 % → 0.08) / 0.00 / negative / without a code / on a tax-free code → the error at «Steuer», nothing built',
    $f1->rowError(0, 'tax_amount') === 'Steuerbetrag 8.50 weicht um 0.40 vom berechneten 8.10 ab — erlaubt sind höchstens 0.08 (Rundung laut Beleg).'
    && $f2->rowError(0, 'tax_amount') === 'Steuer 0.00 geht nur, wenn die berechnete Steuer 0.00 ist (hier 8.10).'
    && $f3->rowError(0, 'tax_amount') === 'Kein Steuerbetrag (z.B. 8.10) — leer lassen, um ihn zu berechnen.'
    && $f4->rowError(0, 'tax_amount') === 'Steuer nur mit MWST-Code — sonst leer lassen.'
    && $f5->rowError(1, 'tax_amount') === 'MWST-Code UE ist steuerfrei — kein Steuerbetrag.'
    && $f1->generalErrors()[0] === 'Die Buchung wurde nicht gebucht — bitte die markierten Zeilen korrigieren.',
    json_encode([messages($f1), messages($f2), messages($f3), messages($f4), messages($f5)], JSON_UNESCAPED_UNICODE));
$voucher = static fn(string $tax) => $submit('2027-03-18', [['6500', '100.00', '', 'VM', $tax, ''], ['1020', '', chf('100.00')->add(chf($tax))->toDecimal(), '', '', '']]);
[$fLow, $rLow] = $voucher('8.05');
[, $rMax]      = $voucher('8.18');
[$fOver, $rOver] = $voucher('8.19');
check('S5b … the limit min(1.00, max(0.05, 1 %)) of the one-line entry: 8.05 (0.05 off) and 8.18 (0.08 off) accepted, 8.19 refused',
    $rLow !== null && $rMax !== null && shape($rMax->lines)[1] === '1170 D 8.18 | MWST' && $rOver === null && str_contains($fOver->rowError(0, 'tax_amount'), 'höchstens 0.08'),
    json_encode([messages($fLow), messages($fOver)], JSON_UNESCAPED_UNICODE));

// Board S4-1, part 2: «nur Steuer».
[$f, $r] = $submit('2027-03-31', [['1170', '81.00', '', 'VM', '', 'Einfuhrsteuer'], ['1020', '', '81.00', '', '', 'Zahlung Spediteur']]);
check('S6 import VAT: VM on the TYPED 1170 → a «nur Steuer» line (base 0.00, rate 8.1 %, tax 81.00), no computed line; the block says «keine — die Zeile auf 1170 ist selbst die Steuer»; the row note «nur Steuer»',
    shape($r?->lines) === ['1170 D 81.00 VM 810 0.00 81.00 | Einfuhrsteuer', '1020 C 81.00 | Zahlung Spediteur'] && $f->computedLines() === [] && $f->showsVatBlock()
    && $f->vatBlockEmptyText() === 'keine — die Zeile auf 1170 ist selbst die Steuer' && $f->rowNote(0) === 'nur Steuer' && $lockSays($r) === null,
    json_encode([shape($r?->lines), messages($f)], JSON_UNESCAPED_UNICODE));
[$f] = $submit('2027-03-31', [['', '81.00', '', 'VM', '', ''], ['1020', '', '81.00', '', '', '']]);
check('S7 VM / VI with the account left empty → «Konto fehlt.» (only VE / VK / VN may leave it empty, S4-15)', $f->rowError(0, 'account') === 'Konto fehlt.');
[$f, $r] = $submit('2027-03-31', [['6500', '125.00', '', '', '', 'Anteil nicht steuerbar'], ['', '', '125.00', 'VK', '', 'Korrektur 415']]);
check('S8 VK with the account left empty → z77 sets 1171: Haben 125.00 = tax +125.00 (VK is credit-natural), base 0.00 rate 0; the note «nur Steuer · 1171 gesetzt»; «keine — … ist selbst die Korrektur»',
    shape($r?->lines) === ['6500 D 125.00 | Anteil nicht steuerbar', '1171 C 125.00 VK 0 0.00 125.00 | Korrektur 415'] && $f->rows()[1]['account'] === '1171'
    && $f->rowNote(1) === 'nur Steuer · 1171 gesetzt' && $f->vatBlockEmptyText() === 'keine — die Zeile auf 1171 ist selbst die Korrektur' && $lockSays($r) === null,
    json_encode([shape($r?->lines), messages($f)], JSON_UNESCAPED_UNICODE));
[, $rVe] = $submit('2027-03-31', [['1171', '50.00', '', 'VE', '', ''], ['6500', '', '50.00', '', '', '']]);
[, $rVkD] = $submit('2027-03-31', [['', '20.00', '', 'VK', '', ''], ['6500', '', '20.00', '', '', '']]);
[, $rVn] = $submit('2027-03-31', [['6500', '30.00', '', '', '', ''], ['1171', '', '30.00', 'VN', '', '']]);
check('S9 the signs: VE in Soll +50.00 (debit-natural), VK in Soll −20.00, VN in Haben +30.00 — each passes the lock rule',
    shape($rVe?->lines)[0] === '1171 D 50.00 VE 0 0.00 50.00' && shape($rVkD?->lines)[0] === '1171 D 20.00 VK 0 0.00 -20.00' && shape($rVn?->lines)[1] === '1171 C 30.00 VN 0 0.00 30.00'
    && $lockSays($rVe) === null && $lockSays($rVkD) === null && $lockSays($rVn) === null, json_encode([shape($rVe?->lines), shape($rVkD?->lines), shape($rVn?->lines)]));

[$fVk]  = $submit('2027-03-31', [['6500', '', '125.00', 'VK', '', ''], ['1020', '125.00', '', '', '', '']]);
[$fVm]  = $submit('2027-03-31', [['1171', '81.00', '', 'VM', '', ''], ['1020', '', '81.00', '', '', '']]);
[$f3]   = $submit('2027-03-18', [['6500', '100.00', '', 'VM', '', ''], ['1170', '8.10', '', '', '', 'Vorsteuer'], ['2200', '', '16.20', 'UN', '', ''], ['1020', '', '108.10', '', '', '']]);
[$fBm]  = $submit('2027-03-18', [['1170', '81.00', '', 'BM', '', ''], ['1020', '', '81.00', '', '', '']]);
[$fBare] = $submit('2027-03-18', [['2200', '100.00', '', '', '', ''], ['1020', '', '100.00', '', '', '']]);
check('S10 refused at the row: VK on an ordinary account; VM on 1171; an uncoded 1170 next to a VM row (board S4-1 part 3); UN on 2200; BM on 1170; an uncoded 2200 with no code at all',
    $fVk->rowError(0, 'account') === 'VK ist immer «nur Steuer» — Konto leer lassen oder 1171 tippen.'
    && $fVm->rowError(0, 'tax_code') === 'VM gehört auf 1170 — nicht auf 1171.'
    && $f3->rowError(1, 'account') === '1170 ist ein MWST-Konto: die Steuerzeile schreibt z77 aus dem Code VM — diese Zeile entfernen.'
    && $f3->rowError(2, 'tax_code') === 'Geschuldete MWST rechnet z77 immer aus dem Umsatz — den Code UN auf die Ertragszeile setzen, nicht auf 2200.'
    && $fBm->rowError(0, 'tax_code') === 'Bezugsteuer rechnet z77 aus der Aufwandzeile — den Code BM auf die Aufwandzeile setzen, nicht auf 1170.'
    && $fBare->rowError(0, 'account') === '2200 ist ein MWST-Konto: eine Zeile darauf braucht einen MWST-Code («nur Steuer») — sonst die Zeile entfernen.',
    json_encode([messages($fVk), messages($fVm), messages($f3), messages($fBm), messages($fBare)], JSON_UNESCAPED_UNICODE));
check('S11 … board S4-1 part 3: the block still shows 1170 8.10, the total 116.20 / 124.30 names the computed VAT; a hand-built request with the typed 1170 line is refused by the ledger\'s rule too',
    $f3->computedSummary() === '8.10 auf 1170' && array_map(static fn(Money $m) => $m->toDecimal(), $f3->sums()) === ['116.20', '124.30']
    && $lockSays(PostingRequest::manual(day('2027-03-18'), 'x', [PostingLine::debit('6500', chf('100.00'), null, 'VM', 810, chf('100.00'), chf('8.10')), PostingLine::debit('1170', chf('18.10')), PostingLine::credit('1020', chf('118.10'))])) !== null);

[$f, $r] = $submit('2027-03-18', [['6500', '1000.00', '', 'BM', '', 'Beratung Ausland'], ['1020', '', '1000.00', '', '', '']]);
[, $rBi2] = $submit('2027-03-18', [['6500', '1000.00', '', 'BI2', '', ''], ['1020', '', '1000.00', '', '', '']]);
check('S12 reverse charge: BM on the expense → TWO computed lines, 2200 C 81.00 and 1170 D 81.00 (owed first); BI2 → 2200 C 26.00 / 1171 D 26.00; both pass the lock rule',
    shape($r?->lines) === ['6500 D 1000.00 BM 810 1000.00 81.00 | Beratung Ausland', '2200 C 81.00 | MWST', '1170 D 81.00 | MWST', '1020 C 1000.00']
    && shape($rBi2?->lines) === ['6500 D 1000.00 BI2 260 1000.00 26.00', '2200 C 26.00 | MWST', '1171 D 26.00 | MWST', '1020 C 1000.00']
    && count($f->computedLines()) === 2 && $f->computedLines()[0]['note'] === 'BM 8.1 % auf 1\'000.00' && $lockSays($r) === null && $lockSays($rBi2) === null,
    json_encode([shape($r?->lines), shape($rBi2?->lines), messages($f)], JSON_UNESCAPED_UNICODE));

[, $rRefund] = $submit('2027-03-18', [['1020', '108.10', '', '', '', ''], ['4200', '', '100.00', 'VM', '', '']]);
[$fTwo, $rTwo] = $submit('2027-03-18', [['6500', '100.00', '', 'VM', '', ''], ['4200', '', '50.00', 'VM', '', ''], ['1020', '', '54.05', '', '', '']]);
[, $rSale] = $submit('2027-03-18', [['1020', '108.10', '', '', '', ''], ['3200', '', '100.00', 'UN', '', '']]);
[$fZero, $rZero] = $submit('2027-03-18', [['1020', '400.00', '', '', '', ''], ['3200', '', '400.00', 'UE', '', '']]);
check('S13 the side by sign: a VM refund in Haben → 1170 C 8.10 (base −100.00); VM on both sides → 1170 D 8.10 AND 1170 C 4.05 (one line per account and side); UN → 2200 C 8.10; UE → no tax line, no block',
    shape($rRefund?->lines) === ['1020 D 108.10', '4200 C 100.00 VM 810 -100.00 -8.10', '1170 C 8.10 | MWST']
    && shape($rTwo?->lines) === ['6500 D 100.00 VM 810 100.00 8.10', '1170 D 8.10 | MWST', '4200 C 50.00 VM 810 -50.00 -4.05', '1170 C 4.05 | MWST', '1020 C 54.05']
    && shape($rSale?->lines) === ['1020 D 108.10', '3200 C 100.00 UN 810 100.00 8.10', '2200 C 8.10 | MWST']
    && shape($rZero?->lines) === ['1020 D 400.00', '3200 C 400.00 UE 0 400.00 0.00'] && !$fZero->showsVatBlock()
    && $lockSays($rRefund) === null && $lockSays($rTwo) === null && $lockSays($rSale) === null,
    json_encode([shape($rRefund?->lines), shape($rTwo?->lines), shape($rSale?->lines), shape($rZero?->lines)]));

[$fBefore, $rBefore] = $submit('2027-01-31', [['2200', '18345.60', '', '', '', 'Altsaldo Umsatzsteuer'], ['1170', '', '4210.35', '', '', ''], ['1171', '', '820.15', '', '', ''], ['2201', '', '13315.10', '', '', '']]);
[$fOldWay, $rOldWay] = $submit('2027-01-20', [['6500', '100.00', '', 'VM', '', ''], ['1170', '8.10', '', '', '', ''], ['1020', '', '108.10', '', '', '']]);
[, $rNoMap] = $submit('2027-03-18', [['6500', '100.00', '', 'VM', '', ''], ['1170', '8.10', '', '', '', ''], ['1020', '', '108.10', '', '', '']], false);
check('S14 before «Abrechnung ab» (board S4-1 part 5) everything as before: the carry-over onto 2201 with uncoded VAT-account rows, the typed 1170 next to a VM row — no block; a form without a map works the same',
    shape($rBefore?->lines) === ['2200 D 18345.60 | Altsaldo Umsatzsteuer', '1170 C 4210.35', '1171 C 820.15', '2201 C 13315.10'] && !$fBefore->showsVatBlock()
    && shape($rOldWay?->lines) === ['6500 D 100.00 VM 810 100.00 8.10', '1170 D 8.10', '1020 C 108.10'] && !$fOldWay->showsVatBlock()
    && shape($rNoMap?->lines) === shape($rOldWay?->lines));

$groups = [];
foreach ($newForm()->codeGroups() as $group) {
    $groups[$group['label']] = array_map(static fn(TaxCode $c) => $c->getCode(), $group['codes']);
}
check('S15 the code select in groups (board S4-1): Umsatz, Vorsteuer, Bezugsteuer, «Korrekturen (nur Sammelbuchung)» with VE / VK / VN — offered here (includeManualOnly)',
    array_keys($groups) === ['Umsatz', 'Vorsteuer', 'Bezugsteuer', 'Korrekturen (nur Sammelbuchung)'] && $groups['Vorsteuer'] === ['VI', 'VM']
    && $groups['Korrekturen (nur Sammelbuchung)'] === ['VE', 'VK', 'VN'] && in_array('BM2', $groups['Bezugsteuer'], true) && in_array('UL', $groups['Umsatz'], true), json_encode($groups));

// The screen and the help through the real TemplateRenderer.
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
$render = static fn(string $template, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial('Backend/JournalController/' . $template, $context);
$year   = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2027']);
$page   = static fn(ManualEntryForm $form): string => $render('form', ['form' => $form, 'year' => $year, 'entry' => null, 'csrfToken' => 'tok', 'actionBase' => '/backend/finance/journal',
    'configNotice' => null, 'fmt' => static fn(?Money $m) => \Z77\Shared\Money\AmountFormat::of($m)]);
[$f1Board] = $submit('2027-03-18', [['6500', '100.00', '', 'VM', '8.15', 'Büromaterial'], ['4400', '250.00', '', 'VM', '', 'Kurierdienst'], ['1020', '', '378.40', '', '', 'Zahlung']]);
$html  = $page($f1Board);
$html3 = $page($f3);
$htmlK = $page($submit('2027-03-31', [['6500', '125.00', '', '', '', ''], ['', '', '125.00', 'VK', '', '']])[0]);
check('S16 the screen: the column «Steuer» with its input per row (8.15 kept), the block «MWST-Zeilen (berechnet)» before the total with 1170, 28.40, the note and «berechnet»; «ausgeglichen»; the code select in optgroups',
    str_contains($html, '<span class="be-list__col be-list__col--num">Steuer</span>') && substr_count($html, 'name="tax_amount[]"') === 3 && str_contains($html, 'value="8.15"')
    && str_contains($html, 'MWST-Zeilen (berechnet)') && strpos($html, 'MWST-Zeilen (berechnet)') < strpos($html, '<strong>Total</strong>')
    && str_contains($html, 'VM 8.1 % auf 350.00 · Belegsteuer 8.15 statt 8.10') && str_contains($html, '28.40') && str_contains($html, '>berechnet<') && str_contains($html, '· ausgeglichen')
    && str_contains($html, '<optgroup label="Korrekturen (nur Sammelbuchung)">') && str_contains($html, 'VK · Vorsteuerkorrektur'));
check('S17 … board S4-1 part 3: the row errors under their rows, the top line, the total «Differenz −8.10 — inkl. berechneter MWST 8.10 auf 1170»; part 2: «nur Steuer · 1171 gesetzt» and «keine — … ist selbst die Korrektur»',
    str_contains($html3, 'Die Buchung wurde nicht gebucht — bitte die markierten Zeilen korrigieren.') && str_contains($html3, '1170 ist ein MWST-Konto: die Steuerzeile schreibt z77 aus dem Code VM — diese Zeile entfernen.')
    && str_contains($html3, 'Differenz −8.10 — inkl. berechneter MWST 8.10 auf 1170') && str_contains($html3, 'aria-invalid="true"')
    && str_contains($htmlK, 'nur Steuer · 1171 gesetzt') && str_contains($htmlK, 'keine — die Zeile auf 1171 ist selbst die Korrektur'));
$help = $render('form.help', ['form' => $f1Board, 'year' => $year, 'entry' => null]);
check('S18 the help: sections tax_code, tax_amount, tax_only, corrections (the sole proprietor\'s private share through VK), before_start (the carry-over onto 2201), old_entry; the row hints listed',
    str_contains($help, 'data-help-field="tax_amount"') && str_contains($help, 'data-help-field="tax_only"') && str_contains($help, 'data-help-field="corrections"')
    && str_contains($help, 'data-help-field="before_start"') && str_contains($help, 'data-help-field="old_entry"') && str_contains($help, 'MWST-Zeilen (berechnet)')
    && str_contains($help, 'Privatanteil einer Einzelfirma') && str_contains($help, '2201') && str_contains($help, 'Zeile 1: MWST VM 8.1 %: 8.15 auf 100.00'));

$trait = (string) file_get_contents($root . '/packages/module-financial/src/Ui/JournalControllerTrait.php');
$tpl   = (string) file_get_contents($root . '/packages/module-financial/res/view/templates/Backend/JournalController/form.tpl.php');
$cls   = (string) file_get_contents($root . '/packages/module-financial/src/Ui/ManualEntryForm.php');
check('S19 the wiring: the journal builds the form with the mandator\'s VatAccountMap and previews «Weitere Zeilen» (add and edit); no stale «no JavaScript (Rule 7)»; the tolerance is the builder\'s ONE (no copy)',
    str_contains($trait, 'new VatAccountMap($this->em())') && substr_count($trait, '$form->preview();') === 2
    && !str_contains($trait . $tpl . $cls, 'JavaScript (Rule 7)') && !str_contains($trait . $tpl . $cls, 'no JavaScript')
    && str_contains($cls, 'GrossEntryBuilder::taxCorrectionLimit(') && !str_contains($cls, 'TAX_CORRECTION_MAX') && !str_contains($cls, 'INPUT_CATEGORIES'));

// ─────────────────────────────────────────────────────────────────────────
echo "E. Old entries (posted before «Abrechnung ab» was set, with a typed tax line) — board S4-2\n";

$em      = $wire();
$entries = $em->getRepository(JournalEntry::class);
$changes = $em->getRepository(EntryChange::class);
$manual  = new ManualEntryService($em, 'harness');
$open    = static function (string $name) use ($refs, $newForm): array {
    $entry = \Z77\Core\DI::getUnifiedEntityManager()->getRepository(JournalEntry::class)->findByRef($refs[$name]);

    return [$entry, $newForm()->startFrom($entry)];
};
/** The form's rows as [account, debit, credit, code, Steuer, text], blank rows left out. */
$rowsOf = static fn(ManualEntryForm $form): array => array_values(array_map(static fn(array $r) => [$r['account'], $r['debit'], $r['credit'], $r['tax_code'], $r['tax_amount'], $r['text']],
    array_filter($form->rows(), static fn(array $r) => implode('', $r) !== '')));
/** POST the edit of $entry with $rows (and $text) — the form as the edit action builds it (keepFrom, fromPost). */
$repost = static function (JournalEntry $entry, array $rows, ?string $text = null, ?string $date = null) use ($newForm, $body): ManualEntryForm {
    return $newForm()->keepFrom($entry)->fromPost($body($date ?? $entry->getDate()->format('Y-m-d'), $rows, $text ?? $entry->getText()));
};

[$e, $form] = $open('match');
check('E1 matching (8.10 = 100.00 × 8.1 %): the typed 1170 line leaves the rows and stands in the block «aus der gebuchten Zeile 2 übernommen»; no error',
    $rowsOf($form) === [['6500', '100.00', '', 'VM', '', 'Büromaterial'], ['1020', '', '108.10', '', '', 'Zahlung']] && !$form->hasErrors()
    && count($form->computedLines()) === 1 && $form->computedLines()[0]['note'] === 'VM 8.1 % auf 100.00 · aus der gebuchten Zeile 2 übernommen'
    && array_map(static fn(Money $m) => $m->toDecimal(), $form->sums()) === ['108.10', '108.10'], json_encode([$rowsOf($form), messages($form)], JSON_UNESCAPED_UNICODE));
check('E2 … an unchanged save rebuilds exactly the stored lines (1170 at position 2 with its text) — update() is a no-op, no change row (O26b)',
    $manual->update((int) $e->getId(), $e->getVersion(), $form->toRequest()) === false && $changes->forEntry((int) $e->getId()) === []);
$changed = $repost($e, [['6500', '100.00', '', 'VM', '', 'Papier'], ['1020', '', '108.10', '', '', 'Zahlung']]);
$req     = $changed->toRequest();
check('E3 … a changed row text: judged — the computed 1170 keeps position 2 and the text «Vorsteuer»; it passes the lock rule and saves',
    shape($req?->lines) === ['6500 D 100.00 VM 810 100.00 8.10 | Papier', '1170 D 8.10 | Vorsteuer', '1020 C 108.10 | Zahlung'] && $lockSays($req) === null
    && $manual->update((int) $e->getId(), $e->getVersion(), $req) === true, json_encode(shape($req?->lines)));

[$e, $form] = $open('within');
$req = $form->toRequest();
check('E4 within the limit (8.15 typed, 8.10 computed): the deviation becomes «Steuer» 8.15 of the net row, 1170 is taken over («Belegsteuer 8.15 statt 8.10»); the save makes the entry consistent (net tax 8.15)',
    $rowsOf($form) === [['6500', '100.00', '', 'VM', '8.15', 'Büromaterial'], ['1020', '', '108.15', '', '', 'Zahlung']] && !$form->hasErrors()
    && str_contains($form->computedLines()[0]['note'], 'Belegsteuer 8.15 statt 8.10')
    && shape($req?->lines) === ['6500 D 100.00 VM 810 100.00 8.15 | Büromaterial', '1170 D 8.15 | Vorsteuer', '1020 C 108.15 | Zahlung'] && $lockSays($req) === null
    && $manual->update((int) $e->getId(), $e->getVersion(), $req) === true, json_encode([$rowsOf($form), messages($form), shape($req?->lines)], JSON_UNESCAPED_UNICODE));

[$e, $form] = $open('off');
check('E5 not matching (18.10 typed, 8.10 computed): the 1170 row stays with the error AT OPEN — «Zeile auf MWST-Konto 1170: z77 rechnet 8.10 aus Code VM — diese Zeile entfernen und die Gegenzeile prüfen.»; the block shows 8.10; no top message, no button',
    $rowsOf($form) === [['6500', '100.00', '', 'VM', '', 'Büromaterial'], ['1170', '18.10', '', '', '', 'Vorsteuer'], ['1020', '', '118.10', '', '', 'Zahlung']]
    && $form->rowError(1, 'account') === 'Zeile auf MWST-Konto 1170: z77 rechnet 8.10 aus Code VM — diese Zeile entfernen und die Gegenzeile prüfen.'
    && $form->generalErrors() === [] && $form->computedSummary() === '8.10 auf 1170' && array_map(static fn(Money $m) => $m->toDecimal(), $form->sums()) === ['126.20', '118.10'],
    json_encode([$rowsOf($form), messages($form)], JSON_UNESCAPED_UNICODE));
$textOnly = $repost($e, $rowsOf($form), 'Büromaterial Papeterie Demo, Rechnung 27-0311');
$req      = $textOnly->toRequest();
check('E6 … only the entry text changed (board S4-2 part 4): saved without the VAT check — the stored lines as they are (18.10 stays); the ledger\'s relief lets it pass',
    shape($req?->lines) === ['6500 D 100.00 VM 810 100.00 8.10 | Büromaterial', '1170 D 18.10 | Vorsteuer', '1020 C 118.10 | Zahlung'] && !$textOnly->hasErrors()
    && PostingRules::vatLockExempt($req, null, $e) && $manual->update((int) $e->getId(), $e->getVersion(), $req) === true
    && $entries->findByRef($refs['off'])->getText() === 'Büromaterial Papeterie Demo, Rechnung 27-0311', json_encode([shape($req?->lines), messages($textOnly)], JSON_UNESCAPED_UNICODE));
$e = $entries->findByRef($refs['off']);
$rowText = $repost($e, [['6500', '100.00', '', 'VM', '', 'Büromaterial'], ['1170', '18.10', '', '', '', 'Vorsteuer alt'], ['1020', '', '118.10', '', '', 'Zahlung']])->toRequest();
check('E6b … a row text changed, amounts and codes as stored: still the relief (the ledger compares accounts, amounts, tax data) — the new row text is kept',
    shape($rowText?->lines) === ['6500 D 100.00 VM 810 100.00 8.10 | Büromaterial', '1170 D 18.10 | Vorsteuer alt', '1020 C 118.10 | Zahlung'] && PostingRules::vatLockExempt($rowText, null, $e));
$lineChanged = $repost($e, [['6500', '100.00', '', 'VM', '', 'Büromaterial'], ['1170', '18.10', '', '', '', 'Vorsteuer'], ['1020', '', '118.00', '', '', 'Zahlung']]);
check('E7 … a changed amount: the whole entry is judged — the error at the 1170 row again, nothing built', $lineChanged->toRequest() === null
    && str_starts_with($lineChanged->rowError(1, 'account'), 'Zeile auf MWST-Konto 1170: z77 rechnet 8.10'));
$fixed = $repost($e, [['6500', '100.00', '', 'VM', '', 'Büromaterial'], ['1020', '', '108.10', '', '', 'Zahlung']])->toRequest();
check('E7b … the 1170 row removed and the counter line corrected: consistent, passes the lock rule and saves',
    shape($fixed?->lines) === ['6500 D 100.00 VM 810 100.00 8.10 | Büromaterial', '1170 D 8.10 | MWST', '1020 C 108.10 | Zahlung'] && $lockSays($fixed) === null
    && $manual->update((int) $e->getId(), $e->getVersion(), $fixed) === true);

[$e, $form] = $open('voucher');
check('E8 the one-line entry\'s voucher tax (30.00 ≠ 29.97 computed): opens with «Steuer» 30.00 and 1170 taken over; an unchanged save is a no-op',
    $rowsOf($form) === [['6500', '370.00', '', 'VM', '30.00', ''], ['1020', '', '400.00', '', '', '']] && !$form->hasErrors()
    && $manual->update((int) $e->getId(), $e->getVersion(), $form->toRequest()) === false, json_encode([$rowsOf($form), messages($form)], JSON_UNESCAPED_UNICODE));

[$e, $form] = $open('early');
check('E9 an entry dated before «Abrechnung ab» opens as before: the typed 1170 row, no block, no error; an unchanged save is a no-op',
    count($rowsOf($form)) === 3 && !$form->showsVatBlock() && !$form->hasErrors() && $manual->update((int) $e->getId(), $e->getVersion(), $form->toRequest()) === false);
$moved = $repost($e, $rowsOf($form), null, '2027-02-10');
check('E10 … its date moved across «Abrechnung ab» with the rows unchanged: judged like a new entry (board S4-2) — the error at the 1170 row',
    $moved->toRequest() === null && str_starts_with($moved->rowError(1, 'account'), 'Zeile auf MWST-Konto 1170: z77 rechnet 8.10'), json_encode(messages($moved), JSON_UNESCAPED_UNICODE));

[$e, $form] = $open('off');
$form->addRows(ManualEntryForm::MORE_ROWS);
$form->preview();
check('E11 «Weitere Zeilen» on an edit: the block stays, the errors wait for the save', $form->showsVatBlock() && !$form->hasErrors() && $form->computedSummary() === '8.10 auf 1170');

$editHtml = $render('form', ['form' => $open('match')[1], 'year' => $year, 'entry' => $entries->findByRef($refs['voucher']), 'csrfToken' => 'tok', 'entityCsrf' => 'etok', 'version' => 1,
    'actionBase' => '/backend/finance/journal', 'configNotice' => null, 'fmt' => static fn(?Money $m) => \Z77\Shared\Money\AmountFormat::of($m), 'oneLineFits' => false]);
check('E12 the edit screen renders the taken-over line in the block, without a button «MWST-Zeilen neu berechnen»',
    str_contains($editHtml, 'aus der gebuchten Zeile 2 übernommen') && !str_contains($editHtml, 'neu berechnen') && str_contains($editHtml, 'Speichern (Änderung wird protokolliert)'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
