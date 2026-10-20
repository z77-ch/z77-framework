<?php

/**
 * module-order harness (CLI) — P7 slim steps 3, 5 and 6 (`docs/03-development/p7-order-plan.md`
 * §4.2, §4.3, §5, §6, §8, §11): the order status as data, the sales order's one write path, the
 * status change, the stock call.
 *
 * What is load-bearing here:
 *
 *   - (S, offline) the status invariants of the validator (within a row and across the rows), the
 *     SEED passes them, exactly one active `invoiceBooked` and one `deliveryNoteTarget` in the seed;
 *   - (N, offline) N1: no code path SELECTS a status by its code (`byCode('…')`, `findByCode('…')`,
 *     a comparison of a status code with a literal) — selection is by flag; N2: module-debtor and
 *     module-article never name `Z77\Module\Order`; no superglobal in the package; the navigation
 *     seed parses and its parent is module-debtor's group;
 *   - (T, offline) the rules of `OrderStatuses` over the seed: initial = lowest active level,
 *     startable, delivery-note target and booked by FLAG; transitions forward / lateral / backward,
 *     backward barred with an invoice, the booked status never a target, an inactive one neither;
 *     the stock TARGETS per line — reserved / consumed from the flags, negative kept, lump-sum
 *     article = one unit, free and text lines skipped, children included;
 *   - (D, offline) `OrderInvoiceDraft::of()`: kind, contact, dates (service date falls back to the
 *     invoice date), price mode, payment target, subject «Auftrag {nr} · {Betreff}», lines 1:1
 *     with types, children beneath their parent, origins;
 *   - (M, database) migrations + `diff` clean; the range `order`; create draws 1, 2; a bad draft
 *     refused per field with nothing drawn; update keeps line identity; a stale version refused;
 *     the log rows; the stock SPY receives the FULL state on create, status change, line add /
 *     edit / delete; a REFUSING ledger → the reason flow (refused without, the reason logged with
 *     the confirmation); D7 (final → read-only; with an invoice: backward barred, edit refused,
 *     forward allowed); the delivery-note status forward only, a reprint writes nothing.
 *
 * Run: php tests/module-order.php
 * S, N, T and D need the monorepo's vendor/ (Doctrine Collections for the entities in memory) —
 * `composer install` in the root. M needs what tests/module-debtor.php needs — a reachable
 * MariaDB and the `z77test` credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*;
 * without them M is reported SKIPPED and the exit code reflects the offline sections only.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-order: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Article\Entities\Product;
use Z77\Module\Article\Entities\Variant;
use Z77\Module\Article\Stock\StockConfirmation;
use Z77\Module\Article\Stock\StockLedger;
use Z77\Module\Article\Stock\StockReasonRequiredException;
use Z77\Module\Article\Stock\StockRequest;
use Z77\Module\Article\Stock\StockResult;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Entities\SalesOrderLine;
use Z77\Module\Order\Close\InvoiceableOrdersCheck;
use Z77\Module\Order\Invoicing\OrderInvoiceDraft;
use Z77\Module\Order\Pdf\OrderPdf;
use Z77\Module\Order\Services\OrderStatuses;
use Z77\Module\Order\Stock\OrderStockTargets;
use Z77\Module\Order\Ui\BookingSelection;
use Z77\Module\Order\Ui\OrderForm;
use Z77\Module\Order\Ui\OrderListing;
use Z77\Module\Order\Validators\OrderStatusValidator;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Sets a server-controlled id (the database's job) on an entity built in memory. */
function withId(object $entity, int $id): object
{
    $ref = new \ReflectionProperty($entity, 'id');
    $ref->setValue($entity, $id);

    return $entity;
}

/** A status row of $phase. */
function status(string $code, OrderPhase $phase, bool $default = false, bool $active = true, ?int $id = null, int $position = 10): OrderStatus
{
    $row = new OrderStatus(['code' => $code, 'label' => ucfirst($code), 'phase' => $phase->value, 'position' => $position, 'phase_default' => $default, 'active' => $active]);

    return $id === null ? $row : withId($row, $id);
}

$seedFile = $root . '/packages/module-order/data/framework/order/order_statuses.default.json';
$seedRaw  = (string) file_get_contents($seedFile);
$seed     = array_map(static fn(array $r) => withId(new OrderStatus($r), (int) $r['id']), json_decode($seedRaw, true, flags: JSON_THROW_ON_ERROR));
$byCode   = [];
foreach ($seed as $row) {
    $byCode[$row->getCode()] = $row;
}

// ── S. the status master data (offline) ──────────────────────────────────

echo "S. Order status master data (offline)\n";
check('S1 the seed is UTF-8 without BOM and holds the seven statuses along the ladder, each in its phase',
    !str_starts_with($seedRaw, "\xEF\xBB\xBF") && array_keys($byCode) === ['opened', 'scheduled', 'delivered', 'to-invoice', 'booked', 'done', 'cancelled']
    && array_map(static fn($s) => $s->getPhase()->value, $seed) === ['new', 'confirmed', 'delivered', 'delivered', 'closed', 'closed', 'cancelled']);
check('S2 every seed row passes the field run, and no seed row takes a switch from another (one start, one action default per phase)',
    array_filter($seed, static fn($s) => !(new OrderStatusValidator($s))->isValid() || OrderStatuses::takeovers($s, $seed) !== ['start' => [], 'default' => []]) === []);
check('S3 ONE start status for all — «opened» —, one action default per phase an action moves into: scheduled, delivered, booked; none for «new» and «cancelled» (owner 2026-10-16)',
    array_values(array_map(static fn($s) => $s->getCode(), array_filter($seed, static fn($s) => $s->isStart()))) === ['opened']
    && array_values(array_map(static fn($s) => $s->getCode(), array_filter($seed, static fn($s) => $s->isPhaseDefault()))) === ['scheduled', 'delivered', 'booked']);
$startIn = static function (string $code, OrderPhase $phase, bool $active = true, ?int $id = null): OrderStatus {
    $row = new OrderStatus(['code' => $code, 'label' => ucfirst($code), 'phase' => $phase->value, 'position' => 10, 'start' => true, 'phase_default' => false, 'active' => $active]);
    return $id === null ? $row : withId($row, $id);
};
$taken = static fn(array $t, string $flag): array => array_map(static fn($s) => $s->getCode(), $t[$flag]);
check('S4 setting a switch TAKES it (owner 2026-10-16): a start status in «Termin» takes it from «opened»; a second action default takes it within its phase only; inactive, own stored copy and a switch the phase does not know take nothing',
    $taken(OrderStatuses::takeovers($startIn('ordered', OrderPhase::Confirmed), $seed), 'start') === ['opened']
    && $taken(OrderStatuses::takeovers(status('ordered', OrderPhase::Confirmed, true), $seed), 'default') === ['scheduled']
    && OrderStatuses::takeovers(status('ordered', OrderPhase::Confirmed, true, false), $seed) === ['start' => [], 'default' => []]
    && OrderStatuses::takeovers($byCode['scheduled'], $seed) === ['start' => [], 'default' => []]
    && OrderStatuses::takeovers($startIn('ready', OrderPhase::Delivered), $seed)['start'] === []
    && !$startIn('ready', OrderPhase::Delivered)->isStart() && !status('enquiry', OrderPhase::Cancelled, true)->isPhaseDefault());
check('S4b a status file of 2026-10-15 (no `start`) reads its «new» default as THE start status; with `start` the key decides',
    (new OrderStatus(['code' => 'opened', 'label' => 'Offerte', 'phase' => 'new', 'phase_default' => true]))->isStart()
    && !(new OrderStatus(['code' => 'opened', 'label' => 'Offerte', 'phase' => 'new', 'start' => false, 'phase_default' => true]))->isStart()
    && !(new OrderStatus(['code' => 'scheduled', 'label' => 'Termin', 'phase' => 'confirmed', 'phase_default' => true]))->isStart());
$bad = new OrderStatus(['code' => 'pickup', 'label' => 'Abholbereit', 'phase' => 'ready', 'position' => -1]);
$v   = new OrderStatusValidator($bad);
check('S5 the field run: an unknown phase, a position out of 0–999, a bad code are field errors; a good row passes',
    !$v->isValid() && $v->hasFieldError('phase') && $v->hasFieldError('position')
    && !(new OrderStatusValidator(status('Bad Code', OrderPhase::New)))->isValid() && (new OrderStatusValidator(status('pickup', OrderPhase::Delivered)))->isValid());
check('S6 a stored phase the validator would refuse (a hand-edited file) reads as «new» — no stock, no booking print: the safe side',
    $bad->getPhase() === OrderPhase::New && $bad->getPhaseValue() === 'ready');

// ── N. one way only (offline) ────────────────────────────────────────────

echo "N. Selection by flag, module direction (offline)\n";
/** The code of every PHP file under $dir without its comments. */
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
$orderCode = $codeOf($root . '/packages/module-order');
$codePattern = '/(?:byCode|findByCode)\(\s*[\'"]|(?:getCode|getStatusCode|getToCode|getFromCode)\(\)\s*[!=]==?\s*[\'"]|[\'"]\s*[!=]==?\s*\$\w+->(?:getCode|getStatusCode)\(\)/';
check('N1 no code path in module-order selects a status by a literal code (selection by flag, ADR-043)',
    array_filter($orderCode, static fn(string $c) => preg_match($codePattern, $c) === 1) === [] && count($orderCode) > 20);
$named = static fn(array $code, string $needle): array => array_keys(array_filter($code, static fn(string $c) => str_contains($c, $needle)));
check('N2 module-debtor and module-article never name Z77\\Module\\Order or OrderStatus (one way only, plan §3)',
    $named($codeOf($root . '/packages/module-debtor'), 'Module\\Order') === [] && $named($codeOf($root . '/packages/module-article'), 'Module\\Order') === []
    && $named($codeOf($root . '/packages/module-article'), 'OrderStatus') === []);
check('N3 no superglobal in module-order (HTTP input only through Request, Rule 4)', array_filter($orderCode, static fn(string $c) => preg_match('/\$_(GET|POST|SERVER|REQUEST|COOKIE)\b/', $c) === 1) === []);
check('N4 the stock seam is called in ONE place — OrderService::syncStock() (plan §8.3)',
    $named($orderCode, '->bringTo(') === [$root . '/packages/module-order/src/Services/OrderService.php'] && substr_count($orderCode[$root . '/packages/module-order/src/Services/OrderService.php'], '->bringTo(') === 1);
$navSeed   = json_decode((string) file_get_contents($root . '/packages/module-order/data/framework/routing/navigation.d/module-order.json'), true);
$debtorNav = array_column(json_decode((string) file_get_contents($root . '/packages/module-debtor/data/framework/routing/navigation.d/module-debtor.json'), true), null, 'key');
$navByKey  = is_array($navSeed) ? array_column($navSeed, null, 'key') : [];
$route     = static fn(array $e): array => [$e['module'], $e['group'], $e['controller'], $e['action']];
check('N5 the navigation seed parses: «Auftragsstatus» sort 10 under debtor\'s «stammdaten-auftraege», route backend/order/order-status/list',
    count($navByKey) === 2 && ($navByKey['auftragsstatus']['sort_key'] ?? null) === 10
    && $navByKey['auftragsstatus']['parent_key'] === 'stammdaten-auftraege' && isset($debtorNav['stammdaten-auftraege'])
    && $route($navByKey['auftragsstatus']) === ['backend', 'order', 'order-status', 'list']);
