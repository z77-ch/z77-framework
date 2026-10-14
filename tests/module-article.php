<?php

/**
 * module-article harness (CLI) — P7 slim steps 1 and 2 (`docs/03-development/p7-order-plan.md`
 * §4.1, §8.1, §11): the article master (P6 slim) and the stock seam; since 2026-10-14 also the
 * product-group tree (plan §4b A1).
 *
 * What is load-bearing here:
 *
 *   - (A, offline) the validator of ONE save over the typed draft: number (required, no blank,
 *     at most 32, unique through the injected question), name, unit, the price string (no sign,
 *     two decimals, «'» grouping, a negative price explained), net / gross, «gültig ab», the tax
 *     code (unknown refused; an INACTIVE code refused for a new reference and kept for the
 *     article that carries it — ADR-043 decision 19), the revenue account (digits; the soft
 *     check is NULL-SAFE: «not checkable» refuses nothing, a refusal names the field); the
 *     product group (mandatory, unknown refused, an INACTIVE group refused for a new placement
 *     and kept by the article that hangs there); the draft from a form body; the group defaults
 *     fill only EMPTY fields;
 *   - (G, offline) the product-group tree: tree order and depth, path, subtree, the defaults
 *     inherited per field from the nearest group, the cycle question, the picker (active + kept,
 *     the own subtree left out); the group validator (name unique among siblings only, parent
 *     known and not inside the group, defaults optional and checked like the article's);
 *   - (K, offline) the stock seam: `StockLineTarget` quantities (three places, sign kept, no
 *     float, nothing else), `StockRequest` (origin, trigger, actor required, a line listed once,
 *     an empty state valid), `StockConfirmation` (reason + confirmed), `PendingStockLedger`
 *     books nothing and never refuses, `StockLedgers::default()` returns it; article never names
 *     module-order or `OrderStatus` in code (tokens, comments excluded), the stock seam names no
 *     financial class (stock never posts), no superglobal in the package;
 *   - (B, database) the migration on an empty schema (`diff` clean afterwards; the root group
 *     «Allgemein» exists); create = product in its group + DEFAULT variant + one price; the price
 *     history (same day replaces, a later day adds, an
 *     unchanged save adds nothing, the price valid at a day); a stale version refused; a taken
 *     number refused; deactivate = product and variants; delete of an unreferenced article, a
 *     REFERENCED one refused by the database (a foreign key like module-order's line) and kept;
 *     the group service (create, defaults reach a NEW article only, move with the articles, a
 *     cycle refused, delete only when empty) and the list filter by group incl. sub-groups.
 *
 * Run: php tests/module-article.php
 * A and K need nothing but PHP (the packages are autoloaded from the repository when vendor/
 * is missing). B needs what tests/module-debtor.php needs — vendor/ with Doctrine, a reachable
 * MariaDB and the `z77test` credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*;
 * without them B is reported SKIPPED and the exit code reflects A and K only. The schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
$vendor   = is_file($autoload);
if ($vendor) {
    require $autoload;
} else {
    // Offline sections only: a minimal PSR-4 autoloader over the packages (tests/module-vat.php model).
    spl_autoload_register(static function (string $class) use ($root): void {
        $map = [
            'Z77\\Core\\'           => $root . '/packages/kernel/core/src/',
            'Z77\\Shared\\'         => $root . '/packages/kernel/shared/src/',
            'Z77\\Persistence\\'    => $root . '/packages/kernel/persistence/src/',
            'Z77\\Module\\Vat\\'    => $root . '/packages/module-vat/src/',
            'Z77\\Module\\Article\\' => $root . '/packages/module-article/src/',
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
}

use Z77\Module\Article\Entities\ProductGroup;
use Z77\Module\Article\Services\ArticleDraft;
use Z77\Module\Article\Services\ProductGroupDraft;
use Z77\Module\Article\Services\ProductGroupTree;
use Z77\Module\Article\Stock\PendingStockLedger;
use Z77\Module\Article\Stock\StockConfirmation;
use Z77\Module\Article\Stock\StockLedger;
use Z77\Module\Article\Stock\StockLedgers;
use Z77\Module\Article\Stock\StockLineTarget;
use Z77\Module\Article\Stock\StockRequest;
use Z77\Module\Article\Stock\StockResult;
use Z77\Module\Article\Validators\ArticleValidator;
use Z77\Module\Article\Validators\ProductGroupValidator;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Runs $fn and returns the exception when it throws $class, null otherwise. */
function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

function throws(callable $fn, string $class): bool
{
    return caught($fn, $class) !== null;
}

function taxCode(string $code, bool $active): TaxCode
{
    $row = new TaxCode();
    $row->setCode($code);
    $row->setActive($active);

    return $row;
}

