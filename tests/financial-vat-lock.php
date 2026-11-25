<?php

/**
 * The lock of the VAT accounts harness (CLI) — `PostingRules::assertVatLinesComputed()` (ADR-042
 * addendum 2 of 2026-11-25, point 7): from «Abrechnung ab» to «Abrechnung bis», for a liable
 * mandator, every entry moves on each of the mandator's VAT accounts exactly the tax its coded lines
 * compute for that account; a coded line stands on a VAT account only when that is the input-side
 * account its code maps to. Called by `LedgerService::post()` / `reverse()` (with the entry reversed)
 * / `amend()` and `ManualEntryService::update()` (with the stored entry); O–P judge the rule itself,
 * L the wired ledger.
 *
 *   O. (offline, no database) the rule's truth table through its pure judgement
 *      (`PostingRules::vatLinesRefusal()`) on a table built from the real sources — the seed codes,
 *      `LedgerService::vatAccountKey()`, `TaxCategory`, the CH country pack — with the KMU numbers
 *      2200 / 1170 / 1171; the exemptions (`vatLockExempt()`); the refusal sentences
 *      (`vatLineRefusalText()`); and the generators that need no database replayed through it:
 *      debtor's `PostingBuilder` (invoice, credit note, fee, a negative line, rounding) and
 *      `PaymentPostingBuilder` (payment, Skonto, Verlust, Gebühren) on documents built in memory,
 *      a ledger reversal, the shapes of the year opening and of the settlement entry.
 *   M. (database) the real method with the real `VatAccountMap` read from the mandator: inside the
 *      window, before «Abrechnung ab», after «Abrechnung bis», not liable, no day; one read per
 *      instance; the exemptions; the account named by its label in the sentence.
 *   P. (database) the probe of risk 1 — every generator of today's tree replayed through the
 *      rule (judged, not posted): `GrossEntryBuilder` as creditor, receipt and the bank call it (input, output,
 *      zero, exempt, refund, tax-only, reverse charge, the split of a deferral and of a reverse
 *      charge), `ReceiptPostingBuilder`, the one-line form, the Sammelbuchung form, debtor's
 *      requests translated to VAT accounts as its gateway does, the year opening's own plan, the
 *      settlement entry of `VatReturnPostingService::plan()`, a bank take-back (a ledger reversal).
 *   L. (database) wired: a manual and a generated entry refused with nothing written, the computed
 *      shapes posted, the window (before «ab», after «bis»), an old entry edited with its lines as
 *      stored (S4-6) and with changed lines, a delete, a module's amend and reversal, the return's and
 *      the opening's entries, R18 (a flagged VAT account and the Sammelbuchung), debtor's gateway
 *      sentence, every screen's refusal arm through `LedgerService::vatLineRefusalText()`.
 *
 * Every name and amount here is synthetic.
 *
 * Run: php tests/financial-vat-lock.php
 * O needs the monorepo's vendor/; M and P a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M and P are reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-lock: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Doctrine\Common\Collections\ArrayCollection;
use Z77\Module\Debtor\Accounting\PostingRequest as DebtorRequest;
use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\AllocationKind;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\InvoiceTax;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Entities\Payment;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Module\Debtor\Invoicing\PostingBuilder;
use Z77\Module\Debtor\Payments\PaymentPostingBuilder;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\PostingRules;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

/** A debit line: [account, amount, code, rate, base, tax]. */
function dr(string $account, string $amount, ?string $code = null, ?int $rate = null, ?string $base = null, ?string $tax = null, ?string $text = null): PostingLine
{
    return PostingLine::debit($account, chf($amount), $text, $code, $rate, $base === null ? null : chf($base), $tax === null ? null : chf($tax));
}

/** A credit line: [account, amount, code, rate, base, tax]. */
function cr(string $account, string $amount, ?string $code = null, ?int $rate = null, ?string $base = null, ?string $tax = null, ?string $text = null): PostingLine
{
    return PostingLine::credit($account, chf($amount), $text, $code, $rate, $base === null ? null : chf($base), $tax === null ? null : chf($tax));
}

/** @param list<PostingLine> $lines */
function manual(string $date, array $lines, string $text = 'Probe'): PostingRequest
{
    return PostingRequest::manual(day($date), $text, $lines);
}

/** @param list<PostingLine> $lines */
function generated(string $date, string $source, array $lines, string $text = 'Probe'): PostingRequest
{
    static $n = 0;
    $n++;

    return PostingRequest::generated(day($date), $text, $source, (string) $n, $source . ':probe:' . $n, $lines);
}

/** Lines as «account D|C amount [code rate base tax]» — the shape a FAIL prints. */
function shape(array $lines): string
{
    return implode(' · ', array_map(static fn(PostingLine $l) => $l->account . ' ' . ($l->debit->isPositive() ? 'D ' . $l->debit->toDecimal() : 'C ' . $l->credit->toDecimal())
        . ($l->hasTax() ? " {$l->taxCode} {$l->taxRate} {$l->taxBase->toDecimal()} {$l->taxAmount->toDecimal()}" : ''), $lines));
}

/** An in-memory account (an entity never persisted). */
function account(string $number, string $name = 'Konto'): Account
{
    return new Account(['number' => $number, 'name' => $name, 'type' => 'asset', 'postable' => true]);
}

/** An in-memory journal entry carrying $request's lines — a stored entry or the entry a reversal reverses. */
function storedEntry(PostingRequest $request): JournalEntry
{
    $entry = new JournalEntry(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')), 7, $request->date, $request->text, $request->kind,
        $request->sourceType, $request->sourceRef, $request->idempotencyKey, 'harness', new \DateTimeImmutable());
    foreach ($request->lines as $i => $l) {
        $entry->addLine(new JournalLine($entry, $i + 1, account($l->account), $l->debit, $l->credit, $l->taxCode, $l->taxRate, $l->taxBase, $l->taxAmount, $l->text));
    }

    return $entry;
}

// ── O. offline: the truth table on a table built from the real sources ──

echo "O. Offline: the rule's truth table (no database)\n";

/** @var array<string, TaxCategory> $categories the seed codes, by code */
$categories = [];
foreach (json_decode((string) file_get_contents($root . '/packages/module-vat/data/framework/vat/tax_codes.default.json'), true) as $row) {
    $categories[$row['code']] = TaxCategory::from($row['category']);
}
$numbers = [LedgerService::VAT_OWED_ACCOUNT => '2200', LedgerService::VAT_INPUT_MATERIAL_ACCOUNT => '1170', LedgerService::VAT_INPUT_OTHER_ACCOUNT => '1171'];
$pack    = FormMapping::forCountry('CH');
// The VatAccountMap's table, built from the same sources it reads (a fixture: the real map needs the mandator record — section M).
$taxLines = static function (string $code, \DateTimeImmutable $date) use ($categories, $numbers, $pack): array {
    $category = $categories[$code] ?? throw new UnknownTaxCodeException($code);
    if (!$category->carriesTax()) {
        return [];
    }
    if ($category === TaxCategory::ReverseCharge) {
        $edition = $pack->edition($date, $date);
        $input   = $edition->inputCategoryOf($code) ?? throw new UnmappedTaxCodeException($code, $category->value, 0, $edition->name, 'no input field for this reverse-charge code');
        $key     = (string) LedgerService::vatAccountKey($input->value);

        return [['key' => LedgerService::VAT_OWED_ACCOUNT, 'account' => $numbers[LedgerService::VAT_OWED_ACCOUNT], 'sign' => -1], ['key' => $key, 'account' => $numbers[$key], 'sign' => 1]];
    }
    $key = (string) LedgerService::vatAccountKey($category->value);

    return [['key' => $key, 'account' => $numbers[$key], 'sign' => $category->isDebitNatural() ? 1 : -1]];
};
$mayCarry = static function (string $code, string $account, \DateTimeImmutable $date) use ($taxLines): bool {
    foreach ($taxLines($code, $date) as $line) {
        if ($line['key'] !== LedgerService::VAT_OWED_ACCOUNT && $line['account'] === $account) {
            return true;
        }
    }

    return false;
};
// As VatAccountMap::lockedAccounts() answers: number → key, the numeric keys come back as int.
$locked = array_flip($numbers);
$refusalOf = static fn(PostingRequest $r, ?array $lockedAccounts = null): ?PostingRefusedException => PostingRules::vatLinesRefusal($r, $lockedAccounts ?? $locked, $taxLines, $mayCarry);
/** «pass», «exempt» or «{reason} {account}» — the outcome a case compares. */
$judge = static function (PostingRequest $r, ?JournalEntry $reversalOf = null, ?JournalEntry $stored = null, ?array $lockedAccounts = null) use ($refusalOf): string {
    if (PostingRules::vatLockExempt($r, $reversalOf, $stored)) {
        return 'exempt';
    }
    $e = $refusalOf($r, $lockedAccounts);

    return $e === null ? 'pass' : trim($e->reason . ' ' . ($e->account ?? ''));
};
check('O0 the table is the real one: int keys from the locked list, UN → 2200 −1, VM → 1170 +1, VI / VE → 1171 +1, VK / VN → 1171 −1, BM → 2200 −1 and 1170 +1, BI → 1171, UE / UA / UL → none',
    array_keys($locked) === [2200, 1170, 1171]
    && $taxLines('UN', day('2026-06-15')) === [['key' => 'vat-owed', 'account' => '2200', 'sign' => -1]]
    && $taxLines('VM', day('2026-06-15'))[0]['sign'] === 1 && $taxLines('VI', day('2026-06-15'))[0]['account'] === '1171'
    && $taxLines('VE', day('2026-06-15'))[0] === ['key' => 'vat-input-other', 'account' => '1171', 'sign' => 1]
    && $taxLines('VK', day('2026-06-15'))[0]['sign'] === -1 && $taxLines('VN', day('2026-06-15'))[0]['sign'] === -1
    && array_column($taxLines('BM', day('2026-06-15')), 'account') === ['2200', '1170'] && array_column($taxLines('BI', day('2026-06-15')), 'account') === ['2200', '1171']
    && $taxLines('UE', day('2026-06-15')) === [] && $taxLines('UA', day('2026-06-15')) === [] && $taxLines('UL', day('2026-06-15')) === []);