check('N6 «Aufträge» (auftraege-liste) is the FIRST entry of debtor\'s area «auftraege» (sort 0; debtor\'s entries 1–4: Rechnungen, Debitoren, Zahlungseingänge, Mahnungen), route backend/order/order/list',
    ($navByKey['auftraege-liste']['parent_key'] ?? null) === 'auftraege' && $navByKey['auftraege-liste']['sort_key'] === 0 && isset($debtorNav['auftraege'])
    && $route($navByKey['auftraege-liste']) === ['backend', 'order', 'order', 'list']
    && [$debtorNav['rechnungen']['sort_key'], $debtorNav['debitoren']['sort_key'], $debtorNav['zahlungseingaenge']['sort_key'], $debtorNav['mahnungen']['sort_key']] === [1, 2, 3, 4]);

// ── T. transitions and stock targets (offline) ───────────────────────────

echo "T. Phases, transitions and stock targets (offline)\n";
$rules = new OrderStatuses($seed);
check('T1 the phases carry the behaviour: reserve only in «confirmed», consume in «delivered» and «closed», book in «confirmed» / «delivered», locked «closed» / «cancelled», a quote only «new», the delivery note from «confirmed»',
    array_map(static fn(OrderPhase $p) => [$p->reservesStock(), $p->consumesStock(), $p->isBookable(), $p->isLocked(), $p->isQuote(), $p->allowsDeliveryNote()], OrderPhase::cases()) === [
        [false, false, false, false, true, false], [true, false, true, false, false, true], [false, true, true, false, false, true],
        [false, true, false, true, false, false], [false, false, false, true, false, false],
    ]
    && array_map(static fn(OrderPhase $p) => $p->rank(), OrderPhase::cases()) === [1, 2, 3, 4, 5]);
check('T2 initial = THE start status (also one in «Termin», owner 2026-10-16); without an active one, the first ACTIVE of «new» by position; none active → null',
    $rules->initial()?->getCode() === 'opened'
    && (new OrderStatuses(array_merge(array_map(static fn($s) => $s->getCode() === 'opened' ? status('opened', OrderPhase::New, true, false, 1) : $s, $seed), [status('enquiry', OrderPhase::New, position: 30), status('lead', OrderPhase::New, position: 20)])))->initial()?->getCode() === 'lead'
    && (new OrderStatuses([status('x', OrderPhase::New, true, false)]))->initial() === null
    && (new OrderStatuses(array_merge(array_map(static fn($s) => $s->getCode() === 'opened' ? status('opened', OrderPhase::New, false, true, 1) : $s, $seed), [$startIn('direct', OrderPhase::Confirmed, true, 99)])))->initial()?->getCode() === 'direct');
check('T3 the default per phase — what an action sets; startable = every active status of «new», «confirmed», «delivered»',
    array_map(static fn(OrderPhase $p) => $rules->defaultOf($p)?->getCode(), OrderPhase::cases()) === ['opened', 'scheduled', 'delivered', 'booked', 'cancelled']
    && array_map(static fn($s) => $s->getCode(), $rules->startable()) === ['opened', 'scheduled', 'delivered', 'to-invoice']);
check('T4 an action moves only FORWARD into its phase: a quote → «scheduled» / «delivered»; an order in or past the phase → null (a reprint)',
    $rules->actionTarget($byCode['opened'], OrderPhase::Confirmed)?->getCode() === 'scheduled' && $rules->actionTarget($byCode['opened'], OrderPhase::Delivered)?->getCode() === 'delivered'
    && $rules->actionTarget($byCode['scheduled'], OrderPhase::Confirmed) === null && $rules->actionTarget($byCode['to-invoice'], OrderPhase::Delivered) === null
    && $rules->actionTarget($byCode['booked'], OrderPhase::Delivered) === null);
$refused = static fn(string $from, string $to, bool $invoice): ?string => $rules->refusal($byCode[$from], $byCode[$to], $invoice)[0] ?? null;
check('T5 forward and lateral are free (opened → scheduled → delivered → to-invoice → done; booked → done with an invoice)', $refused('opened', 'scheduled', false) === null && $refused('scheduled', 'delivered', false) === null
    && $refused('delivered', 'to-invoice', false) === null && $refused('to-invoice', 'delivered', false) === null && $refused('to-invoice', 'done', false) === null && $refused('booked', 'done', true) === null);
check('T6 backward is allowed WITHOUT an invoice, barred WITH one', $refused('delivered', 'opened', false) === null && $refused('cancelled', 'scheduled', false) === null
    && $refused('done', 'scheduled', true) === OrderStatuses::BACKWARD_BARRED);
check('T7 the default of «closed» is reached by the booking print only — never a target', $refused('to-invoice', 'booked', false) === OrderStatuses::BOOKED_TARGET && $refused('done', 'booked', true) === OrderStatuses::BOOKED_TARGET);
check('T8 «cancelled» only without an invoice; a cancelled order may be reopened', $refused('scheduled', 'cancelled', false) === null && $refused('done', 'cancelled', true) === OrderStatuses::CANCEL_BARRED
    && $refused('cancelled', 'opened', false) === null);
check('T9 the same status and an inactive target are refused; an order may SIT in an inactive one',
    $refused('opened', 'opened', false) === OrderStatuses::SAME
    && $rules->refusal($byCode['opened'], status('gone', OrderPhase::Confirmed, active: false), false)[0] === OrderStatuses::INACTIVE_TARGET
    && $rules->refusal(status('gone', OrderPhase::Confirmed, active: false), $byCode['delivered'], false) === null);
check('T10 the targets offered from «done» with an invoice: lateral only, never booked, never cancelled; from «to-invoice» without one: every other but booked',
    array_map(static fn($s) => $s->getCode(), $rules->targets($byCode['done'], true)) === []
    && array_map(static fn($s) => $s->getCode(), $rules->targets($byCode['to-invoice'], false)) === ['opened', 'scheduled', 'delivered', 'done', 'cancelled']);

// An order in memory: an article line (2), a child article line (1) beneath it, a negative article line (-1),
// a lump-sum article line, a free line, a text line.
$contact = withId(new Contact(['kind' => 'organisation', 'company' => 'Muster AG']), 7);
$product = new Product('harness', new \DateTimeImmutable('2026-10-14'));
$variantA = withId(new Variant($product), 11);
$variantB = withId(new Variant($product), 12);
$order   = withId(new SalesOrder(42, $contact, 'scheduled', SalesOrder::SOURCE_BACKEND, null, 'harness', new \DateTimeImmutable('2026-10-14 10:00')), 5);
$order->describe($contact, 'Wein für das Fest', new \DateTimeImmutable('2026-10-14'), null, null, PriceMode::Net, 'CHF', 'lukb', null, null);
$chf     = static fn(string $d) => Money::fromDecimal($d, 'CHF');
$mk      = static function (int $id, int $pos, ?SalesOrderLine $parent, LineType $type, ?Variant $variant, string $text, ?string $qty, ?string $price, ?string $tax = 'UN', ?string $acc = '3400') use ($order, $chf): SalesOrderLine {
    $line = withId(new SalesOrderLine($order), $id);
    $line->write($pos, $parent, $type, $variant, $variant?->getNumber() ?? null, $text, $qty, $qty === null ? null : 'Fl.', $price === null ? null : $chf($price), 0, $tax, $acc);
    $order->addLine($line);

    return $line;
};
$l1 = $mk(101, 1, null, LineType::Service, $variantA, 'Pinot Noir 2022', '2', '24.50');
$l2 = $mk(102, 2, $l1, LineType::Service, $variantB, 'Geschenkkarton', '1', '0.00');
$l3 = $mk(103, 3, null, LineType::Service, $variantA, 'Rücknahme', '-1', '24.50');
$l4 = $mk(104, 4, null, LineType::LumpSum, $variantB, 'Versandpauschale', null, '12.00');
$l5 = $mk(105, 5, null, LineType::Service, null, 'Degustation', '1.5', '80.00');
$l6 = $mk(106, 6, null, LineType::Text, null, 'Danke!', null, null, null, null);
$shape  = static fn(array $targets): array => array_map(static fn($t) => [$t->lineRef, $t->variantId, $t->reserved, $t->consumed], $targets);
$expect = static fn(string $r1, string $c1, string $r2, string $c2, string $r3, string $c3, string $r4, string $c4): array => [['101', 11, $r1, $c1], ['102', 12, $r2, $c2], ['103', 11, $r3, $c3], ['104', 12, $r4, $c4]];
check('T11 phase «new»: every ARTICLE line (children included), all zero — free and text lines not sent',
    $shape(OrderStockTargets::of($order->getLines(), OrderPhase::New)) === $expect('0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000'));
check('T12 «confirmed» reserves the quantity; a negative quantity stays negative (an inflow); a lump-sum article = one unit',
    $shape(OrderStockTargets::of($order->getLines(), OrderPhase::Confirmed)) === $expect('2.000', '0.000', '1.000', '0.000', '-1.000', '0.000', '1.000', '0.000'));
check('T13 «delivered» and «closed» consume the quantity, the reservation goes to zero',
    $shape(OrderStockTargets::of($order->getLines(), OrderPhase::Delivered)) === $expect('0.000', '2.000', '0.000', '1.000', '0.000', '-1.000', '0.000', '1.000')
    && $shape(OrderStockTargets::of($order->getLines(), OrderPhase::Closed)) === $shape(OrderStockTargets::of($order->getLines(), OrderPhase::Delivered)));
check('T14 «cancelled»: everything back to zero (the ledger demands the reason — not the order)',
    $shape(OrderStockTargets::of($order->getLines(), OrderPhase::Cancelled)) === $expect('0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000'));
$unsaved = new SalesOrderLine($order);
$unsaved->write(7, null, LineType::Service, $variantA, '1', 'x', '1', null, $chf('1.00'), 0, 'UN', '3400');
check('T15 an article line without its id (not flushed) is refused — the line id IS the stock reference',
    (static function () use ($unsaved) { try { OrderStockTargets::of([$unsaved], OrderPhase::Confirmed); } catch (\LogicException) { return true; } return false; })());
$order->removeLine($unsaved);
check('T16 the line guards its shape: a child beneath a text line, a nested child, a rounding line, a text line with an article are refused',
    (static function () use ($order, $l6, $l2, $variantA, $chf) {
        $count = 0;
        foreach ([
            fn() => (new SalesOrderLine($order))->write(9, $l6, LineType::Service, null, null, 'x', '1', null, $chf('1.00'), 0, 'UN', '3400'),
            fn() => (new SalesOrderLine($order))->write(9, $l2, LineType::Service, null, null, 'x', '1', null, $chf('1.00'), 0, 'UN', '3400'),
            fn() => (new SalesOrderLine($order))->write(9, null, LineType::Rounding, null, null, 'x', null, null, $chf('0.05'), 0, null, '3400'),
            fn() => (new SalesOrderLine($order))->write(9, null, LineType::Text, $variantA, '1', 'x', null, null, null, 0, null, null),
        ] as $fn) {
            try { $fn(); } catch (\LogicException) { $count++; }
        }
        return $count === 4;
    })());