/** A complete, valid draft with $changes applied. */
function draft(array $changes = []): ArticleDraft
{
    $values = array_merge([
        'number' => '1001', 'name' => 'Beratung', 'unit' => 'h', 'price' => '150.00', 'priceMode' => 'net',
        'validFrom' => '2026-10-14', 'taxCode' => 'UN', 'revenueAccount' => '3400', 'stockRelevant' => false,
        'productGroup' => 1,
    ], $changes);

    return new ArticleDraft(...$values);
}

/**
 * A saved-looking product group: the id the database would assign set by reflection (the entity
 * has no id setter on purpose), the parent through the entity's own move.
 */
function group(int $id, string $name, ?ProductGroup $parent = null, int $sort = 0, bool $active = true, string $tax = '', string $account = ''): ProductGroup
{
    $group = new ProductGroup();
    (new \ReflectionProperty(ProductGroup::class, 'id'))->setValue($group, $id);
    $group->describe($name, $tax, $account);
    $group->moveUnder($parent);
    $group->setSortKey($sort);
    $group->setActive($active);

    return $group;
}

/**
 * The validator with the questions answered offline: taken numbers, the tax codes (UN active,
 * OLD inactive), the account check ($accountProblem null = not checkable), the groups (1 active,
 * 2 inactive).
 */
function validator(ArticleDraft $draft, ?\Closure $accountProblem = null, string $kept = '', int $keptGroup = 0): ArticleValidator
{
    $codes  = ['UN' => taxCode('UN', true), 'OLD' => taxCode('OLD', false)];
    $groups = [1 => group(1, 'Allgemein'), 2 => group(2, 'Alt', active: false)];

    return new ArticleValidator(
        $draft,
        numberTaken: static fn(string $n): bool => $n === 'TAKEN',
        taxCode: static fn(string $c): ?TaxCode => $codes[$c] ?? null,
        accountProblem: $accountProblem,
        keptTaxCode: $kept,
        group: static fn(int $id): ?ProductGroup => $groups[$id] ?? null,
        keptGroup: $keptGroup,
    );
}

/** The field errors of $draft (empty = valid). */
function errorsOf(ArticleDraft $draft, ?\Closure $accountProblem = null, string $kept = '', int $keptGroup = 0): array
{
    $v = validator($draft, $accountProblem, $kept, $keptGroup);
    $v->isValid();

    return $v->getFieldErrors();
}

// ── A. validator (offline) ───────────────────────────────────────────────

echo "A. Validator of one article save (offline)\n";
check('A1 a complete draft is valid (financial absent: the account is not checkable → accepted)', errorsOf(draft()) === []);
check('A2 number required', isset(errorsOf(draft(['number' => '']))['number']));
check('A3 number without blank, at most 32', isset(errorsOf(draft(['number' => 'A 1']))['number']) && isset(errorsOf(draft(['number' => str_repeat('9', 33)]))['number']) && errorsOf(draft(['number' => 'WEIN-2024-75/0.75'])) === []);
check('A4 a taken number is refused on the field', str_contains(errorsOf(draft(['number' => 'TAKEN']))['number'] ?? '', 'schon vergeben'));
check('A5 name required, unit at most 16, unit may be empty', isset(errorsOf(draft(['name' => '']))['name']) && isset(errorsOf(draft(['unit' => str_repeat('x', 17)]))['unit']) && errorsOf(draft(['unit' => ''])) === []);
check('A6 price required; «abc» and three decimals refused', isset(errorsOf(draft(['price' => '']))['price']) && isset(errorsOf(draft(['price' => 'abc']))['price']) && isset(errorsOf(draft(['price' => '1.234']))['price']));
check('A7 a negative price is refused with the reason (a return is a negative QUANTITY)', str_contains(errorsOf(draft(['price' => '-5']))['price'] ?? '', 'negativer Menge'));
check('A8 «1\'234.50», «12,5», «0» are prices; normalizePrice gives the decimal', errorsOf(draft(['price' => "1'234.50"])) === [] && ArticleValidator::normalizePrice('12,5') === '12.5' && ArticleValidator::normalizePrice("1'234.50") === '1234.50' && errorsOf(draft(['price' => '0'])) === []);
check('A9 price mode net / gross only', isset(errorsOf(draft(['priceMode' => 'brutto']))['price_mode']) && errorsOf(draft(['priceMode' => 'gross'])) === []);
check('A10 «gültig ab» required and a real date', isset(errorsOf(draft(['validFrom' => '']))['valid_from']) && isset(errorsOf(draft(['validFrom' => '2026-02-30']))['valid_from']) && ArticleValidator::parseDate('2026-02-28') instanceof \DateTimeImmutable);
check('A11 tax code required; an unknown code refused', isset(errorsOf(draft(['taxCode' => '']))['tax_code']) && str_contains(errorsOf(draft(['taxCode' => 'XX']))['tax_code'] ?? '', 'gibt es nicht'));
check('A12 an INACTIVE tax code: refused as a new reference, kept by the article that carries it (ADR-043 decision 19)',
    str_contains(errorsOf(draft(['taxCode' => 'OLD']))['tax_code'] ?? '', 'inaktiv') && errorsOf(draft(['taxCode' => 'OLD']), null, 'OLD') === []);
