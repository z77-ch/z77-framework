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

/** A status row with $flags on. */
function status(string $code, int $level, array $flags = [], bool $active = true, ?int $id = null): OrderStatus
{
    $row = new OrderStatus(['code' => $code, 'label' => ucfirst($code), 'level' => $level, 'active' => $active] + array_fill_keys($flags, true));

    return $id === null ? $row : withId($row, $id);
}

$seedFile = $root . '/packages/module-order/data/framework/order/order_statuses.default.json';
$seedRaw  = (string) file_get_contents($seedFile);
$seed     = array_map(static fn(array $r) => withId(new OrderStatus($r), (int) $r['id']), json_decode($seedRaw, true, flags: JSON_THROW_ON_ERROR));
$byCode   = [];
foreach ($seed as $row) {
    $byCode[$row->getCode()] = $row;
}

// ── S. status invariants (offline) ───────────────────────────────────────

echo "S. Order status invariants (offline)\n";
check('S1 the seed is UTF-8 without BOM and holds the seven statuses of plan §4.2 in level order',
    !str_starts_with($seedRaw, "\xEF\xBB\xBF") && array_keys($byCode) === ['offer', 'scheduled', 'delivered', 'to-invoice', 'booked', 'done', 'done-no-stock']
    && array_map(static fn($s) => $s->getLevel(), $seed) === [10, 20, 30, 40, 50, 90, 90]);
check('S2 every seed row passes the in-row invariants and the cross-row rules',
    array_filter($seed, static fn($s) => OrderStatusValidator::flagProblems($s) !== [] || OrderStatusValidator::crossRowProblem($s, $seed) !== null) === []);
check('S3 the seed: exactly one invoiceBooked («booked»), one deliveryNoteTarget («delivered»), one offer, two final',
    count(array_filter($seed, static fn($s) => $s->isInvoiceBooked())) === 1 && $byCode['booked']->isInvoiceBooked()
    && count(array_filter($seed, static fn($s) => $s->isDeliveryNoteTarget())) === 1 && $byCode['delivered']->isDeliveryNoteTarget()
    && count(array_filter($seed, static fn($s) => $s->isOffer())) === 1 && count(array_filter($seed, static fn($s) => $s->isFinal())) === 2);
check('S4 the seed\'s stock behaviour: scheduled reserves; delivered, to-invoice, booked, done consume; offer and done-no-stock neither',
    $byCode['scheduled']->isReservesStock() && !$byCode['scheduled']->isConsumesStock()
    && $byCode['delivered']->isConsumesStock() && $byCode['to-invoice']->isConsumesStock() && $byCode['booked']->isConsumesStock() && $byCode['done']->isConsumesStock()
    && !$byCode['offer']->isReservesStock() && !$byCode['offer']->isConsumesStock() && !$byCode['done-no-stock']->isConsumesStock() && !$byCode['done-no-stock']->isReservesStock());
check('S5 final ⇒ ¬invoiceable ∧ ¬invoiceBooked', isset(OrderStatusValidator::flagProblems(status('x', 1, ['final', 'invoiceable']))['final'])
    && isset(OrderStatusValidator::flagProblems(status('x', 1, ['final', 'invoice_booked']))['final']));
check('S6 consumesStock ⇒ ¬reservesStock', isset(OrderStatusValidator::flagProblems(status('x', 1, ['consumes_stock', 'reserves_stock']))['reserves_stock']));
check('S7 offer ⇒ ¬reserves ∧ ¬consumes ∧ ¬invoiceable', isset(OrderStatusValidator::flagProblems(status('x', 1, ['offer', 'reserves_stock']))['offer'])
    && isset(OrderStatusValidator::flagProblems(status('x', 1, ['offer', 'consumes_stock']))['offer']) && isset(OrderStatusValidator::flagProblems(status('x', 1, ['offer', 'invoiceable']))['offer']));
check('S8 invoiceBooked ⇒ ¬invoiceable', isset(OrderStatusValidator::flagProblems(status('x', 1, ['invoice_booked', 'invoiceable']))['invoice_booked']));
check('S9 a second ACTIVE invoiceBooked / deliveryNoteTarget is refused; an inactive one does not count',
    OrderStatusValidator::crossRowProblem(status('new', 60, ['invoice_booked']), $seed)[0] === 'invoice_booked'
    && OrderStatusValidator::crossRowProblem(status('new', 35, ['delivery_note_target']), $seed)[0] === 'delivery_note_target'
    && OrderStatusValidator::crossRowProblem(status('new', 60, ['invoice_booked']), array_map(static fn($s) => $s->isInvoiceBooked() ? status($s->getCode(), $s->getLevel(), ['invoice_booked'], false, $s->getId()) : $s, $seed)) === null);