// ── D. the invoice drafted from the order (offline) ──────────────────────

echo "D. OrderInvoiceDraft mapping (offline)\n";
$draft = OrderInvoiceDraft::of($order, new \DateTimeImmutable('2026-10-31 15:30'));
check('D1 kind invoice, the contact, the invoice date (a day), the service date falls back to it, no address (debtor snapshots the invoice address), no terms (the profile\'s)',
    $draft->kind === InvoiceKind::Invoice && $draft->contactId === 7 && $draft->invoiceDate->format('Y-m-d H:i') === '2026-10-31 00:00'
    && $draft->serviceFrom?->format('Y-m-d') === '2026-10-31' && $draft->serviceTo === null && $draft->address === null && $draft->paymentTermsCode === null);
check('D2 price mode, currency, payment target and the subject «Auftrag 42 · Wein für das Fest» (D10); origin order / 42',
    $draft->priceMode === PriceMode::Net && $draft->currency === 'CHF' && $draft->paymentTargetCode === 'lukb'
    && $draft->subject === 'Auftrag 42 · Wein für das Fest' && $draft->sourceType === 'order' && $draft->sourceRef === '42');
check('D3 the top-level lines 1:1 in document order, the child BENEATH its parent',
    array_map(static fn($l) => $l->text, $draft->lines) === ['Pinot Noir 2022', 'Rücknahme', 'Versandpauschale', 'Degustation', 'Danke!']
    && count($draft->lines[0]->children) === 1 && $draft->lines[0]->children[0]->text === 'Geschenkkarton');
$first = $draft->lines[0];
check('D4 a line carries type, quantity, unit, price, discount, tax code, account and its origin order-line / id',
    $first->type === LineType::Service && $first->quantity === '2' && $first->unit === 'Fl.' && $first->unitPrice?->toDecimal() === '24.50'
    && $first->discountPercent === 0 && $first->taxCode === 'UN' && $first->revenueAccount === '3400' && $first->sourceType === 'order-line' && $first->sourceRef === '101');
check('D5 a lump sum without quantity, a negative quantity kept, a text line without price / tax / account',
    $draft->lines[2]->type === LineType::LumpSum && $draft->lines[2]->quantity === null && $draft->lines[1]->quantity === '-1'
    && $draft->lines[4]->type === LineType::Text && $draft->lines[4]->unitPrice === null && $draft->lines[4]->taxCode === null && $draft->lines[4]->revenueAccount === null);
$billTo = new \Z77\Module\Debtor\Entities\AddressSnapshot('', '', '', 'Muster AG Buchhaltung', '', 'Postfach', '220', '8712', 'Stäfa', 'CH');
$order->describe($contact, 'Wein für das Fest', new \DateTimeImmutable('2026-10-14'), null, null, PriceMode::Net, 'CHF', 'lukb', null, null, $billTo);
check('D5b a billing snapshot on the order (bill_*, 2026-10-17) is the booked invoice address; none → null (debtor takes the customer address)',
    $order->billingAddress() === $billTo && OrderInvoiceDraft::of($order, new \DateTimeImmutable('2026-10-31'))->address === $billTo);
$order->describe($contact, 'Wein für das Fest', new \DateTimeImmutable('2026-10-14'), null, null, PriceMode::Net, 'CHF', 'lukb', null, null, null, 'disc-2-10');
check('D5c the order\'s payment terms (2026-10-18) are the booked invoice\'s; none (D1) → null = the debtor profile\'s',
    $order->getPaymentTermsCode() === 'disc-2-10' && OrderInvoiceDraft::of($order, new \DateTimeImmutable('2026-10-31'))->paymentTermsCode === 'disc-2-10'
    && $order->getPaymentTargetCode() === 'lukb');
$order->describe($contact, '  ', new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-10'), PriceMode::Gross, 'CHF', null, null, null);
$draft2 = OrderInvoiceDraft::of($order, new \DateTimeImmutable('2026-10-31'));
check('D6 without a Betreff the subject is «Auftrag 42»; the head\'s service period wins; gross; no payment target',
    $draft2->subject === 'Auftrag 42' && $draft2->serviceFrom?->format('Y-m-d') === '2026-10-01' && $draft2->serviceTo?->format('Y-m-d') === '2026-10-10'
    && $draft2->priceMode === PriceMode::Gross && $draft2->paymentTargetCode === null);

// ── V. the list's view tabs (offline) ────────────────────────────────────

echo "V. List tabs are the phases (offline)\n";
$viewOfSeed = array_combine(array_keys($byCode), array_map(static fn($s) => OrderListing::viewOf($s), array_values($byCode)));
check('V1 each seed status falls into the tab of its PHASE: opened → Eröffnet; scheduled → Termin; delivered, to-invoice → Geliefert; booked, done → Abgeschlossen; cancelled → Storniert',
    $viewOfSeed === ['opened' => 'new', 'scheduled' => 'confirmed', 'delivered' => 'delivered', 'to-invoice' => 'delivered', 'booked' => 'closed', 'done' => 'closed', 'cancelled' => 'cancelled']);
check('V2 a NEW status lands in its tab without code (by phase), inactive ones still count; «Alle» filters nothing',
    OrderListing::viewOf(status('pickup', OrderPhase::Delivered)) === 'delivered'
    && OrderListing::codesOf('confirmed', array_merge($seed, [status('waiting', OrderPhase::Confirmed, active: false)])) === ['scheduled', 'waiting']
    && OrderListing::codesOf('all', $seed) === null && OrderListing::codesOf('closed', $seed) === ['booked', 'done']);
check('V3 «Status» searches the labels (case-insensitive) within the tab — «Alle» too; no match = nothing',
    OrderListing::codesOf('all', $seed, 'FAKT') === ['to-invoice'] && OrderListing::codesOf('delivered', $seed, 'geliefert') === ['delivered']
    && OrderListing::codesOf('closed', $seed, 'termin') === []);
$cols = static fn(string $view): array => array_map(static fn($c) => $c->label !== '' ? $c->label : '[' . $c->ariaLabel . ']', OrderListing::definition('CHF', $view)->columns());
check('V5 the columns: the selection checkbox in the bookable tabs («Termin», «Geliefert»), «Zahlung» only in «Abgeschlossen», «Status» searchable; the default tab is «Termin», the second — «Eröffnet» stays first',
    $cols('confirmed')[0] === '[Auswahl]' && $cols('delivered')[0] === '[Auswahl]' && !in_array('[Auswahl]', $cols('new'), true) && !in_array('[Auswahl]', $cols('closed'), true)
    && in_array('Zahlung', $cols('closed'), true) && !in_array('Zahlung', $cols('all'), true)
    && OrderListing::definition('CHF')->column('f_status')->isSearchable()
    && OrderListing::definition('CHF')->extraDefault('view') === 'confirmed' && OrderListing::definition('CHF')->read([])->extra('view') === 'confirmed'
    && OrderListing::definition('CHF')->read(['view' => 'nonsense'])->extra('view') === 'confirmed'
    && array_keys(OrderListing::VIEWS) === ['new', 'confirmed', 'delivered', 'closed', 'cancelled', 'all']);
$svc = OrderListing::search(OrderListing::definition('CHF', 'confirmed')->read(['view' => 'confirmed', 'f_service' => '11.2026', 'sort' => 'service']), $seed);
check('V6 «Leistung» (= «Leistung von», the delivery date of goods, owner 2026-10-16): searchable and sortable; a month searches the order day or period overlapping it, in the tab\'s codes',
    OrderListing::definition('CHF')->column('f_service')->isSearchable() && OrderListing::definition('CHF')->column('f_service')->sort === 'service'
    && $svc->serviceFrom === '2026-11-01' && $svc->serviceTo === '2026-11-30' && $svc->sort === 'service' && $svc->statusCodes === ['scheduled']);

// ── I. the booking print's selection and its idempotency (offline) ───────

echo "I. Booking selection and the repeated submit (offline)\n";
check('I1 pairs: `{id}:{version}` read, unique by id (the last wins), anything else dropped, at most 200',
    BookingSelection::pairs(['7:5', 'x', '8:1', '7:6', ['9:1'], '10:-1']) === [['id' => 7, 'version' => 6], ['id' => 8, 'version' => 1]]
    && BookingSelection::pairs('7:5') === [] && count(BookingSelection::pairs(array_map(static fn($i) => $i . ':1', range(1, 250)))) === 200);
$finalInvoice = withId(new \Z77\Module\Debtor\Entities\Invoice(InvoiceKind::Invoice, $contact, null, 'harness', new \DateTimeImmutable('2026-10-14')), 80);
(new \ReflectionProperty($finalInvoice, 'state'))->setValue($finalInvoice, \Z77\Module\Debtor\Entities\InvoiceState::Final->value);
$draftInvoice = withId(new \Z77\Module\Debtor\Entities\Invoice(InvoiceKind::Invoice, $contact, null, 'harness', new \DateTimeImmutable('2026-10-14')), 81);
$bookedOrder = withId(new SalesOrder(1, $contact, 'booked', SalesOrder::SOURCE_BACKEND, null, 'harness', new \DateTimeImmutable('2026-10-14')), 7);
$bookedOrder->linkInvoice($finalInvoice);
(new \ReflectionProperty($bookedOrder, 'version'))->setValue($bookedOrder, 6);
$openOrder = withId(new SalesOrder(2, $contact, 'to-invoice', SalesOrder::SOURCE_BACKEND, null, 'harness', new \DateTimeImmutable('2026-10-14')), 8);
(new \ReflectionProperty($openOrder, 'version'))->setValue($openOrder, 6);
$finder = static fn(array $orders): \Closure => static fn(int $id): ?SalesOrder => $orders[$id] ?? null;
check('I2 a repeat: every order linked to a FINAL invoice at the version it showed + 1 → the invoices to reprint, nothing booked',
    BookingSelection::bookedBy([['id' => 7, 'version' => 5]], $finder([7 => $bookedOrder])) === [80]);
check('I3 not a repeat: another version (edited since, booked by another print), an order without invoice, a gone order, an empty selection',
    BookingSelection::bookedBy([['id' => 7, 'version' => 4]], $finder([7 => $bookedOrder])) === null
    && BookingSelection::bookedBy([['id' => 7, 'version' => 5], ['id' => 8, 'version' => 5]], $finder([7 => $bookedOrder, 8 => $openOrder])) === null
    && BookingSelection::bookedBy([['id' => 9, 'version' => 1]], $finder([])) === null && BookingSelection::bookedBy([], $finder([])) === null);
$bookedOrder->linkInvoice($draftInvoice);
check('I4 an invoice that is not final is no repeat either', BookingSelection::bookedBy([['id' => 7, 'version' => 5]], $finder([7 => $bookedOrder])) === null);

// ── F. the window's form ↔ the draft (offline) ───────────────────────────

echo "F. OrderForm ↔ OrderDraft (offline)\n";
$form = (new OrderForm())->fromPost([
    'contact' => '6', 'subject' => ' Einführung ', 'order_date' => '2026-10-14', 'price_mode' => 'net', 'target' => 'lukb', 'ship' => 'a3', 'status' => 'opened',
    'rows' => [
        ['type' => 'service', 'article' => '1001', 'quantity' => '2', 'id' => '15'],
        ['type' => 'service', 'child' => '1', 'text' => 'Unterlagen', 'quantity' => '1', 'price' => '0', 'tax_code' => 'UN', 'account' => '3400'],
        ['type' => 'bogus', 'text' => '', 'price' => '', 'quantity' => '', 'article' => ''],
        ['type' => 'text', 'text' => 'Danke', 'id' => 'x1'],
        ['type' => 'service', 'article' => '1001', 'text' => 'mit Code', 'quantity' => '1', 'tax_code' => 'UR', 'account' => '3000'],
    ],
]);
$ship = new \Z77\Module\Debtor\Entities\AddressSnapshot('', '', 'Anna', 'Muster', '', 'Weg', '1', '4051', 'Basel', 'CH');
$fdPosted = $form->toDraft(['1001' => 11], ['' => null, 'a3' => $ship], 'harness');
$fd   = $form->withTarget('lukb')->toDraft(['1001' => 11], ['' => null, 'a3' => $ship], 'harness');
check('F1 the head: contact id, subject (the service trims), dates, the target the SERVER sets (a posted `target` is ignored since 2026-10-18), the chosen delivery address as snapshot, the start status',
    $fdPosted !== null && $fdPosted->paymentTargetCode === null && $fd !== null && $fd->contactId === 6 && $fd->orderDate === '2026-10-14' && $fd->paymentTargetCode === 'lukb' && $fd->deliveryAddress === $ship && $fd->statusCode === 'opened' && $fd->sourceRef === 'harness');
$fb = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'bill' => 'a3', 'rows' => []])->toDraft([], ['' => null], 'harness', ['' => null, 'a3' => $ship]);
$fbBad = new OrderForm();
$fbBad->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'bill' => 'a9', 'rows' => []]);
check('F1b the billing address (2026-10-17): the chosen one as snapshot; one the customer does not have is refused on its field',
    $fb !== null && $fb->billingAddress === $ship && $fb->deliveryAddress === null
    && $fbBad->toDraft([], ['' => null], null, ['' => null]) === null && $fbBad->error('bill') !== '' && in_array('order-bill', $fbBad->invalidIds(), true));
