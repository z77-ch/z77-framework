<?php
namespace Z77\Module\Order\Ui;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Ui\InvoiceForm;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Services\OrderDraft;
use Z77\Module\Order\Services\OrderLineDraft;

/**
 * The order window's form (plan §9.4): a posted form ↔ an {@see OrderDraft}. Plain PHP arrays in
 * and out. The rows are a SORT ORDER (owner 2026-10-18): they come back in the order they were
 * posted — core.js «rows» moves them (drag & drop, Alt+↑/↓) and renumbers the field names; without
 * the script the order is the one rendered. «+ Artikel» / «+ Textzeile» and × are submits
 * (`op` = `add-article` | `add-text` | `remove:{i}`, {@see applyOp()}) the server answers with the
 * changed rows; the script does the same in place, without the round trip.
 *
 * A line row: its `id` (hidden — an existing line keeps its identity, the stock seam's line
 * reference; order.md rule), the type (Leistung | Text — no «Pauschale» in an order since
 * 2026-10-18: a stored lump sum shows as «Leistung» × 1), the INDENT flag `child` (beneath the
 * nearest preceding non-indented row — one level, today's parent line), the ARTICLE number (a
 * `<datalist>` of the active articles), text, quantity (negative = a return), unit, price,
 * discount. Every priced row is an article (ADR-043 addendum 2026-10-18); a free priced line the
 * order already holds comes back without an article and the SERVICE keeps it while unchanged —
 * its stored tax code rides along for the display only (`tax_code`, never sent). An article line
 * with an empty text / unit / price takes the article's values when it is SAVED (`OrderService`,
 * no script needed). A row with neither article, text, quantity nor price is left out — an
 * existing line emptied like that is removed.
 *
 * The form only turns the fields into a draft and says which field it cannot read (an unknown
 * article number, an unknown delivery address); every order rule is `OrderService`'s, its
 * `InvalidOrderException` comes back per field through {@see applyErrors()}.
 */
final class OrderForm
{
    /** A new order starts with one article row; «+ Artikel» / «+ Textzeile» add more. */
    public const ROWS_NEW = 1;

    /**
     * Row type → German label: debtor's `LineType` without the rounding line and — in an order —
     * without the lump sum (owner 2026-10-18: a lump sum is an article with quantity 1). The
     * invoice editor keeps all three (`InvoiceForm::TYPES`).
     */
    public const TYPES = ['service' => 'Leistung', 'text' => 'Text'];

    /** The delivery-address choice that keeps the snapshot the order already holds. */
    public const SHIP_KEEP = 'keep';

    /**
     * The address choice «angepasst»: the card's own fields (`{ship|bill}_addr_…`, contact's
     * `_addressFields`) — an address for THIS order only, the customer's stays (owner 2026-10-18:
     * «anpassen» starts from the shown address; no separate «frei erfassen»).
     */
    public const ADDRESS_CUSTOM = 'custom';

    /** What an address card says for a choice without an address (`_addressCases`). */
    public const ADDRESS_NONE = [
        'bill' => 'Der Kunde hat keine Haupt- oder Rechnungsadresse — «anpassen» oder beim Kontakt erfassen.',
        'ship' => 'Lieferung an die Rechnungsadresse.',
    ];

    /** The snapshot's fields in `AddressSnapshot`'s constructor order (the posted names after the prefix). */
    private const ADDRESS_FIELDS = ['salutation', 'title', 'first_name', 'name', 'address_row', 'street', 'house_no', 'zip', 'city', 'country'];

    private const EMPTY_ROW = ['id' => '', 'type' => 'service', 'child' => '', 'article' => '', 'text' => '', 'quantity' => '', 'unit' => '', 'price' => '', 'discount' => '', 'tax_code' => ''];

    /** The service's field names → the form's. */
    private const HEADER_FIELDS = ['contact_id' => 'contact', 'payment_terms_code' => 'terms', 'status_code' => 'status'];
    private const ROW_FIELDS    = ['variant' => 'article', 'unit_price' => 'price', 'children' => 'child'];

    /**
     * The head. `prices_mode` (hidden, owner 2026-10-18) = the mode the row PRICES are in: rendered
     * as `price_mode`, rewritten by core.js «price mode» when it converts the rows, echoed as posted
     * on a re-render — `OrderService` converts at the save when it differs from `price_mode`.
     *
     * @var array<string, string>
     */
    private array $header = ['contact' => '', 'subject' => '', 'order_date' => '', 'service_from' => '', 'service_to' => '', 'price_mode' => 'net', 'prices_mode' => 'net', 'terms' => '', 'ship' => '', 'bill' => '', 'status' => '', 'notes' => ''];