check('A13 revenue account required and digits only', isset(errorsOf(draft(['revenueAccount' => '']))['revenue_account']) && isset(errorsOf(draft(['revenueAccount' => '34a0']))['revenue_account']) && isset(errorsOf(draft(['revenueAccount' => '12345678901']))['revenue_account']));
$refusal = static fn(string $n): ?string => $n === '3800' ? 'Konto 3800 ist im Kontenplan nicht «auf Belegen wählbar»' : null;
check('A14 the soft account check: its refusal lands on the field; «not checkable» (null) refuses nothing',
    str_contains(errorsOf(draft(['revenueAccount' => '3800']), $refusal)['revenue_account'] ?? '', 'auf Belegen wählbar') && errorsOf(draft(), $refusal) === []);
$fromBody = ArticleDraft::fromArray(['number' => ' 1001 ', 'name' => 'Beratung', 'tax_code' => ' un ', 'price' => '150', 'price_mode' => 'net', 'valid_from' => '2026-10-14', 'revenue_account' => '3400', 'stock_relevant' => '1', 'evil' => ['x']]);
check('A15 the draft from a form body: trimmed, code upper-cased, the switch read, a missing key is empty / off',
    $fromBody->number === '1001' && $fromBody->taxCode === 'UN' && $fromBody->stockRelevant === true && $fromBody->unit === ''
    && ArticleDraft::fromArray([])->stockRelevant === false && ArticleDraft::fromArray(['number' => ['array']])->number === '');
check('A16 the validator never mutates the draft (a readonly DTO — nothing managed is touched before validation)', (new \ReflectionClass(ArticleDraft::class))->getProperty('number')->isReadOnly());
check('A17 the product group is mandatory (A1) — 0 refused with the reason', str_contains(errorsOf(draft(['productGroup' => 0]))['product_group'] ?? '', 'genau einer'));
check('A18 an unknown group is refused', isset(errorsOf(draft(['productGroup' => 9]))['product_group']));
check('A19 an INACTIVE group: refused as a new placement, kept by the article that hangs there',
    str_contains(errorsOf(draft(['productGroup' => 2]))['product_group'] ?? '', 'inaktiv') && errorsOf(draft(['productGroup' => 2]), null, '', 2) === []);
check('A20 the draft from a form body reads the group; no number is 0',
    ArticleDraft::fromArray(['product_group' => '7'])->productGroup === 7 && ArticleDraft::fromArray(['product_group' => 'x'])->productGroup === 0 && ArticleDraft::fromArray([])->productGroup === 0);
$filled = draft(['taxCode' => '', 'revenueAccount' => ''])->withDefaults('UN', '3200');
$kept   = draft(['taxCode' => 'OLD', 'revenueAccount' => '3400'])->withDefaults('UN', '3200');
check('A21 the group defaults fill only EMPTY fields — a typed value wins; the rest of the draft stays',
    $filled->taxCode === 'UN' && $filled->revenueAccount === '3200' && $kept->taxCode === 'OLD' && $kept->revenueAccount === '3400'
    && $filled->number === '1001' && $filled->productGroup === 1 && $filled->price === '150.00');

// ── G. the product-group tree (offline) ───────────────────────────────────

echo "G. Product-group tree (offline)\n";
$allg   = group(1, 'Allgemein', null, 0);
$dienst = group(2, 'Dienstleistungen', null, 1, true, 'UN', '3400');
$hosting  = group(4, 'Hosting', $dienst, 1);
$beratung = group(3, 'Beratung', $dienst, 0, true, '', '3410');
$intern = group(5, 'Intern', $beratung, 0, false);
$tree   = new ProductGroupTree([$hosting, $intern, $allg, $beratung, $dienst]);
$flat   = static fn(array $rows): array => array_map(static fn($r) => $r['group']->getName() . ':' . $r['depth'], $rows);
check('G1 rows: tree order (roots and siblings by sort key, each followed by its subtree) with the depth',
    $flat($tree->rows()) === ['Allgemein:0', 'Dienstleistungen:0', 'Beratung:1', 'Intern:2', 'Hosting:1']);