$fa = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'rows' => []])->adjust('ship', $ship);
$faPost = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'ship' => 'custom', 'ship_addr_name' => ' Lager Ost ', 'ship_addr_zip' => '8001', 'ship_addr_city' => 'Zürich', 'ship_addr_country' => 'ch', 'bill_addr_name' => 'ignored', 'rows' => []]);
$faDraft = $faPost->toDraft([], ['' => null], null, ['' => null]);
check('F1c «anpassen» (2026-10-18): the card switches to «custom», filled with the shown address; posted fields become THIS order\'s snapshot (trimmed); a card not on «custom» ignores its fields',
    $fa->header('ship') === OrderForm::ADDRESS_CUSTOM && $fa->customAddress('ship')->lines() === $ship->lines()
    && (new OrderForm())->adjust('bill', null)->customAddress('bill')->getCountry() === 'CH'
    && $faDraft !== null && $faDraft->deliveryAddress?->getName() === 'Lager Ost' && $faDraft->deliveryAddress->getCity() === 'Zürich'
    && $faDraft->deliveryAddress->getCountry() === 'CH' && $faDraft->billingAddress === null);
$ft = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'terms' => ' disc-2-10 ', 'rows' => []])->withTarget('lukb')->toDraft([], ['' => null]);
$ftEmpty = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'terms' => '', 'rows' => []])->toDraft([], ['' => null]);
$ftBlank = (new OrderForm())->startBlank(new \DateTimeImmutable('2026-10-18'), 6, 'lukb', 'opened', 'net-30');
check('F1d «Zahlungskonditionen» (2026-10-18): the posted code (trimmed) → paymentTermsCode beside the target; «wie beim Kunden» \'\' → null; startBlank() holds the preselected terms; FD and F1 carry none',
    $ft !== null && $ft->paymentTermsCode === 'disc-2-10' && $ft->paymentTargetCode === 'lukb' && $ftEmpty !== null && $ftEmpty->paymentTermsCode === null
    && $ftBlank->header('terms') === 'net-30' && $ftBlank->target() === 'lukb'
    && (new OrderForm())->startBlank(new \DateTimeImmutable('2026-10-18'), null, '', 'opened')->header('terms') === '' && $fd->paymentTermsCode === null);
check('F2 the lines: the article number → its variant id, the existing line keeps its id, a «unter» row beneath its parent, the empty row left out, a bogus id dropped',
    count($fd->lines) === 3 && $fd->lines[0]->variantId === 11 && $fd->lines[0]->id === 15 && count($fd->lines[0]->children) === 1
    && $fd->lines[0]->children[0]->text === 'Unterlagen' && $fd->lines[1]->type === 'text' && $fd->lines[1]->id === null);
$lump = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'rows' => [['type' => 'lump-sum', 'article' => '1001', 'quantity' => '1']]])->toDraft(['1001' => 11], ['' => null]);
check('F3 the order knows «Leistung» and «Text» only (owner 2026-10-18): a posted «lump-sum» reads as service; a row carries no tax code or account to the draft — the article (or a kept free line) decides (D3)',
    OrderForm::TYPES === ['service' => 'Leistung', 'text' => 'Text'] && $lump !== null && $lump->lines[0]->type === 'service'
    && !property_exists(\Z77\Module\Order\Services\OrderLineDraft::class, 'taxCode') && !property_exists(\Z77\Module\Order\Services\OrderLineDraft::class, 'revenueAccount'));
$form->applyErrors(['contact_id' => 'Kunde wählen.', 'lines.0.0.unit_price' => 'Preis fehlt.', 'lines.2.variant' => 'inaktiv', 'payment_target_code' => 'weg', 'payment_terms_code' => 'unbekannt', 'lines.9.text' => 'verirrt']);
check('F4 the service\'s field errors land on the form: the head by name (the terms on `terms` / #order-terms; the target — no field since 2026-10-18 — a general message), `lines.{path}.{field}` on the row the line came from',
    $form->error('contact') === 'Kunde wählen.' && $form->rowError(1, 'price') === 'Preis fehlt.' && $form->rowError(4, 'article') === 'inaktiv'
    && $form->error('target') === '' && $form->error('terms') === 'unbekannt' && $form->generalErrors() === ['weg', 'verirrt'] && $form->invalidIds()[0] === 'invoice-contact-name'   // the customer field's text (debtor's CustomerPicker, 2026-10-15)
    && $form->invalidIds()[1] === 'order-terms' && !in_array('order-target', $form->invalidIds(), true));
$bad = (new OrderForm())->fromPost(['contact' => '6', 'ship' => 'a99', 'rows' => [['article' => '9999', 'quantity' => '1'], ['child' => '1', 'text' => 'x', 'quantity' => '1', 'price' => '1']]]);
check('F5 what the form cannot read: an unknown article, a delivery address that is not the customer\'s, a «unter» row without a parent above',
    $bad->toDraft(['1001' => 11], ['' => null]) === null && $bad->rowError(0, 'article') !== '' && $bad->error('ship') !== '');
$bad2 = (new OrderForm())->fromPost(['contact' => '6', 'rows' => [['child' => '1', 'text' => 'x', 'quantity' => '1', 'price' => '1']]]);
check('F6 … a child as the first row is refused on its row', $bad2->toDraft([], ['' => null]) === null && $bad2->rowError(0, 'child') !== '');
$fresh = (new OrderForm())->startFrom($order);
check('F7 startFrom(): one row per line in position order with its id, the indent flag on the child, no empty row (the adders add); an article line shows no tax code, a kept free line its own (display only); a stored LUMP SUM shows as «Leistung» × 1',
    count($fresh->rows()) === 6 && $fresh->rows()[0]['id'] === '101' && $fresh->rows()[1]['child'] === '1' && $fresh->rows()[0]['tax_code'] === ''
    && $fresh->rows()[4]['tax_code'] === 'UN' && $fresh->rows()[0]['quantity'] === '2'
    && $fresh->rows()[3]['type'] === 'service' && $fresh->rows()[3]['quantity'] === '1' && $fresh->rows()[3]['id'] === '104'
    && !array_key_exists('account', $fresh->rows()[0]));
$termsOrder = withId(new SalesOrder(43, $contact, 'opened', SalesOrder::SOURCE_BACKEND, null, 'harness', new \DateTimeImmutable('2026-10-18 10:00')), 6);
$termsOrder->describe($contact, '', new \DateTimeImmutable('2026-10-18'), null, null, PriceMode::Net, 'CHF', null, null, null, null, 'disc-2-10');
check('F7b startFrom() reads the order\'s payment terms; an order without (existing rows: NULL) shows «wie beim Kunden» \'\'',
    (new OrderForm())->startFrom($termsOrder)->header('terms') === 'disc-2-10' && $fresh->header('terms') === '');
$keptTarget = (new OrderForm())->startFrom($order);
check('F7c an existing order keeps its stored payment target (no field in the window); startFrom() reads it, toDraft() sends it',
    (new OrderForm())->startFrom($termsOrder)->target() === '' && $keptTarget->withTarget('lukb')->toDraft(['W-1' => 11, 'W-2' => 12], ['' => null, OrderForm::SHIP_KEEP => null])?->paymentTargetCode === 'lukb');
// The rows as a sort order (owner 2026-10-18): the posted order is the order; the row submits without script.
$sorted = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-14', 'rows' => [
    3 => ['type' => 'text', 'text' => 'zuerst'],
    0 => ['type' => 'service', 'article' => '1001', 'quantity' => '1', 'id' => '15'],
    7 => ['type' => 'text', 'text' => 'darunter', 'child' => '1'],
]]);
$sortedDraft = $sorted->toDraft(['1001' => 11], ['' => null]);
check('F8 rows come back in POSTED order (core.js «rows» renumbers the names; the keys do not decide); an indented row hangs beneath the nearest row above that is not',
    $sortedDraft !== null && count($sortedDraft->lines) === 2 && $sortedDraft->lines[0]->text === 'zuerst' && $sortedDraft->lines[1]->id === 15
    && $sortedDraft->lines[1]->children[0]->text === 'darunter');