    /**
     * The payment target (bank account of the payment part) — NOT a posted field since 2026-10-18
     * (owner: the window offers «Zahlungskonditionen» only): the server sets it — a new order the
     * first active target, an existing one its own ({@see withTarget()}); a posted `target` is ignored.
     */
    private string $target = '';

    /** @var array{ship: array<string, string>, bill: array<string, string>} the «angepasst» fields per card */
    private array $custom = ['ship' => [], 'bill' => []];

    /** @var list<array<string, string>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<int, array<string, string>> */
    private array $rowErrors = [];

    /** @var list<string> */
    private array $general = [];

    /** @var array<string, int> the draft's line path → the form row it came from (the last {@see toDraft()}) */
    private array $paths = [];

    /**
     * A new order: today, the customer, payment target, payment terms ($terms '' = «wie beim
     * Kunden») and price mode the screen preselects (the debtor's, owner 2026-10-18; else net),
     * empty rows.
     */
    public function startBlank(\DateTimeImmutable $today, ?int $contactId, string $target, string $status, string $terms = '', string $priceMode = 'net'): self
    {
        $this->header['contact']    = $contactId !== null ? (string) $contactId : '';
        $this->header['order_date'] = $today->format('Y-m-d');
        $this->header['terms']      = $terms;
        $this->header['price_mode'] = $this->header['prices_mode'] = $priceMode === 'gross' ? 'gross' : 'net';
        $this->target               = $target;
        $this->header['status']     = $status;
        $this->rows = array_fill(0, self::ROWS_NEW, self::EMPTY_ROW);

        return $this;
    }

    /**
     * The fields of a stored order, one row per line in position order (children flagged
     * `child`). A stored lump sum shows as «Leistung» × 1 — the same amount (owner 2026-10-18).
     */
    public function startFrom(SalesOrder $order): self
    {
        $this->header = [
            'contact'      => (string) $order->getContact()->getId(),
            'subject'      => $order->getSubject(),
            'order_date'   => $order->getOrderDate()->format('Y-m-d'),
            'service_from' => $order->getServiceFrom()?->format('Y-m-d') ?? '',
            'service_to'   => $order->getServiceTo()?->format('Y-m-d') ?? '',
            'price_mode'   => $order->priceMode()->value,
            'prices_mode'  => $order->priceMode()->value,
            'terms'        => (string) $order->getPaymentTermsCode(),
            'ship'         => $order->deliveryAddress() !== null ? self::SHIP_KEEP : '',
            'bill'         => $order->billingAddress() !== null ? self::SHIP_KEEP : '',
            'status'       => $order->getStatusCode(),
            'notes'        => (string) $order->getNotes(),
        ];
        $this->target = (string) $order->getPaymentTargetCode();
        $this->rows   = [];
        foreach ($order->getLines() as $line) {
            $lumpSum      = $line->type() === LineType::LumpSum;
            $this->rows[] = [
                'id'       => (string) $line->getId(),
                'type'     => $lumpSum ? LineType::Service->value : $line->type()->value,
                'child'    => $line->getParentLine() !== null ? '1' : '',
                'article'  => $line->getVariant() !== null ? (string) $line->getArticleNumber() : '',
                'text'     => $line->getText(),
                'quantity' => $lumpSum ? '1' : ($line->getQuantity() === null ? '' : self::trimQuantity($line->getQuantity())),
                'unit'     => (string) $line->getUnit(),
                'price'    => $line->getUnitPrice()?->toDecimal() ?? '',
                'discount' => InvoiceForm::formatPercent($line->getDiscountPercent()),
                'tax_code' => $line->getVariant() === null ? (string) $line->getTaxCode() : '',
            ];
        }

        return $this;
    }