check('G2 path and depth', $tree->path(5) === 'Dienstleistungen › Beratung › Intern' && $tree->path(1) === 'Allgemein' && $tree->path(99) === '' && $tree->depth(5) === 2);
check('G3 the subtree of a group includes itself and every level below; a leaf is itself; unknown is empty',
    $tree->subtreeIds(2) === [2, 3, 5, 4] && $tree->subtreeIds(4) === [4] && $tree->subtreeIds(99) === []);
check('G4 defaults: each field from the NEAREST group that sets one — own first, independently per field',
    $tree->defaultsFor(5) === ['taxCode' => 'UN', 'revenueAccount' => '3410']
    && $tree->defaultsFor(4) === ['taxCode' => 'UN', 'revenueAccount' => '3400']
    && $tree->defaultsFor(1) === ['taxCode' => '', 'revenueAccount' => '']);
check('G5 the cycle question: a group itself and everything below it are «within»; a sibling is not',
    $tree->isWithin(2, 2) && $tree->isWithin(5, 2) && !$tree->isWithin(4, 3) && !$tree->isWithin(2, 3));
check('G6 the picker: active groups only, a kept inactive one offered, the own subtree left out',
    $flat($tree->selectable()) === ['Allgemein:0', 'Dienstleistungen:0', 'Beratung:1', 'Hosting:1']
    && in_array('Intern:2', $flat($tree->selectable(keep: 5)), true)
    && $flat($tree->selectable(without: 3)) === ['Allgemein:0', 'Dienstleistungen:0', 'Hosting:1']);
check('G7 child count; ids', $tree->childCount(2) === 2 && $tree->childCount(4) === 0 && count($tree->ids()) === 5 && (new ProductGroupTree([]))->isEmpty());

$gErrors = static function (ProductGroupDraft $d, ?ProductGroup $existing = null, ?\Closure $problem = null) use ($tree): array {
    $codes = ['UN' => taxCode('UN', true), 'OLD' => taxCode('OLD', false)];
    $v     = new ProductGroupValidator($d, $tree, $existing, static fn(string $c): ?TaxCode => $codes[$c] ?? null, $problem);
    $v->isValid();

    return $v->getFieldErrors();
};
check('G8 a complete new group is valid; the name is required and at most 80',
    $gErrors(new ProductGroupDraft('Software', 2)) === [] && isset($gErrors(new ProductGroupDraft('', 2))['name']) && isset($gErrors(new ProductGroupDraft(str_repeat('x', 81), 2))['name']));
check('G9 a name is unique among its SIBLINGS only (case-insensitive); the same name elsewhere and the group\'s own name are fine',
    str_contains($gErrors(new ProductGroupDraft('beratung', 2))['name'] ?? '', 'auf dieser Ebene') && $gErrors(new ProductGroupDraft('Beratung', 1)) === []
    && $gErrors(new ProductGroupDraft('Beratung', 2), $beratung) === []);
check('G10 the parent: unknown refused; the group itself or one below it refused (a cycle); another branch is fine (a move)',
    isset($gErrors(new ProductGroupDraft('X', 99))['parent_id'])
    && str_contains($gErrors(new ProductGroupDraft('Dienstleistungen', 5, 'UN', '3400'), $dienst)['parent_id'] ?? '', 'unter sich selbst')
    && isset($gErrors(new ProductGroupDraft('Dienstleistungen', 2, 'UN', '3400'), $dienst)['parent_id'])
    && $gErrors(new ProductGroupDraft('Beratung', 1, '', '3410'), $beratung) === []);
check('G11 default tax code: optional; unknown refused; an INACTIVE one refused, kept by the group that carries it',
    isset($gErrors(new ProductGroupDraft('X', 0, 'XX'))['default_tax_code']) && str_contains($gErrors(new ProductGroupDraft('X', 0, 'OLD'))['default_tax_code'] ?? '', 'inaktiv')
    && $gErrors(new ProductGroupDraft('Alt', 0, 'OLD'), group(9, 'Alt', null, 0, true, 'OLD')) === []);
$gProblem = static fn(string $n): ?string => $n === '1000' ? 'Konto 1000 ist kein Ertragskonto' : null;
check('G12 default revenue account: optional; digits only; the soft check\'s refusal lands on the field',
    isset($gErrors(new ProductGroupDraft('X', 0, '', '34a'))['default_revenue_account'])
    && str_contains($gErrors(new ProductGroupDraft('X', 0, '', '1000'), null, $gProblem)['default_revenue_account'] ?? '', 'Ertragskonto')
    && $gErrors(new ProductGroupDraft('X', 0, '', ''), null, $gProblem) === []);
$gBody = ProductGroupDraft::fromArray(['name' => ' Wein ', 'parent_id' => '2', 'default_tax_code' => ' un ', 'default_revenue_account' => ' 3200 ', 'evil' => ['x']]);
check('G13 the group draft from a form body: trimmed, code upper-cased, a parent that is no number is a root',
    $gBody->name === 'Wein' && $gBody->parentId === 2 && $gBody->defaultTaxCode === 'UN' && $gBody->defaultRevenueAccount === '3200'
    && ProductGroupDraft::fromArray(['parent_id' => '-1'])->parentId === 0 && ProductGroupDraft::fromArray([])->name === '');
