<?php
namespace Z77\Module\Financial\Ui;

use Z77\Shared\Money\AmountFormat;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Services\VatException;
use Z77\Module\Vat\Services\VatRates;
use Z77\Shared\Money\Money;

/**
 * The multi-line manual-entry form, «Sammelbuchung» (owner, 2026-09-22 —
 * for real splits; the default capture form is {@see OneLineEntryForm}): a
 * date, a text and n rows — account NUMBER, debit or credit, an optional tax
 * code, an optional tax amount («Steuer»), a row text — turned into a manual
 * {@see PostingRequest}, with the field errors the screen shows. No script:
 * a fixed number of blank rows, «Weitere Zeilen» is a server-side submit that
 * adds rows ({@see preview()}), and the balance (Σ debit, Σ credit,
 * difference) and the computed VAT lines are shown after every submit.
 *
 * VAT on a row (ADR-041 decision 7, net method): the tax code goes on the
 * NET row; the form resolves the rate for the entry date (`VatRates` — the
 * entry date is the rate date) and computes base = the row's amount, tax =
 * base × rate, rounded once. A typed «Steuer» replaces the computed tax
 * within the voucher-rounding limit of the one-line entry
 * ({@see GrossEntryBuilder::taxCorrectionLimit()}). Sign of base and tax:
 * positive when the row stands on the code's NATURAL side
 * ({@see TaxCategory::isDebitNatural()}), negative on the opposite one, so a
 * revenue reduction posts a negative base and the return sums correctly.
 *
 * The lock of the VAT accounts (ADR-042 addendum 2 of 2026-11-25, point 7;
 * ADR-041 addendum 2026-11-25, point 6) — from «Abrechnung ab» on, for a
 * liable mandator ({@see VatAccountMap::lockedAccounts()} non-empty on the
 * entry date):
 *
 *   - the tax lines are NOT typed: per VAT account and side the form writes
 *     ONE computed line, Σ sign × tax of the coded rows mapping there
 *     ({@see VatAccountMap::taxLines()} — reverse charge: owed AND input),
 *     shown read-only as «MWST-Zeilen (berechnet)» before the total and
 *     placed after the last row feeding it;
 *   - a row WITH a code on a VAT account is a «nur Steuer» row: base 0.00,
 *     the code's rate, its amount IS the tax — allowed only where
 *     {@see VatAccountMap::mayCarryCode()} says so (VM / VI on their input
 *     account, VE / VK / VN on theirs). With VE / VK / VN the account may
 *     stay empty: the form sets the code's account. Those three are never a
 *     net row;
 *   - a row WITHOUT a code on a VAT account is a row error.
 *
 * Before «Abrechnung ab», after «Abrechnung bis», for a mandator not liable —
 * and for a caller without a {@see VatAccountMap} — the form works as it did
 * before the lock: the code computes the tax data of its net row, the tax
 * line is entered like any other row.
 *
 * An entry posted BEFORE the lock with a typed tax line («old entry», S4-2):
 * {@see keepFrom()} compares each typed line on a VAT account with what the
 * form computes. Equal, or within the voucher-rounding limit (the deviation
 * becomes the «Steuer» of the net row), the line is TAKEN OVER — it leaves the
 * input rows, and the computed line keeps its position and text, so an
 * unchanged save rebuilds exactly the stored lines (a no-op for the
 * service). A typed line that does not match stays a row and carries the
 * error at once. When the line rows are left as they were opened, the stored
 * lines are handed on unjudged — text or date of an old entry stay
 * correctable (S4-6); the ledger's own rule decides.
 *
 * Tax codes offered: ACTIVE codes, the three input-tax corrections included
 * (they exist here only), plus, on an edit, the codes the entry already
 * carries (a deactivated code may be kept, never newly chosen — ADR-043
 * decision 19).
 */
final class ManualEntryForm
{
    public const INITIAL_ROWS = 4;
    public const MORE_ROWS    = 3;

    /** The text of a computed tax line (board S4-1). */
    public const COMPUTED_LINE_TEXT = 'MWST';

    /**
     * The row fields, in the order the template shows them, with the POST
     * key each arrives under — the row text posts as `line_text[]` because
     * `text` is the entry's own text field.
     */
    private const ROW_FIELDS = ['account' => 'account', 'debit' => 'debit', 'credit' => 'credit', 'tax_code' => 'tax_code', 'tax_amount' => 'tax_amount', 'text' => 'line_text'];

    /** The option groups of the code select, by category (board S4-1). */
    private const CODE_GROUPS = [
        'Umsatz'                          => [TaxCategory::Standard, TaxCategory::Reduced, TaxCategory::Special, TaxCategory::Zero, TaxCategory::Exempt],
        'Vorsteuer'                       => [TaxCategory::InputMaterial, TaxCategory::InputOther],
        'Bezugsteuer'                     => [TaxCategory::ReverseCharge],
        'Korrekturen (nur Sammelbuchung)' => [TaxCategory::InputSubsequent, TaxCategory::InputCorrection, TaxCategory::InputReduction],
    ];

    private string $date = '';
    private string $text = '';

    /** @var list<array<string, string>> one entry per row, keys {@see ROW_FIELDS} */
    private array $rows = [];

    /** @var array<string, string> header field → message */
    private array $errors = [];

    /** @var list<string> */
    private array $generalErrors = [];

    /** @var array<int, array<string, string>> row → field → message */
    private array $rowErrors = [];

    /** @var array<int, string> row → «MWST …» hint after validation (the help lists them) */
    private array $taxHints = [];

    /** @var array<int, string> row → the muted note in its text cell («nur Steuer», «… · 1171 gesetzt») */
    private array $rowNotes = [];

    /** @var array<string, true> codes an existing entry already carries — allowed although deactivated */
    private array $keptCodes = [];

    /** @var array<string, true> the stored lines of an edited entry (fingerprint keys) — an unchanged line keeps a deactivated account */
    private array $keptLines = [];

    /** @var list<array<string, mixed>>|null the stored lines of an edited entry (`JournalLine::snapshot()`), null for a new one */
    private ?array $storedLines = null;

    private ?\DateTimeImmutable $storedDate = null;