$d = '2026-06-15';
/** [label, request, expected outcome] — the truth table. */
$table = [
    // passes: the shapes the lock exists to let through
    ['T1 no VAT account touched (6500 / 1020)', manual($d, [dr('6500', '100.00'), cr('1020', '100.00')]), 'pass'],
    ['T2 input VM: net with code, tax 8.10 on 1170', manual($d, [dr('6500', '100.00', 'VM', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('1020', '108.10')]), 'pass'],
    ['T3 input VI: tax on 1171', manual($d, [dr('6200', '100.00', 'VI', 810, '100.00', '8.10'), dr('1171', '8.10'), cr('1020', '108.10')]), 'pass'],
    ['T4 output UN: net in Haben, tax 8.10 Haben on 2200', manual($d, [dr('1020', '108.10'), cr('3200', '100.00', 'UN', 810, '100.00', '8.10'), cr('2200', '8.10')]), 'pass'],
    ['T5 a credit note UN: base and tax negative, 8.10 Soll on 2200', manual($d, [dr('3200', '100.00', 'UN', 810, '-100.00', '-8.10'), dr('2200', '8.10'), cr('1020', '108.10')]), 'pass'],
    ['T6 two output codes on one account: UN 8.10 + UR 1.30 = 9.40 on 2200 (one or two lines)', manual($d, [dr('1020', '159.40'), cr('3200', '100.00', 'UN', 810, '100.00', '8.10'), cr('3200', '50.00', 'UR', 260, '50.00', '1.30'), cr('2200', '9.00'), cr('2200', '0.40')]), 'pass'],
    ['T7 a refund VM on the revenue side (golden «refund»): base and tax negative, 4.86 Haben on 1170', manual($d, [dr('1020', '64.80'), cr('4200', '59.94', 'VM', 810, '-59.94', '-4.86'), cr('1170', '4.86')]), 'pass'],
    ['T8 an output code on an expense (golden «reduced»): UR −0.66, 0.66 Soll on 2200', manual($d, [dr('6500', '25.24', 'UR', 260, '-25.24', '-0.66'), dr('2200', '0.66'), cr('1000', '25.90')]), 'pass'],
    ['T9 a tax that rounds to 0.00: coded line, no tax line', manual($d, [dr('6500', '0.05', 'VM', 810, '0.05', '0.00'), cr('1000', '0.05')]), 'pass'],
    ['T10 zero-rated UE and exempt UA: code on the net line, no tax line', manual($d, [dr('1020', '300.00'), cr('3200', '200.00', 'UE', 0, '200.00', '0.00'), cr('3000', '100.00', 'UA', 0, '100.00', '0.00')]), 'pass'],
    ['T11 tax-only VM ON 1170 (base 0.00, the amount is the tax) against the creditor', manual($d, [dr('1170', '8.10', 'VM', 810, '0.00', '8.10'), cr('2000', '8.10')]), 'pass'],
    ['T12 tax-only VI ON 1171', manual($d, [dr('1171', '24.30', 'VI', 810, '0.00', '24.30'), cr('1020', '24.30')]), 'pass'],
    ['T13 VE (410, subsequent deduction) ON 1171 in Soll', manual($d, [dr('1171', '50.00', 'VE', 0, '0.00', '50.00'), cr('6500', '50.00')]), 'pass'],
    ['T14 VK (415, correction) ON 1171 in Haben', manual($d, [dr('6500', '30.00'), cr('1171', '30.00', 'VK', 0, '0.00', '30.00')]), 'pass'],
    ['T15 VN (420, reduction) ON 1171 in Haben', manual($d, [dr('3600', '12.00'), cr('1171', '12.00', 'VN', 0, '0.00', '12.00')]), 'pass'],
    ['T16 VK coded on an expense account, the tax line 30.00 Haben on 1171', manual($d, [dr('6500', '30.00', 'VK', 0, '0.00', '30.00'), cr('1171', '30.00')]), 'pass'],
    ['T17 reverse charge BM, single entry: expense paid 100 [BM 8.10], 1170 Soll 8.10, 2200 Haben 8.10', manual($d, [dr('6500', '100.00', 'BM', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('2200', '8.10'), cr('1020', '100.00')]), 'pass'],
    ['T18 reverse charge BM, the pair alone: the coded line ON 1170 (base 100) against 2200', manual($d, [dr('1170', '8.10', 'BM', 810, '100.00', '8.10'), cr('2200', '8.10')]), 'pass'],
    ['T19 reverse charge BI pair ON 1171', manual($d, [dr('1171', '81.00', 'BI', 810, '1000.00', '81.00'), cr('2200', '81.00')]), 'pass'],
    ['T20 reverse charge BM2 (2.6 %) pair ON 1170', manual($d, [dr('1170', '2.60', 'BM2', 260, '100.00', '2.60'), cr('2200', '2.60')]), 'pass'],
    ['T21 deferral E1: net on 6500, tax on the transit account 1179 (not locked), no code', generated($d, 'creditor', [dr('6500', '100.00'), dr('1179', '8.10'), cr('1020', '108.10')]), 'pass'],
    ['T22 deferral E2: tax-only VM ON 1170 against the transit account 1179', generated($d, 'creditor', [dr('1170', '8.10', 'VM', 810, '0.00', '8.10'), cr('1179', '8.10')]), 'pass'],
    ['T23 the settlement account 2201 and the rounding account 6945 are not locked (payment to the ESTV, Rappen by hand)', manual($d, [dr('2201', '500.05'), cr('1020', '500.00'), cr('6945', '0.05')]), 'pass'],
    // refusals: VAT_LINE_NOT_COMPUTED
    ['T24 the tax line is missing: VM 8.10 computed, nothing on 1170', manual($d, [dr('6500', '108.10', 'VM', 810, '100.00', '8.10'), cr('1020', '108.10')]), 'vat-line-not-computed 1170'],
    ['T25 a typed tax that differs: 8.20 on 1170, the code says 8.10', manual($d, [dr('6500', '100.00', 'VM', 810, '100.00', '8.10'), dr('1170', '8.20'), cr('1020', '108.20')]), 'vat-line-not-computed 1170'],
    ['T26 the tax on the wrong input account: VM tax on 1171 (first account touched named)', manual($d, [dr('6500', '100.00', 'VM', 810, '100.00', '8.10'), dr('1171', '8.10'), cr('1020', '108.10')]), 'vat-line-not-computed 1171'],
    ['T27 an uncoded amount on 2200 (VAT paid «by hand»)', manual($d, [dr('1020', '5.00'), cr('2200', '5.00')]), 'vat-line-not-computed 2200'],
    ['T28 an uncoded carry-over 1170 → 2201 inside the window', manual($d, [dr('2201', '30.00'), cr('1170', '30.00')]), 'vat-line-not-computed 1170'],
    ['T29 VK with the wrong side: Soll on 1171 (the code says Haben)', manual($d, [dr('1171', '30.00', 'VK', 0, '0.00', '30.00'), cr('6500', '30.00')]), 'vat-line-not-computed 1171'],
    ['T30 reverse charge without the owed line: 2200 computes −8.10, moves nothing', manual($d, [dr('6500', '100.00', 'BM', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('1020', '108.10')]), 'vat-line-not-computed 2200'],
    ['T31 a zero-rated code carrying a tax (UE tax 8.10, tax line on 2200): UE maps to no account, 2200 moves', manual($d, [dr('1020', '108.10'), cr('3200', '100.00', 'UE', 0, '100.00', '8.10'), cr('2200', '8.10')]), 'vat-line-not-computed 2200'],
    ['T32 an unknown code (QQ) cannot be placed on a VAT account — refused as not computable, no account named', manual($d, [dr('6500', '100.00', 'QQ', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('1020', '108.10')]), 'vat-line-not-computed'],
    ['T33 reverse charge dated before the first form edition (2023-12-31), single entry: no edition names its input field — not computable', manual('2023-12-31', [dr('6500', '100.00', 'BM', 770, '100.00', '7.70'), dr('1170', '7.70'), cr('2200', '7.70'), cr('1020', '100.00')]), 'vat-line-not-computed'],
    ['T33b … the same as the pair alone: its coded line on 1170 cannot be placed either — refused by the predicate', manual('2023-12-31', [dr('1170', '7.70', 'BM', 770, '100.00', '7.70'), cr('2200', '7.70')]), 'tax-code-on-vat-account 1170'],
    // refusals: TAX_CODE_ON_VAT_ACCOUNT (the predicate)
    ['T34 an output code ON 2200', manual($d, [dr('1020', '8.10'), cr('2200', '8.10', 'UN', 810, '100.00', '8.10')]), 'tax-code-on-vat-account 2200'],
    ['T35 an input code ON the owed account (VM on 2200)', manual($d, [dr('2200', '8.10', 'VM', 810, '0.00', '8.10'), cr('2000', '8.10')]), 'tax-code-on-vat-account 2200'],
    ['T36 an input code ON the OTHER input account (VM on 1171)', manual($d, [dr('1171', '8.10', 'VM', 810, '0.00', '8.10'), cr('2000', '8.10')]), 'tax-code-on-vat-account 1171'],
    ['T37 a zero-rated code ON 1170', manual($d, [dr('1170', '10.00', 'UE', 0, '10.00', '0.00'), cr('1020', '10.00')]), 'tax-code-on-vat-account 1170'],
    ['T38 a reverse-charge code ON the input account it does NOT name (BI on 1170)', manual($d, [dr('1170', '81.00', 'BI', 810, '1000.00', '81.00'), cr('2200', '81.00')]), 'tax-code-on-vat-account 1170'],
    ['T39 a reverse-charge code ON the owed account (BM on 2200)', manual($d, [dr('1170', '8.10'), cr('2200', '8.10', 'BM', 810, '100.00', '8.10')]), 'tax-code-on-vat-account 2200'],
    ['T40 the predicate is asked before the equation (VI on 1170 AND an uncoded 2200 line: the coded line is named)', manual($d, [dr('1020', '5.00'), cr('2200', '5.00'), dr('1170', '8.10', 'VI', 810, '0.00', '8.10'), cr('2000', '8.10')]), 'tax-code-on-vat-account 1170'],
    // the same entry as a module posts it is judged alike (S4-2)
    ['T41 a GENERATED entry is judged like a manual one (bank, uncoded 1170)', generated($d, 'bank', [dr('1170', '5.00'), cr('1020', '5.00')]), 'vat-line-not-computed 1170'],
];
foreach ($table as [$label, $request, $expected]) {
    $got = $judge($request);
    check("{$label} → {$expected}", $got === $expected, $got . ' | ' . shape($request->lines));
}
$noOther = [2200 => 'vat-owed', 1170 => 'vat-input-material'];   // the mandator left «input other» empty
check('T42 an account the mandator leaves empty is not locked: VI with its tax on 1171 passes, an uncoded 1171 line too',
    $judge(manual($d, [dr('6200', '100.00', 'VI', 810, '100.00', '8.10'), dr('1171', '8.10'), cr('1020', '108.10')]), lockedAccounts: $noOther) === 'pass'
    && $judge(manual($d, [dr('1171', '5.00'), cr('1020', '5.00')]), lockedAccounts: $noOther) === 'pass');
check('T43 nothing locked (outside the window — VatAccountMap answers []) → every shape passes the pure judgement',
    $judge(manual($d, [dr('1020', '5.00'), cr('2200', '5.00')]), lockedAccounts: []) === 'pass'
    && $judge(manual($d, [dr('1020', '8.10'), cr('2200', '8.10', 'UN', 810, '100.00', '8.10')]), lockedAccounts: []) === 'pass');
$t25 = $refusalOf($table[24][1]);
check('T44 the refusal carries the account, what the entry moves and what its codes compute (debit − credit)',
    $t25?->account === '1170' && $t25->booked?->toDecimal() === '8.20' && $t25->computed?->toDecimal() === '8.10' && $t25->taxCode === null
    && str_contains($t25->getMessage(), 'VAT account 1170'), json_encode([$t25?->account, $t25?->booked?->toDecimal(), $t25?->computed?->toDecimal()]));

echo "O. Exemptions (vatLockExempt — nothing is read for them)\n";
$settlementShape = generated('2026-06-30', VatReturnService::SOURCE_TYPE, [dr('2200', '81.00'), cr('1170', '30.00'), cr('2201', '51.00')], 'MWST-Abrechnung 2. Quartal 2026');
$openingShape    = generated('2026-01-01', YearOpeningService::SOURCE_TYPE, [dr('1170', '120.00'), cr('9100', '120.00')], 'Eröffnungsbilanz 2026');
check('E1 the settlement entry (source vat-return: 2200 Soll / 1170 Haben / 2201, uncoded) is exempt — without the exemption it would be refused',
    $judge($settlementShape) === 'exempt' && $refusalOf($settlementShape)?->reason === PostingRefusedException::VAT_LINE_NOT_COMPUTED);
check('E2 the year opening (source opening: 1170 against 9100, uncoded, dated 1.1. inside the window) is exempt — without it refused',
    $judge($openingShape) === 'exempt' && $refusalOf($openingShape)?->reason === PostingRefusedException::VAT_LINE_NOT_COMPUTED);
$bankUncoded = generated($d, 'bank', [dr('1170', '5.00'), cr('1020', '5.00')]);
$reversal    = PostingRequest::generated(day($d), 'Zurückgenommen: Probe', 'bank', '1', 'reversal:2026/7', array_map(static fn(PostingLine $l) => $l->swapped(), $bankUncoded->lines));
check('E3 a ledger reversal ($reversalOf given) is exempt, whatever its lines — the same lines without $reversalOf are judged',
    $judge($reversal, storedEntry($bankUncoded)) === 'exempt' && $judge($reversal) === 'vat-line-not-computed 1170');
$old   = manual($d, [dr('1020', '5.00', null, null, null, null, 'alt'), cr('2200', '5.00')], 'Alte Buchung');
$moved = manual('2026-06-20', [cr('2200', '5.00'), dr('1020', '5.00', null, null, null, null, 'neuer Zeilentext')], 'Neuer Text');
check('E4 a change whose lines stay as stored (S4-6) — other date, other entry text, other line text, other order — passes unjudged',
    $judge($moved, null, storedEntry($old)) === 'exempt');
check('E5 a change of an amount is judged as a whole (the old uncoded 2200 line now refused)',
    $judge(manual($d, [dr('1020', '6.00'), cr('2200', '6.00')]), null, storedEntry($old)) === 'vat-line-not-computed 2200');
check('E6 a change that fixes the old entry passes (the 2200 line now carries the computed tax of a coded line)',
    $judge(manual($d, [dr('1020', '108.10'), cr('3200', '100.00', 'UN', 810, '100.00', '8.10'), cr('2200', '8.10')]), null, storedEntry($old)) === 'pass');
check('E7 a new entry (no stored) and every other source (bank, invoice, receipt, creditor) are not exempt',
    !PostingRules::vatLockExempt($old, null) && !PostingRules::vatLockExempt($bankUncoded, null)
    && !PostingRules::vatLockExempt(generated($d, 'invoice', [dr('1020', '5.00'), cr('2200', '5.00')]), null)
    && !PostingRules::vatLockExempt(generated($d, 'receipt', [dr('1020', '5.00'), cr('2200', '5.00')]), null)
    && !PostingRules::vatLockExempt(generated($d, 'creditor', [dr('1020', '5.00'), cr('2200', '5.00')]), null));

echo "O. The refusal sentences (vatLineRefusalText)\n";
$sentence = static fn(PostingRefusedException $e): string => PostingRules::vatLineRefusalText($e);
$notComputed = new PostingRefusedException(PostingRefusedException::VAT_LINE_NOT_COMPUTED, 'x', '1170 Vorsteuer MWST Material, Waren, Dienstleistungen, Energie', booked: chf('8.20'), computed: chf('8.10'));
$uncoded     = new PostingRefusedException(PostingRefusedException::VAT_LINE_NOT_COMPUTED, 'x', '2200 Geschuldete MWST (Umsatzsteuer)', booked: chf('-5.00'), computed: chf('0.00'));
$missing     = new PostingRefusedException(PostingRefusedException::VAT_LINE_NOT_COMPUTED, 'x', '1170 Vorsteuer MWST Material, Waren, Dienstleistungen, Energie', booked: chf('0.00'), computed: chf('1234.50'));
$unplaced    = new PostingRefusedException(PostingRefusedException::VAT_LINE_NOT_COMPUTED, 'x', taxCode: 'BM');
$onAccount   = new PostingRefusedException(PostingRefusedException::TAX_CODE_ON_VAT_ACCOUNT, 'x', '2200 Geschuldete MWST (Umsatzsteuer)', taxCode: 'UN');
check('S1 VAT_LINE_NOT_COMPUTED names the account, what it carries and what the codes compute, by side — no final full stop',
    $sentence($notComputed) === 'Auf dem MWST-Konto «1170 Vorsteuer MWST Material, Waren, Dienstleistungen, Energie» stehen 8.20 im Soll, aus den MWST-Codes der Buchung ergeben sich 8.10 im Soll — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet',
    $sentence($notComputed));
check('S2 an uncoded amount: «stehen 5.00 im Haben … ergeben sich 0.00»; a missing tax line: «steht nichts … 1\'234.50 im Soll»',
    $sentence($uncoded) === 'Auf dem MWST-Konto «2200 Geschuldete MWST (Umsatzsteuer)» stehen 5.00 im Haben, aus den MWST-Codes der Buchung ergeben sich 0.00 — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet'
    && $sentence($missing) === 'Auf dem MWST-Konto «1170 Vorsteuer MWST Material, Waren, Dienstleistungen, Energie» steht nichts, aus den MWST-Codes der Buchung ergeben sich 1\'234.50 im Soll — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet',
    $sentence($uncoded) . ' | ' . $sentence($missing));
check('S3 a code that cannot be placed on a VAT account',
    $sentence($unplaced) === 'Die Steuer zum MWST-Code BM lässt sich keinem MWST-Konto zuordnen — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet', $sentence($unplaced));
check('S4 TAX_CODE_ON_VAT_ACCOUNT names the account and the code and the way out',
    $sentence($onAccount) === 'Auf dem MWST-Konto «2200 Geschuldete MWST (Umsatzsteuer)» steht eine Zeile mit MWST-Code UN — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet; der MWST-Code gehört auf das Gegenkonto',
    $sentence($onAccount));
$other = null;
try { $sentence(new PostingRefusedException(PostingRefusedException::PERIOD_CLOSED, 'x')); } catch (\InvalidArgumentException $e) { $other = $e; }
check('S5 another reason is not the lock\'s — refused loudly', $other !== null);
$texts = $sentence($notComputed) . $sentence($uncoded) . $sentence($unplaced) . $sentence($onAccount);
check('S6 German, Swiss spelling: no ß, «MWST» (never «MwSt»), no final full stop', !str_contains($texts, 'ß') && !str_contains($texts, 'MwSt')
    && !str_ends_with($sentence($notComputed), '.') && !str_ends_with($sentence($onAccount), '.'));

// ── O. the generators that need no database, replayed ──

echo "O. Generators without a database: debtor's builders on documents built in memory\n";

$set = static function (object $object, string $property, mixed $value): void {
    (new \ReflectionProperty($object, $property))->setValue($object, $value);
};
/**
 * A numbered debtor document in memory — only what the posting builders read.
 *
 * @param list<array{0: LineType, 1: string, 2: string, 3: ?string, 4: ?int, 5: string}> $rows [type, text, amount, code, rate, account]
 * @param list<array{0: string, 1: string, 2: int, 3: string, 4: string}> $taxes [code, category, rate, base, tax]
 */
$document = static function (InvoiceKind $kind, int $id, string $date, array $rows, array $taxes, string $gross) use ($set): Invoice {
    $invoice = (new \ReflectionClass(Invoice::class))->newInstanceWithoutConstructor();
    $address = (new \ReflectionClass(AddressSnapshot::class))->newInstanceWithoutConstructor();
    $set($address, 'name', 'Beispiel AG');
    foreach (['id' => $id, 'number' => 1000 + $id, 'kind' => $kind->value, 'currency' => 'CHF', 'priceMode' => 'net', 'invoiceDate' => day($date),
        'grossTotal' => chf($gross), 'address' => $address, 'lines' => new ArrayCollection(), 'taxes' => new ArrayCollection()] as $property => $value) {
        $set($invoice, $property, $value);
    }
    foreach ($rows as $i => [$type, $text, $amount, $code, $rate, $account]) {
        (new \ReflectionProperty($invoice, 'lines'))->getValue($invoice)->add(new InvoiceLine(
            $invoice, $i + 1, $type, null, $text, null, null, $type === LineType::Rounding ? null : chf($amount), 0, chf($amount),
            $code, $rate, $code === null ? null : 'MWST ' . $code, $account, null, null
        ));
    }
    foreach ($taxes as $i => [$code, $category, $rate, $base, $tax]) {
        (new \ReflectionProperty($invoice, 'taxes'))->getValue($invoice)->add(new InvoiceTax($invoice, $i + 1, $code, $category, 'MWST ' . $code, $rate, chf($base), chf($tax)));
    }

    return $invoice;
};
/** debtor's request as its gateway translates it: a line named by VAT category gets the account of `vatAccountKey()`. */
$translate = static function (DebtorRequest $r) use ($numbers): PostingRequest {
    $lines = [];
    foreach ($r->lines as $l) {
        $account = $l->account ?? $numbers[(string) LedgerService::vatAccountKey((string) $l->vatCategory)];
        $lines[] = new PostingLine($account, $l->debit, $l->credit, $l->taxCode, $l->taxRate, $l->taxBase, $l->taxAmount, $l->text);
    }

    return PostingRequest::generated($r->date, $r->text, $r->sourceType, $r->sourceRef, $r->idempotencyKey, $lines);
};
// An invoice: two UN lines (one negative — a deduction), UR, UE; 900 × 8.1 % = 72.90, 50 × 2.6 % = 1.30.
$invoice = $document(InvoiceKind::Invoice, 1, $d, [
    [LineType::LumpSum, 'Beratung', '1000.00', 'UN', 810, '3400'],
    [LineType::LumpSum, 'Rabatt', '-100.00', 'UN', 810, '3400'],
    [LineType::LumpSum, 'Buch', '50.00', 'UR', 260, '3200'],
    [LineType::LumpSum, 'Export', '200.00', 'UE', 0, '3200'],
], [['UN', 'standard', 810, '900.00', '72.90'], ['UR', 'reduced', 260, '50.00', '1.30'], ['UE', 'zero', 0, '200.00', '0.00']], '1224.20');
// A small invoice rounded UP to 5 Rappen: 10.35 + 0.84 = 11.19 → 11.20 (rounding +0.01 on 3809).
$rounded = $document(InvoiceKind::Invoice, 2, $d, [
    [LineType::LumpSum, 'Kleinauftrag', '10.35', 'UN', 810, '3400'],
    [LineType::Rounding, 'Rundung', '0.01', null, null, '3809'],
], [['UN', 'standard', 810, '10.35', '0.84']], '11.20');
$creditNote = $document(InvoiceKind::CreditNote, 3, $d, [
    [LineType::LumpSum, 'Gutschrift Beratung', '100.00', 'UN', 810, '3400'],
], [['UN', 'standard', 810, '100.00', '8.10']], '108.10');
$feePlain = $document(InvoiceKind::Fee, 4, $d, [[LineType::LumpSum, 'Mahngebühr', '20.00', null, null, '3400']], [], '20.00');
// A fee with the dunned invoice's code, rounded DOWN: 20.00 + 1.62 = 21.62 → 21.60 (rounding −0.02 posts in Soll).
$feeCoded = $document(InvoiceKind::Fee, 5, $d, [
    [LineType::LumpSum, 'Mahngebühr', '20.00', 'UN', 810, '3400'],
    [LineType::Rounding, 'Rundung', '-0.02', null, null, '3809'],
], [['UN', 'standard', 810, '20.00', '1.62']], '21.60');
$debtorDocs = ['invoice (UN ± lines, UR, UE)' => $invoice, 'invoice rounded up' => $rounded, 'credit note' => $creditNote, 'fee without code' => $feePlain, 'fee with code, rounded down' => $feeCoded];
$debtorRequests = [];
foreach ($debtorDocs as $name => $doc) {
    $request = $translate(PostingBuilder::build($doc, '1100'));
    $debtorRequests[$name] = $request;
    check("G-debtor {$name}: PostingBuilder's request passes the lock", $judge($request) === 'pass', $judge($request) . ' | ' . shape($request->lines));
}
$payment = new Payment(day('2026-06-30'), chf('1224.20'), 'bank', '1020', null, 'harness', new \DateTimeImmutable());
$set($payment, 'id', 91);
$allocation = static function (Invoice $doc, AllocationKind $kind, string $amount, int $id) use ($payment, $set): PaymentAllocation {
    $a = new PaymentAllocation($payment, $doc, $id, $kind, chf($amount));
    $set($a, 'id', $id);

    return $a;
};
$paymentCases = [
    'payment'                                    => [$invoice, AllocationKind::Payment, '1199.72', '1020'],
    'Skonto 2 % (three codes)'                   => [$invoice, AllocationKind::Discount, '24.48', '3800'],
    'Verlust of the rounded-up invoice (rest +)' => [$rounded, AllocationKind::Loss, '11.20', '3805'],
    'Verlust of the rounded-down fee (rest −)'   => [$feeCoded, AllocationKind::Loss, '21.60', '3805'],
    'Verlust of the fee without code'            => [$feePlain, AllocationKind::Loss, '20.00', '3805'],
    'Gebühren'                                   => [$invoice, AllocationKind::Charges, '3.50', '6840'],
];
$i = 0;
foreach ($paymentCases as $name => [$doc, $kind, $amount, $counter]) {
    $request = $translate(PaymentPostingBuilder::build($allocation($doc, $kind, $amount, ++$i), '1100', $counter));
    $debtorRequests['payment: ' . $name] = $request;
    check("G-debtor payment {$name}: PaymentPostingBuilder's request passes the lock", $judge($request) === 'pass', $judge($request) . ' | ' . shape($request->lines));
}
$invoiceEntry = storedEntry($debtorRequests['invoice (UN ± lines, UR, UE)']);
$invoiceBack  = PostingRequest::generated(day('2026-07-02'), 'Storno', 'invoice', '1001', 'reversal:2026/7', array_map(static fn(PostingLine $l) => $l->swapped(), $debtorRequests['invoice (UN ± lines, UR, UE)']->lines));
check('G-ledger a ledger reversal of the invoice (swapped lines, tax negated) is exempt — and its lines would pass anyway',
    $judge($invoiceBack, $invoiceEntry) === 'exempt' && $judge($invoiceBack) === 'pass');

// ── M / P. database ──────────────────────────────────────────────────────

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
    echo "M. / P. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database (throwaway schema): the real rule on the mandator's settings\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-lock-' . getmypid());
define('DEBUG', false);
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
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $root . '/packages/module-financial',
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['vat' => [], 'mandator' => [], 'financial' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
$write('data/framework/vat/tax_codes.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'));
$write('data/framework/vat/tax_rates.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'));

$wire = static function () use ($base): \Z77\Persistence\Resolver\UnifiedEntityManager {
    gc_collect_cycles();
    static $previous = null;
    if ($previous !== null) {
        foreach ((new \ReflectionProperty($previous, 'managerCache'))->getValue($previous) as $manager) {
            if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
                (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection()->close();
            }
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

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
};
$em = $wire();
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
    \Z77\Core\DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
);
$app->setAutoExit(false);
$out  = new \Symfony\Component\Console\Output\BufferedOutput();
$code = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $out);
check('M0 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out->fetch()));

// The books: KMU chart, fiscal years 2025 and 2026, a liable mandator settling from 1.1.2026 (quarter, effective, agreed).
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new FiscalYear('2025', day('2025-01-01'), day('2025-12-31')));
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
// The transit account of a deferral (W1-D's column; absent → the split probe below reports it).
$pendingColumn = (bool) $db->fetchOne("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'mandator' AND COLUMN_NAME = 'account_vat_input_pending'", [$dbName]);
if ($pendingColumn) {
    $db->executeStatement("UPDATE mandator SET account_vat_input_pending = '1179'");
}
$mandatorSet = static fn(array $columns): int => (int) $db->executeStatement(
    'UPDATE mandator SET ' . implode(', ', array_map(static fn(string $c) => "{$c} = ?", array_keys($columns))),
    array_values($columns)
);
$post = static function (PostingRequest $request) use ($wire): \Z77\Module\Financial\Ledger\EntryRef {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->post($request));
};
/** The real rule on a fresh EntityManager: «pass», or «{reason} {account label}». */
$real = static function (PostingRequest $r, ?JournalEntry $reversalOf = null, ?JournalEntry $stored = null, ?PostingRules $rules = null) use ($wire): string {
    try {
        ($rules ?? new PostingRules($wire()))->assertVatLinesComputed($r, $reversalOf, $stored);
    } catch (PostingRefusedException $e) {
        return trim($e->reason . ' ' . ($e->account ?? ''));
    }

    return 'pass';
};
$uncoded2200 = static fn(string $date): PostingRequest => manual($date, [dr('1020', '5.00'), cr('2200', '5.00')]);
$label2200   = '2200 Geschuldete MWST (Umsatzsteuer)';

check('M1 inside the window an uncoded 2200 line is refused, the account named by its LABEL',
    $real($uncoded2200('2026-06-15')) === "vat-line-not-computed {$label2200}", $real($uncoded2200('2026-06-15')));
$caught = null;
try { (new PostingRules($wire()))->assertVatLinesComputed($uncoded2200('2026-06-15'), null); } catch (PostingRefusedException $e) { $caught = $e; }
check('M2 its sentence: «Auf dem MWST-Konto «2200 Geschuldete MWST (Umsatzsteuer)» stehen 5.00 im Haben, aus den MWST-Codes der Buchung ergeben sich 0.00 — …»',
    $caught !== null && PostingRules::vatLineRefusalText($caught) === 'Auf dem MWST-Konto «2200 Geschuldete MWST (Umsatzsteuer)» stehen 5.00 im Haben, aus den MWST-Codes der Buchung ergeben sich 0.00 — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet',
    $caught === null ? 'not refused' : PostingRules::vatLineRefusalText($caught));
check('M3 the real VatAccountMap agrees with the offline table on every truth-table case dated inside the window',
    (function () use ($table, $real, $judge, $wire): bool {
        $rules = new PostingRules($wire());
        // reason + the account NUMBER (offline names the number, the database the label «1170 Vorsteuer …»)
        $norm = static fn(string $outcome): string => implode(' ', array_slice(explode(' ', $outcome), 0, 2));
        foreach ($table as [$label, $request]) {
            if ($request->date->format('Y') !== '2026') { continue; }
            $offline = $judge($request);
            $online  = $real($request, null, null, $rules);
            if ($norm($offline) !== $norm($online)) {
                echo "         differs: {$label}: offline {$offline}, database {$online}\n";

                return false;
            }
        }

        return true;
    })());
check('M4 before «Abrechnung ab» (31.12.2025) nothing is locked: the uncoded 2200 line passes', $real($uncoded2200('2025-12-31')) === 'pass');
$mandatorSet(['vat_settlement_until' => '2026-06-30']);
check('M5 after «Abrechnung bis» (30.6.2026) the lock is off: 1.7.2026 passes, 30.6.2026 is still refused',
    $real($uncoded2200('2026-07-01')) === 'pass' && str_starts_with($real($uncoded2200('2026-06-30')), 'vat-line-not-computed'));
$mandatorSet(['vat_settlement_until' => null]);
$heldRules = new PostingRules($wire());
check('M6a one rule instance, first read: refused', str_starts_with($real($uncoded2200('2026-06-15'), rules: $heldRules), 'vat-line-not-computed'));
$mandatorSet(['liable_to_vat' => 0]);
check('M6 not liable: nothing is locked (a fresh instance passes) — the SAME instance keeps what it read once (one mandator read per instance)',
    $real($uncoded2200('2026-06-15')) === 'pass' && str_starts_with($real($uncoded2200('2026-06-15'), rules: $heldRules), 'vat-line-not-computed'));
$mandatorSet(['liable_to_vat' => 1, 'vat_settlement_from' => null]);
check('M7 liable without «Abrechnung ab» (settles outside z77): nothing is locked', $real($uncoded2200('2026-06-15')) === 'pass');
$mandatorSet(['vat_settlement_from' => '2026-01-01']);
$mandatorSet(['account_vat_input_other' => '']);
check('M8 an account field left empty on the mandator is not locked (1171 uncoded passes, 1170 still refused)',
    $real(manual('2026-06-15', [dr('1171', '5.00'), cr('1020', '5.00')])) === 'pass'
    && str_starts_with($real(manual('2026-06-15', [dr('1170', '5.00'), cr('1020', '5.00')])), 'vat-line-not-computed'));
$mandatorSet(['account_vat_input_other' => '1171']);
check('M9 the exemptions on the real method: vat-return, opening, a ledger reversal, unchanged lines',
    $real($settlementShape) === 'pass' && $real($openingShape) === 'pass' && $real($reversal, storedEntry($bankUncoded)) === 'pass'
    && $real($moved, null, storedEntry($old)) === 'pass' && str_starts_with($real($bankUncoded), 'vat-line-not-computed'));
check('M10 an unknown code (QQ) is refused as not computable, with no account named',
    $real($table[31][1]) === 'vat-line-not-computed', $real($table[31][1]));

// ── P. the probe: every generator of today's tree, replayed ──

echo "P. Probe: the generators of today's tree through the rule (window open: liable, «Abrechnung ab» 1.1.2026)\n";

$em      = $wire();
$builder = \Z77\Module\Financial\Services\GrossEntryBuilder::of($em);
/** One probe: the request a generator builds, judged by the real rule; a builder refusal is reported, not hidden. */
$probe = static function (string $label, callable $build, string $expected = 'pass', ?JournalEntry $reversalOf = null) use ($real): void {
    try {
        $request = $build();
    } catch (\Throwable $e) {
        check("{$label} → {$expected}", false, 'the generator refused: ' . get_class($e) . ': ' . $e->getMessage());

        return;
    }
    $got = $real($request, $reversalOf);
    check("{$label} → {$expected}", $got === $expected, $got . ' | ' . shape($request->lines));
};
$gross = static fn(string $date, string $counter, array $parts, string $source, ?bool $liable = true, ?string $rateDate = null, bool $rc = false): PostingRequest
    => generated($date, $source, $builder->build(day($date), $counter, $parts, $liable, [], $rateDate === null ? null : day($rateDate), $rc)->lines);

// creditor — DirectPosting: one part per invoice line against the payment method, liable applied, rate date = invoice date
$probe('P1 creditor (DirectPosting): VM, VI, ohne MWST against 1020', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('108.10'), 'VM', 'Material'), new GrossPart('6200', chf('54.05'), 'VI', 'Benzin'), new GrossPart('6500', chf('20.00'), null, 'Porto')], 'creditor', true, '2026-06-10'));
$probe('P2 creditor: an output code on a line (UN — a resale) against 1020', static fn() => $gross($d, '1020', [new GrossPart('1500', chf('200.00'), 'UN')], 'creditor'));
$probe('P3 creditor: zero-rated and exempt lines (UE, UA)', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('50.00'), 'UE'), new GrossPart('6500', chf('50.00'), 'UA')], 'creditor'));
$probe('P4 creditor: a voucher tax within the limit (VM, 30.00 instead of 29.97)', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('400.00'), 'VM', null, chf('30.00'))], 'creditor'));
$probe('P5 creditor: a tax-only part (VM «nur Steuer» 8.10)', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('8.10'), 'VM', null, null, GrossPartKind::TaxOnly)], 'creditor'));
$probe('P6 creditor: a tax-only part VI and a standard part together', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('24.30'), 'VI', null, null, GrossPartKind::TaxOnly), new GrossPart('6500', chf('108.10'), 'VM')], 'creditor'));
$probe('P7 creditor: reverse charge BM single entry (paid 1000, tax on top)', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('1000.00'), 'BM')], 'creditor', true, null, true));
$probe('P8 creditor: reverse charge BI and BM2 with a VM line', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('1000.00'), 'BI'), new GrossPart('6500', chf('100.00'), 'BM2'), new GrossPart('6500', chf('108.10'), 'VM')], 'creditor', true, null, true));
$probe('P9 creditor: reverse charge with a base of its own (taxBase 950 on 1000 paid)', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('1000.00'), 'BM', null, null, GrossPartKind::Standard, chf('950.00'))], 'creditor', true, null, true));
foreach (['input VM (deferral)' => [new GrossPart('6500', chf('108.10'), 'VM'), new GrossPart('6200', chf('54.05'), 'VI'), new GrossPart('6500', chf('8.10'), 'VM', null, null, GrossPartKind::TaxOnly)], 'reverse charge BM (pair)' => [new GrossPart('6500', chf('1000.00'), 'BM')]] as $kind => $parts) {
    $split = null;
    try {
        $split = $builder->buildSplit(day('2026-06-15'), '1020', $parts, true, [], null, str_contains($kind, 'reverse'));
    } catch (\Throwable $e) {
        check("P10 creditor split {$kind}: the builder builds both entries", false, get_class($e) . ': ' . $e->getMessage() . ($pendingColumn ? '' : ' (the mandator has no column account_vat_input_pending yet — W1-D)'));
        continue;
    }
    $probe("P10 creditor split {$kind}: E1 the expense entry", static fn() => generated('2026-06-15', 'creditor', $split->lines));
    $probe("P10 creditor split {$kind}: E2 the tax entry on the tax date", static fn() => generated('2026-07-01', 'creditor', $split->taxLines));
}