$ops = (new OrderForm())->fromPost(['contact' => '6', 'rows' => [['type' => 'service', 'article' => '1001'], ['type' => 'text', 'text' => 'weg'], ['type' => 'text', 'text' => 'bleibt']]]);
$opsOk = $ops->applyOp('add-article') && $ops->applyOp('add-text') && $ops->applyOp('remove:1') && !$ops->applyOp('save') && !$ops->applyOp('remove:x');
check('F9 the row submits without script: «+ Artikel» / «+ Textzeile» append an empty row of that kind, «remove:{i}» drops the posted row i (the rest closes up); any other op is no row op',
    $opsOk && array_column($ops->rows(), 'type') === ['service', 'text', 'service', 'text'] && $ops->rows()[1]['text'] === 'bleibt'
    && $ops->rows()[2]['article'] === '' && count($blankRows = (new OrderForm())->startBlank(new \DateTimeImmutable('2026-10-18'), null, '', 'opened')->rows()) === 1
    && $blankRows[0]['type'] === 'service' && $blankRows[0]['article'] === '');
// The price mode (owner 2026-10-18): the debtor's starts a new order; `prices_mode` = the mode the prices are in.
$grossStart = (new OrderForm())->startBlank(new \DateTimeImmutable('2026-10-18'), 6, '', 'opened', '', 'gross');
$switched   = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-18', 'price_mode' => 'gross', 'prices_mode' => 'net', 'rows' => [['article' => '1001', 'quantity' => '1', 'price' => '15.40']]]);
$switchedDraft = $switched->toDraft(['1001' => 11], ['' => null]);
$noShown    = (new OrderForm())->fromPost(['contact' => '6', 'order_date' => '2026-10-18', 'price_mode' => 'gross', 'rows' => []])->toDraft([], ['' => null]);
check('F10 price mode: startBlank() takes the debtor\'s (gross → price_mode and prices_mode gross, anything else net); startFrom() renders prices_mode = the order\'s mode; a posted prices_mode travels as OrderDraft::$pricesMode and is ECHOED on a re-render (never set to the new mode by the server); none posted → \'\' (the service reads it as price_mode)',
    $grossStart->header('price_mode') === 'gross' && $grossStart->header('prices_mode') === 'gross'
    && (new OrderForm())->startBlank(new \DateTimeImmutable('2026-10-18'), null, '', 'opened', '', 'brutto')->header('price_mode') === 'net'
    && (new OrderForm())->startFrom($termsOrder)->header('prices_mode') === 'net'
    && $switchedDraft !== null && $switchedDraft->priceMode === 'gross' && $switchedDraft->pricesMode === 'net' && $switched->header('prices_mode') === 'net'
    && $noShown !== null && $noShown->pricesMode === '');
$orderTpl   = file_get_contents($root . '/packages/module-order/res/view/templates/Backend/OrderController/edit.tpl.php');
$orderTrait = file_get_contents($root . '/packages/module-order/src/Ui/OrderControllerTrait.php');
$pickTrait  = file_get_contents($root . '/packages/module-debtor/src/Ui/CustomerPickerTrait.php');
$coreJs     = file_get_contents($root . '/packages/kernel/shared/res/assets/js/core.js');
check('F11 the window wires core.js «price mode»: the positions block is the scope, «Preise» its select, the hidden `prices_mode` its shown mode, each row\'s price and rate; the article answer carries `prices` (asked with prices=1); a customer choice sets `price_mode` from the debtor (`set-value`, debtor\'s shared answer); core.js has both',
    str_contains($orderTpl, 'data-sum data-price-mode>') && str_contains($orderTpl, 'data-price-mode-select') && str_contains($orderTpl, 'name="prices_mode" value="<?= e($form->header(\'prices_mode\')) ?>" data-price-mode-shown')
    && str_contains($orderTpl, 'data-price-mode-price') && str_contains($orderTpl, 'data-price-mode-rate')
    && str_contains($orderTrait, "getGetParameter('prices') === '1'") && str_contains($orderTrait, "['prices' => \$defaults['prices']]")
    && str_contains($pickTrait, "'set-value', ['target' => 'select[name=\"price_mode\"]'")
    && str_contains($coreJs, "registerCommand('set-value'") && str_contains($coreJs, '_Z77.core.priceMode.bind()') && str_contains($coreJs, "ask(source, { prices: '1' })"));
$orderSvcSrc = file_get_contents($root . '/packages/module-order/src/Services/OrderService.php');
check('F12 no document for a contact without a debtor profile (owner 2026-10-18; in the SERVICE since 2026-10-19 — M27): OrderService refuses a NEW customer without a profile on `contact_id` with debtor\'s sentence (DebtorProfileService::notDebtor), the window keeps no check of its own (no double message); «+ Kontakt» answers «Debitor anlegen» (customerDebtorPage), the picker asks it for a contact without a profile',
    str_contains($orderSvcSrc, "\$errors['contact_id'] = DebtorProfileService::notDebtor(\$contact);")
    && !str_contains($orderTrait, 'debtorRefusal(') && !str_contains($orderTrait, 'orderNotDebtor(') && str_contains($orderTrait, 'return $this->customerDebtorPage($contact')
    && !str_contains($orderTrait, 'Debitor wird er mit dem Buchungsdruck')
    && str_contains($pickTrait, 'if ($profile === null) {' . "\n" . '                return $this->customerDebtorPage(') && str_contains($pickTrait, '->open($contact, $terms, $mode)'));
check('F13 «Artikel wählen …» (owner 2026-10-19): the order mounts article\'s picker (the price from OrderService::articlePriceAt(), the context = the fill\'s fields); a row\'s magnifier opens it for THAT row (target = the row\'s article id, a new tab without the script), «+ Artikel» opens it for a new row and stays the no-script submit; the answer: set-value on the row + focus on its quantity, else add-row from the article template filled through the article field',
    str_contains($orderTrait, 'use ArticlePickerTrait;') && str_contains($orderTrait, "return ['order_date', 'service_from', 'price_mode'];")
    && str_contains($orderTrait, '$service->articlePriceAt($variant,')
    && str_contains($orderTpl, "\\Z77\\Module\\Article\\Ui\\ArticlePicker::url(\$pickUrl, ['target' => \$rid . 'article'] + \$pickState)")
    && str_contains($orderTpl, 'target="_blank" rel="noopener" data-window-open="<?= e($pick) ?>" data-window-with="<?= e($pickWith) ?>"')
    && str_contains($orderTpl, "\$pickWith  = 'order_date service_from price_mode';")
    && str_contains($orderTpl, '<span class="be-list__cell be-rows__article"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>article" name="<?= $p ?>[article]"')
    && str_contains($orderTpl, 'name="op" value="add-article" formnovalidate data-window-open=') && !str_contains($orderTpl, 'data-row-add="article"')
    && str_contains($orderTpl, 'data-row-template="article"') && str_contains($orderTpl, 'data-fill-url="<?= e($actionBase . \'/article-defaults\') ?>"')
    && preg_match("#'set-value', \['target' => '\#' \. \\\$target#", $orderTrait) === 1 && str_contains($orderTrait, "'focus', ['target' => '#order-row-' . \$m[1] . '-quantity'")
    && str_contains($orderTrait, "'add-row', [") && str_contains($orderTrait, "'values' => ['[data-fill-url]' => \$variant->getNumber()]")
    && str_contains($coreJs, "registerCommand('add-row'") && str_contains($coreJs, "registerCommand('focus'") && str_contains($coreJs, "'data-window-with'"));
check('F14 the positions\' sticky top (owner 2026-10-20): the bar — title, «+ Artikel» / «+ Textzeile», «Preise» — and the column head in ONE `.be-rows__top` inside the rows grid, in a frame without a scrollport; no adders below the table',
    preg_match('#<div class="be-list__frame be-list__frame--stick">\s*<div class="be-list__table be-rows"[^
]*data-rows="rows">\s*<div class="be-rows__top">\s*<div class="be-form__block-head be-rows__bar">\s*<h3 class="be-list__section-title">Positionen</h3>#', $orderTpl) === 1
    && strpos($orderTpl, 'value="add-text"') < strpos($orderTpl, 'id="order-price_mode"')
    && strpos($orderTpl, 'id="order-price_mode"') < strpos($orderTpl, '<span class="be-list__col be-list__col--num">Pos.</span>')
    && substr_count($orderTpl, 'class="be-rows__adders"') === 1);
check('F15 no article <datalist> (owner 2026-10-20: the picker searches; the browser filtered the list to the typed value) — the number is typed or picked, «fill» completes the row',
    !str_contains($orderTpl, '<datalist') && !str_contains($orderTpl, 'list="order-articles-') && str_contains($orderTpl, 'data-fill-url="<?= e($actionBase . \'/article-defaults\') ?>"'));

// ── P. the order's PDFs (offline where possible) ─────────────────────────

echo "P. Order PDFs (offline)\n";
check('P1 the title by PHASE: «Offerte» for a status of phase «new», else «Auftragsbestätigung» (also without a status row)',
    OrderPdf::orderTitle($byCode['opened']) === 'Offerte' && OrderPdf::orderTitle(status('enquiry', OrderPhase::New)) === 'Offerte'
    && OrderPdf::orderTitle($byCode['scheduled']) === 'Auftragsbestätigung' && OrderPdf::orderTitle(null) === 'Auftragsbestätigung');
check('P2 the file names are kebab-case lower (Rule 6)', OrderPdf::fileName('Auftragsbestätigung 12') === 'auftragsbestaetigung-12.pdf' && OrderPdf::fileName('Lieferschein 3') === 'lieferschein-3.pdf');
$pdfBase = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-order-pdf-' . getmypid();
@mkdir($pdfBase . '/config/vendor', 0777, true);
@mkdir($pdfBase . '/var/cache', 0777, true);
$pkg = $root . '/packages';
file_put_contents($pdfBase . '/config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$pkg}/kernel/shared']],\n'Z77\\\\Module\\\\Mandator\\\\' => ['sourcePaths' => ['{$pkg}/module-mandator']],\n'Z77\\\\Module\\\\Order\\\\' => ['sourcePaths' => ['{$pkg}/module-order']],\n]];");
if (!defined('ABS_BASE_PATH')) {
    define('ABS_BASE_PATH', $pdfBase);
}
if (!defined('DEBUG')) {
    define('DEBUG', false);
}
\Z77\Core\DI::getInstance(true)
    ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
    ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true);
\Z77\Core\DI::getCacheManager()->setCacheDir($pdfBase . '/var/cache');
$note = new SalesOrder(42, $contact, 'scheduled', SalesOrder::SOURCE_BACKEND, null, 'harness', new \DateTimeImmutable('2026-10-14'));
$note->describe($contact, 'Wein fuer das Fest', new \DateTimeImmutable('2026-10-14'), null, null, PriceMode::Net, 'CHF', null, null, null);
$variantA->identify('PN22', 'Fl.');
$nl = withId(new SalesOrderLine($note), 201);
$nl->write(1, null, LineType::Service, $variantA, 'PN22', 'Pinot Noir 2022', '6', 'Fl.', $chf('24.50'), 0, 'UN', '3400');
$note->addLine($nl);
$nt = withId(new SalesOrderLine($note), 202);
$nt->write(2, null, LineType::Text, null, null, 'Bitte kuehl lagern', null, null, null, 0, null, null);
$note->addLine($nt);
try {
    $bytes = \Z77\Shared\Pdf\PdfDocument::create('Lieferschein 42')->withoutCompression()->partial('pdf/deliveryNote', [
        'order' => $note, 'title' => 'Lieferschein 42', 'address' => $ship, 'mandator' => null, 'customerNumber' => 1000, 'today' => new \DateTimeImmutable('2026-10-20'),
    ], 'Z77\\Module\\Order')->output();
} catch (\Throwable $e) {
    $bytes = 'ERROR ' . $e->getMessage();
}
check('P3 pdf/deliveryNote draws offline: title, the delivery address in the window, article number, quantity and unit, the text line, the signature line',
    str_starts_with($bytes, '%PDF-') && str_contains($bytes, '(Lieferschein 42)') && str_contains($bytes, '(Anna Muster)')
    && str_contains($bytes, '(PN22)') && str_contains($bytes, '(Pinot Noir 2022)') && str_contains($bytes, '(Bitte kuehl lagern)') && str_contains($bytes, 'Ware erhalten'));
