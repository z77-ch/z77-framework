<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Invoicing\LineDraft;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Shared\Money\Money;

/**
 * The document editor's form (P3 part 3): a posted form ↔ an
 * {@see InvoiceDraft}. Plain PHP arrays in and out, no JavaScript (Rule 7):
 * a fixed number of line rows, «Weitere Zeilen» is a submit the server
 * answers with more rows (the journal's Sammelbuchung model).
 *
 * Three modes, one form:
 *
 *   - a NEW invoice ({@see startBlank()}): the party from the debtor picker,
 *     dates, price mode, payment terms (empty = the debtor's), the payment
 *     target (preselected by the screen), the lines;
 *   - an EDIT of a document in `invoicing` ({@see startFrom()}): the same
 *     fields filled from the document's snapshot; the party is fixed
 *     (`reinvoice()` refuses another one);
 *   - a CREDIT NOTE against a final invoice ({@see startCreditNote()}): the
 *     party fixed, the service dates PREFILLED from the invoice and editable
 *     for a partial period — unchanged dates go to the draft as null, so the
 *     service takes the invoice's and the rate of the original supply applies
 *     (ADR-041 decision 4, the `debtor.md` rule); the lines prefilled from the
 *     invoice (the rounding line excepted) to be cut down to what is
 *     credited; no payment target (a credit note has no payment part).
 *
 * A line row: type (Leistung | Pauschale | Text), «unter» (the row is printed
 * beneath the nearest preceding top-level row — ONE level, the service
 * refuses more), text, quantity + unit (Leistung), price, discount in percent
 * (two decimals → stored in hundredths), tax code (the shared picker of
 * module-vat) and revenue account (the shared account datalist). A row with
 * neither text nor price nor quantity is ignored. The rules of the document
 * stay `InvoicingService`'s: the form only turns the fields into a draft and
 * says which FIELD is unreadable; a refusal of the service comes back as a
 * general error.
 */
final class InvoiceForm
{
    public const ROWS_NEW  = 5;
    public const MORE_ROWS = 3;

    /** Row type → German label; the rounding line is the system's and never offered. */
    public const TYPES = ['service' => 'Leistung', 'lump-sum' => 'Pauschale', 'text' => 'Text'];

    private const EMPTY_ROW = ['type' => 'service', 'child' => '', 'text' => '', 'quantity' => '', 'unit' => '', 'price' => '', 'discount' => '', 'tax_code' => '', 'account' => ''];

    /** @var array<string, string> */
    private array $header = ['contact' => '', 'invoice_date' => '', 'service_from' => '', 'service_to' => '', 'price_mode' => 'net', 'terms' => '', 'target' => ''];

    /** @var list<array<string, string>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<int, array<string, string>> */
    private array $rowErrors = [];

    /** @var list<string> */
    private array $general = [];

    /** @var list<string> tax codes the edited / credited document carries — kept selectable though deactivated */
    private array $keptCodes = [];

    public function __construct(
        private readonly string $currency,
        public readonly InvoiceKind $kind = InvoiceKind::Invoice,
        public readonly ?Invoice $creditNoteOf = null,
    ) {
        if (($kind === InvoiceKind::CreditNote) !== ($creditNoteOf !== null)) {
            throw new \LogicException('A credit-note form names the invoice it corrects; an invoice form names none');
        }
    }

    /** A new invoice: today, net, the party and the payment target the screen preselects, empty rows. */
    public function startBlank(\DateTimeImmutable $today, ?int $contactId, string $target): self
    {
        $this->header['contact']      = $contactId !== null ? (string) $contactId : '';
        $this->header['invoice_date'] = $today->format('Y-m-d');
        $this->header['service_from'] = $today->format('Y-m-d');
        $this->header['target']       = $target;
        $this->rows = array_fill(0, self::ROWS_NEW, self::EMPTY_ROW);

        return $this;
    }