// receipt — ReceiptPostingBuilder::lines(), the cases of the golden section of financial-gross-entry
$receipt = new \Z77\Module\Receipt\Services\ReceiptPostingBuilder($em);
foreach ([
    'vi' => ['6200', '1000', '108.10', 'VI'], 'vm' => ['6500', '1000', '12.40', 'VM'], 'none' => ['6200', '1020', '1000.00', null],
    'r005' => ['6500', '1000', '0.05', 'VM'], 'zero' => ['6500', '1000', '50.00', 'UE'], 'exempt' => ['6500', '1000', '50.00', 'UA'],
    'asset' => ['1500', '1020', '5000.00', 'VI'], 'assetOutput' => ['1500', '1020', '200.00', 'UN'], 'refund' => ['1020', '4200', '64.80', 'VM'],
    'outputCode' => ['1000', '3200', '21.60', 'UN'], 'reduced' => ['6500', '1000', '25.90', 'UR'], 'payToEstv' => ['2201', '1020', '500.00', null],
] as $name => [$debit, $credit, $amount, $code]) {
    $probe("P11 receipt «{$name}» {$debit}/{$credit} {$amount} " . ($code ?? 'ohne MWST'), static fn() => generated($d, 'receipt', $receipt->lines(day($d), chf($amount), $debit, $credit, $code)));
}