check('P4 … and NO price: neither the unit price nor a total appears on the delivery note', !str_contains($bytes, '24.50') && !str_contains($bytes, '147.00'));

// ── C. the year-close warning (offline) ──────────────────────────────────

echo "C. InvoiceableOrdersCheck (offline)\n";
$msg = InvoiceableOrdersCheck::message(['id' => 7, 'number' => 1, 'order_date' => '2026-10-14', 'status_code' => 'to-invoice', 'kind' => 'organisation', 'company' => 'Test-Firma xy', 'first_name' => 'A', 'last_name' => 'B'], ['to-invoice' => 'Zu fakturieren']);
check('C1 the warning names the order, its date, the customer and the status label', str_contains($msg, 'Auftrag 1 vom 14.10.2026 an Test-Firma xy steht in «Zu fakturieren»'));
check('C2 registered under the open-work scope period-close in orderConfig, as a class name',
    (require $root . '/packages/module-order/src/App/Config/orderConfig.inc.php')['openWorkChecks']['period-close'] === [InvoiceableOrdersCheck::class]);

// ── H. the list's help (offline) ─────────────────────────────────────────

echo "H. The order list's help in the toolbar (offline, HELP-003)\n";
$orderTrait = (string) file_get_contents($root . '/packages/module-order/src/Ui/OrderControllerTrait.php');
$listSrc    = preg_match('/protected function listAction\(\): HtmlResponse\s*\{(.*?)\n    \}\n/s', $orderTrait, $mL) === 1 ? $mL[1] : '';
check('H1 the list attaches its help with `toolbar: true` («Hilfe» at the right end of the toolbar, none in the top bar) — BEFORE html() (which renders it), and only for the page: a FETCH of the region carries none',
    preg_match('/if \(!\$this->orderIsFetch\(\)\) \{\s*\$this->help->attach\(\'Backend\/OrderController\/list\.help\', self::ORDER_NS, \[\], \'Hilfe: Aufträge\', toolbar: true\);/', $listSrc) === 1
    && strpos($listSrc, "->help->attach(") < strpos($listSrc, '$this->html('));
$listHelpFile = $root . '/packages/module-order/res/view/templates/Backend/OrderController/list.help.tpl.php';
$listHelp     = is_file($listHelpFile) ? (static function (string $f): string { ob_start(); require $f; return (string) ob_get_clean(); })($listHelpFile) : '';
check('H2 the list help is short and says what the code does: the six tabs by their labels (OrderListing::VIEWS), «+ Neuer Auftrag», «Buchungsdruck …» only in «Termin» and «Geliefert», the search; no field sections (the list has no form field worth one)',
    $listHelp !== '' && array_filter(OrderListing::VIEWS, static fn(string $l) => !str_contains($listHelp, '<strong>' . $l . '</strong>')) === []
    && str_contains($listHelp, '+ Neuer Auftrag') && str_contains($listHelp, 'Buchungsdruck …') && str_contains($listHelp, 'nur in <strong>Termin</strong> und <strong>Geliefert</strong>')
    && !str_contains($listHelp, 'data-help-field') && strlen(strip_tags($listHelp)) < 3000
    && OrderListing::isSelectable('confirmed') && OrderListing::isSelectable('delivered') && !OrderListing::isSelectable('new'));

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

// Section P already fixed the constant (a constant is set once per process): M works in THAT
// directory and writes its own config over P's; P's cache goes first (it holds P's FileFinder map).
if (!defined('ABS_BASE_PATH')) {
    define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-order-' . getmypid());
}
if (!defined('DEBUG')) {
    define('DEBUG', false);
}
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
$rm($base . '/var/cache');
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

// The REAL packages. module-financial is NOT registered: the account check is soft, and the
// preview (D11) posts nothing — the booking print (step 9) is where financial is needed.
$packages = [
    'Vat'      => $root . '/packages/module-vat',
    'Contact'  => $root . '/packages/module-contact',
    'Mandator' => $root . '/packages/module-mandator',
    'Debtor'   => $root . '/packages/module-debtor',
    'Article'  => $root . '/packages/module-article',
    'Order'    => $root . '/packages/module-order',
];
$namespaces = '';
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'order', 'modules' => ['vat' => [], 'contact' => [], 'mandator' => [], 'debtor' => [], 'article' => [], 'order' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// Seed the way the installer does: `*.default.json` → `data/…` with the marker stripped.
foreach (['Vat' => 'vat', 'Contact' => 'contact', 'Debtor' => 'debtor', 'Order' => 'order'] as $name => $dir) {
    @mkdir($base . '/data/framework/' . $dir, 0777, true);
    foreach (glob($packages[$name] . '/data/framework/' . $dir . '/*.default.json') as $seedPath) {
        copy($seedPath, $base . '/data/framework/' . $dir . '/' . str_replace('.default.json', '.json', basename($seedPath)));
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
$em = \Z77\Core\DI::getUnifiedEntityManager();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$count = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");

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
check('M1 migrate exits 0 (every module, timestamp order: article before order)' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
$migrations = glob($packages['Order'] . '/res/migrations/Version*.php');
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Order\\Migrations']);
check('M2 diff after migrate reports NO change — mapping and migrations agree', $code !== 0 && str_contains($out, 'No changes detected') && glob($packages['Order'] . '/res/migrations/Version*.php') === $migrations);
check('M3 the range `order` exists at 0 (a separate data migration)', $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") !== false
    && (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") === 0);

// Master data: a contact, an article with a price.
$db->insert('contact', ['kind' => 'organisation', 'company' => 'Muster AG', 'first_name' => '', 'last_name' => '', 'language' => 'de', 'email' => '', 'phone' => '', 'active' => 1]);
$contactId = (int) $db->lastInsertId();
// No order for a contact without a debtor profile (owner 2026-10-19, M27): the customer is made a
// debtor first — through THE one creation path, as an importer would.
(new \Z77\Module\Debtor\Services\DebtorProfileService($em))->open($em->getRepository(\Z77\Module\Contact\Entities\Contact::class)->find($contactId), 'net-30');
// Debtor refuses a document without an invoice address (newer than this section) — the contact gets one.
$db->insert('address', ['salutation' => '', 'title' => '', 'first_name' => '', 'name' => 'Muster AG', 'address_row' => '', 'street' => 'Musterweg', 'house_no' => '1', 'zip' => '6000', 'city' => 'Luzern', 'country' => 'CH']);
$db->insert('contact_address', ['contact_id' => $contactId, 'address_id' => (int) $db->lastInsertId(), 'type_code' => 'invoice', 'title' => '']);
$article = (new \Z77\Module\Article\Services\ArticleService($em, 'harness'))->create(new \Z77\Module\Article\Services\ArticleDraft(
    number: 'W-22', name: 'Pinot Noir', unit: 'Fl.', price: '24.50', priceMode: 'net', validFrom: '2026-01-01', taxCode: 'UN', revenueAccount: '3200', stockRelevant: true,
    productGroup: (int) (new \Z77\Module\Article\Services\ProductGroupService($em))->create(new \Z77\Module\Article\Services\ProductGroupDraft('Wein'))->getId(),   // every article hangs in one group (A1, 2026-10-14)
));
$variantId = (int) $em->getRepository(Variant::class)->defaultOf($article)->getId();
// A service article — every priced line is an article since 2026-10-18 (the former free «Degustation» line).
$degArticle = (new \Z77\Module\Article\Services\ArticleService($em, 'harness'))->create(new \Z77\Module\Article\Services\ArticleDraft(
    number: 'D-80', name: 'Degustation', unit: 'Pers.', price: '80.00', priceMode: 'net', validFrom: '2026-01-01', taxCode: 'UN', revenueAccount: '3400',
    productGroup: (int) $article->getProductGroup()->getId(),
));
$degId = (int) $em->getRepository(Variant::class)->defaultOf($degArticle)->getId();

/** The stock spy — every request it got; refuses a consumed-stock reversal without a confirmation when told to. */
$spy = new class implements StockLedger {
    public array $calls = [];
    public bool $refuse = false;
    private array $held = [];
    public function bringTo(StockRequest $request): StockResult
    {
        $back = array_values(array_map(static fn($l) => $l->lineRef, array_filter($request->lines, fn($l) => (float) $l->consumed < (float) ($this->held[$l->lineRef] ?? 0))));
        if ($this->refuse && $back !== [] && $request->confirmation === null) {
            throw new StockReasonRequiredException($back);
        }
        $this->calls[] = $request;
        foreach ($request->lines as $l) {
            $this->held[$l->lineRef] = $l->consumed;
        }
        return StockResult::none();
    }
    public function shape(): array
    {
        return array_map(static fn($l) => [$l->lineRef, $l->variantId, $l->reserved, $l->consumed], end($this->calls)->lines);
    }
};
$svc = new \Z77\Module\Order\Services\OrderService($em, 'harness', $spy);
$D   = \Z77\Module\Order\Services\OrderDraft::class;
$L   = \Z77\Module\Order\Services\OrderLineDraft::class;
$refusedBy = static function (callable $fn): ?string {
    try { $fn(); } catch (\Z77\Module\Order\Services\OrderRefusedException $e) { return $e->reason; } catch (\Throwable $e) { return get_class($e); }
    return null;
};

$o1 = $svc->create(new $D($contactId, '2026-10-14', 'Fest', lines: [$L::article($variantId, '2'), $L::article($degId, '1'), $L::text('Danke')]));
$o2 = $svc->create(new $D($contactId, '2026-10-14', lines: [$L::article($variantId, '1')]));
check('M4 create draws 1, 2 from `order`; the initial status = the default of phase «new»', $o1->getNumber() === 1 && $o2->getNumber() === 2 && $o1->getStatusCode() === 'opened'
    && (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") === 2);
$line1 = $o1->getLines()[0];
check('M5 the article line takes text, unit and the price at the order date; tax code and account FROM the article (D3)',
    $line1->getText() === 'Pinot Noir' && $line1->getUnit() === 'Fl.' && $line1->getUnitPrice()?->toDecimal() === '24.50' && $line1->getRevenueAccount() === '3200' && $line1->getArticleNumber() === 'W-22');
$line2 = $o1->getLines()[1];
check('M6 the log row `create`; the spy got the FULL state (both article lines, opened = zero; the text line not sent)', $count('sales_order_status_change') === 2 && count($spy->calls) === 2
    && $spy->calls[0]->trigger === 'create' && $spy->calls[0]->sourceRef === (string) $o1->getId()
    && array_map(static fn($l) => [$l->lineRef, $l->variantId, $l->reserved, $l->consumed], $spy->calls[0]->lines) === [[(string) $line1->getId(), $variantId, '0.000', '0.000'], [(string) $line2->getId(), $degId, '0.000', '0.000']]);
check('M7 the totals come from debtor\'s preview (D11): 49.00 + 80.00 net', $o1->getTotalNet()?->toDecimal() === '129.00' && $o1->getTotalGross() !== null);
$bad = null;
try { $svc->create(new $D(0, 'kein Datum', lines: [new $L('service', null, '', 'x'), new $L('service', $variantId, '', 'x')])); } catch (\Z77\Module\Order\Services\InvalidOrderException $e) { $bad = $e->errors; }
check('M8 a bad draft is refused per field, nothing drawn; a priced line without article is refused on `variant` (every priced line an article, 2026-10-18)', $bad !== null && isset($bad['contact_id'], $bad['order_date'], $bad['lines.0.variant'], $bad['lines.1.quantity'])
    && (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") === 2);

$o1 = $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'scheduled');
check('M9 status change opened → scheduled: log row, the spy reserves', $o1->getStatusCode() === 'scheduled' && $spy->shape() === [[(string) $line1->getId(), $variantId, '2.000', '0.000'], [(string) $line2->getId(), $degId, '1.000', '0.000']]
    && end($spy->calls)->trigger === 'status:opened>scheduled');
check('M10 a stale version is refused', $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion() - 1, 'delivered')) === \Z77\Module\Order\Services\OrderConflictException::class);
$o1 = $svc->update((int) $o1->getId(), $o1->getVersion(), new $D($contactId, '2026-10-14', 'Fest', lines: [
    $L::article($variantId, '3', id: (int) $line1->getId()),   // edit
    $L::article($variantId, '-1', 'Rücknahme'),                  // add
]));                                                              // the second article and the text line deleted
$lines = $o1->getLines();
check('M11 update: the edited line keeps its id, the deleted ones are gone, the new one added; the spy got the FULL state',
    count($lines) === 2 && $lines[0]->getId() === $line1->getId() && $count('sales_order_line') === 3
    && $spy->shape() === [[(string) $line1->getId(), $variantId, '3.000', '0.000'], [(string) $lines[1]->getId(), $variantId, '-1.000', '0.000']] && end($spy->calls)->trigger === 'lines');
$o1 = $svc->printDeliveryNote((int) $o1->getId(), $o1->getVersion());
$calls = count($spy->calls);
$again = $svc->printDeliveryNote((int) $o1->getId(), $o1->getVersion() - 1);
check('M12 the delivery note moves scheduled → the default of «delivered»; a reprint writes nothing', $o1->getStatusCode() === 'delivered' && $again->getVersion() === $o1->getVersion() && count($spy->calls) === $calls);
$spy->refuse = true;
check('M13 delivered → cancelled would bring consumed stock back: refused without a reason, nothing written',
    $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'cancelled')) === StockReasonRequiredException::class
    && $db->fetchOne('SELECT status_code FROM sales_order WHERE id = ?', [$o1->getId()]) === 'delivered');
$o1 = $em->getRepository(SalesOrder::class)->find((int) $o1->getId());
$o1 = $svc->changeStatus((int) $o1->getId(), (int) $db->fetchOne('SELECT version FROM sales_order WHERE id = ?', [$o1->getId()]), 'cancelled', new StockConfirmation('Storno durch Kunde'));
check('M14 with the confirmation: moved, the reason in the log row', $o1->getStatusCode() === 'cancelled'
    && $db->fetchOne('SELECT reason FROM sales_order_status_change WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o1->getId()]) === 'Storno durch Kunde');
$spy->refuse = false;
check('M15 a locked phase (cancelled) → read-only (D7); the default of «closed» is never a target by hand', $refusedBy(fn() => $svc->update((int) $o1->getId(), $o1->getVersion(), new $D($contactId, '2026-10-14'))) === 'not-editable'
    && $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'booked')) === 'booked-by-print-only');
// An invoice linked (the booking print's part in step 9 — here by hand): backward barred, forward allowed.
$o1 = $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'to-invoice');
$db->executeStatement("INSERT INTO invoice (kind, state, contact_id, language, invoice_date, service_from, currency, price_mode, payment_terms_code, due_date, discount_tiers, net_total, tax_total, rounding, gross_total, created_by, created_at, version, addr_salutation, addr_title, addr_first_name, addr_name, addr_address_row, addr_street, addr_house_no, addr_zip, addr_city, addr_country, pay_target_code, pay_account, pay_reference_type, pay_reference, pay_message, pay_creditor_name, pay_creditor_street, pay_creditor_house_no, pay_creditor_zip, pay_creditor_city, pay_creditor_country)
    SELECT 'invoice', 'final', ?, 'de', '2026-10-14', '2026-10-14', 'CHF', 'net', 'net-30', '2026-11-13', '[]', 0, 0, 0, 0, 'harness', NOW(), 1, '', '', '', 'Muster AG', '', '', '', '8000', 'Zürich', 'CH', '', '', '', '', '', '', '', '', '', '', ''", [$contactId]);
$db->executeStatement('UPDATE sales_order SET invoice_id = ? WHERE id = ?', [(int) $db->lastInsertId(), $o1->getId()]);
$o1 = $em->getRepository(SalesOrder::class)->withLines((int) $o1->getId());
check('M16 with an invoice: backward barred, cancelling barred, edit refused, forward to «erledigt» by hand',
    $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'scheduled')) === 'backward-barred'
    && $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'cancelled')) === 'cancel-barred'
    && $refusedBy(fn() => $svc->update((int) $o1->getId(), $o1->getVersion(), new $D($contactId, '2026-10-14'))) === 'not-editable'
    && $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'done')->getStatusCode() === 'done');
