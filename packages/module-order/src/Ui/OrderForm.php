<?php
namespace Z77\Module\Order\Ui;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Ui\InvoiceForm;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Services\OrderDraft;
use Z77\Module\Order\Services\OrderLineDraft;

/**
 * The order window's form (plan §9.4): a posted form ↔ an {@see OrderDraft}. Plain PHP arrays in
 * and out, no JavaScript (Rule 7): a fixed number of line rows, «Weitere Zeilen» is a submit the
 * server answers with more rows (the invoice editor's model, `InvoiceForm`).
 *
 * A line row: its `id` (hidden — an existing line keeps its identity, the stock seam's line
 * reference; order.md rule), the type (Leistung | Pauschale | Text), «unter» (beneath the nearest
 * preceding top-level row — one level), the ARTICLE number (a `<datalist>` of the active articles;
 * empty = a free line), text, quantity (negative = a return), unit, price, discount, and — only on
 * a FREE line — tax code and revenue account (on an article line they come from the article, D3).
 * An article line with an empty text / unit / price takes the article's values when it is SAVED
 * (`OrderService`, no script needed). A row with neither article, text, quantity nor price is
 * left out — an existing line emptied like that is removed.
 *
 * The form only turns the fields into a draft and says which field it cannot read (an unknown
 * article number, an unknown delivery address); every order rule is `OrderService`'s, its
 * `InvalidOrderException` comes back per field through {@see applyErrors()}.
 */
final class OrderForm
{
    public const ROWS_NEW  = 4;
    public const MORE_ROWS = 3;

    /** Row type → German label (debtor's `LineType` without the rounding line). */
    public const TYPES = InvoiceForm::TYPES;

    /** The delivery-address choice that keeps the snapshot the order already holds. */
    public const SHIP_KEEP = 'keep';

    private const EMPTY_ROW = ['id' => '', 'type' => 'service', 'child' => '', 'article' => '', 'text' => '', 'quantity' => '', 'unit' => '', 'price' => '', 'discount' => '', 'tax_code' => '', 'account' => ''];

    /** The service's field names → the form's. */
    private const HEADER_FIELDS = ['contact_id' => 'contact', 'payment_target_code' => 'target', 'status_code' => 'status'];
    private const ROW_FIELDS    = ['variant' => 'article', 'unit_price' => 'price', 'revenue_account' => 'account', 'children' => 'child'];

    /** @var array<string, string> */
    private array $header = ['contact' => '', 'subject' => '', 'order_date' => '', 'service_from' => '', 'service_to' => '', 'price_mode' => 'net', 'target' => '', 'ship' => '', 'status' => '', 'notes' => ''];

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

    /** A new order: today, net, the customer and payment target the screen preselects, empty rows. */
    public function startBlank(\DateTimeImmutable $today, ?int $contactId, string $target, string $status): self
    {
        $this->header['contact']    = $contactId !== null ? (string) $contactId : '';
        $this->header['order_date'] = $today->format('Y-m-d');
        $this->header['target']     = $target;
        $this->header['status']     = $status;
        $this->rows = array_fill(0, self::ROWS_NEW, self::EMPTY_ROW);

        return $this;
    }

    /** The fields of a stored order, one row per line (children marked «unter»), plus one empty row. */
    public function startFrom(SalesOrder $order): self
    {
        $this->header = [
            'contact'      => (string) $order->getContact()->getId(),
            'subject'      => $order->getSubject(),
            'order_date'   => $order->getOrderDate()->format('Y-m-d'),
            'service_from' => $order->getServiceFrom()?->format('Y-m-d') ?? '',
            'service_to'   => $order->getServiceTo()?->format('Y-m-d') ?? '',
            'price_mode'   => $order->priceMode()->value,
            'target'       => (string) $order->getPaymentTargetCode(),
            'ship'         => $order->deliveryAddress() !== null ? self::SHIP_KEEP : '',
            'status'       => $order->getStatusCode(),
            'notes'        => (string) $order->getNotes(),
        ];
        $this->rows = [];
        foreach ($order->getLines() as $line) {
            $this->rows[] = [
                'id'       => (string) $line->getId(),
                'type'     => $line->type()->value,
                'child'    => $line->getParentLine() !== null ? '1' : '',
                'article'  => $line->getVariant() !== null ? (string) $line->getArticleNumber() : '',
                'text'     => $line->getText(),
                'quantity' => $line->getQuantity() === null ? '' : self::trimQuantity($line->getQuantity()),
                'unit'     => (string) $line->getUnit(),
                'price'    => $line->getUnitPrice()?->toDecimal() ?? '',
                'discount' => InvoiceForm::formatPercent($line->getDiscountPercent()),
                'tax_code' => $line->getVariant() === null ? (string) $line->getTaxCode() : '',
                'account'  => $line->getVariant() === null ? (string) $line->getRevenueAccount() : '',
            ];
        }
        $this->addRows(1);

        return $this;
    }

    /** @param array<string, mixed> $post */
    public function fromPost(array $post): self
    {
        foreach (array_keys($this->header) as $key) {
            $this->header[$key] = is_string($post[$key] ?? null) ? trim($post[$key]) : '';
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

    public function addRows(int $count): self
    {
        for ($i = 0; $i < $count; $i++) {
            $this->rows[] = self::EMPTY_ROW;
        }

        return $this;
    }

    /**
     * The draft, or null with the field errors of what cannot be read.
     *
     * @param array<string, int>                  $articles  article number → variant id (the offered ones plus the ones the order holds)
     * @param array<string, AddressSnapshot|null> $ships     the delivery-address choices of the customer → snapshot ('' → null)
     */
    public function toDraft(array $articles, array $ships, ?string $sourceRef = null): ?OrderDraft
    {
        $this->errors    = [];
        $this->rowErrors = [];
        $this->paths     = [];

        $contactId = ctype_digit($this->header['contact']) ? (int) $this->header['contact'] : 0;
        if (!array_key_exists($this->header['ship'], $ships)) {
            $this->errors['ship'] = 'Diese Lieferadresse gehört nicht zum gewählten Kunden — neu wählen.';
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
                taxCode: $variantId === null ? $row['tax_code'] : '',
                revenueAccount: $variantId === null ? $row['account'] : '',
                id: $row['id'] !== '' ? (int) $row['id'] : null,
            );
            if ($row['child'] !== '') {
                if ($top === []) {
                    $this->rowErrors[$i]['child'] = 'Eine Unterposition braucht eine Position darüber.';
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
            paymentTargetCode: $this->header['target'] !== '' ? $this->header['target'] : null,
            deliveryAddress: $ships[$this->header['ship']] ?? null,
            notes: $this->header['notes'],
            lines: $lines,
            statusCode: $this->header['status'] !== '' ? $this->header['status'] : null,
            sourceRef: $sourceRef,
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
                $ids[] = 'order-' . $key;
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