// bank — «Geschäftlich buchen»: BankPostingService::lines() calls the builder so (contra against the bank, the direction decides)
$probe('P12 bank, a debit movement: «6500 VM an Bank»', static fn() => $gross($d, '1020', [new GrossPart('6500', chf('108.10'), 'VM')], 'bank', true));
$probe('P12 bank, a credit movement: «Bank an 3200 UN»', static fn() => $gross($d, '3200', [new GrossPart('1020', chf('108.10'), 'UN')], 'bank', true));
$probe('P12 bank, the payment to the ESTV on the settlement account 2201', static fn() => $gross($d, '1020', [new GrossPart('2201', chf('500.00'))], 'bank', true));
$probe('P12 bank, «Übertrag» to the cash without code', static fn() => $gross($d, '1020', [new GrossPart('1000', chf('300.00'))], 'bank', true));
$bankContra = null;
try { $gross($d, '1020', [new GrossPart('2200', chf('500.00'))], 'bank', true); } catch (\Z77\Module\Financial\Services\GrossEntryRefusedException $e) { $bankContra = $e; }
check('P13 bank / creditor / receipt: a VAT account typed as the contra is refused by the BUILDER already (W1-C) — the lock never sees it',
    $bankContra !== null && str_contains($bankContra->getMessage(), 'MWST-Konto'), $bankContra?->getMessage() ?? 'not refused');