check('G14 ProductGroup is a TreeNode of the shared TreeService; its parent moves only through moveUnder() (a relation, not an id)',
    $intern instanceof \Z77\Shared\Tree\TreeNode && $intern->getParentId() === 3
    && throws(fn() => $intern->setParentId(2), \LogicException::class) && caught(fn() => $intern->setParentId(3), \Throwable::class) === null);

// ── K. the stock seam (offline) ──────────────────────────────────────────

echo "K. Stock seam (offline)\n";
check('K1 quantities: three places, the sign kept (an inflow / a return), «-0» is zero',
    StockLineTarget::quantity('2') === '2.000' && StockLineTarget::quantity('-1.5') === '-1.500' && StockLineTarget::quantity('-0') === '0.000' && StockLineTarget::quantity('007.25') === '7.250');
check('K2 quantities: four places, text, a float-looking exponent are refused',
    throws(fn() => StockLineTarget::quantity('1.2345'), \InvalidArgumentException::class) && throws(fn() => StockLineTarget::quantity('abc'), \InvalidArgumentException::class) && throws(fn() => StockLineTarget::quantity('1e3'), \InvalidArgumentException::class));
$line = new StockLineTarget('17', 5, '3', '0');
check('K3 a line target: reference, variant, reserved / consumed normalised; default zero', $line->lineRef === '17' && $line->variantId === 5 && $line->reserved === '3.000' && $line->consumed === StockLineTarget::ZERO);
check('K4 a line target needs a reference and a variant id ≥ 1', throws(fn() => new StockLineTarget(' ', 5), \InvalidArgumentException::class) && throws(fn() => new StockLineTarget('1', 0), \InvalidArgumentException::class));
$request = new StockRequest('order', '42', 'status:scheduled>delivered', 'harness', [$line, new StockLineTarget('18', 6, '0', '-2')]);
check('K5 a request carries origin, trigger, actor and the COMPLETE state', $request->sourceType === 'order' && $request->sourceRef === '42' && count($request->lines) === 2 && $request->confirmation === null);
check('K6 an empty state is valid — «this document holds nothing» (every line deleted)', count((new StockRequest('order', '42', 'lines', 'harness', []))->lines) === 0);
check('K7 a line listed twice is refused — a request carries each line\'s ONE target', throws(fn() => new StockRequest('order', '42', 'lines', 'harness', [$line, new StockLineTarget('17', 9, '1')]), \InvalidArgumentException::class));
check('K8 origin, trigger and actor are required; only targets in the list',
    throws(fn() => new StockRequest('', '42', 'lines', 'harness', []), \InvalidArgumentException::class)
    && throws(fn() => new StockRequest('order', '42', ' ', 'harness', []), \InvalidArgumentException::class)
    && throws(fn() => new StockRequest('order', '42', 'lines', '', []), \InvalidArgumentException::class)
    && throws(fn() => new StockRequest('order', '42', 'lines', 'harness', ['17']), \InvalidArgumentException::class));
check('K9 a reversal confirmation needs a reason and a yes', (new StockConfirmation(' Fehlklick «geliefert» '))->reason === 'Fehlklick «geliefert»'
    && throws(fn() => new StockConfirmation(''), \InvalidArgumentException::class) && throws(fn() => new StockConfirmation('Grund', false), \InvalidArgumentException::class));
$pending = new PendingStockLedger();
$result  = $pending->bringTo($request);
$again   = $pending->bringTo(new StockRequest('order', '42', 'status:delivered>done-no-stock', 'harness', [new StockLineTarget('17', 5)]));
check('K10 the pending ledger books nothing and never refuses (no reason demanded in slim)', $pending instanceof StockLedger && $result instanceof StockResult && !$result->hasMovements() && $result->movements === 0 && !$again->hasMovements());
check('K11 StockLedgers::default() is the pending ledger in P7 slim', StockLedgers::default(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])), 'harness') instanceof PendingStockLedger);
check('K12 a movement count is never negative', throws(fn() => StockResult::booked(-1), \InvalidArgumentException::class) && StockResult::booked(2)->hasMovements());

