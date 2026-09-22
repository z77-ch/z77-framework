<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Vat\Calculation\ResolvedRate;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Services\VatException;
use Z77\Module\Vat\Services\VatRates;
use Z77\Shared\Money\Money;

/**
 * The manual-entry form (owner, 2026-09-22): a date, a text and n lines —
 * account NUMBER, debit or credit, an optional tax code, a line text —
 * turned into a manual {@see PostingRequest}, with the field errors the
 * screen shows. Done WITHOUT JavaScript (Rule 7): a fixed number of blank
 * rows, «Weitere Zeilen» is a server-side submit that adds rows, and the
 * balance (Σ debit, Σ credit, difference) is shown after every submit.
 *
 * VAT on a manual line (ADR-041 decision 7, net method): the bookkeeper
 * picks a tax code on the NET line only; the form resolves the rate for
 * the entry date (`VatRates`, the rate in effect that day — the manual
 * entry's date is its service date) and computes base = the line's amount,
 * tax = base × rate, rounded once. The tax-account line (1170 / 2200) is
 * entered by hand like any other line — the balance check and the hint
 * («MWST 8.10 auf 100.00») tell the bookkeeper what it has to be. Sign of
 * base and amount: positive when the line stands on the code's NATURAL
 * side (credit for an output code, debit for an input code —
 * `input-material` / `input-other`), negative on the opposite side, so a
 * manual revenue reduction posts a negative base and the return sums
 * correctly. That convention lives here, in the form: the ledger stores
 * what it is given.
 *
 * Tax codes offered: ACTIVE codes (ADR-043 decision 19 — a new document
 * picks active codes), plus, on an edit, the codes the entry already
 * carries (a deactivated code may be kept, never newly chosen).
 */
final class ManualEntryForm
{
    public const INITIAL_ROWS = 4;
    public const MORE_ROWS    = 3;

    /**
     * The row fields, in the order the template shows them, with the POST
     * key each arrives under — the line text posts as `line_text[]` because
     * `text` is the entry's own text field.
     */
    private const ROW_FIELDS = ['account' => 'account', 'debit' => 'debit', 'credit' => 'credit', 'tax_code' => 'tax_code', 'text' => 'line_text'];

    private string $date = '';
    private string $text = '';

    /** @var list<array<string, string>> one entry per row, keys {@see ROW_FIELDS} */
    private array $rows = [];

    /** @var array<string, string> header field → message; `general` holds the entry-level ones under numeric keys */
    private array $errors = [];

    /** @var list<string> */
    private array $generalErrors = [];

    /** @var array<int, array<string, string>> row → field → message */
    private array $rowErrors = [];

    /** @var array<int, string> row → «MWST …» hint after validation */
    private array $taxHints = [];

    /** @var array<string, true> codes an existing entry already carries — allowed although deactivated */
    private array $keptCodes = [];

    /** @var array<string, true> the stored lines of an edited entry (fingerprint keys) — an unchanged line keeps a deactivated account */
    private array $keptLines = [];

    public function __construct(
        private readonly string $currency,
        private readonly AccountRepository $accounts,
        private readonly TaxCodeRepository $codes,
        private readonly VatRates $rates,
    ) {}

    /** A blank form for a new entry on $date, with the initial rows. */
    public function startBlank(\DateTimeImmutable $date): self
    {
        $this->date = $date->format('Y-m-d');
        $this->addRows(self::INITIAL_ROWS);

        return $this;
    }