$bankEntry = generated($d, 'bank', $builder->build(day($d), '1020', [new GrossPart('6500', chf('108.10'), 'VM')], true)->lines);
$probe('P14 bank take-back = LedgerService::reverse() of that entry: exempt', static fn() => PostingRequest::generated(day($d), 'Zurückgenommen: Probe', 'bank', '1', 'reversal:2026/9', array_map(static fn(PostingLine $l) => $l->swapped(), $bankEntry->lines)), 'pass', storedEntry($bankEntry));

// the one-line manual entry
$oneLine = static function (array $post) use ($wire): PostingRequest {
    $em   = $wire();
    $form = new \Z77\Module\Financial\Ui\OneLineEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(\Z77\Module\Vat\Entities\TaxCode::class), \Z77\Module\Vat\Services\VatRates::from($em), new LedgerService($em));
    $request = $form->fromPost($post + ['date' => '2026-06-15', 'text' => 'Beleg'])->toRequest();
    if ($request === null) {
        throw new \RuntimeException('form refused: ' . json_encode(['general' => $form->generalErrors(), 'tax_code' => $form->error('tax_code'), 'tax_amount' => $form->error('tax_amount'), 'debit' => $form->error('debit'), 'credit' => $form->error('credit')], JSON_UNESCAPED_UNICODE));
    }

    return $request;
};
$probe('P15 one-line: 6500/1020 VM', static fn() => $oneLine(['debit' => '6500', 'credit' => '1020', 'amount' => '400.00', 'vat' => '1', 'tax_code' => 'VM', 'tax_amount' => '']));
$probe('P15 one-line: 1020/3200 UN', static fn() => $oneLine(['debit' => '1020', 'credit' => '3200', 'amount' => '400.00', 'vat' => '1', 'tax_code' => 'UN', 'tax_amount' => '']));
$probe('P15 one-line: voucher tax 30.00 (VM)', static fn() => $oneLine(['debit' => '6500', 'credit' => '1020', 'amount' => '400.00', 'vat' => '1', 'tax_code' => 'VM', 'tax_amount' => '30.00']));
$probe('P15 one-line: zero-rated UE', static fn() => $oneLine(['debit' => '1020', 'credit' => '3200', 'amount' => '400.00', 'vat' => '1', 'tax_code' => 'UE', 'tax_amount' => '']));
$probe('P15 one-line: a refund 3200/1020 UN (base and tax negative)', static fn() => $oneLine(['debit' => '3200', 'credit' => '1020', 'amount' => '108.10', 'vat' => '1', 'tax_code' => 'UN', 'tax_amount' => '']));
$probe('P15 one-line: without VAT 6500/1020', static fn() => $oneLine(['debit' => '6500', 'credit' => '1020', 'amount' => '50.00', 'tax_code' => '', 'tax_amount' => '']));