    /** @var list<array<string, string>> the rows an edited entry opens with — the typed tax lines taken over are not among them */
    private array $storedRows = [];

    /** @var list<int> per row of {@see $storedRows} the position of its stored line */
    private array $storedRowPositions = [];

    /** @var array<string, array{position: int, text: ?string}> computed-line key («account|side») → the stored line it took over */
    private array $takeOver = [];

    /** Whether the lock of the VAT accounts applied on the date of the last analysis. */
    private bool $lockApplies = false;

    /** @var list<array{account: string, side: string, amount: Money, note: string}> the computed tax lines of the last analysis, in posting order */
    private array $computed = [];

    /** @var list<array{account: string, correction: bool}> the «nur Steuer» rows of the last analysis */
    private array $taxOnly = [];

    /**
     * @param VatAccountMap|null $vatAccounts the VAT accounts, their signs and the lock; null = the lock is not applied (a caller without a mandator)
     */
    public function __construct(
        private readonly string $currency,
        private readonly AccountRepository $accounts,
        private readonly TaxCodeRepository $codes,
        private readonly VatRates $rates,
        private readonly ?VatAccountMap $vatAccounts = null,
    ) {}

    /** A blank form for a new entry on $date, with the initial rows. */
    public function startBlank(\DateTimeImmutable $date): self
    {
        $this->date = $date->format('Y-m-d');
        $this->addRows(self::INITIAL_ROWS);

        return $this;
    }

    /**
     * The form pre-filled from an existing MANUAL entry (edit). Under the
     * lock the rows are analysed at once: a typed tax line that does not
     * match shows its error when the form opens, not only at the save
     * (board S4-2).
     */
    public function startFrom(JournalEntry $entry): self
    {
        $this->keepFrom($entry);
        $this->date = $entry->getDate()->format('Y-m-d');
        $this->text = $entry->getText();
        $this->rows = $this->storedRows;
        $this->addRows(1);
        $this->analyse(relief: false);
        $this->errors        = [];
        $this->generalErrors = [];

        return $this;
    }

    /**
     * The form as posted (`$_POST` shape: `date`, `text`, and the row fields
     * as indexed arrays `account[]`, `debit[]`, …). Rows keep their order,
     * blank rows included, so the screen re-renders what was typed.
     *
     * @param array<string, mixed> $post
     */
    public function fromPost(array $post): self
    {
        $this->date = trim((string) ($post['date'] ?? ''));
        $this->text = trim((string) ($post['text'] ?? ''));
        $this->rows = [];
        $count = 0;
        foreach (self::ROW_FIELDS as $postKey) {
            $count = max($count, is_array($post[$postKey] ?? null) ? count($post[$postKey]) : 0);
        }
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach (self::ROW_FIELDS as $field => $postKey) {
                $value       = is_array($post[$postKey] ?? null) ? ($post[$postKey][$i] ?? '') : '';
                $row[$field] = is_string($value) ? trim($value) : '';
            }
            $this->rows[] = $row;
        }
        if ($this->rows === []) {
            $this->addRows(self::INITIAL_ROWS);
        }