    /** The form pre-filled from an existing MANUAL entry (edit). */
    public function startFrom(JournalEntry $entry): self
    {
        $this->keepFrom($entry);
        $this->date = $entry->getDate()->format('Y-m-d');
        $this->text = $entry->getText();
        foreach ($entry->getLines() as $line) {
            $this->rows[] = [
                'account'  => $line->getAccount()->getNumber(),
                'debit'    => AmountFormat::field($line->getDebit()),
                'credit'   => AmountFormat::field($line->getCredit()),
                'tax_code' => (string) $line->getTaxCode(),
                'text'     => (string) $line->getText(),
            ];
        }
        $this->addRows(1);

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
     */
    public function keepFrom(JournalEntry $entry): self
    {
        foreach ($entry->getLines() as $line) {
            if ($line->getTaxCode() !== null) {
                $this->keptCodes[$line->getTaxCode()] = true;
            }
            $this->keptLines[self::lineKey($line->snapshot())] = true;
        }

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
     * Validate and build the request. Null when anything is wrong — the
     * errors and the hints are then set for the re-render.
     */
    public function toRequest(): ?PostingRequest
    {
        $this->errors = $this->rowErrors = $this->taxHints = [];
        $this->generalErrors = [];

        $date = self::parseDate($this->date);
        if ($date === null) {
            $this->errors['date'] = 'Datum ist ein Pflichtfeld (gültiges Datum).';
        }
        if ($this->text === '') {
            $this->errors['text'] = 'Buchungstext ist ein Pflichtfeld.';
        } elseif (mb_strlen($this->text) > JournalEntry::TEXT_LENGTH) {
            $this->errors['text'] = 'Buchungstext darf maximal ' . JournalEntry::TEXT_LENGTH . ' Zeichen lang sein.';
        }

        $lines = [];
        foreach ($this->rows as $i => $row) {
            if (self::isBlank($row)) {
                continue;
            }
            $line = $this->lineFor($i, $row, $date);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($lines === [] || count($lines) < 2) {
            if ($this->rowErrors === []) {
                $this->generalErrors[] = 'Eine Buchung braucht mindestens zwei Zeilen.';
            }
        }
        [$debit, $credit] = $this->sums();
        if (!$debit->equals($credit)) {
            $this->generalErrors[] = 'Die Buchung ist nicht ausgeglichen: Soll ' . AmountFormat::of($debit) . ', Haben ' . AmountFormat::of($credit)
                . ' — Differenz ' . AmountFormat::of($debit->subtract($credit)) . '.';
        }
        if ($this->hasErrors() || $date === null) {
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

    /** @return list<string> */
    public function generalErrors(): array { return $this->generalErrors; }

    public function hasErrors(): bool
    {
        return $this->errors !== [] || $this->rowErrors !== [] || $this->generalErrors !== [];
    }

    /**
     * Σ debit and Σ credit of what can be read as an amount — shown after
     * every submit, so «Weitere Zeilen» already tells the bookkeeper where
     * the entry stands.
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

        return [$debit, $credit];
    }

    /**
     * The accounts a line may name — active AND postable — in chart order,
     * for the `<datalist>`.
     *
     * @return list<Account>
     */
    public function postableAccounts(): array
    {
        return array_values(array_filter($this->accounts->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive()));
    }

    /**
     * The tax codes a line may carry: active ones, plus the entry's own
     * (edit). Sorted by code.
     *
     * @return list<TaxCode>
     */
    public function selectableCodes(): array
    {
        return array_values(array_filter($this->codes->allSorted(), fn(TaxCode $code) => $code->isActive() || isset($this->keptCodes[$code->getCode()])));
    }

    /** One posted row → a PostingLine, or null with the row's errors set. */
    private function lineFor(int $i, array $row, ?\DateTimeImmutable $date): ?PostingLine
    {
        $account = $this->accounts->findOneBy(['number' => $row['account']]);
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
            $this->rowErrors[$i]['debit'] = 'Kein Betrag (z.B. 100.50).';
        }
        if ($row['credit'] !== '' && $credit === null) {
            $this->rowErrors[$i]['credit'] = 'Kein Betrag (z.B. 100.50).';
        }
        $hasDebit  = $debit !== null && $debit->isPositive();
        $hasCredit = $credit !== null && $credit->isPositive();
        if (($debit !== null && $debit->isNegative()) || ($credit !== null && $credit->isNegative())) {
            $this->rowErrors[$i][$debit !== null && $debit->isNegative() ? 'debit' : 'credit'] = 'Kein negativer Betrag — die andere Seite buchen.';
        } elseif ($hasDebit === $hasCredit && !isset($this->rowErrors[$i]['debit'], $this->rowErrors[$i]['credit'])) {
            $this->rowErrors[$i][$hasDebit ? 'credit' : 'debit'] = $hasDebit ? 'Soll ODER Haben — nicht beides.' : 'Soll oder Haben fehlt.';
        }
        if (mb_strlen($row['text']) > JournalLine::TEXT_LENGTH) {
            $this->rowErrors[$i]['text'] = 'Text darf maximal ' . JournalLine::TEXT_LENGTH . ' Zeichen lang sein.';
        }

        $tax = null;
        if ($row['tax_code'] !== '') {
            $code = $this->codes->findByCode($row['tax_code']);
            if ($code === null) {
                $this->rowErrors[$i]['tax_code'] = 'MWST-Code ' . $row['tax_code'] . ' gibt es nicht.';
            } elseif (!$code->isActive() && !isset($this->keptCodes[$code->getCode()])) {
                $this->rowErrors[$i]['tax_code'] = 'MWST-Code ' . $code->getCode() . ' ist inaktiv — nur bestehende Zeilen behalten ihn.';
            } elseif ($date !== null && !isset($this->rowErrors[$i])) {
                try {
                    $tax = $this->taxFor($this->rates->resolve($code->getCode(), $date), $hasDebit ? $debit : $credit, $hasDebit);
                    $this->taxHints[$i] = 'MWST ' . $code->getCode() . ' ' . self::percent($tax['rate']) . ': ' . AmountFormat::of($tax['amount']) . ' auf ' . AmountFormat::of($tax['base']);
                } catch (VatException) {
                    $this->rowErrors[$i]['tax_code'] = 'Für ' . $code->getCode() . ' gilt am ' . $date->format('d.m.Y') . ' kein Satz.';
                }
            }
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
     * Base and tax of a taxed net line, signed by the code's natural side
     * (see the class docblock).
     *
     * @return array{code: string, rate: int, base: Money, amount: Money}
     */
    private function taxFor(ResolvedRate $rate, Money $amount, bool $onDebit): array
    {
        $category    = TaxCategory::tryFrom($rate->category);
        $naturalSide = in_array($category, [TaxCategory::InputMaterial, TaxCategory::InputOther], true) ? 'debit' : 'credit';
        $base        = ($onDebit ? 'debit' : 'credit') === $naturalSide ? $amount : $amount->negate();

        return ['code' => $rate->code, 'rate' => $rate->rate, 'base' => $base, 'amount' => $base->multiplyByRatio($rate->rate, 10000)];
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

    /** A form amount: `100`, `100.5`, `100,50`, `1'000.00` — into Money; null when it is not one. */
    private static function parseAmount(string $value, string $currency): ?Money
    {
        $value = str_replace(["'", ' ', ','], ['', '', '.'], trim($value));
        if ($value === '') {
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