// the Sammelbuchung (ManualEntryForm — rebuilt by the Sammelbuchung package in this wave: what the tree has now)
$compound = static function (array $rows) use ($wire): PostingRequest {
    $em   = $wire();
    $form = new \Z77\Module\Financial\Ui\ManualEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(\Z77\Module\Vat\Entities\TaxCode::class), \Z77\Module\Vat\Services\VatRates::from($em), new \Z77\Module\Financial\Services\VatAccountMap($em));
    $request = $form->fromPost(['date' => '2026-06-15', 'text' => 'Sammelbuchung', 'account' => array_column($rows, 0), 'debit' => array_column($rows, 1), 'credit' => array_column($rows, 2),
        'tax_code' => array_column($rows, 3), 'tax_amount' => array_fill(0, count($rows), ''), 'line_text' => array_fill(0, count($rows), '')])->toRequest();
    if ($request === null) {
        $errors = [];
        foreach (array_keys($rows) as $i) {
            foreach (['account', 'debit', 'credit', 'tax_code', 'tax_amount'] as $field) {
                if ($form->rowError($i, $field) !== '') { $errors["{$i}.{$field}"] = $form->rowError($i, $field); }
            }
        }
        throw new \RuntimeException('form refused: ' . json_encode(['general' => $form->generalErrors(), 'rows' => $errors], JSON_UNESCAPED_UNICODE));
    }

    return $request;
};
$probe('P16 Sammelbuchung: net row VM 100.00 against 1020 108.10 (the form writes the 1170 line)', static fn() => $compound([['6500', '100.00', '', 'VM'], ['1020', '', '108.10', '']]));
$probe('P16 Sammelbuchung: a sale UN and UR in one entry', static fn() => $compound([['1020', '159.40', '', ''], ['3200', '', '100.00', 'UN'], ['3200', '', '50.00', 'UR']]));
$probe('P16 Sammelbuchung: without VAT', static fn() => $compound([['6500', '100.00', '', ''], ['1020', '', '100.00', '']]));

