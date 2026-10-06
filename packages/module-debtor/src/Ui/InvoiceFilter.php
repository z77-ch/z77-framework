<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Repositories\InvoiceSearch;
use Z77\Shared\Money\Money;

/**
 * The state of the document list, read from the address (P3 part 3; the
 * journal list's `JournalFilter` model): the VIEW (`?view=invoicing|final|credit`),
 * the column search (`f_nr`, `f_date`, `f_name`, `f_amount`), the sort
 * (`sort` / `dir`) and the page. The state IS the address — every link of
 * the list is built here ({@see query()}), no JavaScript.
 *
 * Column search, each value parsed strictly; an unreadable one marks its
 * field invalid and is ignored rather than guessed:
 *
 *   - Nr.     — the document number, exact;
 *   - Datum   — `31.03.2026` (the day), `03.2026` (the month) or `2026` (the year);
 *   - Name    — part of the addressee's first name or name (the snapshot);
 *   - Betrag  — the gross total, exact (`1'234.50`, `1234.5`).
 */
final class InvoiceFilter
{
    /** Search field → column title. */
    public const FIELDS = ['f_nr' => 'Nr.', 'f_date' => 'Datum', 'f_name' => 'Name', 'f_amount' => 'Betrag'];

    /** Search field → the sort key of its column. */
    public const SORT_OF = ['f_nr' => 'number', 'f_date' => 'date', 'f_name' => 'name', 'f_amount' => 'amount'];

    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, true> */
    private array $invalid = [];

    private ?int $number = null;
    private ?string $dateFrom = null;
    private ?string $dateTo = null;
    private ?string $name = null;
    private ?string $amount = null;

    private function __construct(
        public readonly string $view,
        public readonly string $sort,
        public readonly bool $descending,
        public readonly int $page,
    ) {}

    /** @param array<string, mixed> $query the GET parameters */
    public static function fromQuery(array $query, string $currency): self
    {
        $view = is_string($query['view'] ?? null) && in_array($query['view'], InvoiceSearch::VIEWS, true) ? $query['view'] : InvoiceSearch::VIEW_INVOICING;
        $sort = is_string($query['sort'] ?? null) && in_array($query['sort'], InvoiceSearch::SORTS, true) ? $query['sort'] : 'number';
        $dir  = ($query['dir'] ?? '') === 'asc' ? false : (($query['dir'] ?? '') === 'desc' ? true : $sort !== 'name');
        $page = max(1, (int) ($query['page'] ?? 1));

        $filter = new self($view, $sort, $dir, $page);
        foreach (array_keys(self::FIELDS) as $key) {
            $value = is_string($query[$key] ?? null) ? trim($query[$key]) : '';
            if ($value === '') {
                continue;
            }
            $value = mb_substr($value, 0, 80);   // what is kept is what is searched (review 2026-09-30)
            $filter->values[$key] = $value;
            if (!$filter->parse($key, $value, $currency)) {
                $filter->invalid[$key] = true;
            }
        }

        return $filter;
    }

    public function search(): InvoiceSearch
    {
        return new InvoiceSearch($this->view, $this->number, $this->dateFrom, $this->dateTo, $this->name, $this->amount, $this->sort, $this->descending);
    }

    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function isInvalid(string $key): bool
    {
        return isset($this->invalid[$key]);
    }

    /** Any column search is set. */
    public function isActive(): bool
    {
        return $this->values !== [];
    }

    /**
     * The query string of this state with $changes applied (null removes a
     * key); a change of the view or of the search starts at page 1.
     *
     * @param array<string, string|int|bool|null> $changes
     */
    public function query(array $changes = []): string
    {
        $state = ['view' => $this->view] + $this->values + ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc', 'page' => $this->page];
        if (array_diff(array_keys($changes), ['page', 'sort', 'dir']) !== []) {
            $state['page'] = 1;
        }
        foreach ($changes as $key => $value) {
            if ($value === null || $value === '') {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }
        if (($state['page'] ?? 1) === 1) {
            unset($state['page']);
        }
        if (($state['view'] ?? '') === InvoiceSearch::VIEW_INVOICING) {
            unset($state['view']);
        }

        return http_build_query($state);
    }

    /** @return array<string, string> the state a search form carries in hidden fields (the column values travel as inputs) */
    public function hiddenState(): array
    {
        return array_filter(['view' => $this->view === InvoiceSearch::VIEW_INVOICING ? '' : $this->view, 'sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']);
    }

    private function parse(string $key, string $value, string $currency): bool
    {
        switch ($key) {
            case 'f_nr':
                if (!preg_match('/^\d{1,9}$/', $value)) { return false; }
                $this->number = (int) $value;
                return true;
            case 'f_date':
                if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    $this->dateFrom = $this->dateTo = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    return true;
                }
                if (preg_match('/^(\d{1,2})\.(\d{4})$/', $value, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
                    $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $m[2], $m[1]));
                    $this->dateFrom = $first->format('Y-m-d');
                    $this->dateTo   = $first->modify('last day of this month')->format('Y-m-d');
                    return true;
                }
                if (preg_match('/^\d{4}$/', $value)) {
                    $this->dateFrom = $value . '-01-01';
                    $this->dateTo   = $value . '-12-31';
                    return true;
                }
                return false;
            case 'f_name':
                $this->name = $value;
                return true;
            case 'f_amount':
                // The shape InvoiceForm::parseAmount() accepts — a 20-digit number would overflow
                // Money (\OverflowException) and must be «invalid», never a 500 (review 2026-09-30).
                $normalized = str_replace(["'", '’', ' '], '', str_replace(',', '.', $value));
                if (!preg_match('/^-?\d{1,11}(\.\d{1,2})?$/', $normalized)) {
                    return false;
                }
                try {
                    $this->amount = Money::fromDecimal($normalized, $currency)->toDecimal();
                    return true;
                } catch (\InvalidArgumentException | \OverflowException) {
                    return false;
                }
        }

        return false;
    }
}