check('S10 the booked level lies above every invoiceable level — both directions refused',
    OrderStatusValidator::crossRowProblem(status('late', 60, ['invoiceable', 'consumes_stock']), $seed)[0] === 'level'
    && OrderStatusValidator::crossRowProblem(withId(status('booked', 40, ['invoice_booked', 'consumes_stock']), 5), $seed)[0] === 'level');
check('S11 a row is not compared with its own stored copy (edit of «booked» keeps working)', OrderStatusValidator::crossRowProblem($byCode['booked'], $seed) === null);
$v = new OrderStatusValidator(status('pickup', 0, ['offer', 'reserves_stock']));
check('S12 the field run: level 1–999, code format, the in-row invariants as field errors', !$v->isValid() && $v->hasFieldError('level') && $v->hasFieldError('offer')
    && !(new OrderStatusValidator(status('Bad Code', 10)))->isValid() && (new OrderStatusValidator(status('pickup', 25, ['reserves_stock'])))->isValid());

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

echo "T. Transitions and stock targets (offline)\n";
$rules = new OrderStatuses($seed);
check('T1 initial = the active status with the lowest level («offer»); with offer inactive → «scheduled»; none active → null',
    $rules->initial()?->getCode() === 'offer'
    && (new OrderStatuses(array_map(static fn($s) => $s->getCode() === 'offer' ? status('offer', 10, ['offer'], false, 1) : $s, $seed)))->initial()?->getCode() === 'scheduled'
    && (new OrderStatuses([status('x', 1, [], false)]))->initial() === null);
check('T2 by FLAG: delivery-note target = «delivered», booked = «booked»; startable = not booked, not final, active',
    $rules->deliveryNoteTarget()?->getCode() === 'delivered' && $rules->invoiceBooked()?->getCode() === 'booked'
    && array_map(static fn($s) => $s->getCode(), $rules->startable()) === ['offer', 'scheduled', 'delivered', 'to-invoice']);
$refused = static fn(string $from, string $to, bool $invoice): ?string => OrderStatuses::refusal($byCode[$from], $byCode[$to], $invoice)[0] ?? null;
check('T3 forward is free (offer → scheduled → delivered → to-invoice → done)', $refused('offer', 'scheduled', false) === null && $refused('scheduled', 'delivered', false) === null
    && $refused('delivered', 'to-invoice', false) === null && $refused('to-invoice', 'done', false) === null && $refused('booked', 'done', true) === null);
check('T4 lateral (equal level) is allowed — done ↔ done-no-stock, also with an invoice', $refused('done', 'done-no-stock', true) === null && $refused('done-no-stock', 'done', false) === null);
check('T5 backward is allowed WITHOUT an invoice, barred WITH one', $refused('delivered', 'offer', false) === null && $refused('done-no-stock', 'scheduled', false) === null
    && $refused('to-invoice', 'scheduled', true) === OrderStatuses::BACKWARD_BARRED && $refused('done', 'booked', true) === OrderStatuses::BOOKED_TARGET);
check('T6 the booked status is reached by the booking print only — never a target', $refused('to-invoice', 'booked', false) === OrderStatuses::BOOKED_TARGET && $refused('offer', 'booked', false) === OrderStatuses::BOOKED_TARGET);
check('T7 the same status and an inactive target are refused; an order may SIT in an inactive one',
    $refused('offer', 'offer', false) === OrderStatuses::SAME
    && OrderStatuses::refusal($byCode['offer'], status('gone', 25, [], false), false)[0] === OrderStatuses::INACTIVE_TARGET
    && OrderStatuses::refusal(status('gone', 25, [], false), $byCode['delivered'], false) === null);
check('T8 the targets offered from «to-invoice» with an invoice: forward and lateral only, never booked',
    array_map(static fn($s) => $s->getCode(), $rules->targets($byCode['to-invoice'], true)) === ['done', 'done-no-stock']
    && array_map(static fn($s) => $s->getCode(), $rules->targets($byCode['to-invoice'], false)) === ['offer', 'scheduled', 'delivered', 'done', 'done-no-stock']);

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
check('T9 offer: every ARTICLE line (children included), all zero — free and text lines not sent',
    $shape(OrderStockTargets::of($order->getLines(), $byCode['offer'])) === $expect('0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000'));
