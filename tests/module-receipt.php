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
check('O7d the proposed cut-off is TODAY at midnight (owner decision E16 — replaces E11\'s «last day of the previous month»)',
    ReceiptJournalPostingService::defaultCutOff(new \DateTimeImmutable('2026-11-12 15:00'))->format('Y-m-d H:i') === '2026-11-12 00:00'
    && ReceiptJournalPostingService::defaultCutOff(new \DateTimeImmutable('2026-01-05'))->format('Y-m-d') === '2026-01-05'
    && ReceiptJournalPostingService::defaultCutOff()->format('Y-m-d') === (new \DateTimeImmutable('today'))->format('Y-m-d'));
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

// ── O. the API targets and the app screen (wave 2, part E) ──
$receiptConfig = require $root . '/packages/module-receipt/src/App/Config/receiptConfig.inc.php';
$targets = [
    'receipt-templates' => \Z77\Module\Receipt\Api\ReceiptTemplatesService::class,
    'payment-methods'   => \Z77\Module\Receipt\Api\PaymentMethodsService::class,
    'receipts'          => \Z77\Module\Receipt\Api\ReceiptsService::class,
];
check('O17 the config registers the three targets, offers the scope receipt.capture to app users — minimum role EDITOR in the backend, never for members (ADR-058) — and contributes the app screen',
    ($receiptConfig['apiServices'] ?? null) === $targets && isset($receiptConfig['apiDeviceScopes']['receipt.capture']['label'], $receiptConfig['apiDeviceScopes']['receipt.capture']['hint'])
    && ($receiptConfig['apiDeviceScopes']['receipt.capture']['realms'] ?? null) === ['backend' => \Z77\Core\Config\AuthRole::EDITOR]
    && ($receiptConfig['appScreens']['receipt']['scope'] ?? '') === 'receipt.capture' && ($receiptConfig['appScreens']['receipt']['template'] ?? '') === 'App/receipt'
    && is_file($root . '/packages/module-receipt/res/view/templates/App/receipt.tpl.php')
    && is_file($root . '/packages/module-receipt/res/assets/js/app/receipt.js') && is_file($root . '/packages/module-receipt/res/assets/js/app/receipt.min.js'));
$scopes = [];
foreach ($targets as $endpoint => $class) {
    $svc = new $class();
    $scopes[$endpoint] = [$svc->scopeFor('get', []), $svc->scopeFor('post', [])];
}
check('O18 each target declares receipt.capture for exactly its method: the lists GET, receipts POST (anything else 405)',
    $scopes === ['receipt-templates' => ['receipt.capture', null], 'payment-methods' => ['receipt.capture', null], 'receipts' => [null, 'receipt.capture']], json_encode($scopes));
$machine = new \Z77\Shared\Api\ApiRequest('zihlundsee', [], [], null, 'pilot', method: 'post', body: ['amount' => '1.00'],
    principal: new \Z77\Shared\Auth\ApiPrincipal('zihlundsee', 'pilot'), idempotencyKey: 'k-00000001');
$refusals = array_map(static fn(string $class) => (new $class())->handle($machine), $targets);
check('O19 a machine (bearer) caller is refused by every target with 403 before anything is read or written (the gateway\'s scope check lets it through, ADR-057)',
    array_map(static fn($r) => [$r->status, $r->errorCode], $refusals) === array_fill_keys(array_keys($targets), [403, 'forbidden']));
$apiDevice = \Z77\Shared\Auth\ApiPrincipal::device('7', 'iPhone Peter', '5', 'backend', '3', 'Peter Muster', ['receipt.capture']);
$sub = (new \Z77\Module\Receipt\Api\ReceiptsService())->handle(new \Z77\Shared\Api\ApiRequest('device', ['x'], method: 'post', principal: $apiDevice));
check('O20 a sub-path of a target is 404 unknown_endpoint', $sub->status === 404 && $sub->errorCode === 'unknown_endpoint');
$offered = defined(ReceiptTemplate::class . '::ICONS') ? array_keys(constant(ReceiptTemplate::class . '::ICONS')) : [];
check('O21 the phone draws every symbol the backend offers (ReceiptTemplate::ICONS ⊆ ReceiptIcons::GLYPHS); an unknown key draws the default',
    $offered !== [] && array_diff($offered, array_keys(\Z77\Module\Receipt\Services\ReceiptIcons::GLYPHS)) === []
    && \Z77\Module\Receipt\Services\ReceiptIcons::resolve('rocket') === \Z77\Module\Receipt\Services\ReceiptIcons::DEFAULT, json_encode($offered));

// ── S. the backend screens, offline (wave 2 D1: board «Belegvorlagen» / «Belegjournal», E10) ──

echo "S. Backend screens — navigation, mounts, fragments, templates, help (offline)\n";

$pkg      = $root . '/packages/module-receipt';
$tplDir   = $pkg . '/res/view/templates/Backend';
$tpl      = static fn(string $rel): string => (string) @file_get_contents($tplDir . '/' . $rel . '.tpl.php');
$uiSrc    = $sourceOf($pkg . '/src/Ui');
$seed     = json_decode((string) file_get_contents($pkg . '/data/framework/routing/navigation.d/module-receipt.json'), true);
$seedKeys = array_column($seed, null, 'key');
check('S1 the seed ships three leaves: «Belegjournal» under Finanzen, «Belegvorlagen» and «Zahlungsarten» under Stammdaten › Finanzen — backend/finance routes to the three mounts',
    array_keys($seedKeys) === ['belegjournal', 'belegvorlagen', 'zahlungsarten']
    && $seedKeys['belegjournal']['parent_key'] === 'finanzen' && $seedKeys['belegjournal']['controller'] === 'receipt-journal'
    && $seedKeys['belegvorlagen']['parent_key'] === 'stammdaten-finanzen' && $seedKeys['belegvorlagen']['controller'] === 'receipt-template'
    && $seedKeys['zahlungsarten']['parent_key'] === 'stammdaten-finanzen' && $seedKeys['zahlungsarten']['controller'] === 'payment-method'
    && array_unique(array_column($seed, 'group')) === ['finance'] && array_unique(array_column($seed, 'module')) === ['backend'] && array_unique(array_column($seed, 'action')) === ['list']);

