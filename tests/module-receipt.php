<?php

/**
 * module-receipt harness (CLI) — wave 1 of the gateway plan, part B (`docs/03-development/
 * gateway-app-kanal-bauplan.md`, owner decisions E1–E12 and T2 / T4 of 2026-11-12): the receipt
 * templates and payment methods, the receipt journal, the capture without a session, the
 * correction and the split, «Journal verbuchen» up to a cut-off.
 *
 * What is load-bearing here:
 *
 *   - (O, offline) module direction (financial and dms never name the receipt module, receipt
 *     never names module-api, no superglobal); the config announces the entities and the three
 *     open-work scopes; `Receipt::missing()` / `applyTemplate()` (debit, VAT) /
 *     `applyPaymentMethod()` (credit, E10) / `splitOff()` (E12) / the VAT decision / the state
 *     guard; the default cut-off (E11); the master-data normalization and the format-only
 *     validators; the photo sniff on the BYTES (JPEG, PNG, PDF in; text, empty, oversize out);
 *   - (M, database) migrate + `diff` clean; templates and payment methods with references (account,
 *     group, tax code), code immutable, deactivate; capture WITHOUT an AuthService: template and
 *     payment method applied, text defaulted, photo in `Belege/<year>/<month>` with the sniffed
 *     extension, refusals per field with nothing stored, the capture key; correction with the
 *     version; preview and post up to a cut-off: an incomplete receipt blocks the run, a stale or
 *     changed run refused, nothing posted; the entries (source `receipt`, key `receipt:{id}`, the
 *     VAT split of the one-line entry), the protocol, a ledger refusal rolls the whole run back;
 *     split (sum, shared photo); delete keeps the photo and offers it only when no part names it;
 *     the open-work findings.
 *
 * Run: php tests/module-receipt.php
 * O needs the monorepo's vendor/ — `composer install` in the root. M needs a reachable MariaDB and
 * the `z77test` credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M
 * is reported SKIPPED and the exit code reflects the offline section only.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-receipt: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptJournalPosting;
use Z77\Module\Receipt\Entities\ReceiptState;
use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Module\Receipt\Services\InvalidMasterDataException;
use Z77\Module\Receipt\Services\InvalidReceiptException;
use Z77\Module\Receipt\Services\MasterDataCodeChangedException;
use Z77\Module\Receipt\Services\PaymentMethodService;
use Z77\Module\Receipt\Services\ReceiptCaptureService;
use Z77\Module\Receipt\Services\ReceiptCorrection;
use Z77\Module\Receipt\Services\ReceiptJournalPostingService;
use Z77\Module\Receipt\Services\ReceiptJournalService;
use Z77\Module\Receipt\Services\ReceiptRefusedException;
use Z77\Module\Receipt\Services\ReceiptTemplateService;
use Z77\Module\Receipt\Validators\PaymentMethodValidator;
use Z77\Module\Receipt\Validators\ReceiptTemplateValidator;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

/** A real JPEG (GD), so the DMS can build its variants. */
function jpeg(): string
{
    $img = imagecreatetruecolor(40, 30);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
    ob_start();
    imagejpeg($img, null, 80);

    return (string) ob_get_clean();
}

function png(): string
{
    $img = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

/** A receipt in memory. */
function receipt(string $text = '', string $amount = '45.50'): Receipt
{
    return new Receipt(new \DateTimeImmutable('2026-11-03'), chf($amount), $text, 7, 'Peter', new \DateTimeImmutable('2026-11-03 10:00'), 'dev-1', 'iPhone');
}

// ── O. offline ───────────────────────────────────────────────────────────

echo "O. Module direction, entities, template shape, photo sniff (offline)\n";

$sourceOf = static function (string $dir): string {
    $out = '';
    $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out .= file_get_contents($file->getPathname()) . "\n";
        }
    }
    return $out;
};
$receiptSrc   = $sourceOf($root . '/packages/module-receipt/src');
$financialSrc = $sourceOf($root . '/packages/module-financial/src');
$dmsSrc       = $sourceOf($root . '/packages/module-dms/src');
$composer     = json_decode((string) file_get_contents($root . '/packages/module-receipt/composer.json'), true);
$finComposer  = json_decode((string) file_get_contents($root . '/packages/module-financial/composer.json'), true);

check('O1 module-receipt requires financial and dms; module-financial gains neither dms nor api (plan T4)',
    isset($composer['require']['z77/module-financial'], $composer['require']['z77/module-dms'])
    && !isset($finComposer['require']['z77/module-dms']) && !isset($finComposer['require']['z77/module-api']));
check('O2 neither module-financial nor module-dms names the receipt module; module-receipt never names module-api (wave 2 registers the targets)',
    !str_contains($financialSrc, 'Module\\Receipt') && !str_contains($dmsSrc, 'Module\\Receipt') && !str_contains($receiptSrc, 'Module\\Api'));
check('O3 no superglobal in the package (Rule 4) and no UploadService (the trusted system path, T2)',
    preg_match('/\$_(SERVER|POST|GET|FILES|COOKIE|REQUEST)\b/', $receiptSrc) === 0 && !str_contains($receiptSrc, 'UploadService'));