check('T10 scheduled reserves the quantity; a negative quantity stays negative (an inflow); a lump-sum article = one unit',
    $shape(OrderStockTargets::of($order->getLines(), $byCode['scheduled'])) === $expect('2.000', '0.000', '1.000', '0.000', '-1.000', '0.000', '1.000', '0.000'));
check('T11 delivered consumes the quantity, the reservation goes to zero',
    $shape(OrderStockTargets::of($order->getLines(), $byCode['delivered'])) === $expect('0.000', '2.000', '0.000', '1.000', '0.000', '-1.000', '0.000', '1.000'));
check('T12 done-no-stock: everything back to zero (the ledger demands the reason — not the order)',
    $shape(OrderStockTargets::of($order->getLines(), $byCode['done-no-stock'])) === $expect('0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000', '0.000'));
$unsaved = new SalesOrderLine($order);
$unsaved->write(7, null, LineType::Service, $variantA, '1', 'x', '1', null, $chf('1.00'), 0, 'UN', '3400');
check('T13 an article line without its id (not flushed) is refused — the line id IS the stock reference',
    (static function () use ($unsaved, $byCode) { try { OrderStockTargets::of([$unsaved], $byCode['scheduled']); } catch (\LogicException) { return true; } return false; })());
$order->removeLine($unsaved);
check('T14 the line guards its shape: a child beneath a text line, a nested child, a rounding line, a text line with an article are refused',
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
$order->describe($contact, '  ', new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-10'), PriceMode::Gross, 'CHF', null, null, null);
$draft2 = OrderInvoiceDraft::of($order, new \DateTimeImmutable('2026-10-31'));
check('D6 without a Betreff the subject is «Auftrag 42»; the head\'s service period wins; gross; no payment target',
    $draft2->subject === 'Auftrag 42' && $draft2->serviceFrom?->format('Y-m-d') === '2026-10-01' && $draft2->serviceTo?->format('Y-m-d') === '2026-10-10'
    && $draft2->priceMode === PriceMode::Gross && $draft2->paymentTargetCode === null);

// ── V. the list's view tabs (offline) ────────────────────────────────────

echo "V. List tabs from the status flags (offline)\n";
$viewOfSeed = array_combine(array_keys($byCode), array_map(static fn($s) => OrderListing::viewOf($s), array_values($byCode)));
check('V1 each seed status falls into ONE tab by its flags: offer → Offerten; scheduled, delivered → In Arbeit; to-invoice → Zu fakturieren; booked → Gebucht; done, done-no-stock → Erledigt',
    $viewOfSeed === ['offer' => 'offer', 'scheduled' => 'active', 'delivered' => 'active', 'to-invoice' => 'invoiceable', 'booked' => 'booked', 'done' => 'final', 'done-no-stock' => 'final']);
check('V2 a NEW status lands in its tab without code (by flag), inactive ones still count; «Alle» filters nothing',
    OrderListing::viewOf(status('pickup', 35, ['consumes_stock', 'invoiceable'])) === 'invoiceable'
    && OrderListing::codesOf('active', array_merge($seed, [status('waiting', 25, [], false)])) === ['scheduled', 'delivered', 'waiting']
    && OrderListing::codesOf('all', $seed) === null && OrderListing::codesOf('booked', $seed) === ['booked']);
check('V3 the badges: orders per status mapped to their tab; «Alle» counts every order, also a code missing from the master data',
    OrderListing::counts($seed, ['offer' => 2, 'scheduled' => 1, 'delivered' => 3, 'to-invoice' => 4, 'done' => 1, 'gone' => 5])
    === ['offer' => 2, 'active' => 4, 'invoiceable' => 4, 'booked' => 0, 'final' => 1, 'all' => 16]);
$cols = static fn(string $view): array => array_map(static fn($c) => $c->label !== '' ? $c->label : '[' . $c->ariaLabel . ']', OrderListing::definition('CHF', $view)->columns());
check('V4 the columns: the selection checkbox only in «Zu fakturieren», «Zahlung» only in «Gebucht»; the default tab is «Offerten»',
    $cols('invoiceable')[0] === '[Auswahl]' && !in_array('[Auswahl]', $cols('offer'), true) && in_array('Zahlung', $cols('booked'), true) && !in_array('Zahlung', $cols('all'), true)
    && OrderListing::definition('CHF')->extraDefault('view') === 'offer' && array_key_first(OrderListing::VIEWS) === 'offer');

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
    'contact' => '6', 'subject' => ' Einführung ', 'order_date' => '2026-10-14', 'price_mode' => 'net', 'target' => 'lukb', 'ship' => 'a3', 'status' => 'offer',
    'rows' => [
        ['type' => 'service', 'article' => '1001', 'quantity' => '2', 'id' => '15'],
        ['type' => 'service', 'child' => '1', 'text' => 'Unterlagen', 'quantity' => '1', 'price' => '0', 'tax_code' => 'UN', 'account' => '3400'],
        ['type' => 'bogus', 'text' => '', 'price' => '', 'quantity' => '', 'article' => ''],
        ['type' => 'text', 'text' => 'Danke', 'id' => 'x1'],
        ['type' => 'service', 'article' => '1001', 'text' => 'mit Code', 'quantity' => '1', 'tax_code' => 'UR', 'account' => '3000'],
    ],
]);
$ship = new \Z77\Module\Debtor\Entities\AddressSnapshot('', '', 'Anna', 'Muster', '', 'Weg', '1', '4051', 'Basel', 'CH');
$fd   = $form->toDraft(['1001' => 11], ['' => null, 'a3' => $ship], 'harness');
check('F1 the head: contact id, subject (the service trims), dates, target, the chosen delivery address as snapshot, the start status',
    $fd !== null && $fd->contactId === 6 && $fd->orderDate === '2026-10-14' && $fd->paymentTargetCode === 'lukb' && $fd->deliveryAddress === $ship && $fd->statusCode === 'offer' && $fd->sourceRef === 'harness');