check('M17 the log of order 1: create, status ×4, delivery-note — from → to in order',
    $db->fetchFirstColumn('SELECT CONCAT(COALESCE(from_code, \'-\'), \'>\', to_code, \':\', change_trigger) FROM sales_order_status_change WHERE order_id = ? ORDER BY id', [$o1->getId()]) === [
        '->opened:create', 'opened>scheduled:status', 'scheduled>delivered:delivery-note', 'delivered>cancelled:status', 'cancelled>to-invoice:status', 'to-invoice>done:status',
    ]);
$o2 = $svc->printConfirmation((int) $o2->getId(), $o2->getVersion());
$calls = count($spy->calls);
$o2again = $svc->printConfirmation((int) $o2->getId(), $o2->getVersion() - 1);
check('M18 the order confirmation moves a quote into the DEFAULT of «confirmed» (scheduled), log row `confirmation`, the spy reserves; a reprint writes nothing; a delivery note for a quote is refused',
    $o2->getStatusCode() === 'scheduled' && end($spy->calls)->trigger === 'status:opened>scheduled' && $spy->shape()[0][2] === '1.000'
    && $o2again->getVersion() === $o2->getVersion() && count($spy->calls) === $calls
    && $db->fetchOne('SELECT change_trigger FROM sales_order_status_change WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o2->getId()]) === 'confirmation'
    && ($o3 = $svc->create(new $D($contactId, '2026-10-14', lines: [$L::article($variantId, '1')]))) !== null
    && $refusedBy(fn() => $svc->printDeliveryNote((int) $o3->getId(), $o3->getVersion())) === 'not-printable');

// The order's payment terms (owner 2026-10-18): by code, validated like the payment target, the preview applies them.
$badTerms = null;
try { $svc->create(new $D($contactId, '2026-10-18', lines: [$L::article($variantId, '1')], paymentTermsCode: 'gibts-nicht')); } catch (\Z77\Module\Order\Services\InvalidOrderException $e) { $badTerms = $e->errors; }
// The lines of M7 (a total without a rounding difference — the harness has no mandator for the rounding account).
$o4 = $svc->create(new $D($contactId, '2026-10-18', lines: [$L::article($variantId, '2'), $L::article($degId, '1')], paymentTermsCode: ' DISC-2-10 '));
$o4Terms = $svc->preview($o4)->getPaymentTermsCode();
$o4Stored = $db->fetchOne('SELECT payment_terms_code FROM sales_order WHERE id = ?', [$o4->getId()]);
$o4 = $svc->update((int) $o4->getId(), $o4->getVersion(), new $D($contactId, '2026-10-18', lines: [$L::article($variantId, '2', id: (int) $o4->getLines()[0]->getId()), $L::article($degId, '1', id: (int) $o4->getLines()[1]->getId())]));
check('M19 payment terms: an unknown code refused on `payment_terms_code`; a known one stored normalised and applied by the preview (= the booking print); cleared → NULL = the customer\'s (the profile\'s net-30)',
    $badTerms !== null && isset($badTerms['payment_terms_code']) && count($badTerms) === 1
    && $o4Stored === 'disc-2-10' && $o4Terms === 'disc-2-10'
    && $db->fetchOne('SELECT payment_terms_code FROM sales_order WHERE id = ?', [$o4->getId()]) === null
    && $svc->preview($o4)->getPaymentTermsCode() === 'net-30');

// The article description on an order line (owner 2026-10-18): the fill and the save build the
// SAME text — `Variant::lineText()`: the name on the first line, the description below it.
$described = (new \Z77\Module\Article\Services\ArticleService($em, 'harness'))->create(new \Z77\Module\Article\Services\ArticleDraft(
    number: 'W-23', name: 'Chasselas', unit: 'Fl.', price: '18.00', priceMode: 'net', validFrom: '2026-01-01', taxCode: 'UN', revenueAccount: '3200',
    productGroup: (int) $article->getProductGroup()->getId(), description: "Lavaux AOC\n75 cl",
));
$describedId = (int) $em->getRepository(Variant::class)->defaultOf($described)->getId();
$fillText    = $svc->articleDefaults('W-23', '2026-10-18', '', 'net')['text'] ?? null;
$o5 = $svc->create(new $D($contactId, '2026-10-18', lines: [$L::article($describedId, '1'), $L::article($describedId, '1', 'Chasselas Spezial')]));
check('M20 an article with a description: the fill and an EMPTY line text at save give «name\\ndescription»; a typed text wins; an article without one stays the name',
    $fillText === "Chasselas\nLavaux AOC\n75 cl" && $o5->getLines()[0]->getText() === "Chasselas\nLavaux AOC\n75 cl"
    && $o5->getLines()[1]->getText() === 'Chasselas Spezial' && ($svc->articleDefaults('W-22', '2026-10-18', '', 'net')['text'] ?? null) === 'Pinot Noir');

// Every priced line an article (owner 2026-10-16 / -18, ADR-043 addendum 2026-10-18): a FREE line an order
// already holds (made before) stays while unchanged; a new one, a changed one and a lump sum are refused.
$o6 = $svc->create(new $D($contactId, '2026-10-18', lines: [$L::article($degId, '1'), $L::article($variantId, '1')]));
[$freeId, $lumpId] = [(int) $o6->getLines()[0]->getId(), (int) $o6->getLines()[1]->getId()];
$db->executeStatement("UPDATE sales_order_line SET variant_id = NULL, article_number = NULL, tax_code = 'UN', revenue_account = '3400' WHERE id IN (?, ?)", [$freeId, $lumpId]);
$db->executeStatement("UPDATE sales_order_line SET type = 'lump-sum', quantity = NULL, text = 'Versand', unit = NULL, unit_price = '12.00' WHERE id = ?", [$lumpId]);
$em->getRepository(SalesOrder::class)->withLines((int) $o6->getId());   // the lines re-read (HINT_REFRESH) after the SQL above
$o6v = (int) $db->fetchOne('SELECT version FROM sales_order WHERE id = ?', [$o6->getId()]);
$kept = static fn(string $qty = '1', ?int $id = null) => [new $L('service', null, 'Degustation', $qty, 'Pers.', '80.00', '', id: $id ?? $freeId), new $L('service', null, 'Versand', '1', '', '12.00', '', id: $lumpId)];
$refusal = static function (callable $fn): array {
    try { $fn(); } catch (\Z77\Module\Order\Services\InvalidOrderException $e) { return $e->errors; }
    return [];
};
$changed  = $refusal(fn() => $svc->update((int) $o6->getId(), $o6v, new $D($contactId, '2026-10-18', lines: $kept('2'))));
$newFree  = $refusal(fn() => $svc->update((int) $o6->getId(), $o6v, new $D($contactId, '2026-10-18', lines: [...$kept(), new $L('service', null, 'Neu', '1', '', '5.00')])));
$lumpType = $refusal(fn() => $svc->update((int) $o6->getId(), $o6v, new $D($contactId, '2026-10-18', lines: [new $L('lump-sum', $degId, '', '', '', '', '')])));
$o6 = $svc->update((int) $o6->getId(), $o6v, new $D($contactId, '2026-10-18', lines: $kept()));
$o6Lines = $db->fetchAllAssociative('SELECT id, type, variant_id, quantity, tax_code, revenue_account FROM sales_order_line WHERE order_id = ? ORDER BY position', [$o6->getId()]);
check('M21 a stored free line saves UNCHANGED (its tax code and account kept); a stored lump sum re-posted as «Leistung» × 1 counts as unchanged and is written as service 1; a changed free line, a new free line and the type «lump-sum» are refused',
    isset($changed['lines.0.variant']) && isset($newFree['lines.2.variant']) && isset($lumpType['lines.0.type'])
    && array_map(static fn($r) => [(int) $r['id'], $r['type'], $r['variant_id'], $r['quantity'], $r['tax_code'], $r['revenue_account']], $o6Lines)
        === [[$freeId, 'service', null, '1.000', 'UN', '3400'], [$lumpId, 'service', null, '1.000', 'UN', '3400']]);

// The price-mode switch (bug report 2026-10-18, owner decision C): article 16.65 GROSS, UN 8.1 %. The
// window converts on the switch and says so in `prices_mode`; the SERVICE converts whenever the posted
// prices are still in the other mode — never stores 15.40 as a gross price.
$grossArticle = (new \Z77\Module\Article\Services\ArticleService($em, 'harness'))->create(new \Z77\Module\Article\Services\ArticleDraft(
    number: '02.100.02', name: 'Grossist', unit: 'Stk.', price: '16.65', priceMode: 'gross', validFrom: '2026-01-01', taxCode: 'UN', revenueAccount: '3200',
    productGroup: (int) $article->getProductGroup()->getId(),
));
$grossId = (int) $em->getRepository(Variant::class)->defaultOf($grossArticle)->getId();
$grossDefaults = $svc->articleDefaults('02.100.02', '2026-10-18', '', 'net');
check('M22 the fill names the article\'s price in BOTH modes (core.js «price mode» re-converts from them): net 15.40 (16.65 ÷ 1.081), gross 16.65 — the fill\'s own price in the asked mode',
    ($grossDefaults['price'] ?? null) === '15.40' && ($grossDefaults['prices'] ?? null) === ['net' => '15.40', 'gross' => '16.65']);
$priceOf = static fn(SalesOrder $o, int $i = 0) => $o->getLines()[$i]->getUnitPrice()?->toDecimal();
$m23a = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'gross', pricesMode: 'net', lines: [$L::article($grossId, '1', unitPrice: '15.40')]));
$m23b = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'gross', pricesMode: 'gross', lines: [$L::article($grossId, '1', unitPrice: '16.65')]));
$m23c = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'net', lines: [$L::article($grossId, '1', unitPrice: '15.40')]));
$m23d = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'net', pricesMode: 'gross', lines: [$L::article($grossId, '1', unitPrice: '16.65')]));
check('M23 THE BUG: «Preise» brutto with the row still filled net (15.40, prices_mode net — no script) → stored 16.65, the exact article price; the same in mode (gross/gross 16.65, net 15.40 without prices_mode) is kept; net with a gross 16.65 posted → 15.40',
    $priceOf($m23a) === '16.65' && $m23a->priceMode()->value === 'gross' && $priceOf($m23b) === '16.65' && $priceOf($m23c) === '15.40' && $priceOf($m23d) === '15.40');