// A fresh install merges every seed: «Belegjournal» right after «Journal», the master data after «MWST-Codes».
$navRoots  = array_map(static fn(string $p) => $root . '/packages/' . $p . '/data', ['kernel/core', 'module-financial', 'module-vat', 'module-mandator', 'module-receipt']);
$navMerged = \Z77\Shared\Import\NavigationSeeds::merge(\Z77\Shared\Import\NavigationSeeds::read(\Z77\Shared\Import\NavigationSeeds::discover($navRoots)), [])['records'];
$navByKey  = array_column(array_filter($navMerged, static fn($r) => $r['key'] !== null), null, 'key');
$navKids   = static function (string $parent) use ($navMerged, $navByKey): array {
    $kids = array_values(array_filter($navMerged, static fn($r) => $r['parent_id'] === ($navByKey[$parent]['id'] ?? -1)));
    usort($kids, static fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_column($kids, 'name');
};
check('S2 fresh install: Finanzen = Journal, Belegjournal, Auswertungen, …; Stammdaten › Finanzen ends with MWST-Codes, Belegvorlagen, Zahlungsarten',
    array_slice($navKids('finanzen'), 0, 3) === ['Journal', 'Belegjournal', 'Auswertungen']
    && array_slice($navKids('stammdaten-finanzen'), -3) === ['MWST-Codes', 'Belegvorlagen', 'Zahlungsarten'], json_encode([$navKids('finanzen'), $navKids('stammdaten-finanzen')], JSON_UNESCAPED_UNICODE));

$hosts = [
    'ReceiptJournal'  => [\Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait::class,  'journal',        'Backend/ReceiptJournalController',  'receipt-journal'],
    'ReceiptTemplate' => [\Z77\Module\Receipt\Ui\ReceiptTemplateControllerTrait::class, 'templates',      'Backend/ReceiptTemplateController', 'receipt-template'],
    'PaymentMethod'   => [\Z77\Module\Receipt\Ui\PaymentMethodControllerTrait::class,   'paymentMethods', 'Backend/PaymentMethodController',   'payment-method'],
];
$hostOk = true;
foreach ($hosts as $name => [$trait, $layout, $path, $url]) {
    $host   = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Finance/' . $name . 'Controller.php');
    $config = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Config/Finance/' . lcfirst($name) . 'ControllerConfig.inc.php');
    $pinned = \Z77\Module\Receipt\Ui\ReceiptLayout::$layout()['levelElements']['body']['main'][0] ?? [];
    $hostOk = $hostOk && str_contains($host, 'use ' . substr($trait, strrpos($trait, '\\') + 1) . ';') && str_contains($host, 'extends BackendAbstractController')
        && str_contains($config, 'ReceiptLayout::' . $layout . '()')
        && $pinned === ['nameSpace' => 'Z77\Module\Receipt', 'path' => $path, 'name' => 'listAction'];
}
check('S3 three thin hosts in module-backend (ADR-018): Finance/{ReceiptJournal,ReceiptTemplate,PaymentMethod}Controller use the traits; their layout configs pin the fragment\'s listAction in module-receipt', $hostOk);
check('S4 the mount URLs live once, in ReceiptLayout, and match the seed routes',
    \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE === '/backend/finance/receipt-journal' && \Z77\Module\Receipt\Ui\ReceiptLayout::TEMPLATE_BASE === '/backend/finance/receipt-template'
    && \Z77\Module\Receipt\Ui\ReceiptLayout::PAYMENT_METHOD_BASE === '/backend/finance/payment-method'
    && substr_count($uiSrc, "'/backend/finance/") === 3);

$methods = static fn(string $trait): array => array_map(static fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($trait))->getMethods());
$csrfOn  = static fn(string $trait, string $method): bool => (new \ReflectionMethod($trait, $method))->getAttributes(\Z77\Shared\Attributes\Csrf::class) !== [];
$jm      = $methods(\Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait::class);
check('S5 the journal fragment: list, edit, split, confirm-delete, the two fill helpers, confirm-post, post — every write #[Csrf]; no other write path',
    array_values(array_intersect(['listAction', 'editAction', 'splitAction', 'confirmDeleteAction', 'templateFillAction', 'paymentFillAction', 'confirmPostAction', 'postAction'], $jm)) === ['listAction', 'editAction', 'splitAction', 'confirmDeleteAction', 'templateFillAction', 'paymentFillAction', 'confirmPostAction', 'postAction']
    && $csrfOn(\Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait::class, 'editAction') && $csrfOn(\Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait::class, 'splitAction')
    && $csrfOn(\Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait::class, 'confirmDeleteAction') && $csrfOn(\Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait::class, 'postAction')
    && count(array_filter($jm, static fn($m) => str_ends_with($m, 'Action'))) === 8);
$masterOk = true;
foreach ([\Z77\Module\Receipt\Ui\ReceiptTemplateControllerTrait::class, \Z77\Module\Receipt\Ui\PaymentMethodControllerTrait::class] as $trait) {
    $mm = $methods($trait);
    $toggle = (new \ReflectionMethod($trait, 'toggleActiveAction'));
    $masterOk = $masterOk && array_values(array_filter($mm, static fn($m) => str_ends_with($m, 'Action'))) === ['listAction', 'addAction', 'editAction', 'toggleActiveAction']
        && $csrfOn($trait, 'addAction') && $csrfOn($trait, 'editAction')
        && $toggle->getAttributes(\Z77\Shared\Attributes\Fetch::class) !== [] && $toggle->getAttributes(\Z77\Shared\Attributes\HttpMethod::class) !== [];
}
check('S6 the two master-data fragments: list, add, edit and the «Aktiv» switch (Fetch, POST) — NO delete (receipts keep the code, ADR-043 decision 19)', $masterOk);
check('S7 Rule 4 / Rule 3: no superglobal and no hand-made response in the screens; the photo address comes from module-dms (MediaField), never built here',
    !preg_match('/\$_(GET|POST|SERVER|REQUEST|FILES|COOKIE)\b/', $uiSrc) && !preg_match('/new\s+\\\\?(Z77\\\\Core\\\\Http\\\\Response\\\\)?(Html|Redirect|Fetch|Json|Bytes)Response\s*\(/', $uiSrc)
    && str_contains($uiSrc, 'MediaField::previewUrl(') && str_contains($uiSrc, 'MediaField::thumbUrl(')
    && !str_contains($uiSrc . implode('', array_map('file_get_contents', glob($tplDir . '/*/*.tpl.php'))), '/document/preview'));
$dmsField = (string) file_get_contents($root . '/packages/module-dms/src/Ui/MediaField.php');
check('S8 module-dms builds the gated preview address in ONE place: MediaField::previewUrl() (thumbUrl() goes through it)',
    substr_count($dmsField, "'/document/preview?id='") === 1 && str_contains($dmsField, "self::previewUrl(\$doc, \$base, 's')"));

$backendTemplates = glob($tplDir . '/*/*.tpl.php');
check('S9 the templates: the journal nine (list, action cell, toolbar, list help, window, window help, split, delete, confirmation), the two master-data lists six each (list, row, action cell, window, two helps)',
    count(glob($tplDir . '/ReceiptJournalController/*.tpl.php')) === 9 && count(glob($tplDir . '/ReceiptTemplateController/*.tpl.php')) === 6
    && count(glob($tplDir . '/PaymentMethodController/*.tpl.php')) === 6, (string) count($backendTemplates));