check('F2 the lines: the article number → its variant id, the existing line keeps its id, a «unter» row beneath its parent, the empty row left out, a bogus id dropped',
    count($fd->lines) === 3 && $fd->lines[0]->variantId === 11 && $fd->lines[0]->id === 15 && count($fd->lines[0]->children) === 1
    && $fd->lines[0]->children[0]->text === 'Unterlagen' && $fd->lines[1]->type === 'text' && $fd->lines[1]->id === null);
check('F3 an ARTICLE line sends no tax code and no account — they come from the article (D3); a free line sends its own',
    $fd->lines[2]->variantId === 11 && $fd->lines[2]->taxCode === '' && $fd->lines[2]->revenueAccount === '' && $fd->lines[0]->children[0]->taxCode === 'UN');
$form->applyErrors(['contact_id' => 'Kunde wählen.', 'lines.0.0.unit_price' => 'Preis fehlt.', 'lines.2.variant' => 'inaktiv', 'payment_target_code' => 'weg', 'lines.9.text' => 'verirrt']);
check('F4 the service\'s field errors land on the form: the head by name, `lines.{path}.{field}` on the row the line came from',
    $form->error('contact') === 'Kunde wählen.' && $form->rowError(1, 'price') === 'Preis fehlt.' && $form->rowError(4, 'article') === 'inaktiv'
    && $form->error('target') === 'weg' && $form->generalErrors() === ['verirrt'] && $form->invalidIds()[0] === 'order-contact');
$bad = (new OrderForm())->fromPost(['contact' => '6', 'ship' => 'a99', 'rows' => [['article' => '9999', 'quantity' => '1'], ['child' => '1', 'text' => 'x', 'quantity' => '1', 'price' => '1']]]);
check('F5 what the form cannot read: an unknown article, a delivery address that is not the customer\'s, a «unter» row without a parent above',
    $bad->toDraft(['1001' => 11], ['' => null]) === null && $bad->rowError(0, 'article') !== '' && $bad->error('ship') !== '');
$bad2 = (new OrderForm())->fromPost(['contact' => '6', 'rows' => [['child' => '1', 'text' => 'x', 'quantity' => '1', 'price' => '1']]]);
check('F6 … a child as the first row is refused on its row', $bad2->toDraft([], ['' => null]) === null && $bad2->rowError(0, 'child') !== '');
$fresh = (new OrderForm())->startFrom($order);
check('F7 startFrom(): one row per line with its id, «unter» on the child, the empty row at the end; an article line shows no tax code / account',
    count($fresh->rows()) === 7 && $fresh->rows()[0]['id'] === '101' && $fresh->rows()[1]['child'] === '1' && $fresh->rows()[0]['tax_code'] === ''
    && $fresh->rows()[4]['tax_code'] === 'UN' && $fresh->rows()[6]['id'] === '' && $fresh->rows()[0]['quantity'] === '2');