$config = require $root . '/packages/module-receipt/src/App/Config/receiptConfig.inc.php';
check('O4 the config announces Receipt and ReceiptJournalPosting and registers the check under period-close, ledger-report, vat-return; no view area',
    $config['doctrineEntities'] === [Receipt::class, ReceiptJournalPosting::class] && $config['viewArea'] === false
    && array_keys($config['openWorkChecks']) === ['period-close', 'ledger-report', 'vat-return']);

$r = receipt();
check('O5 a fresh receipt is captured and misses Text, Soll, Haben, MWST — in that order', $r->isCaptured() && $r->getState() === ReceiptState::Captured
    && $r->missing() === ['Text', 'Soll', 'Haben', 'MWST'] && !$r->isComplete());
$tanken = new ReceiptTemplate(['code' => 'Tanken', 'label' => 'Tanken', 'debit_account' => '6200', 'tax_code' => 'vi', 'position' => 10]);
$r->applyTemplate($tanken);
check('O6 the template normalizes (code lower, tax code upper) and applies the DEBIT account and the code — not the credit side (E10)',
    $tanken->getCode() === 'tanken' && $tanken->getTaxCode() === 'VI' && $r->getTemplateCode() === 'tanken'
    && $r->getDebitAccount() === '6200' && $r->getCreditAccount() === null && $r->getTaxCode() === 'VI' && !$r->isWithoutVat() && $r->missing() === ['Text', 'Haben']);
$kasse = new PaymentMethod(['code' => 'Kasse', 'label' => 'Kasse', 'credit_account' => '1000', 'position' => 10]);
$r->applyPaymentMethod($kasse);
check('O6b the payment method («Bezahlt mit», E10) resolves the credit account and is recorded by code', $kasse->getCode() === 'kasse'
    && $r->getPaymentMethodCode() === 'kasse' && $r->getCreditAccount() === '1000' && $r->missing() === ['Text']);
$parken = new ReceiptTemplate(['code' => 'parken', 'label' => 'Parkgebühr', 'debit_account' => '6200', 'tax_code' => '']);
$r2 = receipt('Parkhaus');
$r2->applyTemplate($parken);
$r2->applyPaymentMethod($kasse);
check('O7 a template with an empty tax code means «without VAT» — with a payment method the receipt is complete', $parken->isWithoutVat() && $r2->isWithoutVat() && $r2->getTaxCode() === null && $r2->isComplete());
$whole = receipt('Coop', '50.00');
$whole->applyTemplate($tanken);
$whole->applyPaymentMethod($kasse);
$part = $whole->splitOff(chf('12.30'), 'Owner', new \DateTimeImmutable('2026-11-20'));
check('O7b split (E12): the parts sum to the original; the new part shares photo, date, text, payment method and credit, capture data; its debit and VAT stay open, no capture key',
    $whole->getAmount()->toDecimal() === '37.70' && $part->getAmount()->toDecimal() === '12.30' && $part->getDocumentId() === $whole->getDocumentId()
    && $part->getReceiptDate() == $whole->getReceiptDate() && $part->getText() === 'Coop' && $part->getPaymentMethodCode() === 'kasse' && $part->getCreditAccount() === '1000'
    && $part->getCapturedBy() === 'Peter' && $part->getDeviceId() === 'dev-1' && $part->getDebitAccount() === null && $part->missing() === ['Soll', 'MWST']
    && $part->getCaptureKey() === null && $part->isCaptured() && $whole->getChangedBy() === 'Owner');
check('O7c a split part must be positive and smaller than the receipt',
    caught(fn() => $whole->splitOff(chf('37.70'), 'O', new \DateTimeImmutable()), \LogicException::class) !== null
    && caught(fn() => $whole->splitOff(chf('0.00'), 'O', new \DateTimeImmutable()), \LogicException::class) !== null);
check('O7d the default cut-off (E11) is the last day of the previous month',
    ReceiptJournalPostingService::defaultCutOff(new \DateTimeImmutable('2026-11-12 15:00'))->format('Y-m-d') === '2026-10-31'
    && ReceiptJournalPostingService::defaultCutOff(new \DateTimeImmutable('2026-01-05'))->format('Y-m-d') === '2025-12-31');
check('O8 the VAT decision is a code OR «without VAT», never both (the entity refuses)',
    caught(fn() => $r2->correct(new \DateTimeImmutable('2026-11-03'), chf('1.00'), 'x', '6200', '1000', 'VI', true, 'P', new \DateTimeImmutable()), \LogicException::class) !== null);
check('O9 a non-positive amount is refused by the entity; an incomplete receipt cannot be posted',
    caught(fn() => receipt('x', '0.00'), \LogicException::class) !== null
    && caught(fn() => receipt()->post('2026/1', new ReceiptJournalPosting('P', new \DateTimeImmutable(), 1, chf('1.00')), 'P', new \DateTimeImmutable()), \LogicException::class) !== null);