    /** @param array<string, mixed> $post */
    public function fromPost(array $post): self
    {
        foreach (array_keys($this->header) as $key) {
            $this->header[$key] = is_string($post[$key] ?? null) ? trim($post[$key]) : '';
        }
        foreach (['ship', 'bill'] as $field) {
            $this->custom[$field] = [];
            if ($this->header[$field] === self::ADDRESS_CUSTOM) {
                foreach (self::ADDRESS_FIELDS as $name) {
                    $value = $post[$field . '_addr_' . $name] ?? null;
                    $this->custom[$field][$name] = is_string($value) ? trim($value) : '';
                }
            }
        }
        $this->rows = [];
        foreach (is_array($post['rows'] ?? null) ? $post['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = self::EMPTY_ROW;
            foreach (array_keys(self::EMPTY_ROW) as $key) {
                $clean[$key] = is_string($row[$key] ?? null) ? trim($row[$key]) : '';
            }
            if (!isset(self::TYPES[$clean['type']])) {
                $clean['type'] = 'service';
            }
            if (!ctype_digit($clean['id'])) {
                $clean['id'] = '';
            }
            $this->rows[] = $clean;
        }
        if ($this->rows === []) {
            $this->rows = [self::EMPTY_ROW];
        }

        return $this;
    }

    /**
     * «anpassen» on an address card: the card switches to its own fields, filled with $start —
     * the address it showed (null = empty fields).
     */
    public function adjust(string $field, ?AddressSnapshot $start): self
    {
        $field = $field === 'bill' ? 'bill' : 'ship';
        $this->header[$field] = self::ADDRESS_CUSTOM;
        $values = $start === null ? [] : [
            $start->getSalutation(), $start->getTitle(), $start->getFirstName(), $start->getName(), $start->getAddressRow(),
            $start->getStreet(), $start->getHouseNo(), $start->getZip(), $start->getCity(), $start->getCountry(),
        ];
        $this->custom[$field] = $values === [] ? array_fill_keys(self::ADDRESS_FIELDS, '') : array_combine(self::ADDRESS_FIELDS, $values);
        if ($this->custom[$field]['country'] === '') {
            $this->custom[$field]['country'] = 'CH';
        }

        return $this;
    }

    /** The «angepasst» address of a card as the snapshot its fields make (empty fields → an empty one). */
    public function customAddress(string $field): AddressSnapshot
    {
        $values = $this->custom[$field === 'bill' ? 'bill' : 'ship'];

        return new AddressSnapshot(...array_map(static fn(string $name): string => $values[$name] ?? '', self::ADDRESS_FIELDS));
    }

    /**
     * A row submit of the window without the script (`op`): `add-article` / `add-text` appends an
     * empty row of that kind, `remove:{i}` drops the row at that posted index. True when $op was
     * one of them — the window is then answered with the changed rows, nothing saved.
     */
    public function applyOp(string $op): bool
    {
        if ($op === 'add-article' || $op === 'add-text') {
            $this->rows[] = ['type' => $op === 'add-text' ? LineType::Text->value : LineType::Service->value] + self::EMPTY_ROW;

            return true;
        }
        if (preg_match('/^remove:(\d+)$/', $op, $m)) {
            unset($this->rows[(int) $m[1]]);
            $this->rows = array_values($this->rows);

            return true;
        }

        return false;
    }

    /**
     * The draft, or null with the field errors of what cannot be read.
     *
     * @param array<string, int>                  $articles  article number → variant id (the offered ones plus the ones the order holds)
     * @param array<string, AddressSnapshot|null> $ships     the delivery-address choices of the customer → snapshot ('' → null)
     * @param array<string, AddressSnapshot|null> $bills     the billing-address choices, the same shape ('' → the customer's)
     */
    public function toDraft(array $articles, array $ships, ?string $sourceRef = null, array $bills = ['' => null]): ?OrderDraft
    {
        $this->errors    = [];
        $this->rowErrors = [];
        $this->paths     = [];

        $contactId = ctype_digit($this->header['contact']) ? (int) $this->header['contact'] : 0;
        // «angepasst»: the card's own fields — OrderService checks them like any snapshot.
        $ships[self::ADDRESS_CUSTOM] = $this->customAddress('ship');
        $bills[self::ADDRESS_CUSTOM] = $this->customAddress('bill');
        if (!array_key_exists($this->header['ship'], $ships)) {
            $this->errors['ship'] = 'Diese Lieferadresse gehört nicht zum gewählten Kunden — neu wählen.';
        }
        if (!array_key_exists($this->header['bill'], $bills)) {
            $this->errors['bill'] = 'Diese Rechnungsadresse gehört nicht zum gewählten Kunden — neu wählen.';
        }

        /** @var list<array{draft: OrderLineDraft, row: int, children: list<array{draft: OrderLineDraft, row: int}>}> $top */
        $top = [];
        foreach ($this->rows as $i => $row) {
            if ($row['article'] === '' && $row['text'] === '' && $row['price'] === '' && $row['quantity'] === '') {
                continue;
            }
            $variantId = null;
            if ($row['article'] !== '') {
                $variantId = $articles[$row['article']] ?? null;
                if ($variantId === null) {
                    $this->rowErrors[$i]['article'] = 'Den Artikel «' . $row['article'] . '» gibt es nicht oder er ist inaktiv.';
                    continue;
                }
            }
            $draft = new OrderLineDraft(
                type: $row['type'],
                variantId: $variantId,
                text: $row['text'],
                quantity: $row['quantity'],
                unit: $row['unit'],
                unitPrice: $row['price'],
                discount: $row['discount'],
                id: $row['id'] !== '' ? (int) $row['id'] : null,
            );
            if ($row['child'] !== '') {
                if ($top === []) {
                    $this->rowErrors[$i]['child'] = 'Eine eingerückte Zeile braucht eine Position darüber.';
                    continue;
                }
                $top[count($top) - 1]['children'][] = ['draft' => $draft, 'row' => $i];
                continue;
            }
            $top[] = ['draft' => $draft, 'row' => $i, 'children' => []];
        }
        if ($this->errors !== [] || $this->rowErrors !== []) {
            return null;
        }

        $lines = [];
        foreach ($top as $k => $entry) {
            $this->paths[(string) $k] = $entry['row'];
            $children = [];
            foreach ($entry['children'] as $j => $child) {
                $this->paths[$k . '.' . $j] = $child['row'];
                $children[] = $child['draft'];
            }
            $lines[] = $children === [] ? $entry['draft'] : $entry['draft']->beneath(...$children);
        }

        return new OrderDraft(
            contactId: $contactId,
            orderDate: $this->header['order_date'],
            subject: $this->header['subject'],
            serviceFrom: $this->header['service_from'],
            serviceTo: $this->header['service_to'],
            priceMode: $this->header['price_mode'],
            pricesMode: $this->header['prices_mode'],
            paymentTargetCode: $this->target !== '' ? $this->target : null,
            deliveryAddress: $ships[$this->header['ship']] ?? null,
            notes: $this->header['notes'],
            lines: $lines,
            statusCode: $this->header['status'] !== '' ? $this->header['status'] : null,
            sourceRef: $sourceRef,
            billingAddress: $bills[$this->header['bill']] ?? null,
            paymentTermsCode: $this->header['terms'] !== '' ? $this->header['terms'] : null,
        );
    }

    /**
     * The service's field errors (`InvalidOrderException::$errors`) onto the form's fields: the
     * head by name, `lines.{path}.{field}` onto the row that line came from.
     *
     * @param array<string, string> $errors
     */
    public function applyErrors(array $errors): void
    {
        foreach ($errors as $key => $message) {
            if (preg_match('/^lines\.(\d+(?:\.\d+)?)\.(\w+)$/', $key, $m)) {
                $row = $this->paths[$m[1]] ?? null;
                if ($row !== null) {
                    $this->rowErrors[$row][self::ROW_FIELDS[$m[2]] ?? $m[2]] = $message;
                    continue;
                }
            }
            $field = self::HEADER_FIELDS[$key] ?? $key;
            if (array_key_exists($field, $this->header)) {
                $this->errors[$field] = $message;
            } else {
                $this->general[] = $message;
            }
        }
    }

    public function header(string $key): string { return $this->header[$key] ?? ''; }

    /** The payment target the draft carries — set by the server, never posted ({@see $target}). */
    public function withTarget(string $code): self
    {
        $this->target = $code;

        return $this;
    }

    public function target(): string { return $this->target; }
    /** @return list<array<string, string>> */
    public function rows(): array { return $this->rows; }
    public function error(string $key): string { return $this->errors[$key] ?? ''; }
    public function rowError(int $i, string $key): string { return $this->rowErrors[$i][$key] ?? ''; }
    /** @return list<string> */
    public function generalErrors(): array { return $this->general; }
    public function addGeneralError(string $message): void { $this->general[] = $message; }

    /** @return list<string> the tax codes the rows carry — kept selectable though deactivated */
    public function keptCodes(): array
    {
        return array_values(array_unique(array_filter(array_column($this->rows, 'tax_code'), static fn(string $c) => $c !== '')));
    }

    /** The ids of the invalid fields in document order — the action bar's «N Fehler» link (ADR-049). */
    public function invalidIds(): array
    {
        $ids = [];
        foreach (array_keys($this->header) as $key) {
            if (isset($this->errors[$key])) {
                $ids[] = $key === 'contact' ? 'invoice-contact-name' : 'order-' . $key;   // the customer field's text (debtor's CustomerPicker)
            }
        }
        foreach ($this->rows as $i => $row) {
            foreach (array_keys(self::EMPTY_ROW) as $key) {
                if (isset($this->rowErrors[$i][$key])) {
                    $ids[] = 'order-row-' . $i . '-' . $key;
                }
            }
        }

        return $ids;
    }

    /** «2.000» → «2», «1.500» → «1.5». */
    private static function trimQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