/** The code of every PHP file under $dir without its comments — what a grep for a NAME must look at. */
$codeOf = static function (string $dir): array {
    $out = [];
    $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $out[str_replace('\\', '/', $file->getPathname())] = $code;
    }

    return $out;
};
$articleCode = $codeOf($root . '/packages/module-article');
$named       = static fn(array $code, string $needle): array => array_keys(array_filter($code, static fn(string $c) => str_contains($c, $needle)));
check('K13 article never names module-order or OrderStatus in code (one way only, plan §3)', $named($articleCode, 'Module\\Order') === [] && $named($articleCode, 'OrderStatus') === [] && count($articleCode) > 20);
check('K14 the stock seam names no financial class — stock never posts (ADR-043 decision 18)', $named($codeOf($root . '/packages/module-article/src/Stock'), 'Financial') === []);
check('K15 no superglobal in module-article (HTTP input only through Request, Rule 4)', array_filter($articleCode, static fn(string $c) => preg_match('/\$_(GET|POST|SERVER|REQUEST|COOKIE)\b/', $c) === 1) === []);
check('K16 the entities follow ADR-039 addendum (b): Product and Variant are EMPTY subclasses of their mapped superclass',
    (new \ReflectionClass(\Z77\Module\Article\Entities\Product::class))->getParentClass()->getName() === \Z77\Module\Article\Entities\AbstractProduct::class
    && (new \ReflectionClass(\Z77\Module\Article\Entities\Variant::class))->getParentClass()->getName() === \Z77\Module\Article\Entities\AbstractVariant::class
    && array_filter((new \ReflectionClass(\Z77\Module\Article\Entities\Product::class))->getMethods(), static fn($m) => $m->getDeclaringClass()->getName() === \Z77\Module\Article\Entities\Product::class) === []
    && array_filter((new \ReflectionClass(\Z77\Module\Article\Entities\Variant::class))->getProperties(), static fn($p) => $p->getDeclaringClass()->getName() === \Z77\Module\Article\Entities\Variant::class) === []);

// ── B. database ──────────────────────────────────────────────────────────

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
if (!$vendor || $credentials['password'] === '' || !class_exists(\Doctrine\DBAL\DriverManager::class)) {
    echo "B. Database — SKIPPED (" . (!$vendor ? 'no vendor/' : 'no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD') . ")\n";
    echo "\n{$pass} passed, {$fail} failed (B skipped)\n";
    exit($fail === 0 ? 0 : 1);
}

echo "B. Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-article-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);

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

// The REAL packages are the modules' source paths. module-financial is NOT registered: the account
// check is soft then (`LedgerAccountCheck` answers «not checkable»), the way a project without
// bookkeeping runs — the refusal itself is section A14 and the live check (article.md).
$packages = [
    'Vat'      => $root . '/packages/module-vat',
    'Mandator' => $root . '/packages/module-mandator',
    'Article'  => $root . '/packages/module-article',
];
$namespaces = '';
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'vat', 'modules' => ['vat' => [], 'mandator' => [], 'article' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
@mkdir($base . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $name) {
    copy($packages['Vat'] . '/data/framework/vat/' . $name . '.default.json', $base . '/data/framework/vat/' . $name . '.json');
}

\Z77\Core\DI::getInstance(true)
    ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
    ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
    ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
    ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
    ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
    ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true);
\Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');
$em = \Z77\Core\DI::getUnifiedEntityManager();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$tables = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
$count  = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");

/** Runs one z77-db command in-process on a fresh application; returns [exit code, output]. */
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