$windows = ['ReceiptJournalController/edit', 'ReceiptJournalController/split', 'ReceiptJournalController/confirmDelete', 'ReceiptTemplateController/edit', 'PaymentMethodController/edit'];
$winOk   = true;
foreach ($windows as $w) {
    $src   = $tpl($w);
    $winOk = $winOk && str_contains($src, 'data-window="') && str_contains($src, "partial('partials/modalActions'") && !str_contains($src, 'be-modal__footer')
        && preg_match('/<div class="be-(form__grid|form__field|docview)/', $src, $fm, PREG_OFFSET_CAPTURE) === 1 && strpos($src, "partial('partials/modalActions'") < $fm[0][1] && str_contains($src, 'name="csrf_token"');
}
check('S10 every window declares its mask, carries the session token and opens with the shared action row (ADR-049) — before any field, no footer', $winOk);
check('S11 the receipt window: «Aufteilen …» (ghost) and «Löschen …» (danger) load into the window; the version travels; the photo beside the fields (.be-docview); template and «Bezahlt mit» fill through core.js «fill» (no script, Rule 7)',
    str_contains($tpl('ReceiptJournalController/edit'), "/split?id=' . \$id) . '\" data-window-link") && str_contains($tpl('ReceiptJournalController/edit'), 'be-btn be-btn--danger" href="\' . e($actionBase . \'/confirm-delete?id=')
    && str_contains($tpl('ReceiptJournalController/edit'), 'name="version"') && str_contains($tpl('ReceiptJournalController/edit'), 'class="be-docview"')
    && str_contains($tpl('ReceiptJournalController/edit'), "data-fill-url=\"<?= e(\$actionBase . '/template-fill') ?>\"") && str_contains($tpl('ReceiptJournalController/edit'), "data-fill-url=\"<?= e(\$actionBase . '/payment-fill') ?>\"")
    && str_contains($tpl('ReceiptJournalController/edit'), 'data-fill-key="debit"') && str_contains($tpl('ReceiptJournalController/edit'), 'data-fill-key="credit"') && str_contains($tpl('ReceiptJournalController/edit'), 'data-fill-key="tax_code"')
    && !preg_match('/<script\b/i', implode('', array_map('file_get_contents', $backendTemplates))));
check('S12 the list: a fetch region; the state icon opens the window from that region; the cells label their magnifiers; «vollständig» / «Konten fehlen»; the totals per VAT code and the row «fehlt»',
    str_contains($tpl('ReceiptJournalController/listAction'), 'data-fetch-region="<?= e($region) ?>"') && str_contains($tpl('ReceiptJournalController/listAction'), 'data-origin="region:<?= e($region) ?>"')
    && substr_count($tpl('ReceiptJournalController/listAction'), "for=\"<?= e(\$definition->inputId('f_") === 4
    && str_contains($tpl('ReceiptJournalController/listAction'), '>vollständig<') && str_contains($tpl('ReceiptJournalController/listAction'), "'Konten fehlen'")
    && str_contains($tpl('ReceiptJournalController/listAction'), 'data-journal-totals') && str_contains($tpl('ReceiptJournalController/listAction'), 'data-journal-missing'));
check('S13 the action cell: «Journal verbuchen …» green (confirm), opening the posting window, disabled with its reason while empty; the toolbar two tabs; the confirmation is the shared window (partials/postConfirm, E16) with the cut-off as its filter and the {id}:{version} pairs',
    str_contains($tpl('ReceiptJournalController/act'), 'be-btn be-btn--confirm') && str_contains($tpl('ReceiptJournalController/act'), "'/confirm-post'") && str_contains($tpl('ReceiptJournalController/act'), 'data-window-open="<?= e($url) ?>"')
    && str_contains($tpl('ReceiptJournalController/act'), 'disabled title="Das Belegjournal ist leer')
    && str_contains($tpl('ReceiptJournalController/toolbar'), "'Im Journal'") && str_contains($tpl('ReceiptJournalController/toolbar'), "'Verbuchte Journale'")
    && str_contains($tpl('ReceiptJournalController/confirmPost'), "\$this->partial('partials/postConfirm'") && str_contains($tpl('ReceiptJournalController/confirmPost'), "'cutoff' => \$cutOff->format('Y-m-d'), 'rec' =>")
    && str_contains($tpl('ReceiptJournalController/confirmPost'), "'action'  => \$actionBase . '/confirm-post'"));

// Help (ADR-048): a section for every field a window or list names — F1 lands where the user is.
$helpKeys = static function (string $src): array {
    preg_match_all('/<section data-help-field="([a-z_]+)"/', $src, $m);
    return $m[1];
};
$fieldKeys = static function (string $src): array {
    preg_match_all('/\sname="([a-z_]+)(?:\[\])?"/', $src, $n);
    preg_match_all('/data-help-key="([a-z_]+)"/', $src, $k);
    return array_values(array_diff(array_unique(array_merge($n[1], $k[1])), ['csrf_token', '_origin', 'version', 'entity_csrf', 'rec', 'cutoff']));
};
$helpOk = [];
foreach ([
    'ReceiptJournalController/edit.help'  => ['ReceiptJournalController/edit', 'ReceiptJournalController/split', 'ReceiptJournalController/confirmDelete'],
    'ReceiptTemplateController/edit.help' => ['ReceiptTemplateController/edit'],
    'PaymentMethodController/edit.help'   => ['PaymentMethodController/edit'],
] as $help => $forms) {
    $need = array_merge(...array_map(static fn($f) => $fieldKeys($tpl($f)), $forms));
    $missingHelp = array_diff($need, $helpKeys($tpl($help)));
    if ($missingHelp !== [] || !str_contains($tpl($help), '<h3>')) {
        $helpOk[$help] = array_values($missingHelp);
    }
}
$listHelp = $tpl('ReceiptJournalController/list.help');
check('S14 every window field has its help section (receipt: date, amount, text, template, payment_method, debit, credit, tax_code, split, delete_photo; template: label, code, icon, position, debit_account, tax_code; payment method: label, code, credit_account, position) behind a general heading',
    $helpOk === [] && count($helpKeys($tpl('ReceiptJournalController/edit.help'))) === 10, json_encode($helpOk));
check('S15 the list helps: the journal one a section per magnifier and one for the cut-off; the two master-data lists a short help each; every list attaches its help in the toolbar (HELP-003)',
    $helpKeys($listHelp) === ['f_date', 'f_text', 'f_amount', 'f_by', 'cutoff'] && str_contains($tpl('ReceiptTemplateController/list.help'), '<h3>Belegvorlagen</h3>')
    && str_contains($tpl('PaymentMethodController/list.help'), '<h3>Zahlungsarten</h3>') && substr_count($uiSrc, "toolbar: true)") === 3);
check('S16 the symbol set (ReceiptTemplate::ICONS) is what the window offers, every key a valid icon key',
    array_reduce(array_keys(ReceiptTemplate::ICONS), static function ($ok, $key) {
        $v = new ReceiptTemplateValidator(new ReceiptTemplate(['code' => 'ok', 'label' => 'Ok', 'icon' => $key, 'debit_account' => '6200']));
        $v->isValid();
        return $ok && !$v->hasFieldError('icon');
    }, true) && str_contains($tpl('ReceiptTemplateController/edit'), 'ReceiptTemplate::ICONS as $key => $name'));
$css = (string) file_get_contents($root . '/packages/module-backend/res/assets/css/base.css');
check('S17 the backend stylesheet carries the shared blocks the screens use: .be-docview (document beside its record), .be-list__thumb, .be-form__missing, .be-form__field--full (built from the SCSS)',
    str_contains($css, '.be-docview{') && str_contains($css, '.be-list__thumb{') && str_contains($css, '.be-form__missing{') && str_contains($css, '.be-form__field--full{'));

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

// ── SM. the backend screens through host doubles (wave 2 D1) ─────────────
// The traits run as the backend mounts them: a host double provides html() / fetch() / redirect() /
// em() and the layout, message and help objects; DI gets a Request and a CSRF double. The DMS photo
// (a session-gated read) and its delete are the two host seams a harness host overrides. The
// templates render through the REAL TemplateRenderer (FileFinder → this package and kernel/shared).