// ── P. the order's PDFs (offline where possible) ─────────────────────────

echo "P. Order PDFs (offline)\n";
check('P1 the title by FLAG: «Offerte» for a status flagged offer, else «Auftragsbestätigung» (also without a status row)',
    OrderPdf::orderTitle($byCode['offer']) === 'Offerte' && OrderPdf::orderTitle($byCode['scheduled']) === 'Auftragsbestätigung' && OrderPdf::orderTitle(null) === 'Auftragsbestätigung');
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

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-order-' . getmypid();
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
$article = (new \Z77\Module\Article\Services\ArticleService($em, 'harness'))->create(new \Z77\Module\Article\Services\ArticleDraft(
    number: 'W-22', name: 'Pinot Noir', unit: 'Fl.', price: '24.50', priceMode: 'net', validFrom: '2026-01-01', taxCode: 'UN', revenueAccount: '3200', stockRelevant: true,
));
$variantId = (int) $em->getRepository(Variant::class)->defaultOf($article)->getId();

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

$o1 = $svc->create(new $D($contactId, '2026-10-14', 'Fest', lines: [$L::article($variantId, '2'), $L::free('Degustation', '1', '80', 'UN', '3400'), $L::text('Danke')]));
$o2 = $svc->create(new $D($contactId, '2026-10-14', lines: [$L::article($variantId, '1')]));
check('M4 create draws 1, 2 from `order`; the initial status by flag (lowest active level)', $o1->getNumber() === 1 && $o2->getNumber() === 2 && $o1->getStatusCode() === 'offer'
    && (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") === 2);
$line1 = $o1->getLines()[0];
check('M5 the article line takes text, unit and the price at the order date; tax code and account FROM the article (D3)',
    $line1->getText() === 'Pinot Noir' && $line1->getUnit() === 'Fl.' && $line1->getUnitPrice()?->toDecimal() === '24.50' && $line1->getRevenueAccount() === '3200' && $line1->getArticleNumber() === 'W-22');
check('M6 the log row `create`; the spy got the FULL state (one article line, offer = zero)', $count('sales_order_status_change') === 2 && count($spy->calls) === 2
    && $spy->calls[0]->trigger === 'create' && $spy->calls[0]->sourceRef === (string) $o1->getId()
    && array_map(static fn($l) => [$l->lineRef, $l->variantId, $l->reserved, $l->consumed], $spy->calls[0]->lines) === [[(string) $line1->getId(), $variantId, '0.000', '0.000']]);
check('M7 the totals come from debtor\'s preview (D11): 49.00 + 80.00 net', $o1->getTotalNet()?->toDecimal() === '129.00' && $o1->getTotalGross() !== null);
$bad = null;
try { $svc->create(new $D(0, 'kein Datum', lines: [$L::free('', 'x', '', 'XX', 'abc')])); } catch (\Z77\Module\Order\Services\InvalidOrderException $e) { $bad = $e->errors; }
check('M8 a bad draft is refused per field, nothing drawn', $bad !== null && isset($bad['contact_id'], $bad['order_date'], $bad['lines.0.text'], $bad['lines.0.quantity'], $bad['lines.0.tax_code'], $bad['lines.0.revenue_account'])
    && (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'order'") === 2);

$o1 = $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'scheduled');
check('M9 status change offer → scheduled: log row, the spy reserves', $o1->getStatusCode() === 'scheduled' && $spy->shape() === [[(string) $line1->getId(), $variantId, '2.000', '0.000']]
    && end($spy->calls)->trigger === 'status:offer>scheduled');
check('M10 a stale version is refused', $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion() - 1, 'delivered')) === \Z77\Module\Order\Services\OrderConflictException::class);
$o1 = $svc->update((int) $o1->getId(), $o1->getVersion(), new $D($contactId, '2026-10-14', 'Fest', lines: [
    $L::article($variantId, '3', id: (int) $line1->getId()),   // edit
    $L::article($variantId, '-1', 'Rücknahme'),                  // add
]));                                                              // the free and the text line deleted
$lines = $o1->getLines();
check('M11 update: the edited line keeps its id, the deleted ones are gone, the new one added; the spy got the FULL state',
    count($lines) === 2 && $lines[0]->getId() === $line1->getId() && $count('sales_order_line') === 3
    && $spy->shape() === [[(string) $line1->getId(), $variantId, '3.000', '0.000'], [(string) $lines[1]->getId(), $variantId, '-1.000', '0.000']] && end($spy->calls)->trigger === 'lines');
$o1 = $svc->printDeliveryNote((int) $o1->getId(), $o1->getVersion());
$calls = count($spy->calls);
$again = $svc->printDeliveryNote((int) $o1->getId(), $o1->getVersion() - 1);
check('M12 the delivery note moves scheduled → delivered (forward, by flag); a reprint writes nothing', $o1->getStatusCode() === 'delivered' && $again->getVersion() === $o1->getVersion() && count($spy->calls) === $calls);
$spy->refuse = true;
check('M13 delivered → done-no-stock would bring consumed stock back: refused without a reason, nothing written',
    $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'done-no-stock')) === StockReasonRequiredException::class
    && $db->fetchOne('SELECT status_code FROM sales_order WHERE id = ?', [$o1->getId()]) === 'delivered');