$r2->post('2026/5', new ReceiptJournalPosting('P', new \DateTimeImmutable(), 1, chf('1.00')), 'P', new \DateTimeImmutable());
check('O10 posted: state, entry ref, who; a posted receipt refuses a correction and a second posting',
    $r2->isPosted() && $r2->getLedgerEntryRef() === '2026/5' && $r2->getPostedBy() === 'P'
    && caught(fn() => $r2->correct(new \DateTimeImmutable('2026-11-03'), chf('1.00'), 'x', null, null, null, false, 'P', new \DateTimeImmutable()), \LogicException::class) !== null);
check('O11 «Handy <user> · <device>» for a device capture, the name alone without a device (E8)',
    receipt()->capturedByLabel() === 'Handy Peter · iPhone'
    && (new Receipt(new \DateTimeImmutable('2026-11-03'), chf('1.00'), '', 1, 'Peter', new \DateTimeImmutable()))->capturedByLabel() === 'Peter');
$a = new ReceiptTemplate(['code' => 'a', 'position' => 20]);
$b = new ReceiptTemplate(['code' => 'b', 'position' => 10]);
$tiles = [$a, $b];
usort($tiles, [ReceiptTemplate::class, 'compare']);
check('O12 the tile order is position first', array_map(static fn($t) => $t->getCode(), $tiles) === ['b', 'a']);
$bad = new ReceiptTemplateValidator(new ReceiptTemplate(['code' => '1x', 'label' => '', 'icon' => '<svg>', 'debit_account' => '6200']));
$bad->isValid();
check('O13 the format-only validator: code shape, label required, icon a key',
    $bad->hasFieldError('code') && $bad->hasFieldError('label') && $bad->hasFieldError('icon'));
$empty = new ReceiptTemplateValidator(new ReceiptTemplate(['code' => 'ok', 'label' => 'Ok']));
$noCredit = new PaymentMethodValidator(new PaymentMethod(['code' => 'x', 'label' => '']));
check('O14 a template without debit and a payment method without credit account are refused; the short code too',
    !$empty->isValid() && $empty->hasFieldError('debit_account') && !$noCredit->isValid() && $noCredit->hasFieldError('credit_account') && $noCredit->hasFieldError('code') && $noCredit->hasFieldError('label'));
$sniff = static function (string $bytes): array { $e = []; $m = ReceiptCaptureService::sniff($bytes, $e); return [$m, $e['photo'] ?? '']; };
check('O15 the photo is judged by its BYTES: JPEG, PNG, PDF taken', $sniff(jpeg())[0] === 'image/jpeg' && $sniff(png())[0] === 'image/png' && $sniff($pdf)[0] === 'application/pdf');
check('O16 … a text file, an empty upload and one byte over the limit refused, with a German message',
    $sniff("MZ\x90\x00 not a photo")[0] === null && str_contains($sniff('hello')[1], 'Nur ein Foto')
    && $sniff('')[1] === 'Das Foto fehlt.' && $sniff(str_repeat('a', ReceiptCaptureService::MAX_PHOTO_BYTES + 1))[0] === null);

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

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-receipt-' . getmypid());
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