echo "SM. Backend screens — list, window, split, delete, fill, «Journal verbuchen», master data (database)\n";
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

$useRequest = function (array $get, ?array $post = null, bool $fetch = false, array $json = []) use ($wire): \Z77\Persistence\Resolver\UnifiedEntityManager {
    $em = $wire();
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $fetch, $json) {
        public function __construct(private array $get, private ?array $post, private bool $fetch, private array $json) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function isPost(): bool { return $this->post !== null; }
        public function getPostParameters(): array { return $this->post ?? []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
        public function getJsonBody(): array { return $this->json; }
    }, true);
    \Z77\Core\DI::getInstance()->set('CsrfService', fn() => new class {
        public function getToken(): string { return 'tok-session'; }
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
    return $em;
};
/** The members every host double shares (the BackendAbstractController surface the traits use). */
trait ScreenHostDouble
{
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
    protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
    protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
    protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
}
$journalHost = fn() => new class {
    use ScreenHostDouble;
    use \Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait { listAction as public; editAction as public; splitAction as public; confirmDeleteAction as public; templateFillAction as public; paymentFillAction as public; confirmPostAction as public; postAction as public; }
    public array $deletedPhotos = [];
    private function receiptJournal(): ReceiptJournalService { return new ReceiptJournalService($this->em(), 'Owner'); }
    private function receiptPosting(): ReceiptJournalPostingService { return new ReceiptJournalPostingService($this->em(), 'Owner'); }
    private function receiptPhotos(array $documentIds): array
    {
        $out = [];
        foreach ($documentIds as $id) {
            $out[$id] = ['state' => 'doc', 'id' => $id, 'name' => 'beleg-' . $id . '.jpg', 'mime' => 'image/jpeg', 'size' => '1 KB', 'isImage' => true,
                'previewUrl' => '/backend/documents/document/preview?id=' . $id, 'thumbUrl' => '/backend/documents/document/preview?id=' . $id . '&variant=s', 'canDelete' => true];
        }
        return $out;
    }
    private function receiptDeletePhoto(int $documentId): void { $this->deletedPhotos[] = $documentId; }
};
$templateHost = fn() => new class {
    use ScreenHostDouble;
    use \Z77\Module\Receipt\Ui\ReceiptTemplateControllerTrait { listAction as public; addAction as public; editAction as public; toggleActiveAction as public; }
};
$methodHost = fn() => new class {
    use ScreenHostDouble;
    use \Z77\Module\Receipt\Ui\PaymentMethodControllerTrait { listAction as public; addAction as public; editAction as public; toggleActiveAction as public; }
};
$render   = static fn(string $path, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Receipt'))->partial($path, $context);
$envelope = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => (fn() => $this->build())->call($r);
$commands = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => array_column($envelope($r)['commands'] ?? [], 'action');
$c6 = (new ReceiptJournalService($wire()))->find((int) $c6->getId());

// ── the list ──
$useRequest([]);
$host = $journalHost();
$host->listAction();
$listHtml = $render('Backend/ReceiptJournalController/listAction', $host->context);
check('SM1 the list (page): the two captured receipts by date, the band RED — the old receipt has no fiscal year —, the help in the toolbar, action cell and toolbar added',
    array_map(static fn($r) => $r->getId(), $host->context['receipts']) === [$c5->getId(), $c6->getId()] && $host->context['totals']['count'] === 2
    && count($host->context['blockers']) === 1 && $host->context['blockers'][0]['id'] === $c5->getId() && str_contains($host->context['blockers'][0]['reason'], 'kein Geschäftsjahr')
    && $host->help->has() && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/ReceiptJournalController/act'] && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/ReceiptJournalController/toolbar'],
    json_encode([$host->context['blockers'] ?? null, $host->layoutManager->sections]));
check('SM2 … rendered: a fetch region, a row per receipt opening its window, the photo thumbnail through the DMS address, «vollständig», Haben and «Bezahlt mit» (E10), who captured it (E8), the blocked band with the link, the VAT row VI and the row «fehlt»',
    str_contains($listHtml, 'data-fetch-region="receipt-journal-find-list"') && str_contains($listHtml, 'data-window-open="/backend/finance/receipt-journal/edit?id=' . $c6->getId() . '"')
    && str_contains($listHtml, 'src="/backend/documents/document/preview?id=' . $c6->getDocumentId() . '&amp;variant=s"') && substr_count($listHtml, '>vollständig<') === 2
    && str_contains($listHtml, '>1000<') && str_contains($listHtml, '>Kasse<') && str_contains($listHtml, '>Peter</label>') && str_contains($listHtml, 'data-journal-blocked="1"')
    && preg_match('/data-journal-totals.*?>VI<.*?data-journal-missing/s', $listHtml) === 1, mb_substr(strip_tags($listHtml), 0, 600));
$actHtml = $render('Backend/ReceiptJournalController/act', $host->context);
check('SM3 the action cell: «Journal verbuchen …» a green link that opens the posting WINDOW (E16), the list region as its origin (the journal is not empty)',
    str_contains($actHtml, 'be-btn--confirm" href="/backend/finance/receipt-journal/confirm-post" data-window-open="/backend/finance/receipt-journal/confirm-post" data-origin="region:receipt-journal-find-list"'), $actHtml);
$useRequest(['f_text' => 'benz']);
$host = $journalHost();
$host->listAction();
$useRequest(['view' => 'posted']);
$posted = $journalHost();
$posted->listAction();
$postedHtml = $render('Backend/ReceiptJournalController/listAction', $posted->context);
check('SM4 the magnifier searches the database (text «benz» → the one receipt); the tab «Verbuchte Journale» lists the protocol of M30 (3 receipts, 1\'120.50)',
    array_map(static fn($r) => $r->getId(), $host->context['receipts']) === [$c6->getId()] && count($posted->context['protocols']) === 1
    && str_contains($postedHtml, 'data-journal-posting="' . $protocol->getId() . '"') && str_contains($postedHtml, e("1'120.50")));

// ── the receipt window ──
$useRequest(['id' => (string) $c6->getId(), '_origin' => 'region:receipt-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$editHtml = $render('Backend/ReceiptJournalController/edit', $host->context);
check('SM5 the window (fetched): mask receipt-edit for receipt:<id>, the photo LARGE through the DMS preview, the values (VI selected, Soll 6200 «…», Haben 1000), the template and «Bezahlt mit» as fill helpers, the version; its help attached',
    str_contains($editHtml, 'data-window="receipt-edit" data-window-entity="receipt:' . $c6->getId() . '"') && str_contains($editHtml, '<img src="/backend/documents/document/preview?id=' . $c6->getDocumentId() . '" alt="Foto des Belegs">')
    && str_contains($editHtml, '<option value="VI" selected>') && str_contains($editHtml, 'value="6200"') && str_contains($editHtml, 'value="1000"')
    && str_contains($editHtml, '<option value="tanken" selected>Tanken') && str_contains($editHtml, '<option value="kasse" selected>Kasse') && str_contains($editHtml, 'name="version" value="' . $c6->getVersion() . '"')
    && str_contains($editHtml, '<datalist id="receipt-accounts">') && !str_contains($editHtml, '— fehlt') && $host->help->has());
$editPost = fn(array $post) => $useRequest(['id' => (string) $c6->getId()], $post + ['_origin' => 'region:receipt-journal-find-list'], true);
$editPost(['version' => '99', 'date' => '2026-11-06', 'amount' => '25.00', 'text' => 'Benzin', 'debit' => '6200', 'credit' => '1000', 'tax_code' => 'VI']);
$host = $journalHost();
$stale = $host->editAction();
check('SM6 a stale version: refused as a fetch error with the German sentence, nothing written', $stale instanceof \Z77\Core\Http\Response\FetchResponse && $envelope($stale)['status'] === 'error'
    && str_contains($host->messageService->flashes[0][1] ?? '', 'inzwischen geändert') && $versionOf((int) $c6->getId()) === $c6->getVersion());
$editPost(['version' => (string) $c6->getVersion(), 'date' => '2026-11-06', 'amount' => 'abc', 'text' => 'Benzin', 'debit' => '1', 'credit' => '1000', 'tax_code' => 'VI']);
$host = $journalHost();
$host->editAction();
$errHtml = $render('Backend/ReceiptJournalController/edit', $host->context);
check('SM7 bad values: the window again with the field messages (amount, debit — a group), what was typed kept, the «n Fehler» link; nothing written',
    isset($host->context['errors']['amount'], $host->context['errors']['debit']) && str_contains($errHtml, 'value="abc"') && str_contains($errHtml, 'aria-invalid="true"')
    && str_contains($errHtml, 'data-z77-field-error') && $versionOf((int) $c6->getId()) === $c6->getVersion(), json_encode($host->context['errors'] ?? null, JSON_UNESCAPED_UNICODE));
$editPost(['version' => (string) $c6->getVersion(), 'date' => '2026-11-06', 'amount' => '25.00', 'text' => 'Benzin Tankstelle', 'debit' => '6200', 'credit' => '1020', 'tax_code' => '-']);
$host  = $journalHost();
$saved = $host->editAction();
$row6  = $db->fetchAssociative('SELECT text, credit_account, tax_code, without_vat, changed_by, version, payment_method_code FROM receipt WHERE id = ?', [$c6->getId()]);
check('SM8 saved: the correction is written (Haben 1020, «ohne MWST» from the select\'s «-»), who changed it; «Bezahlt mit» stays the phone\'s; the answer closes the window and reloads the list region',
    $saved instanceof \Z77\Core\Http\Response\FetchResponse && $commands($saved) === ['close-window', 'refresh-region'] && ($envelope($saved)['commands'][1]['name'] ?? '') === 'receipt-journal-find-list'
    && $row6['text'] === 'Benzin Tankstelle' && $row6['credit_account'] === '1020' && $row6['tax_code'] === null && (int) $row6['without_vat'] === 1 && $row6['changed_by'] === 'Owner'
    && $row6['payment_method_code'] === 'kasse' && (int) $row6['version'] === $c6->getVersion() + 1 && str_contains($host->messageService->flashes[0][1] ?? '', 'vollständig'), json_encode([$row6, $host->messageService->flashes]));

// ── the fill helpers ──
$fill = function (string $action, string $value) use ($useRequest, $journalHost, $envelope): array {
    $useRequest(['value' => $value], null, true);
    return $envelope($journalHost()->$action())['data']['fill'] ?? ['none'];
};
check('SM9 «Vorlage» fills Soll and the VAT decision (a code, «-» for a template without VAT); «Bezahlt mit» fills Haben; an unknown code fills nothing',
    $fill('templateFillAction', 'tanken') === ['debit' => '6200', 'tax_code' => 'VI'] && $fill('templateFillAction', 'parken') === ['debit' => '6200', 'tax_code' => '-']
    && $fill('paymentFillAction', 'karte') === ['credit' => '1020'] && $fill('templateFillAction', 'nope') === [] && $fill('paymentFillAction', '') === []);

// ── split ──
$v6 = $versionOf((int) $c6->getId());
$useRequest(['id' => (string) $c6->getId()], ['version' => (string) $v6, 'amount' => '25.00', '_origin' => 'region:receipt-journal-find-list'], true);
$host = $journalHost();
$host->splitAction();
$splitHtml = $render('Backend/ReceiptJournalController/split', $host->context);
check('SM10 «Aufteilen …»: a part not smaller than the receipt is refused under the field, in the split window', isset($host->context['errors']['amount'])
    && str_contains($splitHtml, 'data-window="receipt-split"') && str_contains($splitHtml, 'aria-invalid="true"') && $versionOf((int) $c6->getId()) === $v6);
$useRequest(['id' => (string) $c6->getId()], ['version' => (string) $v6, 'amount' => '5.00', '_origin' => 'region:receipt-journal-find-list'], true);
$host  = $journalHost();
$split = $host->splitAction();
$parts = $db->fetchAllAssociative('SELECT id, amount, document_id FROM receipt WHERE document_id = ? ORDER BY id', [$c6->getDocumentId()]);
check('SM11 split 25.00 → 20.00 + 5.00 sharing the photo; the window closes, the list reloads, the flash names the new part',
    count($parts) === 2 && $parts[0]['amount'] === '20.00' && $parts[1]['amount'] === '5.00' && $parts[0]['document_id'] === $parts[1]['document_id']
    && $commands($split) === ['close-window', 'refresh-region'] && str_contains($host->messageService->flashes[0][1] ?? '', 'Beleg ' . $parts[1]['id']), json_encode([$parts, $host->messageService->flashes], JSON_UNESCAPED_UNICODE));
$partId = (int) $parts[1]['id'];

// ── delete ──
$useRequest(['id' => (string) $partId], null, true);
$host = $journalHost();
$host->confirmDeleteAction();
$delHtml = $render('Backend/ReceiptJournalController/confirmDelete', $host->context);
check('SM12 «Löschen …» of a split part: the photo is SHARED — no «Foto auch löschen», the reason said; the delete in the danger look',
    $host->context['offerPhoto'] === false && $host->context['sharedPhoto'] === true && !str_contains($delHtml, 'name="delete_photo"') && str_contains($delHtml, 'aufgeteilter Teil')
    && str_contains($delHtml, 'be-btn be-btn--danger'));
$useRequest(['id' => (string) $partId], ['version' => (string) $versionOf($partId), 'delete_photo' => '1', '_origin' => 'region:receipt-journal-find-list'], true);
$host = $journalHost();
$del  = $host->confirmDeleteAction();
check('SM13 deleted with the switch on anyway: the part is gone, the shared photo is NOT deleted (delete() answered null)',
    $commands($del) === ['close-window', 'refresh-region'] && $db->fetchOne('SELECT COUNT(*) FROM receipt WHERE id = ?', [$partId]) == 0 && $host->deletedPhotos === []);
$useRequest(['id' => (string) $c6->getId()], null, true);
$host = $journalHost();
$host->confirmDeleteAction();
check('SM14 the last receipt of its photo: «Foto auch löschen» offered, off by default', $host->context['offerPhoto'] === true
    && str_contains($render('Backend/ReceiptJournalController/confirmDelete', $host->context), 'name="delete_photo" value="1" data-help-key="delete_photo">'));

// ── «Journal verbuchen» ──
// The posting WINDOW (owner decision E16): the shared `partials/postConfirm`, errors only or a short summary.
$useRequest(['_origin' => 'region:receipt-journal-find-list'], null, true);
$host = $journalHost();
$host->confirmPostAction();
$cpHtml = $render('Backend/ReceiptJournalController/confirmPost', $host->context);
check('SM15 the window (fetched, no date asked): the cut-off proposed is TODAY; the window markup (mask receipt-journal-post, entity, title, the origin carried back); the date a GET form that re-renders the window (data-window-form="change"), «Schliessen» closes it; the help attached',
    $host->context['cutOff']->format('Y-m-d') === (new \DateTimeImmutable('today'))->format('Y-m-d') && $host->context['window'] === true
    && str_contains($cpHtml, 'data-window="receipt-journal-post" data-window-entity="receipt-journal:open" data-window-title="Belegjournal verbuchen"')
    && str_contains($cpHtml, 'name="_origin" value="region:receipt-journal-find-list"') && str_contains($cpHtml, 'action="/backend/finance/receipt-journal/confirm-post" class="be-list__section" data-window-form="change"')
    && str_contains($cpHtml, 'id="receipt-cutoff" name="cutoff" value="' . (new \DateTimeImmutable('today'))->format('Y-m-d') . '"') && str_contains($cpHtml, 'data-popup-close>Schliessen</button>')
    && $host->help->has() && ($host->layoutManager->sections['main'] ?? []) === ['Backend/ReceiptJournalController/confirmPost'], mb_substr($cpHtml, 0, 900));
check('SM16 … with a blocked receipt up to the date (the 2025 one, no fiscal year): ONLY the errors — the receipt, what is wrong, a link that opens its window —, no summary, no «Jetzt buchen», no pairs in the form',
    !$host->context['preview']['postable'] && str_contains($cpHtml, 'data-post-confirm="errors"') && str_contains($cpHtml, 'data-post-confirm-errors="1"')
    && str_contains($cpHtml, 'data-window-open="/backend/finance/receipt-journal/edit?id=' . $c5->getId() . '">30.12.2025 · Altjahr</a> — ') && str_contains($cpHtml, 'kein Geschäftsjahr')
    && !str_contains($cpHtml, 'Jetzt buchen') && !str_contains($cpHtml, 'type="submit" class="be-btn be-btn--confirm"') && !str_contains($cpHtml, 'name="rec[]"')
    && !str_contains($cpHtml, 'data-post-confirm-facts') && !str_contains($cpHtml, 'data-post-confirm-vat'), strip_tags($cpHtml));
$useRequest(['cutoff' => 'gestern']);
$host = $journalHost();
$host->confirmPostAction();
check('SM17 an unreadable cut-off: today with a note; as a page (no script) the same content with its heading and «Zum Journal»',
    $host->context['cutOff'] == ReceiptJournalPostingService::defaultCutOff() && str_contains($host->context['note'], 'kein Datum') && $host->context['window'] === false
    && str_contains($page = $render('Backend/ReceiptJournalController/confirmPost', $host->context), '<h2 class="be-list__section-title">Belegjournal verbuchen</h2>') && !str_contains($page, 'data-window=')
    && str_contains($page, 'href="/backend/finance/receipt-journal/list" data-window-link>Zum Journal</a>'));
// A receipt fixed out of the window (its window opened from it, origin window:<id>): the posting window follows.
$c5r = (new ReceiptJournalService($wire()))->find((int) $c5->getId());
$useRequest(['id' => (string) $c5->getId()], ['version' => (string) $c5r->getVersion(), 'date' => '2025-12-30', 'amount' => $c5r->getAmount()->toDecimal(), 'text' => 'Altjahr',
    'debit' => (string) $c5r->getDebitAccount(), 'credit' => (string) $c5r->getCreditAccount(), 'tax_code' => (string) $c5r->getTaxCode(), '_origin' => 'window:3'], true);
$fixed = $journalHost()->editAction();
check('SM17b a receipt saved from a window opened out of «Journal verbuchen» closes and reloads THAT window (refresh-window), not a list',
    $fixed instanceof \Z77\Core\Http\Response\FetchResponse && $commands($fixed) === ['close-window', 'refresh-window'] && ($envelope($fixed)['commands'][1]['window'] ?? '') === '3',
    json_encode($fixed instanceof \Z77\Core\Http\Response\FetchResponse ? $envelope($fixed) : null));
// Delete the old receipt, then post the November run through the window.
(new ReceiptJournalService($wire(), 'Owner'))->delete((int) $c5->getId(), $versionOf((int) $c5->getId()));
$useRequest(['cutoff' => '2026-11-30', '_origin' => 'region:receipt-journal-find-list'], null, true);
$host = $journalHost();
$host->confirmPostAction();
$cpHtml = $render('Backend/ReceiptJournalController/confirmPost', $host->context);
$pairs  = array_map(static fn($p) => $p['id'] . ':' . $p['version'], $host->context['preview']['seen']);
check('SM18 … now postable: the SHORT summary (count, total, VAT per code with «ohne MWST»), the green «Jetzt buchen»; the POST carries the cut-off and every receipt\'s {id}:{version}; no entries per account',
    $host->context['preview']['postable'] && str_contains($cpHtml, 'data-post-confirm="summary"') && str_contains($cpHtml, '<button type="submit" class="be-btn be-btn--confirm">Jetzt buchen</button>')
    && str_contains($cpHtml, 'data-post-confirm-facts') && str_contains($cpHtml, 'Belege bis 30.11.2026') && str_contains($cpHtml, 'data-post-confirm-vat') && str_contains($cpHtml, 'ohne MWST')
    && str_contains($cpHtml, 'action="/backend/finance/receipt-journal/post"') && str_contains($cpHtml, 'name="cutoff" value="2026-11-30"') && str_contains($cpHtml, 'name="rec[]" value="' . $pairs[0] . '"')
    && !str_contains($cpHtml, 'data-post-confirm-errors') && !str_contains($cpHtml, 'data-journal-preview') && !str_contains($cpHtml, 'data-post-confirm-note')
    && $pairs === [$c6->getId() . ':' . $versionOf((int) $c6->getId())], json_encode($pairs));
check('SM18b the later receipts that stay are ONE muted line of the summary; nothing up to the date → the sentence, no button',
    str_contains($render('Backend/ReceiptJournalController/confirmPost', ['later' => 2] + $host->context), '<p class="be-form__hint be-list__cell--muted" data-post-confirm-note>2 spätere Belege bleiben im Journal.</p>')
    && str_contains($empty = $render('Backend/ReceiptJournalController/confirmPost', ['preview' => ['seen' => [], 'postable' => false] + $host->context['preview']] + $host->context), 'data-post-confirm="empty"')
    && str_contains($empty, 'kein Beleg im Belegjournal') && !str_contains($empty, 'Jetzt buchen'));
$useRequest([], ['cutoff' => '2026-11-30', 'rec' => [$c6->getId() . ':1']]);
$host = $journalHost();
$host->postAction();
$useRequest([], ['cutoff' => '2026-11-30', 'rec' => [$c6->getId() . ':1'], '_origin' => 'region:receipt-journal-find-list'], true);
$winHost = $journalHost();
$refused = $winHost->postAction();
check('SM19 a changed run (a stale pair): nothing posted — as a page back to the confirmation of THAT cut-off; in the window the German refusal and the window re-rendered for that cut-off (open-window replace)',
    $host->redirectedTo === '/backend/finance/receipt-journal/confirm-post?cutoff=2026-11-30' && ($host->messageService->flashes[0][0] ?? '') === 'error'
    && $refused instanceof \Z77\Core\Http\Response\FetchResponse && $envelope($refused)['status'] === 'error' && $commands($refused) === ['open-window']
    && ($envelope($refused)['commands'][0]['url'] ?? '') === '/backend/finance/receipt-journal/confirm-post?cutoff=2026-11-30' && ($envelope($refused)['commands'][0]['replace'] ?? false) === true
    && ($winHost->messageService->flashes[0][0] ?? '') === 'error' && $db->fetchOne('SELECT state FROM receipt WHERE id = ?', [$c6->getId()]) === 'captured',
    json_encode($refused instanceof \Z77\Core\Http\Response\FetchResponse ? $envelope($refused) : null, JSON_UNESCAPED_UNICODE));
$useRequest([], ['cutoff' => '2026-11-30', 'rec' => $pairs, '_origin' => 'region:receipt-journal-find-list'], true);
$host = $journalHost();
$posted = $host->postAction();
check('SM19b «Jetzt buchen» in the window: the run of 30.11. is posted, the protocol written; the window closes, the list region reloads, the flash',
    $posted instanceof \Z77\Core\Http\Response\FetchResponse && $commands($posted) === ['close-window', 'refresh-region'] && ($envelope($posted)['commands'][1]['name'] ?? '') === 'receipt-journal-find-list'
    && ($host->messageService->flashes[0][0] ?? '') === 'success' && str_contains($host->messageService->flashes[0][1], 'bis 30.11.2026: 1 Beleg') && $db->fetchOne('SELECT state FROM receipt WHERE id = ?', [$c6->getId()]) === 'posted'
    && $count('receipt_journal_posting') === 2, json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE));
$useRequest(['id' => (string) $c6->getId()], null, true);
$host = $journalHost();
$host->editAction();
$roHtml = $render('Backend/ReceiptJournalController/edit', $host->context);
$useRequest([]);
$emptyHost = $journalHost();
$emptyHost->listAction();
check('SM20 a posted receipt opens read-only (no save, no split, no delete, «verbucht als …»); the empty journal disables «Journal verbuchen …» with its reason',
    !str_contains($roHtml, '>Speichern</button>') && !str_contains($roHtml, '/split?id=') && str_contains($roHtml, 'verbucht als ' . $db->fetchOne('SELECT ledger_entry_ref FROM receipt WHERE id = ?', [$c6->getId()]))
    && str_contains($render('Backend/ReceiptJournalController/act', $emptyHost->context), 'disabled title="Das Belegjournal ist leer'));

// ── master data: Belegvorlagen, Zahlungsarten ──
$useRequest([]);
$host = $templateHost();
$host->listAction();
$tplList = $render('Backend/ReceiptTemplateController/listAction', $host->context);
check('SM21 «Belegvorlagen»: every template in tile order (the inactive one too), the receipts per template, symbol name, Soll with the account name, «ohne» for no VAT; «+ Vorlage» in the action cell; help in the toolbar',
    array_map(static fn($r) => $r->getCode(), $host->context['rows']) === ['buero', 'tanken', 'parken'] && ($host->context['usage']['tanken'] ?? 0) >= 1
    && str_contains($tplList, 'data-entity="receiptTemplate:' . $tanken->getId() . '"') && str_contains($tplList, '>Tanken</span>') && str_contains($tplList, '· inaktiv')
    && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/ReceiptTemplateController/act'] && $host->help->has(), json_encode($host->context['usage'] ?? null));
$useRequest(['id' => (string) $tanken->getId()], ['entity_csrf' => 'tok-receiptTemplate-' . $tanken->getId(), 'code' => 'hacked', 'label' => 'Tanken Auto', 'icon' => 'fuel', 'debit_account' => '6200', 'tax_code' => 'VI', 'position' => '15', '_origin' => 'region:receipt-template-list'], true);
$host = $templateHost();
$r    = $host->editAction();
$tk   = (new ReceiptTemplateService($wire()))->find('tanken');
check('SM22 the template window saves through the service: label and position written, the code NOT (fixed); the window closes, the list reloads',
    $commands($r) === ['close-window', 'refresh-region'] && $tk !== null && $tk->getLabel() === 'Tanken Auto' && $tk->getPosition() === 15 && $tk->isActive()
    && (new ReceiptTemplateService($wire()))->find('hacked') === null);
$useRequest([], ['code' => 'post', 'label' => 'Porti', 'icon' => 'mail', 'debit_account' => '1', 'tax_code' => '', 'position' => '60', '_origin' => 'region:receipt-template-list'], true);
$host = $templateHost();
$host->addAction();
$addHtml = $render('Backend/ReceiptTemplateController/edit', $host->context);
check('SM23 a new template naming a GROUP account: the window again with the message under «Soll», what was typed kept, the symbol «Post» selected — nothing saved',
    $host->context['validator']->hasFieldError('debit_account') && str_contains($addHtml, 'data-window="receipt-template-edit"') && str_contains($addHtml, '<option value="mail" selected>Post</option>')
    && str_contains($addHtml, 'value="Porti"') && (new ReceiptTemplateService($wire()))->find('post') === null);
$useRequest([], ['code' => 'post', 'label' => 'Porti', 'icon' => 'mail', 'debit_account' => '6500', 'tax_code' => '', 'position' => '60'], false);
$host = $templateHost();
$host->addAction();
$porti = (new ReceiptTemplateService($wire()))->find('post');
check('SM24 … with an account: created active, without VAT; as a page the answer is a 303 to the list', $porti !== null && $porti->isActive() && $porti->isWithoutVat() && $porti->getIcon() === 'mail'
    && $host->redirectedTo === '/backend/finance/receipt-template/list');
$useRequest(['id' => (string) $porti->getId()], null, true, ['value' => false]);
$host = $templateHost();
$tg   = $host->toggleActiveAction();
check('SM25 the «Aktiv» switch deactivates and answers with its row in place (FETCH-ROW-001)', !(new ReceiptTemplateService($wire()))->find('post')->isActive()
    && $commands($tg) === ['replace-html'] && str_contains($envelope($tg)['commands'][0]['html'] ?? '', 'data-entity="receiptTemplate:' . $porti->getId() . '"'));
$useRequest([]);
$host = $methodHost();
$host->listAction();
$pmList = $render('Backend/PaymentMethodController/listAction', $host->context);
check('SM26 «Zahlungsarten» in the same look: in choice order, Haben with the account name, the receipts per method, «+ Zahlungsart»',
    array_map(static fn($r) => $r->getCode(), $host->context['rows']) === ['karte', 'kasse'] && str_contains($pmList, 'data-entity="paymentMethod:' . $kasse->getId() . '"')
    && str_contains($pmList, '>1000</span>') && ($host->context['usage']['kasse'] ?? 0) >= 1 && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/PaymentMethodController/act']);
$useRequest(['id' => (string) $karte->getId()], ['entity_csrf' => 'wrong', 'label' => 'X', 'credit_account' => '1020', 'position' => '5'], true);
$host = $methodHost();
$bad  = $host->editAction();
$useRequest(['id' => (string) $karte->getId()], ['entity_csrf' => 'tok-paymentMethod-' . $karte->getId(), 'label' => 'Firmenkarte Visa', 'credit_account' => '9999', 'position' => '5'], true);
$host = $methodHost();
$host->editAction();
$pmErr = $host->context['validator']->hasFieldError('credit_account');
$useRequest(['id' => (string) $karte->getId()], ['entity_csrf' => 'tok-paymentMethod-' . $karte->getId(), 'label' => 'Firmenkarte Visa', 'credit_account' => '1020', 'position' => '5', '_origin' => 'region:payment-method-list'], true);
$host = $methodHost();
$ok   = $host->editAction();
check('SM27 the payment-method window: a foreign entity token refused, an unknown account refused under «Haben», then saved; the window closes',
    $envelope($bad)['status'] === 'error' && $pmErr && $commands($ok) === ['close-window', 'refresh-region'] && (new PaymentMethodService($wire()))->find('karte')->getLabel() === 'Firmenkarte Visa');

// ── the API targets against the database (wave 2, part E) ──
$apiRequest = static function (string $method, array $body = [], ?string $photoBytes = null, ?string $key = null) use ($base): \Z77\Shared\Api\ApiRequest {
    $files = [];
    if ($photoBytes !== null) {
        $tmp = $base . '/upload-' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $photoBytes);
        $files['photo'] = [new \Z77\Shared\ValueObjects\UploadedFile('IMG_0815.HEIC', $tmp, strlen($photoBytes), 'image/heic')];
    }
    return new \Z77\Shared\Api\ApiRequest('device', [], method: $method, body: $body, files: $files,
        principal: \Z77\Shared\Auth\ApiPrincipal::device('7', 'iPhone Peter', '5', 'backend', '3', 'Peter Muster', ['receipt.capture']), idempotencyKey: $key);
};
$wire();
$tiles   = (new \Z77\Module\Receipt\Api\ReceiptTemplatesService())->handle($apiRequest('get'));
$methods = (new \Z77\Module\Receipt\Api\PaymentMethodsService())->handle($apiRequest('get'));
check('MA1 GET receipt-templates / payment-methods: the ACTIVE rows in order, without accounts (E2, E10), with an ETag',
    count($tiles->data['templates'] ?? []) > 0 && array_keys($tiles->data['templates'][0]) === ['code', 'label', 'icon', 'position']
    && array_column($tiles->data['templates'], 'code') === array_map(static fn($t) => $t->getCode(), (new ReceiptTemplateService($wire()))->active())
    && ($methods->data['payment_methods'] ?? null) === array_map(static fn($m) => ['code' => $m->getCode(), 'label' => $m->getLabel()], (new PaymentMethodService($wire()))->active())
    && $tiles->etag !== null && $methods->etag !== null, json_encode([$tiles->data, $methods->data], JSON_UNESCAPED_UNICODE));
$wire();
$rowsBefore = $count('receipt');
$apiKey     = '9b2f6c1e-5d4a-4e3b-8a7c-0123456789ab';
$apiFields  = ['amount' => '88.10', 'date' => '2026-11-10', 'text' => 'Tankstelle Oberland', 'template' => 'tanken', 'payment_method' => 'kasse'];
$ok    = (new \Z77\Module\Receipt\Api\ReceiptsService())->handle($apiRequest('post', $apiFields, jpeg(), $apiKey));
$okRow = $db->fetchAssociative('SELECT * FROM receipt WHERE id = ?', [$ok->data['receipt']['id'] ?? 0]) ?: [];
check('MA2 POST receipts: 200 with the JOURNAL position (no ledger number, E1) and «Beleg erfasst»; captured with template and payment method, the owner as «erfasst von», device id and label (E8), the Idempotency-Key as capture key (E7)',
    $ok->status === 200 && ($ok->data['message'] ?? '') === 'Beleg erfasst'
    && ($ok->data['receipt'] ?? null) == ['id' => (int) ($okRow['id'] ?? 0), 'date' => '2026-11-10', 'amount' => '88.10', 'text' => 'Tankstelle Oberland', 'template' => 'tanken', 'payment_method' => 'kasse', 'state' => 'captured']
    && ($okRow['captured_by'] ?? '') === 'Peter Muster' && ($okRow['device_id'] ?? '') === '7' && ($okRow['device_name'] ?? '') === 'iPhone Peter'
    && ($okRow['capture_key'] ?? '') === $apiKey && ($okRow['debit_account'] ?? '') === '6200' && ($okRow['credit_account'] ?? '') === '1000' && $count('receipt') === $rowsBefore + 1,
    json_encode([$ok->status, $ok->data, $ok->fields, $okRow], JSON_UNESCAPED_UNICODE));
$again = (new \Z77\Module\Receipt\Api\ReceiptsService())->handle($apiRequest('post', $apiFields, jpeg(), $apiKey));
check('MA3 the same key again (a queue resend after the gateway\'s 24 h) answers THAT receipt and stores nothing',
    ($again->data['receipt']['id'] ?? null) === ($ok->data['receipt']['id'] ?? -1) && $count('receipt') === $rowsBefore + 1);
$docsBefore = count($docs());
$bad = (new \Z77\Module\Receipt\Api\ReceiptsService())->handle($apiRequest('post', ['amount' => '0', 'date' => '2099-01-01', 'template' => 'gibtsnicht', 'payment_method' => 'bar'], null, 'c0ffee00-0000-4000-8000-000000000001'));
check('MA4 a bad capture → 422 invalid_fields, the German message per field (amount, date, template, payment_method, photo); nothing stored',
    $bad->status === 422 && $bad->errorCode === 'invalid_fields' && count(array_intersect(['amount', 'date', 'template', 'payment_method', 'photo'], array_keys($bad->fields))) === 5
    && ($bad->fields['photo'] ?? '') === 'Das Foto fehlt.' && $count('receipt') === $rowsBefore + 1 && count($docs()) === $docsBefore, json_encode($bad->fields, JSON_UNESCAPED_UNICODE));
$tooBig = (new \Z77\Module\Receipt\Api\ReceiptsService())->handle(new \Z77\Shared\Api\ApiRequest('device', [], method: 'post', body: ['amount' => '5.00'],
    files: ['photo' => [new \Z77\Shared\ValueObjects\UploadedFile('big.jpg', '', 0, 'image/jpeg', UPLOAD_ERR_INI_SIZE)]],
    principal: \Z77\Shared\Auth\ApiPrincipal::device('7', 'iPhone Peter', '5', 'backend', '3', 'Peter Muster', ['receipt.capture']), idempotencyKey: 'c0ffee00-0000-4000-8000-000000000002'));
check('MA5 an upload PHP refused (too large) says so, not «Das Foto fehlt.»', $tooBig->status === 422 && str_contains($tooBig->fields['photo'] ?? '', 'zu gross'), json_encode($tooBig->fields, JSON_UNESCAPED_UNICODE));
$heic = (new \Z77\Module\Receipt\Api\ReceiptsService())->handle($apiRequest('post', ['amount' => '5.00'], "\x00\x00\x00\x18ftypheic not converted", 'c0ffee00-0000-4000-8000-000000000003'));
check('MA6 a photo the phone did not convert (HEIC bytes) is refused by the capture\'s sniff (T2)', $heic->status === 422 && isset($heic->fields['photo']), json_encode($heic->fields, JSON_UNESCAPED_UNICODE));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