    /** The fields of an issued document (edit in `invoicing`). */
    public function startFrom(Invoice $document): self
    {
        $this->header = [
            'contact'      => (string) $document->getContact()->getId(),
            'invoice_date' => $document->getInvoiceDate()->format('Y-m-d'),
            'service_from' => $document->getServiceFrom()->format('Y-m-d'),
            'service_to'   => $document->getServiceTo()?->format('Y-m-d') ?? '',
            'price_mode'   => $document->getPriceMode(),
            'terms'        => $document->getPaymentTermsCode(),
            'target'       => $document->getPayment()->getTargetCode(),
        ];
        $this->rows = $this->rowsOf($document);
        $this->keepCodesOf($document);
        $this->addRows(1);

        return $this;
    }

    /** A credit note against the FINAL invoice this form names: dates and lines prefilled from it. */
    public function startCreditNote(\DateTimeImmutable $today): self
    {
        $invoice      = $this->creditNoteOf;
        $this->header = [
            'contact'      => (string) $invoice->getContact()->getId(),
            'invoice_date' => $today->format('Y-m-d'),
            'service_from' => $invoice->getServiceFrom()->format('Y-m-d'),
            'service_to'   => $invoice->getServiceTo()?->format('Y-m-d') ?? '',
            'price_mode'   => $invoice->getPriceMode(),
            'terms'        => '',
            'target'       => '',
        ];
        $this->rows = $this->rowsOf($invoice);
        $this->keepCodesOf($invoice);
        $this->addRows(1);

        return $this;
    }