        return $this;
    }

    /**
     * What an edited entry may KEEP although it could not be chosen anew
     * (ADR-043 decision 19, the `ContactAddressValidator` model): its tax
     * codes stay selectable when deactivated, and an UNCHANGED line keeps an
     * account that was deactivated since. A new or changed line needs an
     * active account and an active code. `ManualEntryService` applies the
     * same line rule; the form applies it first so the row shows the error.
     *
     * Also remembers the stored lines: the rows the entry opens with, the
     * typed tax lines taken over (class comment) and what «lines unchanged»
     * is compared with.
     */
    public function keepFrom(JournalEntry $entry): self
    {
        foreach ($entry->getLines() as $line) {
            if ($line->getTaxCode() !== null) {
                $this->keptCodes[$line->getTaxCode()] = true;
            }
            $this->keptLines[self::lineKey($line->snapshot())] = true;
        }
        $this->storedDate  = $entry->getDate();
        $this->storedLines = array_values(array_map(static fn(JournalLine $line) => $line->snapshot(), $entry->getLines()));
        $this->classifyStored();

        return $this;
    }

    /** A line's identity for «unchanged»: the fingerprint keys (`PostingLine::fingerprint()` / `JournalLine::snapshot()`). */
    private static function lineKey(array $line): string
    {
        $row = [];
        foreach (['account', 'debit', 'credit', 'tax_code', 'tax_rate', 'tax_base', 'tax_amount', 'text'] as $key) {
            $row[$key] = $line[$key] ?? null;
        }

        return json_encode($row, JSON_THROW_ON_ERROR);
    }

    public function addRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->rows[] = array_fill_keys(array_keys(self::ROW_FIELDS), '');
        }
    }

    /**
     * «Weitere Zeilen»: the computed tax lines and the balance for what is
     * typed so far, without the errors — nothing is posted, a half-typed row
     * is not yet wrong.
     */
    public function preview(): void
    {
        $this->analyse(relief: false);
        $this->errors        = [];
        $this->rowErrors     = [];
        $this->generalErrors = [];
    }

    /**
     * Validate and build the request. Null when anything is wrong — the
     * errors and the hints are then set for the re-render.
     */
    public function toRequest(): ?PostingRequest
    {
        $lines = $this->analyse();
        $date  = self::parseDate($this->date);
        if ($lines === null || $this->hasErrors() || $date === null) {
            return null;
        }

        try {
            return PostingRequest::manual($date, $this->text, $lines);
        } catch (\InvalidArgumentException $e) {
            $this->generalErrors[] = $e->getMessage();

            return null;
        }
    }

    /** A refusal from the service (period closed, …), shown at the top. */
    public function addGeneralError(string $message): void
    {
        $this->generalErrors[] = $message;
    }

    public function date(): string { return $this->date; }
    public function text(): string { return $this->text; }

    /** @return list<array<string, string>> */
    public function rows(): array { return $this->rows; }

    public function error(string $field): string { return $this->errors[$field] ?? ''; }
    public function rowError(int $row, string $field): string { return $this->rowErrors[$row][$field] ?? ''; }
    public function taxHint(int $row): string { return $this->taxHints[$row] ?? ''; }
    public function rowNote(int $row): string { return $this->rowNotes[$row] ?? ''; }

    /** @return list<string> */
    public function generalErrors(): array { return $this->generalErrors; }

    public function hasErrors(): bool
    {
        return $this->errors !== [] || $this->rowErrors !== [] || $this->generalErrors !== [];
    }

    /**
     * The computed tax lines for the read-only block «MWST-Zeilen
     * (berechnet)»: account, its name, side, amount and the note (codes,
     * rate, base; a voucher tax; a line taken over).
     *
     * @return list<array{account: string, name: string, side: string, amount: Money, note: string}>
     */
    public function computedLines(): array
    {
        return array_map(fn(array $line) => $line + ['name' => (string) $this->accounts->findOneBy(['number' => $line['account']])?->getName()], $this->computed);
    }

    /** Whether the screen shows the block «MWST-Zeilen (berechnet)»: under the lock, when a row carries a code that writes or is tax. */
    public function showsVatBlock(): bool
    {
        return $this->lockApplies && ($this->computed !== [] || $this->taxOnly !== []);
    }

    /** The block's line when no tax line is computed because the «nur Steuer» rows are the tax themselves (board S4-1, part 2). */
    public function vatBlockEmptyText(): string
    {
        if ($this->computed !== [] || $this->taxOnly === []) {
            return '';
        }
        $accounts   = array_values(array_unique(array_column($this->taxOnly, 'account')));
        $correction = array_filter($this->taxOnly, static fn(array $row) => !$row['correction']) === [];
        $what       = $correction ? 'die Korrektur' : 'die Steuer';

        return count($accounts) === 1
            ? 'keine — die Zeile auf ' . $accounts[0] . ' ist selbst ' . $what
            : 'keine — die Zeilen auf ' . implode(', ', $accounts) . ' sind selbst ' . $what;
    }

    /** «8.10 auf 1170, 20.25 auf 2200» — what the total includes as computed VAT (the total row names it with a difference). */
    public function computedSummary(): string
    {
        return implode(', ', array_map(static fn(array $line) => AmountFormat::of($line['amount']) . ' auf ' . $line['account'], $this->computed));
    }

    /**
     * Σ debit and Σ credit of what can be read as an amount, the computed tax
     * lines included — shown after every submit, so «Weitere Zeilen» already
     * tells the bookkeeper where the entry stands.
     *
     * @return array{0: Money, 1: Money}
     */
    public function sums(): array
    {
        $debit  = Money::zero($this->currency);
        $credit = Money::zero($this->currency);
        foreach ($this->rows as $row) {
            $d = self::parseAmount($row['debit'] ?? '', $this->currency);
            $c = self::parseAmount($row['credit'] ?? '', $this->currency);
            if ($d !== null && $d->isPositive()) { $debit = $debit->add($d); }
            if ($c !== null && $c->isPositive()) { $credit = $credit->add($c); }
        }
        foreach ($this->computed as $line) {
            if ($line['side'] === 'debit') { $debit = $debit->add($line['amount']); }
            else                           { $credit = $credit->add($line['amount']); }
        }

        return [$debit, $credit];
    }

    /**
     * The accounts a row may name — active AND postable — in chart order,
     * for the `<datalist>`.
     *
     * @return list<Account>
     */
    public function postableAccounts(): array
    {
        return array_values(array_filter($this->accounts->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive()));
    }

    /**
     * The tax codes a row may carry: active ones — the three input-tax
     * corrections included, offered here only —, plus the entry's own (edit).
     * Sorted by code.
     *
     * @return list<TaxCode>
     */
    public function selectableCodes(): array
    {
        return $this->codes->selectable(array_keys($this->keptCodes), includeManualOnly: true);   // the shared picker list (module-vat)
    }

    /**
     * {@see selectableCodes()} in the option groups of the select (board
     * S4-1): Umsatz, Vorsteuer, Bezugsteuer, Korrekturen; a code of an
     * unknown category last, under «Andere». Empty groups are left out.
     *
     * @return list<array{label: string, codes: list<TaxCode>}>
     */
    public function codeGroups(): array
    {
        $groups = array_fill_keys(array_keys(self::CODE_GROUPS), []) + ['Andere' => []];
        foreach ($this->selectableCodes() as $code) {
            $label = 'Andere';
            foreach (self::CODE_GROUPS as $groupLabel => $categories) {
                if (in_array($code->category(), $categories, true)) {
                    $label = $groupLabel;
                    break;
                }
            }
            $groups[$label][] = $code;
        }
        $result = [];
        foreach ($groups as $label => $codes) {
            if ($codes !== []) {
                $result[] = ['label' => $label, 'codes' => $codes];
            }
        }

        return $result;
    }

    // ── analysis ────────────────────────────────────────────────────────

    /**
     * Validate every row and build the lines — the rows' own lines with the
     * computed tax lines placed in between. Sets the errors, hints, notes and
     * the computed block; null when a line could not be built. $relief: an
     * edit whose line rows are unchanged hands on the stored lines unjudged
     * (the save only — the opened form and «Weitere Zeilen» show the errors
     * and the computed block).
     *
     * @return list<PostingLine>|null
     */
    private function analyse(bool $relief = true): ?array
    {
        $this->errors = $this->rowErrors = $this->taxHints = $this->rowNotes = [];
        $this->generalErrors = [];
        $this->computed      = $this->taxOnly = [];

        $date = self::parseDate($this->date);
        if ($date === null) {
            $this->errors['date'] = 'Datum ist ein Pflichtfeld (gültiges Datum).';
        }
        if ($this->text === '') {
            $this->errors['text'] = 'Buchungstext ist ein Pflichtfeld.';
        } elseif (mb_strlen($this->text) > JournalEntry::TEXT_LENGTH) {
            $this->errors['text'] = 'Buchungstext darf maximal ' . JournalEntry::TEXT_LENGTH . ' Zeichen lang sein.';
        }

        $locked            = $date === null ? [] : $this->lockedOn($date);
        $this->lockApplies = $locked !== [];
        $groups            = [];   // «account|side» → the computed line being summed
        $vatRows           = [];   // row → whether it is a line of the stored entry
        $entries           = [];   // [row, PostingLine] in row order
        foreach ($this->rows as $i => $row) {
            if (self::isBlank($row)) {
                continue;
            }
            $line = $this->lineFor($i, $row, $date, $locked, $groups, $vatRows);
            if ($line !== null) {
                $entries[] = [$i, $line];
            }
        }
        // A row without a code on a VAT account — judged once every computed line is known (the message names it).
        foreach ($vatRows as $i => $isStoredLine) {
            $this->rowErrors[$i]['account'] = $this->vatRowError($this->rows[$i], $isStoredLine, $groups);
        }
        $lines = $this->placeComputed($entries, $groups);

        if (count($lines) < 2 && $this->rowErrors === []) {
            $this->generalErrors[] = 'Eine Buchung braucht mindestens zwei Zeilen.';
        }
        [$debit, $credit] = $this->sums();
        if (!$debit->equals($credit)) {
            $this->generalErrors[] = 'Die Buchung ist nicht ausgeglichen: Soll ' . AmountFormat::of($debit) . ', Haben ' . AmountFormat::of($credit)
                . ' — Differenz ' . AmountFormat::of($debit->subtract($credit)) . '.';
        }
        if ($this->rowErrors !== []) {
            array_unshift($this->generalErrors, 'Die Buchung wurde nicht gebucht — bitte die markierten Zeilen korrigieren.');
        }

        // An edited entry whose line rows are left as they were opened: the stored lines pass on
        // unjudged — text or date of an old entry stay correctable (S4-6); the ledger decides.
        if ($relief && ($this->rowErrors !== [] || $this->generalErrors !== []) && $this->linesUnchanged($date)) {
            $this->rowErrors     = [];
            $this->generalErrors = [];
            $this->computed      = $this->taxOnly = [];
            $this->rowNotes      = [];

            return $this->storedPostingLines();
        }

        return $this->rowErrors === [] ? $lines : null;
    }

    /**
     * One posted row → a PostingLine, or null with the row's errors set. A
     * coded net row under the lock adds its tax to $groups; a row without a
     * code on a VAT account is recorded in $vatRows (its error follows).
     *
     * @param array<string, string> $locked
     * @param array<string, array<string, mixed>> $groups
     * @param array<int, bool> $vatRows
     */
    private function lineFor(int $i, array $row, ?\DateTimeImmutable $date, array $locked, array &$groups, array &$vatRows): ?PostingLine
    {
        $code     = $row['tax_code'] === '' ? null : $this->codes->findByCode($row['tax_code']);
        $category = $code?->category();

        // VE / VK / VN with the account left empty: z77 sets the code's account (S4-15).
        $derived = false;
        if ($row['account'] === '' && $locked !== [] && $date !== null && $code !== null && $category?->isManualOnly() === true) {
            $target = $this->taxOnlyAccount($code, $date);
            if ($target !== null) {
                $row['account'] = $this->rows[$i]['account'] = $target;
                $derived        = true;
            }
        }

        $account = $row['account'] === '' ? null : $this->accounts->findOneBy(['number' => $row['account']]);
        if ($row['account'] === '') {
            $this->rowErrors[$i]['account'] = 'Konto fehlt.';
        } elseif ($account === null) {
            $this->rowErrors[$i]['account'] = 'Konto ' . $row['account'] . ' gibt es nicht.';
        } elseif (!$account->isPostable()) {
            $this->rowErrors[$i]['account'] = '«' . $account->label() . '» ist eine Gruppe — nicht bebuchbar.';
        }
        // An inactive account is decided at the end: an UNCHANGED line of an edit may keep it.

        $debit  = self::parseAmount($row['debit'], $this->currency);
        $credit = self::parseAmount($row['credit'], $this->currency);
        if ($row['debit'] !== '' && $debit === null) {
            $this->rowErrors[$i]['debit'] = 'Kein Betrag (z.B. 100.50, höchstens ' . self::AMOUNT_INTEGER_DIGITS . ' Stellen vor dem Punkt).';
        }
        if ($row['credit'] !== '' && $credit === null) {
            $this->rowErrors[$i]['credit'] = 'Kein Betrag (z.B. 100.50, höchstens ' . self::AMOUNT_INTEGER_DIGITS . ' Stellen vor dem Punkt).';
        }
        $hasDebit  = $debit !== null && $debit->isPositive();
        $hasCredit = $credit !== null && $credit->isPositive();
        if (($debit !== null && $debit->isNegative()) || ($credit !== null && $credit->isNegative())) {
            $this->rowErrors[$i][$debit !== null && $debit->isNegative() ? 'debit' : 'credit'] = 'Kein negativer Betrag — die andere Seite buchen.';
        } elseif ($hasDebit === $hasCredit && !isset($this->rowErrors[$i]['debit']) && !isset($this->rowErrors[$i]['credit'])) {
            $this->rowErrors[$i][$hasDebit ? 'credit' : 'debit'] = $hasDebit ? 'Soll ODER Haben — nicht beides.' : 'Soll oder Haben fehlt.';
        }
        if (mb_strlen($row['text']) > JournalLine::TEXT_LENGTH) {
            $this->rowErrors[$i]['text'] = 'Text darf maximal ' . JournalLine::TEXT_LENGTH . ' Zeichen lang sein.';
        }
        $typed = self::parseAmount($row['tax_amount'], $this->currency);
        if ($row['tax_amount'] !== '' && ($typed === null || $typed->isNegative())) {
            $this->rowErrors[$i]['tax_amount'] = 'Kein Steuerbetrag (z.B. 8.10) — leer lassen, um ihn zu berechnen.';
        } elseif ($row['tax_amount'] !== '' && $row['tax_code'] === '') {
            $this->rowErrors[$i]['tax_amount'] = 'Steuer nur mit MWST-Code — sonst leer lassen.';
        }
        $amount = $hasDebit ? $debit : $credit;
        $onVat  = $account !== null && isset($locked[$account->getNumber()]);

        $tax = null;
        if ($row['tax_code'] !== '') {
            if ($code === null) {
                $this->rowErrors[$i]['tax_code'] = 'MWST-Code ' . $row['tax_code'] . ' gibt es nicht.';
            } elseif (!$code->isActive() && !isset($this->keptCodes[$code->getCode()])) {
                $this->rowErrors[$i]['tax_code'] = 'MWST-Code ' . $code->getCode() . ' ist inaktiv — nur bestehende Zeilen behalten ihn.';
            } elseif ($date !== null && $account !== null && !isset($this->rowErrors[$i])) {
                $tax = $locked !== [] && ($onVat || $category?->isManualOnly() === true)
                    ? $this->taxOnlyFor($i, $code, $account->getNumber(), $amount, $hasDebit, $date, $derived, $onVat)
                    : $this->netTaxFor($i, $code, $amount, $hasDebit, $typed, $date, $locked !== [], $groups);
            }
        } elseif ($onVat && !isset($this->rowErrors[$i])) {
            // Without a code on a VAT account: the error follows once every computed line is known.
            $line = new PostingLine($account->getNumber(), $hasDebit ? $debit : Money::zero($this->currency), $hasCredit ? $credit : Money::zero($this->currency),
                null, null, null, null, $row['text'] === '' ? null : $row['text']);
            $vatRows[$i] = isset($this->keptLines[self::lineKey($line->fingerprint())]);

            return null;
        }
        if (isset($this->rowErrors[$i])) {
            return null;
        }

        $line = new PostingLine(
            $account->getNumber(),
            $hasDebit ? $debit : Money::zero($this->currency),
            $hasCredit ? $credit : Money::zero($this->currency),
            $tax['code'] ?? null,
            $tax['rate'] ?? null,
            $tax['base'] ?? null,
            $tax['amount'] ?? null,
            $row['text'] === '' ? null : $row['text'],
        );
        if (!$account->isActive() && !isset($this->keptLines[self::lineKey($line->fingerprint())])) {
            $this->rowErrors[$i]['account'] = '«' . $account->label() . '» ist inaktiv — nur eine unveränderte Zeile behält es.';

            return null;
        }

        return $line;
    }

    /**
     * The tax data of a coded NET row: base and tax signed by the code's
     * natural side, the tax computed or typed within the limit. Under the
     * lock its tax lines are added to $groups. Null with the row's error set.
     *
     * @param array<string, array<string, mixed>> $groups
     * @return array{code: string, rate: int, base: Money, amount: Money}|null
     */
    private function netTaxFor(int $i, TaxCode $code, Money $amount, bool $onDebit, ?Money $typed, \DateTimeImmutable $date, bool $locked, array &$groups): ?array
    {
        try {
            $rate = $this->rates->resolve($code->getCode(), $date);
        } catch (VatException) {
            $this->rowErrors[$i]['tax_code'] = 'Für ' . $code->getCode() . ' gilt am ' . $date->format('d.m.Y') . ' kein Satz.';

            return null;
        }
        $category = TaxCategory::tryFrom($rate->category);
        $natural  = $category?->isDebitNatural() === true ? 'debit' : 'credit';
        $base     = ($onDebit ? 'debit' : 'credit') === $natural ? $amount : $amount->negate();
        $computed = $base->multiplyByRatio($rate->rate, 10000);
        $tax      = $computed;
        if ($typed !== null) {
            $limit = GrossEntryBuilder::taxCorrectionLimit(self::abs($computed));
            $off   = self::abs($typed->subtract(self::abs($computed)));
            if ($category !== null && !$category->carriesTax() && !$typed->isZero()) {
                $this->rowErrors[$i]['tax_amount'] = 'MWST-Code ' . $code->getCode() . ' ist steuerfrei — kein Steuerbetrag.';
            } elseif ($typed->isZero() && !$computed->isZero()) {
                $this->rowErrors[$i]['tax_amount'] = 'Steuer 0.00 geht nur, wenn die berechnete Steuer 0.00 ist (hier ' . AmountFormat::of(self::abs($computed)) . ').';
            } elseif ($off->greaterThan($limit)) {
                $this->rowErrors[$i]['tax_amount'] = 'Steuerbetrag ' . AmountFormat::of($typed) . ' weicht um ' . AmountFormat::of($off) . ' vom berechneten '
                    . AmountFormat::of(self::abs($computed)) . ' ab — erlaubt sind höchstens ' . AmountFormat::of($limit) . ' (Rundung laut Beleg).';
            }
            if (isset($this->rowErrors[$i])) {
                return null;
            }
            $tax = $base->isNegative() ? $typed->negate() : $typed;
        }
        if ($locked && !$this->addToGroups($i, $code, $rate->rate, $base, $tax, $computed, $date, $groups)) {
            return null;
        }
        $this->taxHints[$i] = 'MWST ' . $code->getCode() . ' ' . self::percent($rate->rate) . ': ' . AmountFormat::of($tax) . ' auf ' . AmountFormat::of($base);

        return ['code' => $code->getCode(), 'rate' => $rate->rate, 'base' => $base, 'amount' => $tax];
    }

    /**
     * The tax data of a «nur Steuer» row (ADR-041 addendum 2026-11-25, point
     * 6): base 0.00, the code's rate at the rate date, the amount IS the tax,
     * signed by the code's natural side. Only where the code may sit on that
     * account ({@see VatAccountMap::mayCarryCode()}); a typed «Steuer» is
     * refused. Null with the row's error set.
     *
     * @return array{code: string, rate: int, base: Money, amount: Money}|null
     */
    private function taxOnlyFor(int $i, TaxCode $code, string $account, Money $amount, bool $onDebit, \DateTimeImmutable $date, bool $derived, bool $onVat): ?array
    {
        $category = $code->category();
        $target   = $this->taxOnlyAccount($code, $date);
        if (!$onVat) {
            // VE / VK / VN typed on an ordinary account: they are never a net row.
            $this->rowErrors[$i]['account'] = $code->getCode() . ' ist immer «nur Steuer» — Konto leer lassen oder ' . ($target ?? 'das MWST-Konto') . ' tippen.';

            return null;
        }
        try {
            $allowed = $category?->allowsTaxOnly() === true && $this->vatAccounts->mayCarryCode($code->getCode(), $account, $date);
        } catch (VatAccountUnavailableException $e) {
            $this->generalErrors[] = $e->getMessage();

            return null;
        } catch (FormMappingException | UnmappedTaxCodeException | VatException) {
            $allowed = false;
        }
        if (!$allowed) {
            $this->rowErrors[$i]['tax_code'] = match (true) {
                $category === TaxCategory::ReverseCharge => 'Bezugsteuer rechnet z77 aus der Aufwandzeile — den Code ' . $code->getCode() . ' auf die Aufwandzeile setzen, nicht auf ' . $account . '.',
                $category?->allowsTaxOnly() === true      => $code->getCode() . ' gehört auf ' . ($target ?? 'sein MWST-Konto') . ' — nicht auf ' . $account . '.',
                default                                   => 'Geschuldete MWST rechnet z77 immer aus dem Umsatz — den Code ' . $code->getCode() . ' auf die Ertragszeile setzen, nicht auf ' . $account . '.',
            };

            return null;
        }
        if ($this->rows[$i]['tax_amount'] !== '') {
            $this->rowErrors[$i]['tax_amount'] = 'Bei «nur Steuer» ist der Betrag die Steuer — «Steuer» leer lassen.';

            return null;
        }
        try {
            $rate = $this->rates->resolve($code->getCode(), $date);
        } catch (VatException) {
            $this->rowErrors[$i]['tax_code'] = 'Für ' . $code->getCode() . ' gilt am ' . $date->format('d.m.Y') . ' kein Satz.';

            return null;
        }
        $natural = $category->isDebitNatural() ? 'debit' : 'credit';
        $tax     = ($onDebit ? 'debit' : 'credit') === $natural ? $amount : $amount->negate();

        $this->taxOnly[]    = ['account' => $account, 'correction' => $category->isManualOnly()];
        $this->rowNotes[$i] = 'nur Steuer' . ($derived ? ' · ' . $account . ' gesetzt' : '');
        $this->taxHints[$i] = 'MWST ' . $code->getCode() . ' ' . self::percent($rate->rate) . ': nur Steuer ' . AmountFormat::of($tax);

        return ['code' => $code->getCode(), 'rate' => $rate->rate, 'base' => Money::zero($this->currency), 'amount' => $tax];
    }

    /**
     * Adds a net row's tax to the computed lines it maps to — per VAT account
     * and side ONE line, Σ sign × tax ({@see VatAccountMap::taxLines()}).
     * False with the row's error set when the code cannot be mapped.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function addToGroups(int $i, TaxCode $code, int $rate, Money $base, Money $tax, Money $computed, \DateTimeImmutable $date, array &$groups): bool
    {
        try {
            $taxLines = $this->vatAccounts->taxLines($code->getCode(), $date);
        } catch (VatAccountUnavailableException $e) {
            $this->generalErrors[] = $e->getMessage();

            return false;
        } catch (FormMappingException | UnmappedTaxCodeException | VatException) {
            $this->rowErrors[$i]['tax_code'] = 'Für ' . $code->getCode() . ' kennt das MWST-Formular am ' . $date->format('d.m.Y') . ' kein Vorsteuerfeld.';

            return false;
        }
        foreach ($taxLines as $taxLine) {
            $signed = $taxLine['sign'] > 0 ? $tax : $tax->negate();   // debit − credit
            if ($signed->isZero()) {
                continue;
            }
            if ($taxLine['account'] === null) {
                $message = 'Für MWST-Code ' . $code->getCode() . ' ist kein MWST-Konto hinterlegt — ' . LedgerService::MANDATOR_HINT . '.';
                if (!in_array($message, $this->generalErrors, true)) {
                    $this->generalErrors[] = $message;
                }
                continue;
            }
            $account = (string) $taxLine['account'];
            $side    = $signed->isPositive() ? 'debit' : 'credit';
            $key     = $account . '|' . $side;
            $groups[$key] ??= ['account' => $account, 'side' => $side, 'amount' => Money::zero($this->currency), 'after' => $i, 'codes' => [], 'notes' => []];
            $groups[$key]['amount'] = $groups[$key]['amount']->add(self::abs($signed));
            $groups[$key]['after']  = $i;
            $groups[$key]['codes'][$code->getCode()] ??= ['rate' => $rate, 'base' => Money::zero($this->currency)];
            $groups[$key]['codes'][$code->getCode()]['base'] = $groups[$key]['codes'][$code->getCode()]['base']->add(self::abs($base));
            if (!$tax->equals($computed)) {
                $groups[$key]['notes'][] = 'Belegsteuer ' . AmountFormat::of(self::abs($tax)) . ' statt ' . AmountFormat::of(self::abs($computed));
            }
        }

        return true;
    }

    /**
     * The rows' lines with the computed tax lines in between: each after the
     * last row feeding it, a line that took over a stored one at that line's
     * position with its text. Fills the block of the screen.
     *
     * @param list<array{0: int, 1: PostingLine}> $entries
     * @param array<string, array<string, mixed>> $groups
     * @return list<PostingLine>
     */
    private function placeComputed(array $entries, array $groups): array
    {
        $built = [];
        foreach ($groups as $key => $group) {
            if ($group['amount']->isZero()) {
                continue;
            }
            $account = $this->accounts->findOneBy(['number' => $group['account']]);
            if ($account === null || !$account->isPostable() || !$account->isActive()) {
                $message = 'Das MWST-Konto ' . $group['account'] . ' gibt es nicht, oder es ist eine Gruppe oder inaktiv — ' . LedgerService::MANDATOR_HINT . '.';
                if (!in_array($message, $this->generalErrors, true)) {
                    $this->generalErrors[] = $message;
                }
                continue;
            }
            $kept  = $this->takeOver[$key] ?? null;
            $notes = [];
            foreach ($group['codes'] as $code => $data) {
                $notes[] = $code . ' ' . self::percent($data['rate']) . ' auf ' . AmountFormat::of($data['base']);
            }
            $notes = array_merge($notes, $group['notes']);
            if ($kept !== null) {
                $notes[] = 'aus der gebuchten Zeile ' . ($kept['position'] + 1) . ' übernommen';
            }
            $text  = $kept !== null ? $kept['text'] : self::COMPUTED_LINE_TEXT;
            $line  = $group['side'] === 'debit'
                ? PostingLine::debit($group['account'], $group['amount'], $text)
                : PostingLine::credit($group['account'], $group['amount'], $text);
            $built[] = ['line' => $line, 'after' => $group['after'], 'position' => $kept['position'] ?? null,
                'display' => ['account' => $group['account'], 'side' => $group['side'], 'amount' => $group['amount'], 'note' => implode(' · ', $notes)]];
        }

        $lines = [];
        foreach ($entries as [$row, $line]) {
            $lines[] = $line;
            foreach ($built as $item) {
                if ($item['position'] === null && $item['after'] === $row) {
                    $lines[] = $item['line'];
                }
            }
        }
        $kept = array_values(array_filter($built, static fn(array $item) => $item['position'] !== null));
        usort($kept, static fn(array $a, array $b) => $a['position'] <=> $b['position']);
        foreach ($kept as $item) {
            array_splice($lines, min($item['position'], count($lines)), 0, [$item['line']]);
        }

        // The block lists the computed lines in posting order.
        foreach ($lines as $line) {
            foreach ($built as $item) {
                if ($item['line'] === $line) {
                    $this->computed[] = $item['display'];
                }
            }
        }

        return $lines;
    }

    /**
     * The message of a row without a code on a VAT account (boards S4-1 part
     * 3, S4-2 part 3): which code the computed line comes from; a line of the
     * stored entry is named as such.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function vatRowError(array $row, bool $isStoredLine, array $groups): string
    {
        $account = $row['account'];
        $onIt    = array_values(array_filter($groups, static fn(array $group) => $group['account'] === $account && !$group['amount']->isZero()));
        if ($onIt === []) {
            return $account . ' ist ein MWST-Konto: eine Zeile darauf braucht einen MWST-Code («nur Steuer») — sonst die Zeile entfernen.';
        }
        $side  = ($row['debit'] !== '') ? 'debit' : 'credit';
        $same  = array_values(array_filter($onIt, static fn(array $group) => $group['side'] === $side));
        $group = $same[0] ?? $onIt[0];
        $codes = implode(' / ', array_keys($group['codes']));

        return $isStoredLine
            ? 'Zeile auf MWST-Konto ' . $account . ': z77 rechnet ' . AmountFormat::of($group['amount']) . ' aus Code ' . $codes . ' — diese Zeile entfernen und die Gegenzeile prüfen.'
            : $account . ' ist ein MWST-Konto: die Steuerzeile schreibt z77 aus dem Code ' . $codes . ' — diese Zeile entfernen.';
    }

    /** The account a «nur Steuer» row of $code sits on — the input account of its one tax line; null when there is none or the mandator leaves it empty. */
    private function taxOnlyAccount(TaxCode $code, \DateTimeImmutable $date): ?string
    {
        if ($this->vatAccounts === null || $code->category()?->allowsTaxOnly() !== true) {
            return null;
        }
        try {
            $taxLines = $this->vatAccounts->taxLines($code->getCode(), $date);
        } catch (VatAccountUnavailableException | FormMappingException | UnmappedTaxCodeException | VatException) {
            return null;
        }

        return count($taxLines) === 1 && $taxLines[0]['account'] !== null ? (string) $taxLines[0]['account'] : null;
    }

    /**
     * The VAT accounts the lock guards on $date — account number (string) →
     * mandator key; empty without a map or when the lock does not apply.
     *
     * @return array<string, string>
     */
    private function lockedOn(\DateTimeImmutable $date): array
    {
        $locked = [];
        foreach ($this->vatAccounts?->lockedAccounts($date) ?? [] as $number => $key) {
            $locked[(string) $number] = $key;   // a numeric array key comes back as int
        }

        return $locked;
    }

    // ── the stored entry ────────────────────────────────────────────────

    /**
     * The rows an edited entry opens with, and the typed tax lines taken
     * over (class comment, board S4-2). A coded net line whose stored tax
     * differs from base × rate (a voucher tax) opens with that tax in
     * «Steuer», so an unchanged save rebuilds it.
     */
    private function classifyStored(): void
    {
        $this->takeOver = [];
        $locked         = $this->storedDate === null ? [] : $this->lockedOn($this->storedDate);
        $rows           = [];
        $contribution   = [];   // «account|side» → ['amount' => Money, 'rows' => position → [tax, computed], 'pair' => bool]
        foreach ($this->storedLines ?? [] as $position => $line) {
            $row = [
                'account'    => (string) $line['account'],
                'debit'      => AmountFormat::field(Money::fromDecimal((string) $line['debit'], $this->currency)),
                'credit'     => AmountFormat::field(Money::fromDecimal((string) $line['credit'], $this->currency)),
                'tax_code'   => (string) $line['tax_code'],
                'tax_amount' => '',
                'text'       => (string) $line['text'],
            ];
            if ($line['tax_code'] !== null && $line['tax_base'] !== null && $line['tax_amount'] !== null) {
                $base = Money::fromDecimal((string) $line['tax_base'], $this->currency);
                $tax  = Money::fromDecimal((string) $line['tax_amount'], $this->currency);
                if (!($base->isZero() && isset($locked[(string) $line['account']]))) {   // a «nur Steuer» line has no «Steuer»
                    $computed = $base->multiplyByRatio((int) $line['tax_rate'], 10000);
                    if (!self::abs($tax)->equals(self::abs($computed))) {
                        $row['tax_amount'] = self::abs($tax)->toDecimal();
                    }
                    if ($locked !== [] && !isset($locked[(string) $line['account']])) {
                        $this->contribute($contribution, $position, (string) $line['tax_code'], $tax, $computed);
                    }
                }
            }
            $rows[$position] = $row;
        }

        foreach ($locked === [] ? [] : $this->storedLines as $position => $line) {
            if ($line['tax_code'] !== null || !isset($locked[(string) $line['account']])) {
                continue;
            }
            $debit = Money::fromDecimal((string) $line['debit'], $this->currency);
            $side  = $debit->isPositive() ? 'debit' : 'credit';
            $typed = $side === 'debit' ? $debit : Money::fromDecimal((string) $line['credit'], $this->currency);
            $key   = $line['account'] . '|' . $side;
            $group = $contribution[$key] ?? null;
            if ($group === null || isset($this->takeOver[$key])) {
                continue;
            }
            if (!$typed->equals($group['amount'])) {
                // Within the voucher-rounding limit of ONE net row: the deviation becomes that row's «Steuer» (board S4-2, part 2).
                $off = $typed->subtract($group['amount']);
                $own = null;
                foreach ($group['rows'] as $rowPosition => $taxes) {
                    if ($own === null || $taxes['tax']->greaterThan($group['rows'][$own]['tax'])) {
                        $own = $rowPosition;
                    }
                }
                $newTax = $own === null ? null : $group['rows'][$own]['tax']->add($off);
                if ($group['pair'] || $newTax === null || !$newTax->isPositive()
                    || self::abs($newTax->subtract($group['rows'][$own]['computed']))->greaterThan(GrossEntryBuilder::taxCorrectionLimit($group['rows'][$own]['computed']))) {
                    continue;
                }
                $rows[$own]['tax_amount'] = $newTax->toDecimal();
            }
            $this->takeOver[$key] = ['position' => $position, 'text' => $line['text'] === null ? null : (string) $line['text']];
            unset($rows[$position]);
        }
        $this->storedRowPositions = array_keys($rows);
        $this->storedRows         = array_values($rows);
    }

    /**
     * Adds a stored net line's tax to the expected movement per VAT account and side.
     *
     * @param array<string, array<string, mixed>> $contribution
     */
    private function contribute(array &$contribution, int $position, string $taxCode, Money $tax, Money $computed): void
    {
        try {
            $taxLines = $this->vatAccounts?->taxLines($taxCode, $this->storedDate) ?? [];
        } catch (VatAccountUnavailableException | FormMappingException | UnmappedTaxCodeException | VatException) {
            return;
        }
        foreach ($taxLines as $taxLine) {
            $signed = $taxLine['sign'] > 0 ? $tax : $tax->negate();
            if ($taxLine['account'] === null || $signed->isZero()) {
                continue;
            }
            $key = $taxLine['account'] . '|' . ($signed->isPositive() ? 'debit' : 'credit');
            $contribution[$key] ??= ['amount' => Money::zero($this->currency), 'rows' => [], 'pair' => false];
            $contribution[$key]['amount']          = $contribution[$key]['amount']->add(self::abs($signed));
            $contribution[$key]['rows'][$position] = ['tax' => self::abs($tax), 'computed' => self::abs($computed)];
            $contribution[$key]['pair']            = $contribution[$key]['pair'] || count($taxLines) > 1;
        }
    }

    /**
     * Whether the line rows are those the edited entry opened with — a row's
     * text aside (the ledger's relief compares accounts, amounts and tax
     * data) — and the lock is not newly entered by a date moved across
     * «Abrechnung ab» (then the entry is judged like a new one, board S4-2).
     */
    private function linesUnchanged(?\DateTimeImmutable $date): bool
    {
        if ($this->storedLines === null || $date === null || $this->storedDate === null) {
            return false;
        }
        if ($this->lockedOn($date) !== [] && $this->lockedOn($this->storedDate) === []) {
            return false;
        }
        $signature = fn(array $rows) => array_values(array_map(fn(array $row) => $this->rowSignature($row), array_filter($rows, static fn(array $row) => !self::isBlank($row))));

        return $signature($this->rows) === $signature($this->storedRows);
    }

    /** A row as compared for «unchanged»: amounts parsed, the code normalised, the text left out. */
    private function rowSignature(array $row): string
    {
        $amount = function (string $value): string {
            $money = self::parseAmount($value, $this->currency);

            return $money === null ? $value : ($money->isZero() ? '' : $money->toDecimal());
        };

        return json_encode([
            trim($row['account'] ?? ''),
            $amount($row['debit'] ?? ''),
            $amount($row['credit'] ?? ''),
            TaxCode::normalizeCode($row['tax_code'] ?? ''),
            $amount($row['tax_amount'] ?? ''),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The stored lines of the edited entry, as they are — only a row's text
     * as typed now (the rows stand in the stored order, {@see linesUnchanged()}).
     *
     * @return list<PostingLine>
     */
    private function storedPostingLines(): array
    {
        $money = fn(?string $value): ?Money => $value === null ? null : Money::fromDecimal($value, $this->currency);
        $texts = [];
        foreach (array_values(array_filter($this->rows, static fn(array $row) => !self::isBlank($row))) as $i => $row) {
            $texts[$this->storedRowPositions[$i]] = $row['text'] === '' ? null : $row['text'];
        }

        return array_map(fn(array $line, int $position) => new PostingLine(
            (string) $line['account'],
            $money((string) $line['debit']),
            $money((string) $line['credit']),
            $line['tax_code'],
            $line['tax_rate'] === null ? null : (int) $line['tax_rate'],
            $money($line['tax_base']),
            $money($line['tax_amount']),
            array_key_exists($position, $texts) ? $texts[$position] : $line['text'],
        ), $this->storedLines ?? [], array_keys($this->storedLines ?? []));
    }

    private static function abs(Money $money): Money
    {
        return $money->isNegative() ? $money->negate() : $money;
    }

    private static function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Digits an amount may have before the decimal point: `journal_line`
     * stores DECIMAL(15,2), so 13. A longer amount is a field error here,
     * not a database error at the flush.
     */
    public const AMOUNT_INTEGER_DIGITS = 13;

    /** A form amount: `100`, `100.5`, `100,50`, `1'000.00` — into Money; null when it is not one or exceeds {@see AMOUNT_INTEGER_DIGITS}. Shared with {@see OneLineEntryForm}. */
    public static function parseAmount(string $value, string $currency): ?Money
    {
        $value = str_replace(["'", ' ', ','], ['', '', '.'], trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[+-]?0*([0-9]*)/', $value, $m) && strlen($m[1]) > self::AMOUNT_INTEGER_DIGITS) {
            return null;
        }
        try {
            return Money::fromDecimal($value, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** A `YYYY-MM-DD` form value as a date, or null when it is not a real calendar date. */
    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** 810 → «8.1 %». Integer arithmetic on the hundredths. */
    public static function percent(int $rate): string
    {
        $text = rtrim(rtrim(intdiv($rate, 100) . '.' . str_pad((string) ($rate % 100), 2, '0', STR_PAD_LEFT), '0'), '.');

        return $text . ' %';
    }
}