// The REAL packages: their configs announce the entities, their res/migrations hold the migrations.
$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $root . '/packages/module-financial',
    'Dms'       => $root . '/packages/module-dms',
    'Receipt'   => $root . '/packages/module-receipt',
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'receipt', 'modules' => ['vat' => [], 'mandator' => [], 'financial' => [], 'dms' => [], 'receipt' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
@mkdir($base . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $seedName) {
    copy($packages['Vat'] . '/data/framework/vat/' . $seedName . '.default.json', $base . '/data/framework/vat/' . $seedName . '.json');
}

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map — and NO AuthService (the device channel is stateless). */
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
$count = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");
$docs  = fn() => json_decode((string) @file_get_contents($base . '/data/documents/documents.json'), true) ?: [];
$folderPath = function (int $folderId) use ($base): string {
    $folders = array_column(json_decode((string) file_get_contents($base . '/data/documents/folders.json'), true), null, 'id');
    $names = [];
    for ($id = $folderId; isset($folders[$id]) && $folders[$id]['parent_id'] !== null; $id = (int) $folders[$id]['parent_id']) {
        array_unshift($names, $folders[$id]['name']);
    }
    return implode('/', $names);
};

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
check('M1 migrate exits 0 (vat, mandator, financial, receipt in timestamp order)', $code === 0, trim($out));
$migrations = glob($packages['Receipt'] . '/res/migrations/Version*.php');
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Receipt\\Migrations']);
check('M2 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && glob($packages['Receipt'] . '/res/migrations/Version*.php') === $migrations, trim($out));
foreach (array_diff(glob($packages['Receipt'] . '/res/migrations/Version*.php'), $migrations) as $stray) {
    @unlink($stray);   // a failed M2 must not leave a generated file in the package
}

// The books: the KMU chart, the mandator (VAT accounts, liable), the fiscal year 2026.
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new \Z77\Module\Financial\Entities\FiscalYear('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));

// ── templates and payment methods ──
$em  = $wire();
$tpl = new ReceiptTemplateService($em);
$pms = new PaymentMethodService($em);
$refused = static function (callable $fn): array {
    $e = caught($fn, InvalidMasterDataException::class);
    return $e instanceof InvalidMasterDataException ? $e->validator->getFieldErrors() + ['_' => implode(' ', $e->validator->getErrors())] : ['none' => 'saved'];
};
$t = fn(array $data) => new ReceiptTemplate($data + ['label' => 'X', 'position' => 10]);
$p = fn(array $data) => new PaymentMethod($data + ['label' => 'X', 'position' => 10]);
$r1 = $refused(fn() => $tpl->save($t(['code' => 'a1', 'debit_account' => '62', 'tax_code' => 'VI'])));
$r1b = $refused(fn() => $pms->save($p(['code' => 'p1', 'credit_account' => '9999'])));
check('M3 a template naming a GROUP and a payment method naming an unknown account are refused per field, in German', str_contains($r1['debit_account'] ?? '', 'Gruppe') && str_contains($r1b['credit_account'] ?? '', 'gibt es nicht'), json_encode([$r1, $r1b], JSON_UNESCAPED_UNICODE));
$r2 = $refused(fn() => $tpl->save($t(['code' => 'a2', 'debit_account' => '6200', 'tax_code' => 'XX'])));
check('M4 an unknown tax code refused', str_contains($r2['tax_code'] ?? '', 'gibt es nicht'), json_encode($r2, JSON_UNESCAPED_UNICODE));
$kasse = $p(['code' => 'kasse', 'label' => 'Kasse', 'credit_account' => '1000', 'position' => 10]);
$pms->save($kasse);
$karte = $p(['code' => 'karte', 'label' => 'Firmenkarte', 'credit_account' => '1020', 'position' => 5]);
$pms->save($karte);
check('M5 two payment methods save («Bezahlt mit», E10); the file exists; active() in choice order', $kasse->getId() !== null && is_file($base . '/data/framework/receipt/payment_methods.json')
    && array_map(static fn($x) => $x->getCode(), (new PaymentMethodService($wire()))->active()) === ['karte', 'kasse']);
$tanken = $t(['code' => 'tanken', 'label' => 'Tanken', 'icon' => 'fuel', 'debit_account' => '6200', 'tax_code' => 'VI', 'position' => 10]);
$tpl->save($tanken);
$buero  = $t(['code' => 'buero', 'label' => 'Büromaterial', 'debit_account' => '6500', 'tax_code' => 'VM', 'position' => 5]);
$tpl->save($buero);
$parken = $t(['code' => 'parken', 'label' => 'Parkgebühr', 'debit_account' => '6200', 'tax_code' => '', 'position' => 20]);
$tpl->save($parken);
check('M6 three templates save (one without VAT); the file exists; active() in tile order', $tanken->getId() !== null && is_file($base . '/data/framework/receipt/receipt_templates.json')
    && array_map(static fn($x) => $x->getCode(), (new ReceiptTemplateService($wire()))->active()) === ['buero', 'tanken', 'parken']);
check('M7 a duplicate code refused (template and payment method)', isset($refused(fn() => $tpl->save($t(['code' => 'tanken', 'debit_account' => '6200'])))['code'])
    && isset($refused(fn() => $pms->save($p(['code' => 'kasse', 'credit_account' => '1000'])))['code']));
$em = $wire();
$tpl = new ReceiptTemplateService($em);
$pms = new PaymentMethodService($em);
$changed = $tpl->find('parken');
$changed->setCode('parkieren');
$changedPm = $pms->find('karte');
$changedPm->setCode('kreditkarte');
check('M8 the code of an existing template or payment method is immutable', caught(fn() => $tpl->save($changed), MasterDataCodeChangedException::class) !== null
    && caught(fn() => $pms->save($changedPm), MasterDataCodeChangedException::class) !== null);
$tpl->setActive($tpl->find('parken'), false);
$pms->setActive($pms->find('karte'), false);
check('M9 deactivated (never deleted): gone from active(), still in all() and findable', array_map(static fn($x) => $x->getCode(), (new ReceiptTemplateService($wire()))->active()) === ['buero', 'tanken']
    && count((new ReceiptTemplateService($wire()))->all()) === 3 && (new ReceiptTemplateService($wire()))->find('parken')?->isActive() === false
    && array_map(static fn($x) => $x->getCode(), (new PaymentMethodService($wire()))->active()) === ['kasse'] && count((new PaymentMethodService($wire()))->all()) === 2);

// ── capture, without a session ──
$em = $wire();
check('M10 the capture runs without an AuthService (the device channel is stateless)', !\Z77\Core\DI::getInstance()->has('AuthService'));
$capture = new ReceiptCaptureService($em);
$c1 = $capture->capture('108.10', jpeg(), 'IMG_0042.exe', 'Peter', '2026-11-03', '', 'tanken', 'kasse', 'dev-1', 'iPhone Peter', 'key-001');
$doc1 = array_values(array_filter($docs(), static fn($d) => (int) $d['id'] === $c1->getDocumentId()))[0] ?? [];
check('M11 captured: template (debit, VAT) and payment method (credit) applied, the text defaulted to the label, device and who as data',
    $c1->getId() !== null && $c1->isCaptured() && $c1->getTemplateCode() === 'tanken' && $c1->getText() === 'Tanken' && $c1->getPaymentMethodCode() === 'kasse'
    && $c1->getDebitAccount() === '6200' && $c1->getCreditAccount() === '1000' && $c1->getTaxCode() === 'VI' && $c1->isComplete()
    && $c1->getCapturedBy() === 'Peter' && $c1->getDeviceId() === 'dev-1' && $c1->getAmount()->toDecimal() === '108.10' && $c1->getVersion() === 1);
check('M12 the photo is in the DMS under Belege/2026/11 (E4), named from the SNIFFED type (.jpg, not the client\'s .exe), source generated',
    ($doc1['mime_type'] ?? '') === 'image/jpeg' && ($doc1['ext'] ?? '') === 'jpg' && ($doc1['original_name'] ?? '') === 'img-0042.jpg'
    && ($doc1['source'] ?? '') === 'generated' && $folderPath((int) ($doc1['folder_id'] ?? 0)) === 'Belege/2026/11', json_encode([$doc1['original_name'] ?? null, $doc1['ext'] ?? null, $folderPath((int) ($doc1['folder_id'] ?? 0))]));
$docsBefore = count($docs());
$again = $capture->capture('108.10', jpeg(), 'IMG_0042.jpg', 'Peter', '2026-11-03', '', 'tanken', 'kasse', 'dev-1', 'iPhone Peter', 'key-001');
check('M13 the same capture key again answers with THAT receipt and stores nothing (E7, the offline queue)', $again->getId() === $c1->getId() && count($docs()) === $docsBefore && $count('receipt') === 1);
$c2 = $capture->capture('12.40', png(), 'scan.png', 'Peter', null, 'Kugelschreiber');
check('M14 without template, payment method and date: today (E6), incomplete — only the text', $c2->getReceiptDate()->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d')
    && $c2->getTemplateCode() === null && $c2->getPaymentMethodCode() === null && $c2->missing() === ['Soll', 'Haben', 'MWST'] && $c2->getDeviceId() === null);
$c3 = $capture->capture("1'000.00", $pdf, 'rechnung.pdf', 'Peter', '2026-10-31', 'Parkhaus Jahresabo', 'parken', 'karte');
check('M15 a DEACTIVATED template and payment method are still applied (an offline queue may send late); «without VAT»; a PDF in Belege/2026/10',
    $c3->getTemplateCode() === 'parken' && $c3->isWithoutVat() && $c3->getCreditAccount() === '1020' && $c3->isComplete() && $c3->getAmount()->toDecimal() === '1000.00'
    && $folderPath((int) (array_values(array_filter($docs(), static fn($d) => (int) $d['id'] === $c3->getDocumentId()))[0]['folder_id'] ?? 0)) === 'Belege/2026/10');
$rowsBefore = $count('receipt');
$docsBefore = count($docs());
$e = caught(fn() => $capture->capture('', 'just text', 'x.txt', '', (new \DateTimeImmutable('+2 days'))->format('Y-m-d'), str_repeat('x', 300), 'gibtsnicht', 'bar'), InvalidReceiptException::class);
check('M16 a bad capture is refused PER FIELD (date in the future, amount, text length, who, template, payment method, photo) and stores nothing',
    $e instanceof InvalidReceiptException && array_keys($e->errors) == ['date', 'amount', 'text', 'captured_by', 'template', 'payment_method', 'photo'] && $count('receipt') === $rowsBefore && count($docs()) === $docsBefore,
    $e instanceof InvalidReceiptException ? json_encode($e->errors, JSON_UNESCAPED_UNICODE) : 'no exception');
check('M17 amount 0 refused (E5); a bad capture key refused before anything else',
    isset(caught(fn() => $capture->capture('0', jpeg(), 'a.jpg', 'Peter'), InvalidReceiptException::class)?->errors['amount'])
    && isset(caught(fn() => $capture->capture('1', jpeg(), 'a.jpg', 'Peter', captureKey: 'bad key!'), InvalidReceiptException::class)?->errors['capture_key']));

// ── correction ──
$em      = $wire();
$journal = new ReceiptJournalService($em, 'Owner');
check('M18 the open journal: captured receipts by date, then id; `until` cuts by receipt date', array_map(static fn($x) => $x->getId(), $journal->pending()) === [$c3->getId(), $c1->getId(), $c2->getId()]
    && array_map(static fn($x) => $x->getId(), $journal->pending(new \DateTimeImmutable('2026-11-05'))) === [$c3->getId(), $c1->getId()]);
check('M19 a stale version is refused (CHANGED), nothing written', caught(fn() => $journal->update((int) $c2->getId(), 99, new ReceiptCorrection((new \DateTimeImmutable())->format('Y-m-d'), '12.40', 'x')), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED);
$bad = caught(fn() => $journal->update((int) $c2->getId(), 1, new ReceiptCorrection('2026-11-04', '12.40', 'Kugelschreiber', '65', '1000', 'VM', true)), InvalidReceiptException::class);
check('M20 a correction is checked like a capture: a group account, a code AND «ohne MWST» refused', $bad instanceof InvalidReceiptException && isset($bad->errors['debit'], $bad->errors['tax_code']));
$fixed = $journal->update((int) $c2->getId(), 1, new ReceiptCorrection('2026-11-04', '12.40', 'Kugelschreiber', '6500', '1000', 'VM'));
check('M21 corrected: date, accounts (the credit too), code; complete; the version moved; who and when stamped', $fixed->isComplete() && $fixed->getVersion() === 2 && $fixed->getChangedBy() === 'Owner'
    && $fixed->getReceiptDate()->format('Y-m-d') === '2026-11-04' && $fixed->getCreditAccount() === '1000' && (int) $db->fetchOne('SELECT version FROM receipt WHERE id = ?', [$c2->getId()]) === 2);
$c4 = (new ReceiptCaptureService($wire()))->capture('30.00', jpeg(), 'b.jpg', 'Peter', '2026-11-05', 'Coop', null, 'kasse');

// ── preview and post with a cut-off (E11) ──
$em      = $wire();
$posting = new ReceiptJournalPostingService($em, 'Owner');
$cut     = new \DateTimeImmutable('2026-11-04');
$preview = $posting->preview($cut);
$rowOf   = static fn(string $acc) => array_values(array_filter($preview['rows'], static fn($r) => $r['account'] === $acc))[0] ?? null;
check('M22 preview up to the cut-off 04.11.: the three receipts dated on or before it (with their versions), nothing blocked; the later one is not in the run',
    array_column($preview['seen'], 'id') === [$c3->getId(), $c1->getId(), $c2->getId()] && $preview['seen'][2]['version'] === 2 && $preview['blocked'] === []
    && $preview['postable'] && $preview['entries'] === 3 && $preview['gross']->toDecimal() === '1120.50', json_encode($preview['blocked'], JSON_UNESCAPED_UNICODE));
check('M23 preview rows per account: VI 8.1 % in 108.10 = 8.10 on 1171, VM in 12.40 = 0.93 on 1170, 1000 and 1020 credited',
    $rowOf('1171')['debit']->toDecimal() === '8.10' && $rowOf('1170')['debit']->toDecimal() === '0.93' && $rowOf('6200')['debit']->toDecimal() === '1100.00'
    && $rowOf('1000')['credit']->toDecimal() === '120.50' && $rowOf('1020')['credit']->toDecimal() === '1000.00', json_encode(array_map(static fn($r) => [$r['account'], $r['debit']->toDecimal(), $r['credit']->toDecimal()], $preview['rows'])));
$wide = $posting->preview(new \DateTimeImmutable('2026-11-30'));
$e    = caught(fn() => $posting->post(new \DateTimeImmutable('2026-11-30'), $wide['seen']), ReceiptRefusedException::class);
check('M24 up to 30.11. the incomplete receipt BLOCKS the run: preview not postable, post() refuses the WHOLE run (INCOMPLETE) naming it — nothing posted, nothing moved',
    !$wide['postable'] && count($wide['blocked']) === 1 && $wide['blocked'][0]['id'] === $c4->getId() && str_contains($wide['blocked'][0]['reason'], 'Soll, MWST')
    && $e?->reason === ReceiptRefusedException::INCOMPLETE && str_contains((string) $e?->getMessage(), 'Beleg ' . $c4->getId())
    && $count('journal_entry') === 0 && $count('receipt_journal_posting') === 0 && $db->fetchFirstColumn("SELECT DISTINCT state FROM receipt") === ['captured'], (string) $e?->getMessage());
$stale = $preview['seen'];
$stale[0]['version'] = 99;
check('M25 a stale version refuses with CHANGED; so does a run missing a receipt of the cut-off (one added since the confirmation) — nothing posted',
    caught(fn() => $posting->post($cut, $stale), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED
    && caught(fn() => $posting->post($cut, array_slice($preview['seen'], 1)), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED && $count('journal_entry') === 0);
check('M26 post() of an empty run refuses with EMPTY', caught(fn() => $posting->post(new \DateTimeImmutable('2026-01-31'), []), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::EMPTY);
check('M27 without a named actor and without a session the posting refuses to guess who', caught(fn() => (new ReceiptJournalPostingService($wire()))->post($cut, $preview['seen']), \LogicException::class) !== null);

$em       = $wire();
$protocol = (new ReceiptJournalPostingService($em, 'Owner'))->post($cut, $preview['seen']);
$entries  = $db->fetchAllAssociative("SELECT e.id, CONCAT(y.code, '/', e.number) AS ref, e.entry_date, e.text, e.source_type, e.source_ref, e.idempotency_key FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id ORDER BY e.number");
$linesOf  = fn(int $entryId) => $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit, l.tax_code, l.tax_rate, l.tax_base, l.tax_amount FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.position', [$entryId]);
$rowC1    = $db->fetchAssociative('SELECT * FROM receipt WHERE id = ?', [$c1->getId()]);
check('M28 one journal entry per receipt, in date order, source `receipt`, ref = id, key `receipt:{id}`, dated on the receipt date',
    count($entries) === 3 && array_column($entries, 'source_type') === ['receipt', 'receipt', 'receipt']
    && array_column($entries, 'source_ref') === [(string) $c3->getId(), (string) $c1->getId(), (string) $c2->getId()]
    && $entries[1]['idempotency_key'] === 'receipt:' . $c1->getId() && $entries[1]['entry_date'] === '2026-11-03' && $entries[1]['text'] === 'Tanken', json_encode($entries));
$l1 = $linesOf((int) $entries[1]['id']);
$l0 = $linesOf((int) $entries[0]['id']);
check('M29 the VAT split is the one-line entry\'s: net 100.00 on 6200 with VI 810, tax 8.10 on 1171, gross on 1000; «without VAT» = two lines, no code, credit 1020',
    count($l1) === 3 && $l1[0]['number'] === '6200' && $l1[0]['debit'] === '100.00' && $l1[0]['tax_code'] === 'VI' && (int) $l1[0]['tax_rate'] === 810 && $l1[0]['tax_amount'] === '8.10'
    && $l1[1]['number'] === '1171' && $l1[1]['debit'] === '8.10' && $l1[2]['number'] === '1000' && $l1[2]['credit'] === '108.10'
    && count($l0) === 2 && $l0[0]['tax_code'] === null && $l0[1]['number'] === '1020' && $l0[1]['credit'] === '1000.00', json_encode([$l1, $l0]));
check('M30 the receipts are posted: state, entry ref, protocol, who / when; the protocol counts 3 for 1120.50; the receipt after the cut-off is untouched',
    $rowC1['state'] === 'posted' && $rowC1['ledger_entry_ref'] === $entries[1]['ref'] && (int) $rowC1['journal_posting_id'] === $protocol->getId() && $rowC1['posted_by'] === 'Owner'
    && $protocol->getReceiptCount() === 3 && $protocol->getGrossTotal()->toDecimal() === '1120.50' && $protocol->getPostedBy() === 'Owner'
    && $db->fetchAssociative('SELECT state, version FROM receipt WHERE id = ?', [$c4->getId()]) == ['state' => 'captured', 'version' => 1]);
check('M31 a posted receipt cannot be posted again, corrected, split or deleted (CHANGED)',
    caught(fn() => (new ReceiptJournalPostingService($wire(), 'Owner'))->post($cut, [['id' => $c1->getId(), 'version' => (int) $rowC1['version']]]), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED
    && caught(fn() => (new ReceiptJournalService($wire(), 'Owner'))->update((int) $c1->getId(), (int) $rowC1['version'], new ReceiptCorrection('2026-11-03', '1', 'x')), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED
    && caught(fn() => (new ReceiptJournalService($wire(), 'Owner'))->split((int) $c1->getId(), (int) $rowC1['version'], '1.00'), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED
    && caught(fn() => (new ReceiptJournalService($wire(), 'Owner'))->delete((int) $c1->getId(), (int) $rowC1['version']), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED);
check('M32 the open journal now holds only the receipt after the cut-off', array_map(static fn($x) => $x->getId(), (new ReceiptJournalService($wire()))->pending()) === [$c4->getId()]);

// ── split (E12) ──
$journal = new ReceiptJournalService($wire(), 'Owner');
$c4 = $journal->update((int) $c4->getId(), 1, new ReceiptCorrection('2026-11-05', '30.00', 'Coop', '6500', '1000', 'VM'));
check('M33 a split part not smaller than the receipt is refused per field; a stale version with CHANGED',
    isset(caught(fn() => $journal->split((int) $c4->getId(), $c4->getVersion(), '30.00'), InvalidReceiptException::class)?->errors['amount'])
    && caught(fn() => $journal->split((int) $c4->getId(), 1, '10.00'), ReceiptRefusedException::class)?->reason === ReceiptRefusedException::CHANGED);
[$c4, $c4b] = (new ReceiptJournalService($wire(), 'Owner'))->split((int) $c4->getId(), $c4->getVersion(), '10.00');
$rowsC4 = $db->fetchAllAssociative('SELECT id, amount, document_id, receipt_date, text, payment_method_code, credit_account, debit_account, tax_code, captured_by, version FROM receipt WHERE id IN (?, ?) ORDER BY id', [$c4->getId(), $c4b->getId()]);
check('M34 split: 30.00 → 20.00 + 10.00 (the sum holds), the SAME photo, date, text, payment method and credit; the new part is captured and lacks debit and VAT; the original\'s version moved',
    count($rowsC4) === 2 && $rowsC4[0]['amount'] === '20.00' && $rowsC4[1]['amount'] === '10.00' && $rowsC4[0]['document_id'] === $rowsC4[1]['document_id']
    && $rowsC4[1]['receipt_date'] === '2026-11-05' && $rowsC4[1]['text'] === 'Coop' && $rowsC4[1]['payment_method_code'] === 'kasse' && $rowsC4[1]['credit_account'] === '1000'
    && $rowsC4[1]['debit_account'] === null && $rowsC4[1]['tax_code'] === null && $rowsC4[1]['captured_by'] === 'Peter' && (int) $rowsC4[0]['version'] === 3, json_encode($rowsC4));
$c4b = (new ReceiptJournalService($wire(), 'Owner'))->update((int) $c4b->getId(), $c4b->getVersion(), new ReceiptCorrection('2026-11-05', '10.00', 'Coop Pfand', '6500', '1000', '', true));

// A ledger refusal rolls the whole run back: one receipt in a year without a fiscal year.
$c5 = (new ReceiptCaptureService($wire()))->capture('20.00', jpeg(), 'c.jpg', 'Peter', '2025-12-30', 'Altjahr', 'tanken', 'kasse');
$c6 = (new ReceiptCaptureService($wire()))->capture('25.00', jpeg(), 'd.jpg', 'Peter', '2026-11-06', 'Benzin', 'tanken', 'kasse');
$em = $wire();
$p2 = (new ReceiptJournalPostingService($em, 'Owner'))->preview(new \DateTimeImmutable('2026-11-30'));
check('M35 the preview blocks a receipt outside every fiscal year, in German, naming it; the split parts are both in the run',
    array_column($p2['seen'], 'id') === [$c5->getId(), $c4->getId(), $c4b->getId(), $c6->getId()] && !$p2['postable']
    && count($p2['blocked']) === 1 && $p2['blocked'][0]['id'] === $c5->getId() && str_contains($p2['blocked'][0]['reason'], 'kein Geschäftsjahr'), json_encode($p2['blocked'], JSON_UNESCAPED_UNICODE));
$entriesBefore = $count('journal_entry');
$e = caught(fn() => (new ReceiptJournalPostingService($wire(), 'Owner'))->post(new \DateTimeImmutable('2026-11-30'), $p2['seen']), ReceiptRefusedException::class);
check('M36 posted anyway: the ledger refuses (LEDGER) — the WHOLE run rolled back, no entry, no protocol, all still captured',
    $e?->reason === ReceiptRefusedException::LEDGER && str_contains((string) $e?->getMessage(), 'kein Geschäftsjahr') && $count('journal_entry') === $entriesBefore
    && $count('receipt_journal_posting') === 1 && $db->fetchOne('SELECT state FROM receipt WHERE id = ?', [$c6->getId()]) === 'captured', (string) $e?->getMessage());

// ── open work ──
$em    = $wire();
$check = new \Z77\Module\Receipt\Close\UnpostedReceiptsCheck();
$year  = ['from' => new \DateTimeImmutable('2026-01-01'), 'to' => new \DateTimeImmutable('2026-12-31')];
$close = iterator_to_array($check->check('period-close', $year), false);
$warn  = iterator_to_array($check->check('ledger-report', $year), false);
$vat   = iterator_to_array($check->check('vat-return', ['from' => new \DateTimeImmutable('2025-10-01'), 'to' => new \DateTimeImmutable('2025-12-31')]), false);
check('M37 the year close is BLOCKED by every captured receipt of the year (one finding each); the report warns with the total; the VAT return of Q4/2025 is blocked by the old receipt',
    count($close) === 3 && $close[0]->severity === \Z77\Persistence\Doctrine\OpenWork\Severity::Blocking && str_contains($close[0]->message, 'Beleg ' . $c4->getId())
    && count($warn) === 1 && $warn[0]->severity === \Z77\Persistence\Doctrine\OpenWork\Severity::Warning && str_contains($warn[0]->message, '3 Belege') && str_contains($warn[0]->message, '55.00')
    && count($vat) === 1 && str_contains($vat[0]->message, '1 Beleg'), json_encode(array_map(static fn($f) => $f->message, array_merge($close, $warn, $vat)), JSON_UNESCAPED_UNICODE));

// ── delete ──
$versionOf  = fn(int $id) => (int) $db->fetchOne('SELECT version FROM receipt WHERE id = ?', [$id]);
$docsBefore = count($docs());
$shared = (new ReceiptJournalService($wire(), 'Owner'))->delete((int) $c4b->getId(), $versionOf((int) $c4b->getId()));
check('M38 deleting one split part: the row is gone, the SHARED photo stays and is NOT offered for deletion (null) — the other part still names it (E12)',
    $shared === null && $db->fetchOne('SELECT COUNT(*) FROM receipt WHERE id = ?', [$c4b->getId()]) == 0 && count($docs()) === $docsBefore
    && (int) $db->fetchOne('SELECT document_id FROM receipt WHERE id = ?', [$c4->getId()]) === $c4->getDocumentId());
$docId = (new ReceiptJournalService($wire(), 'Owner'))->delete((int) $c4->getId(), $versionOf((int) $c4->getId()));
check('M39 deleting the last receipt of a photo: the photo STAYS in the DMS, its id is returned for «Foto auch löschen»',
    $docId === $c4->getDocumentId() && count($docs()) === $docsBefore && in_array($docId, array_map('intval', array_column($docs(), 'id')), true));
check('M40 a delete without an actor and without a session refuses to guess who', caught(fn() => (new ReceiptJournalService($wire()))->delete((int) $c6->getId(), 1), \LogicException::class) !== null
    && $db->fetchOne('SELECT COUNT(*) FROM receipt WHERE id = ?', [$c6->getId()]) == 1);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