    /**
     * The posted fields. For an edit / a credit note, $document is what the
     * form was rendered from: its codes stay selectable, and the party of a
     * fixed-party form is taken from it, not from the post.
     *
     * @param array<string, mixed> $post
     */
    public function fromPost(array $post, ?Invoice $document = null): self
    {
        foreach (array_keys($this->header) as $key) {
            $this->header[$key] = is_string($post[$key] ?? null) ? trim($post[$key]) : '';
        }
        $fixed = $this->creditNoteOf ?? $document;
        if ($fixed !== null) {
            $this->header['contact'] = (string) $fixed->getContact()->getId();
            $this->keepCodesOf($fixed);
        }
        if ($this->kind === InvoiceKind::CreditNote) {
            $this->header['target'] = '';
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
     * The draft, or null with the field errors set. The DOCUMENT rules are
     * the service's; here only what cannot be read.
     */
    public function toDraft(): ?InvoiceDraft
    {
        $this->errors    = [];
        $this->rowErrors = [];

        $contactId = ctype_digit($this->header['contact']) ? (int) $this->header['contact'] : 0;
        if ($contactId < 1) {
            $this->errors['contact'] = 'Debitor wählen.';
        }
        $invoiceDate = self::parseDate($this->header['invoice_date']);
        if ($invoiceDate === null) {
            $this->errors['invoice_date'] = 'Datum fehlt oder ist ungültig.';
        }
        $serviceFrom = self::parseDate($this->header['service_from']);
        if ($serviceFrom === null && ($this->header['service_from'] !== '' || $this->kind === InvoiceKind::Invoice)) {
            $this->errors['service_from'] = 'Leistungsdatum fehlt oder ist ungültig.';
        }
        $serviceTo = self::parseDate($this->header['service_to']);
        if ($this->header['service_to'] !== '' && $serviceTo === null) {
            $this->errors['service_to'] = 'Datum ungültig.';
        }
        $mode = PriceMode::tryFrom($this->header['price_mode']);
        if ($mode === null) {
            $this->errors['price_mode'] = 'Netto oder brutto wählen.';
        }

        $lines = $this->lineDrafts();
        if ($this->errors !== [] || $this->rowErrors !== []) {
            return null;
        }
        if ($lines === []) {
            $this->general[] = 'Mindestens eine Position erfassen.';
            return null;
        }

        $terms  = $this->header['terms'] !== '' ? $this->header['terms'] : null;
        $target = $this->header['target'] !== '' ? $this->header['target'] : null;
        if ($this->kind === InvoiceKind::CreditNote) {
            // Unchanged dates → null: the service takes the INVOICE's, the rate of the original supply applies.
            $invoice   = $this->creditNoteOf;
            $unchanged = $serviceFrom?->format('Y-m-d') === $invoice->getServiceFrom()->format('Y-m-d')
                && $serviceTo?->format('Y-m-d') === $invoice->getServiceTo()?->format('Y-m-d');

            return InvoiceDraft::creditNote(
                (int) $invoice->getId(), $contactId, $invoiceDate, $this->currency, $lines,
                $unchanged ? null : $serviceFrom, $unchanged ? null : $serviceTo, $mode, $terms,
            );
        }

        return InvoiceDraft::invoice($contactId, $invoiceDate, $serviceFrom, $this->currency, $lines, $serviceTo, $mode, $terms, paymentTargetCode: $target);
    }

    public function header(string $key): string { return $this->header[$key] ?? ''; }
    /** @return list<array<string, string>> */
    public function rows(): array { return $this->rows; }
    public function error(string $key): string { return $this->errors[$key] ?? ''; }
    public function rowError(int $i, string $key): string { return $this->rowErrors[$i][$key] ?? ''; }
    /** @return list<string> */
    public function generalErrors(): array { return $this->general; }
    public function addGeneralError(string $message): void { $this->general[] = $message; }
    /** @return list<string> */
    public function keptCodes(): array { return $this->keptCodes; }

    /** The ids of the invalid fields in document order — the action bar's «N Fehler» link (ADR-049). */
    public function invalidIds(): array
    {
        $ids = [];
        foreach (array_keys($this->header) as $key) {
            if (isset($this->errors[$key])) {
                $ids[] = 'invoice-' . $key;
            }
        }
        foreach ($this->rows as $i => $row) {
            foreach (array_keys(self::EMPTY_ROW) as $key) {
                if (isset($this->rowErrors[$i][$key])) {
                    $ids[] = 'invoice-row-' . $i . '-' . $key;
                }
            }
        }

        return $ids;
    }

    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /** «1'234.50», «1234,5» → Money; null when it is no amount. */
    public static function parseAmount(string $value, string $currency): ?Money
    {
        $normalized = str_replace(["'", '’', ' '], '', str_replace(',', '.', trim($value)));
        if (!preg_match('/^-?\d{1,11}(\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        return Money::fromDecimal($normalized, $currency);
    }

    /** «2», «2.5», «2,25» percent → hundredths (200, 250, 225); null when unreadable. */
    public static function parsePercent(string $value): ?int
    {
        $normalized = str_replace(',', '.', trim($value));
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $normalized, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Hundredths → «2.5» for the field. */
    public static function formatPercent(int $hundredths): string
    {
        if ($hundredths === 0) {
            return '';
        }
        $fraction = rtrim(str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($hundredths, 100) . ($fraction !== '' ? '.' . $fraction : '');
    }

    /**
     * The rows as line drafts, children attached to their parent.
     *
     * @return list<LineDraft>
     */
    private function lineDrafts(): array
    {
        /** @var list<array{draft: LineDraft, children: list<LineDraft>}> $top */
        $top = [];
        foreach ($this->rows as $i => $row) {
            if ($row['text'] === '' && $row['price'] === '' && $row['quantity'] === '') {
                continue;
            }
            $draft = $this->lineDraft($i, $row);
            if ($row['child'] !== '') {
                if ($top === []) {
                    $this->rowErrors[$i]['child'] = 'Eine Unterposition braucht eine Position darüber.';
                    continue;
                }
                if ($draft !== null) {
                    $top[count($top) - 1]['children'][] = $draft;
                }
                continue;
            }
            if ($draft !== null) {
                $top[] = ['draft' => $draft, 'children' => []];
            }
        }

        return array_map(static fn(array $t) => $t['children'] === [] ? $t['draft'] : $t['draft']->beneath(...$t['children']), $top);
    }

    /** @param array<string, string> $row */
    private function lineDraft(int $i, array $row): ?LineDraft
    {
        $type = $row['type'];
        if ($type === 'text') {
            if ($row['text'] === '') {
                $this->rowErrors[$i]['text'] = 'Text fehlt.';
            }
            foreach (['quantity', 'price', 'discount', 'tax_code', 'account'] as $field) {
                if ($row[$field] !== '') {
                    $this->rowErrors[$i][$field] = 'Eine Textposition hat keine Menge, keinen Preis, Rabatt, MWST-Code oder Konto.';
                }
            }

            return isset($this->rowErrors[$i]) ? null : LineDraft::text($row['text']);
        }

        $price = self::parseAmount($row['price'], $this->currency);
        if ($price === null) {
            $this->rowErrors[$i]['price'] = 'Preis fehlt oder ist kein Betrag (z.B. 120.00).';
        }
        $discount = $row['discount'] === '' ? 0 : self::parsePercent($row['discount']);
        if ($discount === null) {
            $this->rowErrors[$i]['discount'] = 'Rabatt in Prozent (z.B. 2.5).';
        }
        if ($row['tax_code'] === '') {
            $this->rowErrors[$i]['tax_code'] = 'MWST-Code wählen.';
        }
        if (!preg_match('/^\d{1,' . InvoiceLine::ACCOUNT_LENGTH . '}$/', $row['account'])) {
            $this->rowErrors[$i]['account'] = 'Ertragskonto (Nummer) fehlt.';
        }
        $quantity = str_replace(',', '.', $row['quantity']);
        if ($type === 'service') {
            if (!preg_match(InvoiceLine::QUANTITY_PATTERN, $quantity)) {
                $this->rowErrors[$i]['quantity'] = 'Menge: Zahl mit höchstens drei Dezimalen.';
            }
        } elseif ($row['quantity'] !== '') {
            $this->rowErrors[$i]['quantity'] = 'Eine Pauschale hat keine Menge.';
        }
        if (isset($this->rowErrors[$i])) {
            return null;
        }

        return $type === 'service'
            ? LineDraft::service($row['text'], $quantity, $row['unit'] !== '' ? $row['unit'] : null, $price, $row['tax_code'], $row['account'], $discount)
            : LineDraft::lumpSum($row['text'], $price, $row['tax_code'], $row['account'], $discount);
    }

    /** @return list<array<string, string>> the document's lines as form rows, the rounding line left out */
    private function rowsOf(Invoice $document): array
    {
        $rows = [];
        foreach ($document->getLines() as $line) {
            if ($line->type() === LineType::Rounding) {
                continue;
            }
            $rows[] = [
                'type'     => $line->type()->value,
                'child'    => $line->getParentLine() !== null ? '1' : '',
                'text'     => $line->getText(),
                'quantity' => $line->getQuantity() === null ? '' : self::trimQuantity($line->getQuantity()),
                'unit'     => (string) $line->getUnit(),
                'price'    => $line->getUnitPrice()?->toDecimal() ?? '',
                'discount' => self::formatPercent($line->getDiscountPercent()),
                'tax_code' => (string) $line->getTaxCode(),
                'account'  => (string) $line->getRevenueAccount(),
            ];
        }

        return $rows;
    }

    private function keepCodesOf(Invoice $document): void
    {
        foreach ($document->getLines() as $line) {
            if ($line->getTaxCode() !== null && !in_array($line->getTaxCode(), $this->keptCodes, true)) {
                $this->keptCodes[] = $line->getTaxCode();
            }
        }
    }

    /** «2.000» → «2», «1.500» → «1.5». */
    private static function trimQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