$m24a = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'gross', pricesMode: 'net', lines: [$L::article($grossId, '1', unitPrice: '20.00')]));
$m24b = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'net', pricesMode: 'gross', lines: [$L::article($grossId, '1', unitPrice: '21.62')]));
$m24c = $svc->create(new $D($contactId, '2026-10-18', priceMode: 'gross', pricesMode: 'net', lines: [$L::article($grossId, '1', unitPrice: '0.05')]));
check('M24 a price typed by hand (not the article\'s) converts with the line\'s rate, cents half away from zero: 20.00 net → 21.62 gross, 21.62 gross → 20.00 net, 0.05 net → 0.05 gross (5.405 cents)',
    $priceOf($m24a) === '21.62' && $priceOf($m24b) === '20.00' && $priceOf($m24c) === '0.05');
$o6 = $em->getRepository(SalesOrder::class)->withLines((int) $o6->getId());
$keptIn = static fn(string $free, string $lump) => [new $L('service', null, 'Degustation', '1', 'Pers.', $free, '', id: $freeId), new $L('service', null, 'Versand', '1', '', $lump, '', id: $lumpId)];
$o6 = $svc->update((int) $o6->getId(), $o6->getVersion(), new $D($contactId, '2026-10-18', priceMode: 'gross', pricesMode: 'gross', lines: $keptIn('86.48', '12.97')));
$m25gross = [$priceOf($o6, 0), $priceOf($o6, 1), $o6->priceMode()->value];
$o6 = $svc->update((int) $o6->getId(), $o6->getVersion(), new $D($contactId, '2026-10-18', priceMode: 'net', pricesMode: 'gross', lines: $keptIn('86.48', '12.97')));
check('M25 a kept free line follows the switch and still counts as unchanged: converted in the window (gross/gross 86.48, 12.97 = 80.00 / 12.00 × 1.081) accepted; switched back without the script (net, prices_mode gross) the server converts → 80.00, 12.00',
    $m25gross === ['86.48', '12.97', 'gross'] && [$priceOf($o6, 0), $priceOf($o6, 1), $o6->priceMode()->value] === ['80.00', '12.00', 'net']);
$m26 = $refusal(fn() => $svc->create(new $D($contactId, '2026-10-18', priceMode: 'gross', pricesMode: 'brutto', lines: [$L::article($grossId, '1', unitPrice: '15.40')])));
check('M26 a prices_mode that names no mode is refused on `price_mode` — the prices cannot be read', isset($m26['price_mode']));

// No document for a contact without a debtor profile — in the SERVICE (owner 2026-10-19): a new
// order and a customer change refuse it on `contact_id`; an order keeps the customer it holds.
$db->insert('contact', ['kind' => 'organisation', 'company' => 'Interessent AG', 'first_name' => '', 'last_name' => '', 'language' => 'de', 'email' => '', 'phone' => '', 'active' => 1]);
$prospectId = (int) $db->lastInsertId();
$m27Number  = (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'");
$m27New     = $refusal(fn() => $svc->create(new $D($prospectId, '2026-10-19', lines: [$L::article($variantId, '1')])));
$m27o       = $svc->create(new $D($contactId, '2026-10-19', lines: [$L::article($variantId, '1')]));
$m27Lines   = static fn(SalesOrder $o) => [$L::article($variantId, '1', id: (int) $o->getLines()[0]->getId())];
$m27Change  = $refusal(fn() => $svc->update((int) $m27o->getId(), $m27o->getVersion(), new $D($prospectId, '2026-10-19', lines: $m27Lines($m27o))));
// An OLDER order whose customer has no profile (made before the rule): it keeps that customer.
$db->executeStatement('UPDATE sales_order SET contact_id = ? WHERE id = ?', [$prospectId, $m27o->getId()]);
$m27o    = $em->getRepository(SalesOrder::class)->withLines((int) $m27o->getId());
$m27Kept = $svc->update((int) $m27o->getId(), (int) $db->fetchOne('SELECT version FROM sales_order WHERE id = ?', [$m27o->getId()]), new $D($prospectId, '2026-10-19', 'behalten', lines: $m27Lines($m27o)));
check('M27 a NEW order for a contact without a debtor profile is refused on `contact_id` («… ist noch kein Debitor — zuerst als Debitor anlegen.»), no number drawn; a customer CHANGE to one is refused too; an order that already holds such a customer saves (its booking print opens the profile, plan D9)',
    ($m27New['contact_id'] ?? '') === '«Interessent AG» ist noch kein Debitor — zuerst als Debitor anlegen.' && count($m27New) === 1
    && (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") === $m27Number + 1
    && ($m27Change['contact_id'] ?? '') === $m27New['contact_id']
    && $m27Kept->getSubject() === 'behalten' && $m27Kept->getContact()->getId() === $prospectId);

// The article picker's «Preis» column (owner 2026-10-19): the same price the fill takes.
$grossVariant = $em->getRepository(Variant::class)->find($grossId);
$m28 = [
    $svc->articlePriceAt($grossVariant, '2026-10-18', '', 'net')?->toDecimal(),
    $svc->articlePriceAt($grossVariant, '2026-10-18', '', 'gross')?->toDecimal(),
    $svc->articleDefaults('02.100.02', '2026-10-18', '', 'net')['price'],
    $svc->articlePriceAt($grossVariant, '2025-12-31', '', 'net'),
    $svc->articlePriceAt($grossVariant, '18.10.2026', '', 'net'),
    $svc->articlePriceAt($grossVariant, '2026-10-18', '', 'brutto'),
];
check('M28 the picker\'s price = the fill\'s: 15.40 net / 16.65 gross at the order date; none before the price starts, none for a date or mode that is no valid input',
    $m28[0] === '15.40' && $m28[1] === '16.65' && $m28[2] === '15.40' && $m28[3] === null && $m28[4] === null && $m28[5] === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
