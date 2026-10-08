<?php

namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Reports\JournalSearch;

/**
 * The journal list's search and order as the URL carries them
 * (FIN-JOURNAL-CAPTURE-001). The state IS the address — a GET form, no
 * JavaScript: bookmarkable, and every link on the page (a sort, a page)
 * keeps the rest of it.
 *
 * One field per column magnifier, prefixed `f_` so they never collide with
 * the capture area's own `date` / `mode`:
 *
 *   f_nr      exact number            «17»
 *   f_date    a day, a month, a year  «15.11.2032», «11.2032», «2032», «2032-11-15»
 *   f_text    contained in the text   «Miete»
 *   f_debit   account with a debit line   «6500» (a leading number is enough: «6500 Büromaterial»)
 *   f_credit  account with a credit line
 *   f_amount  the entry total         «1'250.50», «1250,5»
 *
 * plus `all` (every fiscal year instead of the shown one), `sort` / `dir`
 * and `page`. (The deleted numbers are no longer shown inline — the change
 * log is a screen of its own, Finanzen › Änderungsprotokoll, owner 2026-10-08.) A value that cannot be
 * read (a date «31.02.2032») is kept for the field, marked invalid and does
 * not narrow the search — a typo must not look like «nothing found».
 */
final class JournalFilter
{
    /** URL key → label; the order is the column order. */
    public const FIELDS = [
        'f_nr'     => 'Nr.',
        'f_date'   => 'Datum',
        'f_text'   => 'Text',
        'f_debit'  => 'Soll',
        'f_credit' => 'Haben',
        'f_amount' => 'Betrag',
    ];

    /** Column field → the sort it offers (the account columns do not sort). */
    public const SORT_OF = ['f_nr' => 'number', 'f_date' => 'date', 'f_text' => 'text', 'f_amount' => 'amount'];

    /** @var array<string, string> raw field values, trimmed */
    private array $values = [];

    /** @var array<string, true> */
    private array $invalid = [];

    private ?int $number = null;
    private ?string $dateFrom = null;
    private ?string $dateTo = null;
    private ?string $debit = null;
    private ?string $credit = null;
    private ?string $amount = null;

    private function __construct(
        public readonly bool $allYears,
        public readonly string $sort,
        public readonly bool $descending,
        public readonly int $page,
    ) {
    }

    /** @param array<string, mixed> $query the GET parameters */
    public static function fromQuery(array $query, string $currency): self
    {
        $sort = is_string($query['sort'] ?? null) && in_array($query['sort'], JournalSearch::SORTS, true) ? $query['sort'] : 'number';
        $filter = new self(
            ($query['all'] ?? '') === '1',
            $sort,
            ($query['dir'] ?? 'desc') !== 'asc',
            max(1, (int) ($query['page'] ?? 1)),
        );
        foreach (array_keys(self::FIELDS) as $key) {
            $value = is_string($query[$key] ?? null) ? trim($query[$key]) : '';
            if ($value !== '') {
                $filter->values[$key] = $value;
            }
        }
        $filter->parse($currency);

        return $filter;
    }

    /** The search for the list — scoped to $fiscalYearId unless «alle Geschäftsjahre» is on. */
    public function search(?int $fiscalYearId): JournalSearch
    {
        return new JournalSearch(
            $this->allYears ? null : $fiscalYearId,
            $this->number,
            $this->dateFrom,
            $this->dateTo,
            $this->text(),
            $this->debit,
            $this->credit,
            $this->amount,
            $this->sort,
            $this->descending,
        );
    }

    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function isInvalid(string $key): bool
    {
        return isset($this->invalid[$key]);
    }

    /** Whether any column field narrows the list. */
    public function isActive(): bool
    {
        return $this->values !== [];
    }

    /**
     * The query string of this state with $changes applied — for the sort
     * links and the pager. A change to anything but the page
     * starts at page 1 again; empty / default values are left out.
     *
     * @param array<string, string|int|bool|null> $changes
     */
    public function query(array $changes = []): string
    {
        $state = $this->values + [
            'all'     => $this->allYears ? '1' : '',
            'sort'    => $this->sort === 'number' ? '' : $this->sort,
            'dir'     => $this->descending ? '' : 'asc',
            'page'    => $this->page > 1 ? (string) $this->page : '',
        ];
        if (!array_key_exists('page', $changes)) {
            $state['page'] = '';
        }
        foreach ($changes as $key => $value) {
            $state[$key] = $value === null || $value === false ? '' : (string) ($value === true ? '1' : $value);
        }
        if (($state['sort'] ?? '') === 'number') {
            $state['sort'] = '';
        }
        if (($state['dir'] ?? '') === 'desc') {
            $state['dir'] = '';
        }

        return http_build_query(array_filter($state, static fn($v) => $v !== '' && $v !== null));
    }

    /** The hidden fields that carry the non-column state through the search form. @return array<string, string> */
    public function hiddenState(): array
    {
        return array_filter([
            'all'  => $this->allYears ? '1' : '',
            'sort' => $this->sort === 'number' ? '' : $this->sort,
            'dir'  => $this->descending ? '' : 'asc',
        ], static fn($v) => $v !== '');
    }

    private function text(): ?string
    {
        return $this->values['f_text'] ?? null;
    }

    private function parse(string $currency): void
    {
        if (isset($this->values['f_nr'])) {
            if (ctype_digit($this->values['f_nr'])) {
                $this->number = (int) $this->values['f_nr'];
            } else {
                $this->invalid['f_nr'] = true;
            }
        }
        if (isset($this->values['f_date'])) {
            $range = self::dateRange($this->values['f_date']);
            if ($range === null) {
                $this->invalid['f_date'] = true;
            } else {
                [$this->dateFrom, $this->dateTo] = $range;
            }
        }
        foreach (['f_debit' => 'debit', 'f_credit' => 'credit'] as $key => $property) {
            if (isset($this->values[$key])) {
                // «6500 Büromaterial» from the datalist reads as 6500, like the capture form.
                $this->{$property} = preg_split('/\s+/', $this->values[$key])[0];
            }
        }
        if (isset($this->values['f_amount'])) {
            $money = ManualEntryForm::parseAmount($this->values['f_amount'], $currency);
            if ($money === null) {
                $this->invalid['f_amount'] = true;
            } else {
                $this->amount = $money->toDecimal();
            }
        }
    }

    /**
     * «15.11.2032» / «2032-11-15» → that day; «11.2032» → the month; «2032»
     * → the calendar year. Null when it is none of them or not a real date.
     *
     * @return array{0: string, 1: string}|null `Y-m-d` from, to — inclusive
     */
    public static function dateRange(string $value): ?array
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? array_fill(0, 2, sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? [$value, $value] : null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{4})$/', $value, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            $first = sprintf('%04d-%02d-01', $m[2], $m[1]);

            return [$first, (new \DateTimeImmutable($first))->format('Y-m-t')];
        }
        if (preg_match('/^\d{4}$/', $value)) {
            return [$value . '-01-01', $value . '-12-31'];
        }

        return null;
    }
}