$config = \Z77\Core\DI::getModuleManager()->getModuleConfig('article');
check('B1 the module config announces ProductGroup, Product, Variant and Price — and nothing else', $config?->get('doctrineEntities') === [
    ProductGroup::class, \Z77\Module\Article\Entities\Product::class, \Z77\Module\Article\Entities\Variant::class, \Z77\Module\Article\Entities\Price::class,
]);
[$code, $out] = $run(['command' => 'migrate']);
check('B2 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
check('B3 the four tables exist', array_diff(['article_product_group', 'article_product', 'article_variant', 'article_price'], $tables()) === []);
$allgemein = (int) $db->fetchOne("SELECT id FROM article_product_group WHERE parent_id IS NULL AND name = 'Allgemein'");
check('B3a the data step created the root group «Allgemein» into the empty tree — and only it', $allgemein > 0 && $count('article_product_group') === 1);
$migrations = glob($packages['Article'] . '/res/migrations/Version*.php');
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Article\\Migrations']);
check('B4 diff after migrate reports NO change — mapping and migration agree' . (str_contains($out, 'No changes detected') ? '' : ' — ' . trim($out)),
    $code !== 0 && str_contains($out, 'No changes detected') && glob($packages['Article'] . '/res/migrations/Version*.php') === $migrations);

$service = new \Z77\Module\Article\Services\ArticleService($em, 'harness');
/** @var \Z77\Module\Article\Repositories\VariantRepository $variants */
$variants = $em->getRepository(\Z77\Module\Article\Entities\Variant::class);
/** @var \Z77\Module\Article\Repositories\PriceRepository $prices */
$prices = $em->getRepository(\Z77\Module\Article\Entities\Price::class);
$day    = static fn(string $iso) => new \DateTimeImmutable($iso);

$draft   = static fn(array $changes = []): ArticleDraft => draft($changes + ['productGroup' => $allgemein]);
$product = $service->create($draft(['validFrom' => '2026-10-01']));
$variant = $variants->defaultOf($product);
check('B5 create: product in its group + DEFAULT variant (empty label) + one price row', $product->getId() !== null && $variant !== null && $variant->isDefault()
    && (int) $db->fetchOne('SELECT product_group_id FROM article_product WHERE id = ?', [$product->getId()]) === $allgemein
    && $variant->getNumber() === '1001' && $variant->getUnit() === 'h' && $count('article_price') === 1 && $product->getCreatedBy() === 'harness' && $product->getVersion() === 1);
check('B6 the soft account check: without module-financial registered the account is stored unverified', $product->getRevenueAccount() === '3400');

$v = $product->getVersion();
$product = $service->update((int) $product->getId(), $v, $draft(['price' => '150.00', 'validFrom' => '2026-10-20']));
check('B7 an unchanged price on a later day writes NO row (A4)', $count('article_price') === 1 && $product->getVersion() > $v);
$product = $service->update((int) $product->getId(), $product->getVersion(), $draft(['price' => '160', 'validFrom' => '2026-11-01']));
check('B8 a changed price writes a NEW row from its day', $count('article_price') === 2);
$product = $service->update((int) $product->getId(), $product->getVersion(), $draft(['price' => '165', 'validFrom' => '2026-11-01', 'priceMode' => 'gross']));
check('B9 a second change on the SAME day replaces that day\'s row', $count('article_price') === 2
    && $prices->onDay($variant, $day('2026-11-01'))?->getAmount()->toDecimal() === '165.00' && $prices->onDay($variant, $day('2026-11-01'))?->priceMode()->value === 'gross');
check('B10 the price valid at a day: before the first row none, the old one until the change, the new one from it',
    $prices->validAt($variant, $day('2026-09-30')) === null
    && $prices->validAt($variant, $day('2026-10-31'))?->getAmount()->toDecimal() === '150.00'
    && $prices->validAt($variant, $day('2026-11-01'))?->getAmount()->toDecimal() === '165.00'
    && count($prices->historyOf($variant)) === 2);
check('B11 a STALE version is refused, nothing written', throws(fn() => $service->update((int) $product->getId(), 1, $draft(['name' => 'Neu'])), \Z77\Module\Article\Services\ArticleConflictException::class)
    && $db->fetchOne('SELECT name FROM article_product WHERE id = ?', [$product->getId()]) === 'Beratung');
$taken = caught(fn() => $service->create($draft(['name' => 'Doppelt'])), \Z77\Module\Article\Services\InvalidArticleException::class);
check('B12 a taken number is refused on the field — no second product', $taken !== null && isset($taken->validator->getFieldErrors()['number']) && $count('article_product') === 1);
$refused = caught(fn() => $service->update((int) $product->getId(), $product->getVersion(), $draft(['taxCode' => 'XX'])), \Z77\Module\Article\Services\InvalidArticleException::class);
check('B13 an unknown tax code (module-vat) is refused before anything is touched', $refused !== null && isset($refused->validator->getFieldErrors()['tax_code'])
    && $db->fetchOne('SELECT tax_code FROM article_product WHERE id = ?', [$product->getId()]) === 'UN');

$product = $service->setActive((int) $product->getId(), false);
check('B14 deactivate: product AND variant inactive', (int) $db->fetchOne('SELECT active FROM article_product WHERE id = ?', [$product->getId()]) === 0
    && (int) $db->fetchOne('SELECT active FROM article_variant WHERE product_id = ?', [$product->getId()]) === 0);

// A row elsewhere references the variant — the shape of module-order's line (a foreign key on article_variant).
$db->executeStatement('CREATE TABLE harness_line (id INT AUTO_INCREMENT NOT NULL, variant_id INT NOT NULL, PRIMARY KEY (id), CONSTRAINT fk_harness_line_variant FOREIGN KEY (variant_id) REFERENCES article_variant (id)) ENGINE = InnoDB');
$db->insert('harness_line', ['variant_id' => $variant->getId()]);
$product = $em->getRepository(\Z77\Module\Article\Entities\Product::class)->find((int) $product->getId());
check('B15 a REFERENCED article is not deleted — the database refuses, the service says «deactivate»',
    throws(fn() => $service->delete((int) $product->getId(), $product->getVersion()), \Z77\Module\Article\Services\ArticleInUseException::class)
    && $count('article_product') === 1 && $count('article_price') === 2);
$db->executeStatement('DELETE FROM harness_line');
$product = $em->getRepository(\Z77\Module\Article\Entities\Product::class)->find((int) $product->getId());
$service->delete((int) $product->getId(), $product->getVersion());
check('B16 an unreferenced article is deleted with its variant and price history', $count('article_product') === 0 && $count('article_variant') === 0 && $count('article_price') === 0);

// The product-group tree (A1): service, defaults, move, delete, the list filter.
$groups  = new \Z77\Module\Article\Services\ProductGroupService($em);
$dienst  = $groups->create(new ProductGroupDraft('Dienstleistungen', 0, 'UN', '3400'));
$beratung = $groups->create(new ProductGroupDraft('Beratung', (int) $dienst->getId()));
$hosting = $groups->create(new ProductGroupDraft('Hosting', (int) $dienst->getId(), '', '3410'));
check('B17 groups are created at the end of their siblings (TreeService::nextSortKey)', $dienst->getSortKey() === 1 && $beratung->getSortKey() === 0 && $hosting->getSortKey() === 1);
check('B18 a duplicate sibling name is refused', throws(fn() => $groups->create(new ProductGroupDraft('beratung', (int) $dienst->getId())), \Z77\Module\Article\Services\InvalidProductGroupException::class));
$host = $service->create($draft(['number' => 'H-1', 'name' => 'Webhosting', 'taxCode' => '', 'revenueAccount' => '', 'productGroup' => (int) $hosting->getId()]));
check('B19 a NEW article with empty tax code / account takes the group defaults, inherited per field (UN from Dienstleistungen, 3410 from Hosting)',
    $host->getTaxCode() === 'UN' && $host->getRevenueAccount() === '3410');
$groups->update((int) $hosting->getId(), $hosting->getVersion(), new ProductGroupDraft('Hosting', (int) $dienst->getId(), '', '3420'));
check('B20 a changed group default leaves the existing article untouched', $db->fetchOne('SELECT revenue_account FROM article_product WHERE id = ?', [$host->getId()]) === '3410');
check('B21 a move into the own subtree is refused (cycle)', throws(fn() => $groups->update((int) $dienst->getId(), $dienst->getVersion(), new ProductGroupDraft('Dienstleistungen', (int) $hosting->getId(), 'UN', '3400')), \Z77\Module\Article\Services\InvalidProductGroupException::class));
$hosting = $em->getRepository(ProductGroup::class)->find((int) $hosting->getId());
$groups->update((int) $hosting->getId(), $hosting->getVersion(), new ProductGroupDraft('Hosting', $allgemein, '', '3420'));
/** @var \Z77\Module\Article\Repositories\ProductRepository $productsRepo */
$productsRepo = $em->getRepository(\Z77\Module\Article\Entities\Product::class);
$treeNow = $em->getRepository(ProductGroup::class)->tree();
check('B22 a MOVE takes the articles along (placement by id); the group starts at the end of its new siblings',
    (int) $db->fetchOne('SELECT product_group_id FROM article_product WHERE id = ?', [$host->getId()]) === (int) $hosting->getId()
    && $treeNow->path((int) $hosting->getId()) === 'Allgemein › Hosting' && (int) $db->fetchOne('SELECT sort_key FROM article_product_group WHERE id = ?', [$hosting->getId()]) === 0);
check('B23 the list filter: a group shows its articles INCLUDING sub-groups',
    $productsRepo->countSearch(new \Z77\Module\Article\Repositories\ArticleSearch(groupIds: $treeNow->subtreeIds($allgemein))) === 1
    && $productsRepo->countSearch(new \Z77\Module\Article\Repositories\ArticleSearch(groupIds: $treeNow->subtreeIds((int) $dienst->getId()))) === 0
    && $productsRepo->countSearch(new \Z77\Module\Article\Repositories\ArticleSearch(groupIds: [])) === 0);
$inUse = caught(fn() => $groups->delete((int) $hosting->getId(), $em->getRepository(ProductGroup::class)->find((int) $hosting->getId())->getVersion()), \Z77\Module\Article\Services\ProductGroupInUseException::class);
$withChildren = caught(fn() => $groups->delete((int) $dienst->getId(), $em->getRepository(ProductGroup::class)->find((int) $dienst->getId())->getVersion()), \Z77\Module\Article\Services\ProductGroupInUseException::class);
check('B24 delete refused while a group carries an article or a sub-group — and it names what', $inUse?->products === 1 && $withChildren?->children === 1 && $count('article_product_group') === 4);
$groups->delete((int) $beratung->getId(), $em->getRepository(ProductGroup::class)->find((int) $beratung->getId())->getVersion());
check('B25 an EMPTY group is deleted', $count('article_product_group') === 3);
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Article\\Migrations']);
check('B26 diff after the group writes still reports NO change', $code !== 0 && str_contains($out, 'No changes detected'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