// debtor through the accounts of the real mandator record (its gateway's translation)
$realLedger = new LedgerService($wire());
foreach ($debtorRequests as $name => $request) {
    $probe("P17 debtor {$name} (section O's request) through the real rule", static fn(): PostingRequest => $request);
}
check('P17b … whose offline translation named the accounts the mandator record names (vatAccountFor: 2200 / 1170 / 1171)',
    $realLedger->vatAccountFor('standard') === '2200' && $realLedger->vatAccountFor('reduced') === '2200' && $realLedger->vatAccountFor('input-material') === '1170' && $realLedger->vatAccountFor('input-other') === '1171');

// the year opening: its own plan for 2026, from 2025 entries dated before «Abrechnung ab» (balances on 1170 and 2200)
// Uncoded: before «Abrechnung ab» no tax line is posted (ADR-041 addendum 2026-11-24, point 3) — the balances are what counts here.
$post(manual('2025-11-10', [dr('6500', '100.00'), dr('1170', '8.10'), cr('1020', '108.10')]));
$post(manual('2025-11-12', [dr('1020', '1081.00'), cr('3200', '1000.00'), cr('2200', '81.00')]));
$openingRequests = [];
try {
    $em2025 = $wire();
    $openingRequests = (new YearOpeningService($em2025, 'harness'))->plan($em2025->getRepository(FiscalYear::class)->findOneBy(['code' => '2026']))->requests;
} catch (\Throwable $e) {
    check('P18 the year opening plans its entries', false, get_class($e) . ': ' . $e->getMessage());
}
$openingOnVat = array_filter($openingRequests, static fn(PostingRequest $r) => array_intersect(array_map(static fn(PostingLine $l) => $l->account, $r->lines), ['1170', '2200']) !== []);
check('P18 the year opening (YearOpeningService::plan(), dated 1.1.2026 inside the window): its entries on 1170 and 2200 are exempt, every entry',
    count($openingOnVat) === 2 && array_filter($openingRequests, static fn(PostingRequest $r) => $real($r) !== 'pass') === [],
    implode(' / ', array_map(static fn(PostingRequest $r) => $r->sourceType . ' ' . shape($r->lines) . ' → ' . $real($r), $openingRequests)));
check('P18b … and they WOULD be refused without the exemption (the exemption is load-bearing)',
    array_filter($openingOnVat, static fn(PostingRequest $r) => !str_starts_with($real(PostingRequest::generated($r->date, $r->text, 'probe', 'x', 'probe:' . $r->idempotencyKey, $r->lines)), 'vat-line-not-computed')) === []);