$o1 = $em->getRepository(SalesOrder::class)->find((int) $o1->getId());
$o1 = $svc->changeStatus((int) $o1->getId(), (int) $db->fetchOne('SELECT version FROM sales_order WHERE id = ?', [$o1->getId()]), 'done-no-stock', new StockConfirmation('Storno durch Kunde'));
check('M14 with the confirmation: moved, the reason in the log row', $o1->getStatusCode() === 'done-no-stock'
    && $db->fetchOne('SELECT reason FROM sales_order_status_change WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o1->getId()]) === 'Storno durch Kunde');
$spy->refuse = false;
check('M15 final → read-only (D7); the booked status is never a target', $refusedBy(fn() => $svc->update((int) $o1->getId(), $o1->getVersion(), new $D($contactId, '2026-10-14'))) === 'not-editable'
    && $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'booked')) === 'booked-by-print-only');
// An invoice linked (the booking print's part in step 9 — here by hand): backward barred, forward allowed.
$o1 = $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'to-invoice');
$db->executeStatement("INSERT INTO invoice (kind, state, contact_id, language, invoice_date, service_from, currency, price_mode, payment_terms_code, due_date, discount_tiers, net_total, tax_total, rounding, gross_total, created_by, created_at, version, addr_salutation, addr_title, addr_first_name, addr_name, addr_address_row, addr_street, addr_house_no, addr_zip, addr_city, addr_country, pay_target_code, pay_account, pay_reference_type, pay_reference, pay_message, pay_creditor_name, pay_creditor_street, pay_creditor_house_no, pay_creditor_zip, pay_creditor_city, pay_creditor_country)
    SELECT 'invoice', 'final', ?, 'de', '2026-10-14', '2026-10-14', 'CHF', 'net', 'net-30', '2026-11-13', '[]', 0, 0, 0, 0, 'harness', NOW(), 1, '', '', '', 'Muster AG', '', '', '', '8000', 'Zürich', 'CH', '', '', '', '', '', '', '', '', '', '', ''", [$contactId]);
$db->executeStatement('UPDATE sales_order SET invoice_id = ? WHERE id = ?', [(int) $db->lastInsertId(), $o1->getId()]);
$o1 = $em->getRepository(SalesOrder::class)->withLines((int) $o1->getId());
check('M16 with an invoice: backward barred, edit refused, forward to «erledigt» by hand',
    $refusedBy(fn() => $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'scheduled')) === 'backward-barred'
    && $refusedBy(fn() => $svc->update((int) $o1->getId(), $o1->getVersion(), new $D($contactId, '2026-10-14'))) === 'not-editable'
    && $svc->changeStatus((int) $o1->getId(), $o1->getVersion(), 'done')->getStatusCode() === 'done');
check('M17 the log of order 1: create, status ×4, delivery-note — from → to in order',
    $db->fetchFirstColumn('SELECT CONCAT(COALESCE(from_code, \'-\'), \'>\', to_code, \':\', change_trigger) FROM sales_order_status_change WHERE order_id = ? ORDER BY id', [$o1->getId()]) === [
        '->offer:create', 'offer>scheduled:status', 'scheduled>delivered:delivery-note', 'delivered>done-no-stock:status', 'done-no-stock>to-invoice:status', 'to-invoice>done:status',
    ]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