// the settlement entry: VatReturnPostingService::plan() of Q1 2026 after a coded sale and purchase
$post(generated('2026-02-10', 'invoice', [dr('1100', '1080.99'), cr('3200', '999.99', 'UN', 810, '999.99', '81.00'), cr('2200', '81.00')]));
$post(generated('2026-02-12', 'receipt', [dr('6500', '100.00', 'VM', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('1000', '108.10')]));
$settlement = null;
try {
    $plan       = (new \Z77\Module\Financial\VatReturn\VatReturnPostingService($wire(), 'harness', new \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks([])))
        ->plan(new \Z77\Module\Financial\VatReturn\VatPeriod(day('2026-01-01'), day('2026-03-31'), \Z77\Module\Mandator\Entities\VatRhythm::Quarter), day('2026-04-15'));
    $settlement = $plan->settlement;
} catch (\Throwable $e) {
    check('P19 the settlement plan of Q1 2026 builds its entry', false, get_class($e) . ': ' . $e->getMessage());
}
if ($settlement !== null) {
    check('P19 the settlement entry (VatReturnPostingService::plan(), 31.3.2026): exempt as source vat-return — without the exemption refused',
        $real($settlement) === 'pass' && str_starts_with($real(PostingRequest::generated($settlement->date, $settlement->text, 'probe', 'x', 'probe:settle', $settlement->lines)), 'vat-line-not-computed'),
        shape($settlement->lines));
    check('P19b its reversal (the lift: LedgerService::reverse()) is exempt',
        $real(PostingRequest::generated($settlement->date, 'Aufgehoben', VatReturnService::SOURCE_TYPE, (string) $settlement->sourceRef, 'reversal:2026/99', array_map(static fn(PostingLine $l) => $l->swapped(), $settlement->lines)), storedEntry($settlement)) === 'pass');
}

// ── L. wired: the ledger, the manual edit, amend, the exemptions, R18 ──

echo "L. Wired: LedgerService::post() / amend() / reverse() and ManualEntryService::update() apply the lock\n";

$books = static fn(): array => [
    (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry'), (int) $db->fetchOne('SELECT COUNT(*) FROM journal_line'),
    (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry_change'), $db->fetchAllAssociative('SELECT * FROM number_range ORDER BY 1'),
];
/** «ok», or the reason of the refusal $fn raised, or «class: message». */
$outcome = static function (callable $fn): string {
    try {
        $fn();
    } catch (PostingRefusedException | \Z77\Module\Financial\Services\EntryNotEditableException $e) {
        return $e->reason;
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'ok';
};
$manualService = static fn() => new \Z77\Module\Financial\Services\ManualEntryService($wire(), 'harness');
/** Posts $r with «Abrechnung ab» cleared for that one posting — an entry from before the lock. */
$beforeLock = static function (callable $write) use ($mandatorSet): mixed {
    $mandatorSet(['vat_settlement_from' => null]);
    try {
        return $write();
    } finally {
        $mandatorSet(['vat_settlement_from' => '2026-01-01']);
    }
};
$entryBy = static fn(string $text): array => $db->fetchAssociative('SELECT id, version FROM journal_entry WHERE text = ?', [$text]) ?: [];

$before = $books();
$l1     = $outcome(fn() => $manualService()->create(manual('2026-06-15', [dr('1020', '5.00'), cr('2200', '5.00')], 'L1 MWST von Hand')));
check('L1 a MANUAL entry with an uncoded 2200 line inside the window is refused `vat-line-not-computed` — nothing written, no number consumed',
    $l1 === 'vat-line-not-computed' && $books() === $before, $l1);
$l2 = $outcome(fn() => $post(generated('2026-06-15', 'bank', [dr('1170', '5.00'), cr('1020', '5.00')], 'L2 Bank auf Vorsteuer')));
check('L2 a GENERATED entry (a module, S4-2) with an uncoded 1170 line is refused alike — nothing written', $l2 === 'vat-line-not-computed' && $books() === $before, $l2);
$l3 = $outcome(fn() => $post(manual('2026-06-15', [dr('1020', '8.10'), cr('2200', '8.10', 'UN', 810, '100.00', '8.10')], 'L3 Code auf 2200')));
check('L3 an output code ON 2200 is refused `tax-code-on-vat-account`', $l3 === 'tax-code-on-vat-account' && $books() === $before, $l3);
$l4 = $outcome(fn() => $manualService()->create(manual('2026-06-15', [dr('6500', '100.00', 'VM', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('1020', '108.10')], 'L4 Vorsteuer korrekt')));
$l4b = $outcome(fn() => $post(manual('2026-06-15', [dr('1170', '8.10', 'VM', 810, '0.00', '8.10'), cr('2000', '8.10')], 'L4b nur Steuer')));
check('L4 the computed shapes post: a VM entry with its 1170 line, a tax-only VM line ON 1170', $l4 === 'ok' && $l4b === 'ok', "{$l4} / {$l4b}");
$l5 = $outcome(fn() => $post(manual('2025-12-31', [dr('1020', '5.00'), cr('2200', '5.00')], 'L5 vor Abrechnung ab')));
check('L5 before «Abrechnung ab» the uncoded 2200 line posts (the lock is off)', $l5 === 'ok', $l5);
$mandatorSet(['vat_settlement_until' => '2026-06-30']);
$l6 = $outcome(fn() => $post(manual('2026-07-01', [dr('1020', '5.00'), cr('2200', '5.00')], 'L6 nach Abrechnung bis')));
$mandatorSet(['vat_settlement_until' => null]);
check('L6 after «Abrechnung bis» (S4-4) the uncoded 2200 line posts', $l6 === 'ok', $l6);

// S4-6 — an old entry (written before the lock) stays correctable in text and date; changed lines must make it consistent.
$beforeLock(fn() => $manualService()->create(manual('2026-05-10', [dr('1020', '7.00'), cr('2200', '7.00')], 'L7 alte Buchung')));
$old7 = $entryBy('L7 alte Buchung');
$l7   = $outcome(fn() => $manualService()->update((int) $old7['id'], (int) $old7['version'], manual('2026-05-12', [cr('2200', '7.00'), dr('1020', '7.00')], 'L7 alte Buchung, Text korrigiert')));
check('L7 an old entry with an uncoded 2200 line: text and date changed, the lines as stored (in another order) — saved unjudged (S4-6)', $l7 === 'ok', $l7);
$old7 = $entryBy('L7 alte Buchung, Text korrigiert');
$l8   = $outcome(fn() => $manualService()->update((int) $old7['id'], (int) $old7['version'], manual('2026-05-12', [dr('1020', '8.00'), cr('2200', '8.00')], 'L7 alte Buchung, Text korrigiert')));
$l8b  = $outcome(fn() => $manualService()->update((int) $old7['id'], (int) $old7['version'], manual('2026-05-12', [dr('1020', '108.10'), cr('3200', '100.00', 'UN', 810, '100.00', '8.10'), cr('2200', '8.10')], 'L7 alte Buchung, Text korrigiert')));
check('L8 the same entry with a changed amount is refused `vat-line-not-computed`; changed so that 2200 carries the computed tax, it saves',
    $l8 === 'vat-line-not-computed' && $l8b === 'ok', "{$l8} / {$l8b}");
$old7 = $entryBy('L7 alte Buchung, Text korrigiert');
check('L8b a delete is not judged by the lock (it posts no line)', $outcome(fn() => $manualService()->delete((int) $old7['id'], (int) $old7['version'])) === 'ok');

// amend() / reverse() of a module's old entry
$bankOld = generated('2026-05-20', 'bank', [dr('1170', '6.00'), cr('1020', '6.00')], 'L9 Bank alt');
$bankRef = $beforeLock(fn() => $post($bankOld));
$amendIt = static fn(PostingRequest $r) => $outcome(function () use ($wire, $bankOld, $r): void {
    $em = $wire();
    $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->amend((string) $bankOld->idempotencyKey, $r));
});
$l9  = $amendIt(PostingRequest::generated(day('2026-05-21'), 'L9 Bank alt, neuer Text', 'bank', (string) $bankOld->sourceRef, (string) $bankOld->idempotencyKey, $bankOld->lines));
$l9b = $amendIt(PostingRequest::generated(day('2026-05-21'), 'L9 Bank alt, neuer Text', 'bank', (string) $bankOld->sourceRef, (string) $bankOld->idempotencyKey, [dr('1170', '9.00'), cr('1020', '9.00')]));
check('L9 a module amends its old entry: the lines as stored, new date and text → saved unjudged; new lines → refused `vat-line-not-computed`',
    $l9 === 'ok' && $l9b === 'vat-line-not-computed', "{$l9} / {$l9b}");
$l10 = $outcome(function () use ($wire, $bankRef): void {
    $em = $wire();
    $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->reverse($bankRef, day('2026-06-20'), 'L10 Zurückgenommen'));
});
check('L10 a reversal through the ledger (a bank take-back, S4-3) of that entry posts inside the window — its uncoded 1170 line mirrored', $l10 === 'ok', $l10);
$l11 = $outcome(fn() => $post(generated('2026-06-15', VatReturnService::SOURCE_TYPE, [dr('2200', '5.00'), cr('2201', '5.00')], 'L11 Abrechnung')));
$l11b = $outcome(fn() => $post(generated('2026-06-15', YearOpeningService::SOURCE_TYPE, [dr('1171', '5.00'), cr('9100', '5.00')], 'L11 Eröffnung')));
check('L11 the return\'s own entry (source vat-return) and the year opening (source opening) post their uncoded VAT lines', $l11 === 'ok' && $l11b === 'ok', "{$l11} / {$l11b}");

// R18 — the flag «Nur durch Module bebuchbar» on a VAT account does not stop the Sammelbuchung's computed line while the lock applies.
$db->executeStatement("UPDATE account SET module_posting_only = 1 WHERE number = '1170'");
$r18a = $outcome(fn() => $manualService()->create(manual('2026-06-16', [dr('6500', '100.00', 'VM', 810, '100.00', '8.10'), dr('1170', '8.10'), cr('1020', '108.10')], 'L12 Vorsteuer, 1170 gesperrt')));
$r18b = $outcome(fn() => $manualService()->create(manual('2026-06-16', [dr('1170', '5.00'), cr('1020', '5.00')], 'L12 ohne Code auf 1170')));
$r18c = $outcome(fn() => $manualService()->create(manual('2025-06-16', [dr('1170', '5.00'), cr('1020', '5.00')], 'L12 vor dem Tag')));
$db->executeStatement("UPDATE account SET module_posting_only = 0 WHERE number = '1170'");
check('L12 R18: 1170 flagged «Nur durch Module bebuchbar» — inside the window a manual VM entry with its computed 1170 line posts, an uncoded 1170 line is the LOCK\'s refusal; before «Abrechnung ab» the flag holds (`account-module-only`)',
    $r18a === 'ok' && $r18b === 'vat-line-not-computed' && $r18c === 'account-module-only', "{$r18a} / {$r18b} / {$r18c}");

// The sentence on the screens: debtor through its gateway; the delegate every module calls.
$gatewayRefusal = null;
try {
    $emG = $wire();
    $emG->getTransaction(JournalEntry::class)->run(fn() => (new \Z77\Module\Debtor\Accounting\LedgerAccountingGateway($emG, 'harness'))->post(new DebtorRequest(
        day('2026-06-15'), 'Rechnung 9 · Beispiel AG', 'invoice', '9', 'invoice:9:final',
        [\Z77\Module\Debtor\Accounting\PostingLine::debit('1100', chf('5.00')), \Z77\Module\Debtor\Accounting\PostingLine::vatCredit('standard', chf('5.00'), 'MWST UN')]
    )));
} catch (\Z77\Module\Debtor\Accounting\AccountingRefusedException $e) {
    $gatewayRefusal = $e;
}
check('L13 debtor\'s gateway passes the lock\'s reason and financial\'s sentence with its full stop',
    $gatewayRefusal?->reason === \Z77\Module\Debtor\Accounting\AccountingRefusedException::VAT_LINE_NOT_COMPUTED
    && $gatewayRefusal->getMessage() === 'Auf dem MWST-Konto «2200 Geschuldete MWST (Umsatzsteuer)» stehen 5.00 im Haben, aus den MWST-Codes der Buchung ergeben sich 0.00 — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet.',
    $gatewayRefusal === null ? 'not refused' : $gatewayRefusal->reason . ': ' . $gatewayRefusal->getMessage());
check('L14 the modules\' one door: LedgerService::vatLineRefusalText() = PostingRules::vatLineRefusalText(); the reasons listed in VAT_LOCK_REASONS',
    LedgerService::vatLineRefusalText($onAccount) === PostingRules::vatLineRefusalText($onAccount)
    && PostingRefusedException::VAT_LOCK_REASONS === ['vat-line-not-computed', 'tax-code-on-vat-account']);
$sources = [
    'journal'  => $root . '/packages/module-financial/src/Ui/JournalControllerTrait.php',
    'bank'     => $root . '/packages/module-financial/src/Bank/BankPostingService.php',
    'creditor' => $root . '/packages/module-creditor/src/Services/CreditorJournalPostingService.php',
    'receipt'  => $root . '/packages/module-receipt/src/Services/ReceiptJournalPostingService.php',
    'debtor'   => $root . '/packages/module-debtor/src/Accounting/LedgerAccountingGateway.php',
];
check('L15 every refusal arm shows the ONE sentence through LedgerService::vatLineRefusalText() — journal, bank, creditor, receipt, debtor; none words it itself',
    array_filter($sources, static fn(string $f): bool => !str_contains((string) file_get_contents($f), 'LedgerService::vatLineRefusalText(')
        || str_contains((string) file_get_contents($f), 'nimmt nur die Steuer')) === []);
$trait = (string) file_get_contents($sources['journal']);
check('L16 the journal builds the one-line form WITH the VAT accounts (stitch of the pickers package: its account check narrows)',
    preg_match('/new OneLineEntryForm\([^;]*new VatAccountMap\(\$this->em\(\)\)\s*\)/s', $trait) === 1);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
