<?php

/**
 * Bank account service harness (CLI) — stage B1 (ADR-060, `docs/topics/bank.md`, plan
 * `docs/03-development/bank-account-service-plan.md`): the shared camt parser, the camt.053
 * reader, the master data «Bankkonten», the statement import with its seamless chain and its
 * archive.
 *
 * What is load-bearing here:
 *
 *   - (O, offline) module direction (the shared parser names no module, financial names no
 *     consumer, debtor parses through the shared parser); `CamtParser` on a camt.053.001.08 and a
 *     .04 (statement header, signed balances, entry, transaction, parties nested and plain, the
 *     instructed foreign amount) and on a camt.054; no external entity is loaded; the refusals;
 *     `Camt053Reader` (a debit names the creditor, a credit the ultimate debtor, the foreign
 *     amount and rate, a collective entry, an unbooked entry left out, an entry without a
 *     reference told apart by a stable fingerprint) and its refusals (not a statement, no booked
 *     balance, balances that do not add up); the archive (write once, never overwrite, checksum on
 *     read, discard after a rollback); the ZIP reader; the migration (three tables, `down()`);
 *   - (M, database) migrate + `diff` clean; «Bankkonten» (IBAN, ledger account, «Kontoauszug
 *     einlesen» OFF by default, code and — after an import — IBAN immutable, a foreign-currency
 *     account refused, a refused change never reaches the managed entity); the import: unknown
 *     IBAN, switch off, foreign currency, the same file, the same message — each refused with
 *     nothing stored; THE SEAMLESS CHAIN (ADR-060 decision 18): the first statement sets the
 *     start; the next one joins; a gap is REFUSED; an overlapping export is compared at the
 *     covered end and only what is new is taken; an overlap that does not agree is REFUSED;
 *     nothing is inserted into or in front of the chain; a file with nothing new adds nothing; a
 *     movement booked on the covered end day after an earlier export is still taken; several
 *     statements in one file; a failed unit of work leaves no row and no file; every movement
 *     carries the running balance;
 *   - (S, offline / SM, database through host doubles) the three screens of the boards approved
 *     2026-11-17: «Bankkonten» (list, window with the one switch, the IBAN fixed after a
 *     statement, the contradiction hint as one line), the camt.053 upload (one sentence per
 *     file, fetch and page mode, a ZIP), the ONE list «Bewegungen» (header «eingelesen bis»,
 *     search by amount / text / date in the database, bank text escaped) — and that the screens
 *     carry labels only, the explanations being in the help;
 *   - (L, database) ADR-061 stage C1 — THE LINK (`bank_link`, `BankLinkService`): the free
 *     movements an owner may take (unlinked, an active account that reads statements, no
 *     reversal) by reference, amount, original amount and date; a link only inside a unit of
 *     work, with the movement's FULL amount, one per movement and one per owner — each refusal a
 *     German sentence with nothing written; relabel and release; the list «Bewegungen» shows the
 *     label (escaped) in «Zuordnung»; the migration up and down;
 *   - (C, database) ADR-061 stage C2 — STATES, PATTERNS, THE DIRECT POSTING: every movement is
 *     «zu prüfen» until decided (nothing private by silence); «Privat» confirms the pattern of a
 *     merchant / counterparty (the coming movements too), narrowed for a credit, or by hand; a
 *     document beats a pattern and the question of decision 8 («Ja» → «gemischt», «Nein» → an
 *     exception); «Geschäftlich buchen …» (a generated entry through the ledger, linked through
 *     BankLinkService, the VAT split, the attachment rule from the configured threshold, «Privat»,
 *     the transfer clearing account, the learned proposal); «Buchung zurücknehmen …» and its
 *     refusal in a closed year / a settled VAT period; the screens through the host double; the
 *     statement archive on the shared store and the lost race; the migration down and up with
 *     the backfill of `pattern_key`;
 *   - (PK, database) the lock of the VAT accounts in the pickers (ADR-042 addendum 2 of
 *     2026-11-25, point 7; ADR-061 addendum 2026-11-25): from «Abrechnung ab» on, «Geschäftlich
 *     buchen …» neither offers nor takes 2200 / 1170 / 1171 as contra account (the sentence names
 *     the settlement account), a learned proposal naming one is dropped with one line; the day of
 *     the movement decides; the one-line entry's datalist and its refusal at the field; no
 *     reverse-charge or correction code where a gross amount is split.
 *
 * Every IBAN, name, reference and amount in this file is synthetic.
 *
 * Run: php tests/financial-bank.php
 * O needs the monorepo's vendor/ — `composer install` in the root. M needs a reachable MariaDB and
 * the `z77test` credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M
 * is reported SKIPPED and the exit code reflects the offline section only.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-bank: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Financial\Bank\BankAccountService;
use Z77\Module\Financial\Bank\BankMovementStates;
use Z77\Module\Financial\Bank\BankPostingRefusedException;
use Z77\Module\Financial\Bank\BankPostingService;
use Z77\Module\Financial\Bank\BankRefusedException;
use Z77\Module\Financial\Bank\BankStatementArchive;
use Z77\Module\Financial\Bank\BankStatementService;
use Z77\Module\Financial\Bank\BankVoucherArchive;
use Z77\Module\Financial\Bank\Camt053Reader;
use Z77\Module\Financial\Bank\InvalidBankAccountException;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Entities\BankPattern;
use Z77\Module\Financial\Entities\BankStatement;
use Z77\Module\Financial\Validators\BankAccountValidator;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Camt\CamtArchive;
use Z77\Shared\Payment\Camt\CamtFormatException;
use Z77\Shared\Payment\Camt\CamtMessage;
use Z77\Shared\Payment\Camt\CamtParser;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

/** Synthetic accounts — valid check digits, belonging to nobody. */
const OWN_IBAN   = 'CH9300762011623852957';
const OTHER_IBAN = 'CH5604835012345678009';
const PAYEE_IBAN = 'CH4431999123000889012';

/**
 * A camt.053 as a Swiss bank's e-banking exports it, reduced to what the reader reads. An entry:
 * `ref` (AcctSvcrRef; '' = none), `date` (booking), `value`, `amount`, `credit` (bool), `code`
 * (Domn/Fmly/SubFmly), `info` (AddtlNtryInf), `status`, and per transaction (`txs`, default one
 * built from the entry itself): `creditor` / `debtor` / `ultimate` names, `iban`, `qrr`, `ustrd`,
 * `fx` => [amount, currency, rate]. The closing balance is computed unless `closing` is given.
 *
 * @param array<string, mixed>        $head
 * @param list<array<string, mixed>>  $entries
 */
function camt053(array $head, array $entries): string
{
    $version = $head['version'] ?? '08';
    $ccy     = $head['ccy'] ?? 'CHF';
    $party   = static fn(string $tag, string $name) => $version === '08'
        ? "<{$tag}><Pty><Nm>{$name}</Nm><PstlAdr><TwnNm>Luzern</TwnNm></PstlAdr></Pty></{$tag}>"
        : "<{$tag}><Nm>{$name}</Nm><PstlAdr><TwnNm>Luzern</TwnNm></PstlAdr></{$tag}>";
    $tx = static function (array $t, array $e) use ($party, $ccy): string {
        $out = '<TxDtls>';
        if (($t['ref'] ?? '') !== '') {
            $out .= '<Refs><AcctSvcrRef>' . $t['ref'] . '</AcctSvcrRef></Refs>';
        }
        $amount = $t['amount'] ?? $e['amount'];
        $out   .= '<Amt Ccy="' . $ccy . '">' . $amount . '</Amt>';
        if (isset($t['fx'])) {
            $out .= '<AmtDtls><InstdAmt><Amt Ccy="' . $t['fx'][1] . '">' . $t['fx'][0] . '</Amt><CcyXchg><SrcCcy>' . $t['fx'][1] . '</SrcCcy><TrgtCcy>' . $ccy
                . '</TrgtCcy><UnitCcy>' . $t['fx'][1] . '</UnitCcy><XchgRate>' . $t['fx'][2] . '</XchgRate></CcyXchg></InstdAmt></AmtDtls>';
        } else {
            $out .= '<AmtDtls><InstdAmt><Amt Ccy="' . $ccy . '">' . $amount . '</Amt></InstdAmt></AmtDtls>';
        }
        $parties = '';
        if (isset($t['debtor'])) {
            $parties .= $party('Dbtr', $t['debtor']) . (isset($t['debtor_iban']) ? '<DbtrAcct><Id><IBAN>' . $t['debtor_iban'] . '</IBAN></Id></DbtrAcct>' : '');
        }
        if (isset($t['ultimate'])) {
            $parties .= $party('UltmtDbtr', $t['ultimate']);
        }
        if (isset($t['creditor'])) {
            $parties .= $party('Cdtr', $t['creditor']) . (isset($t['iban']) ? '<CdtrAcct><Id><IBAN>' . $t['iban'] . '</IBAN></Id></CdtrAcct>' : '');
        }
        $out .= $parties !== '' ? '<RltdPties>' . $parties . '</RltdPties>' : '';
        if (isset($t['qrr'])) {
            $out .= '<RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>' . $t['qrr'] . '</Ref></CdtrRefInf></Strd></RmtInf>';
        } elseif (isset($t['ustrd'])) {
            $out .= '<RmtInf><Ustrd>' . $t['ustrd'] . '</Ustrd></RmtInf>';
        }

        return $out . '</TxDtls>';
    };

    $opening = chf($head['opening'] ?? '1000.00');
    $balance = $opening;
    $xml     = '';
    foreach ($entries as $e) {
        $credit = (bool) ($e['credit'] ?? false);
        $status = $e['status'] ?? 'BOOK';
        if ($status === 'BOOK') {
            $balance = $credit ? $balance->add(chf($e['amount'])) : $balance->subtract(chf($e['amount']));
        }
        $txs  = $e['txs'] ?? [$e];
        $code = explode('/', $e['code'] ?? 'PMNT/ICDT/DMCT');
        $xml .= '<Ntry><Amt Ccy="' . ($e['ccy'] ?? $ccy) . '">' . $e['amount'] . '</Amt><CdtDbtInd>' . ($credit ? 'CRDT' : 'DBIT') . '</CdtDbtInd><RvslInd>'
            . (($e['reversal'] ?? false) ? 'true' : 'false') . '</RvslInd>'
            . ($version === '08' ? '<Sts><Cd>' . $status . '</Cd></Sts>' : '<Sts>' . $status . '</Sts>')
            . (isset($e['date']) ? '<BookgDt><Dt>' . $e['date'] . '</Dt></BookgDt>' : '')
            . '<ValDt><Dt>' . ($e['value'] ?? $e['date'] ?? '2026-01-01') . '</Dt></ValDt>'
            . (($e['ref'] ?? '') !== '' ? '<AcctSvcrRef>' . $e['ref'] . '</AcctSvcrRef>' : '')
            . '<BkTxCd><Domn><Cd>' . $code[0] . '</Cd><Fmly><Cd>' . $code[1] . '</Cd><SubFmlyCd>' . $code[2] . '</SubFmlyCd></Fmly></Domn></BkTxCd>'
            . '<NtryDtls><Btch><NbOfTxs>' . count($txs) . '</NbOfTxs></Btch>' . implode('', array_map(static fn(array $t) => $tx($t, $e), $txs)) . '</NtryDtls>'
            . (isset($e['info']) ? '<AddtlNtryInf>' . $e['info'] . '</AddtlNtryInf>' : '') . '</Ntry>';
    }
    $closing = isset($head['closing']) ? chf($head['closing']) : $balance;
    $bal     = static fn(string $type, Money $amount, string $date) => '<Bal><Tp><CdOrPrtry><Cd>' . $type . '</Cd></CdOrPrtry></Tp><Amt Ccy="' . $ccy . '">'
        . ltrim($amount->toDecimal(), '-') . '</Amt><CdtDbtInd>' . ($amount->isNegative() ? 'DBIT' : 'CRDT') . '</CdtDbtInd><Dt><Dt>' . $date . '</Dt></Dt></Bal>';
    $from    = $head['from'] ?? '2026-01-01';
    $to      = $head['to'] ?? '2026-01-31';
    $stmt    = '<Stmt><Id>' . ($head['stmt'] ?? 'STMT-1') . '</Id><ElctrncSeqNb>1</ElctrncSeqNb><FrToDt><FrDtTm>' . $from . 'T00:00:00</FrDtTm><ToDtTm>' . $to . 'T23:59:59</ToDtTm></FrToDt>'
        . '<Acct><Id><IBAN>' . ($head['iban'] ?? OWN_IBAN) . '</IBAN></Id><Ccy>' . $ccy . '</Ccy></Acct>'
        . (($head['no_opening'] ?? false) ? '' : $bal('OPBD', $opening, $from)) . $bal('CLBD', $closing, $to) . $bal('CLAV', $closing, $to)
        . $xml . '</Stmt>';
    if (($head['raw'] ?? false) === true) {
        return $stmt;
    }

    return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.' . $version . '"><BkToCstmrStmt><GrpHdr><MsgId>'
        . ($head['msg'] ?? 'MSG-1') . '</MsgId><CreDtTm>2026-02-01T06:15:00</CreDtTm></GrpHdr>' . $stmt . implode('', $head['more'] ?? []) . '</BkToCstmrStmt></Document>';
}

$qrr = '210000000003139471430009017';

/** January: a QR-bill payment, a card purchase in EUR, a plain card purchase, a customer credit, a collective entry, an entry without a reference. */
$january = [
    ['ref' => 'A-0001', 'date' => '2026-01-05', 'value' => '2026-01-05', 'amount' => '250.00', 'code' => 'PMNT/ICDT/VCOM', 'info' => 'Zahlung QR-Rechnung',
        'creditor' => 'Muster Lieferant AG', 'iban' => PAYEE_IBAN, 'qrr' => $qrr],
    ['ref' => 'A-0002', 'date' => '2026-01-08', 'value' => '2026-01-06', 'amount' => '94.35', 'code' => 'PMNT/CCRD/POSD', 'info' => 'Bezugsort: EXAMPLE SHOP BERLIN, Transaktionsdatum 06.01.2026',
        'fx' => ['100.00', 'EUR', '0.9435']],
    ['ref' => 'A-0003', 'date' => '2026-01-09', 'value' => '2026-01-08', 'amount' => '42.10', 'code' => 'PMNT/CCRD/POSD', 'info' => 'Bezugsort: BEISPIEL MARKT LUZERN'],
    ['ref' => 'A-0004', 'date' => '2026-01-12', 'amount' => '500.00', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift',
        'debtor' => 'Zahlstelle Beispiel', 'debtor_iban' => OTHER_IBAN, 'ultimate' => 'Kunde Beispiel GmbH', 'ustrd' => 'Rechnung 1001'],
    ['ref' => 'A-0005', 'date' => '2026-01-20', 'amount' => '300.00', 'code' => 'PMNT/ICDT/DMCT', 'info' => 'Sammelauftrag', 'txs' => [
        ['ref' => 'T-1', 'amount' => '100.00', 'creditor' => 'Eins AG', 'iban' => PAYEE_IBAN],
        ['ref' => 'T-2', 'amount' => '200.00', 'creditor' => 'Zwei AG', 'iban' => OTHER_IBAN],
    ]],
    ['ref' => '', 'date' => '2026-01-31', 'amount' => '5.00', 'code' => 'PMNT/MDOP/CHRG', 'info' => 'Kontoführung'],
];
$januaryXml = camt053(['msg' => 'MSG-JAN', 'stmt' => 'STMT-JAN'], $january);

// ── O. offline ───────────────────────────────────────────────────────────

echo "O. Shared camt parser, camt.053 reader, archive, migration (offline)\n";

$sourceOf = static function (string $dir): string {
    $out = '';
    $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out .= file_get_contents($file->getPathname()) . "\n";
        }
    }
    return $out;
};
$sharedCamtSrc = $sourceOf($root . '/packages/kernel/shared/src/Payment/Camt');
$bankSrc       = $sourceOf($root . '/packages/module-financial/src/Bank');
$financialSrc  = $sourceOf($root . '/packages/module-financial/src');
$debtorReader  = (string) file_get_contents($root . '/packages/module-debtor/src/Payments/CamtReader.php');
$finComposer   = json_decode((string) file_get_contents($root . '/packages/module-financial/composer.json'), true);

check('O1 the shared camt parser names no module; module-financial names no consumer (debtor, creditor, receipt) and gains no dependency',
    !str_contains($sharedCamtSrc, 'Z77\\Module\\')
    && preg_match('/Module\\\\(Debtor|Creditor|Receipt|Dms)\\\\/', $financialSrc) === 0
    && array_values(array_filter(array_keys($finComposer['require']), static fn(string $name) => str_starts_with($name, 'z77/'))) === ['z77/kernel', 'z77/persistence-doctrine', 'z77/module-vat', 'z77/module-mandator'],
    implode(',', array_keys($finComposer['require'])));
check('O2 ONE XML parser: debtor reads camt.054 through the shared CamtParser, financial reads camt.053 through it — neither opens the XML itself; no superglobal in the bank service',
    str_contains($debtorReader, 'CamtParser::parse(') && !str_contains($debtorReader, 'simplexml_load_string')
    && str_contains($bankSrc, 'CamtParser::parse(') && !str_contains($bankSrc, 'simplexml_load_string')
    && preg_match('/\$_(SERVER|POST|GET|FILES|COOKIE|REQUEST)\b/', $bankSrc . $sharedCamtSrc) === 0);
$config = require $root . '/packages/module-financial/src/App/Config/financialConfig.inc.php';
check('O3 the config announces BankAccount, BankStatement and BankMovement — no «Erledigt» service and no claim table (owner 2026-11-16)',
    array_values(array_intersect($config['doctrineEntities'], [BankAccount::class, BankStatement::class, BankMovement::class])) === [BankAccount::class, BankStatement::class, BankMovement::class]
    && !class_exists('Z77\\Module\\Financial\\Bank\\BankMovementService') && !class_exists('Z77\\Module\\Financial\\Entities\\BankClaim'));

$parsed = CamtParser::parse($januaryXml);
$report = $parsed->reports[0];
check('O4 CamtParser, camt.053.001.08: kind, message id, created; the statement — id, sequence, period, IBAN normalised, currency, three balances with dates',
    $parsed->kind === CamtMessage::KIND_STATEMENT && $parsed->messageId === 'MSG-JAN' && $parsed->createdOn?->format('Y-m-d H:i') === '2026-02-01 06:15' && count($parsed->reports) === 1
    && $report->id === 'STMT-JAN' && $report->electronicSequence === 1 && $report->legalSequence === null
    && $report->from?->format('Y-m-d') === '2026-01-01' && $report->to?->format('Y-m-d') === '2026-01-31'
    && $report->iban === OWN_IBAN && $report->currency === 'CHF'
    && array_map(static fn($b) => $b->type . ' ' . $b->amount . ' ' . $b->date?->format('Y-m-d'), $report->balances) === ['OPBD 1000.00 2026-01-01', 'CLBD 808.55 2026-01-31', 'CLAV 808.55 2026-01-31']);
$e1 = $report->entries[0];
$e2 = $report->entries[1];
$e4 = $report->entries[3];
check('O5 … an entry: amount, side, status BOOK, booking and value date, AcctSvcrRef, bank transaction code as Domain/Family/SubFamily, AddtlNtryInf, batch count',
    $e1->amount === '250.00' && $e1->currency === 'CHF' && !$e1->isCredit && !$e1->isReversal && $e1->status === 'BOOK'
    && $e2->bookingDate?->format('Y-m-d') === '2026-01-08' && $e2->valueDate?->format('Y-m-d') === '2026-01-06'
    && $e1->accountServicerRef === 'A-0001' && $e1->bankTxCode === 'PMNT/ICDT/VCOM' && $e1->additionalInfo === 'Zahlung QR-Rechnung' && $e1->batchCount === 1
    && $e4->isCredit && count($report->entries[4]->transactions) === 2 && $report->entries[4]->batchCount === 2);
$t1 = $e1->transactions[0];
$t2 = $e2->transactions[0];
$t4 = $e4->transactions[0];
check('O6 … a transaction: the creditor nested under Pty (.08) with its account, the QRR reference; the instructed EUR amount with the rate; debtor, its account and the ultimate debtor of a credit, the unstructured message',
    $t1->creditor?->name === 'Muster Lieferant AG' && $t1->creditor->city === 'Luzern' && $t1->creditor->iban === PAYEE_IBAN && $t1->debtor === null
    && $t1->referenceType === 'QRR' && $t1->reference === $qrr && $t1->amount === '250.00'
    && $t2->instructedAmount === '100.00' && $t2->instructedCurrency === 'EUR' && $t2->exchangeRate === '0.9435' && $t2->exchangeSource === 'EUR' && $t2->exchangeTarget === 'CHF'
    && $t1->instructedCurrency === 'CHF' && $t1->exchangeRate === ''
    && $t4->debtor?->name === 'Zahlstelle Beispiel' && $t4->debtor->iban === OTHER_IBAN && $t4->ultimateDebtor?->name === 'Kunde Beispiel GmbH' && $t4->ultimateDebtor->iban === ''
    && $t4->referenceType === 'NON' && $t4->remittance === 'Rechnung 1001');
$parsed04 = CamtParser::parse(camt053(['version' => '04', 'opening' => '-50.00'], [['ref' => 'V4', 'date' => '2026-01-03', 'amount' => '10.00', 'creditor' => 'Alte Bank AG', 'iban' => PAYEE_IBAN]]));
check('O7 camt.053.001.04: the status as a plain value, the party directly under Cdtr; an overdrawn balance (DBIT) is negative',
    $parsed04->reports[0]->entries[0]->status === 'BOOK' && $parsed04->reports[0]->entries[0]->transactions[0]->creditor?->name === 'Alte Bank AG'
    && $parsed04->reports[0]->balance('OPBD')?->amount === '-50.00' && $parsed04->reports[0]->balance('CLBD')?->amount === '-60.00');
$camt054 = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08"><BkToCstmrDbtCdtNtfctn><GrpHdr><MsgId>N-1</MsgId><CreDtTm>2026-01-02T08:00:00</CreDtTm></GrpHdr>'
    . '<Ntfctn><Id>N</Id><Acct><Id><IBAN>' . OWN_IBAN . '</IBAN></Id></Acct><Ntry><Amt Ccy="CHF">12.5</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><ValDt><Dt>2026-01-02</Dt></ValDt>'
    . '<NtryDtls><TxDtls><Refs><TxId>TX-9</TxId></Refs></TxDtls></NtryDtls></Ntry></Ntfctn></BkToCstmrDbtCdtNtfctn></Document>';
$note = CamtParser::parse($camt054);
check('O8 the same parser reads a camt.054: kind notification, no balances, the amount padded to two decimals, TxId, a transaction without an amount of its own',
    $note->kind === CamtMessage::KIND_NOTIFICATION && $note->reports[0]->balances === [] && $note->reports[0]->entries[0]->amount === '12.50'
    && $note->reports[0]->entries[0]->transactions[0]->txId === 'TX-9' && $note->reports[0]->entries[0]->transactions[0]->amount === null);
$debtorFile = \Z77\Module\Debtor\Payments\CamtReader::read($camt054);
check('O9 debtor\'s CamtReader over the shared parser: the same notification as one row (TxId as the reference, the entry\'s amount, credit); a camt.053 is refused as not-camt054',
    $debtorFile->messageId === 'N-1' && $debtorFile->iban === OWN_IBAN && count($debtorFile->entries) === 1 && $debtorFile->entries[0]->txRef === 'TX-9'
    && $debtorFile->entries[0]->amount === '12.50' && $debtorFile->entries[0]->isCredit
    && caught(fn() => \Z77\Module\Debtor\Payments\CamtReader::read($januaryXml), \Z77\Module\Debtor\Services\BankImportRefusedException::class)?->reason === 'not-camt054');
$secret = sys_get_temp_dir() . '/z77-bank-xxe-' . getmypid() . '.txt';
file_put_contents($secret, 'LEAK');
$xxe = str_replace(['<?xml version="1.0" encoding="UTF-8"?>', 'Zahlung QR-Rechnung'], ['<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE Document [<!ENTITY x SYSTEM "file:///' . str_replace('\\', '/', $secret) . '">]>', '&x;'], $januaryXml);
$xxeInfo = (function () use ($xxe) { try { return CamtParser::parse($xxe)->reports[0]->entries[0]->additionalInfo; } catch (CamtFormatException) { return ''; } })();
@unlink($secret);
check('O10 an external entity in the file is never loaded (no file content reaches the data)', !str_contains($xxeInfo, 'LEAK'), $xxeInfo);
check('O11 the parser refuses what is not XML (not-xml) and XML that is no camt report (not-camt)',
    caught(fn() => CamtParser::parse('nope'), CamtFormatException::class)?->reason === CamtFormatException::NOT_XML
    && caught(fn() => CamtParser::parse('<?xml version="1.0"?><Document><Other/></Document>'), CamtFormatException::class)?->reason === CamtFormatException::NOT_CAMT
    && caught(fn() => CamtParser::parse('<?xml version="1.0"?><Other><BkToCstmrStmt><GrpHdr/></BkToCstmrStmt></Other>'), CamtFormatException::class)?->reason === CamtFormatException::NOT_CAMT);

$read = Camt053Reader::read($januaryXml);
$stmt = $read[0];
$mv   = $stmt->movements;
check('O12 Camt053Reader: one statement — message and statement id, period, IBAN, currency, opening 1000.00 / closing 808.55 with their dates, six movements, none skipped',
    count($read) === 1 && $stmt->messageId === 'MSG-JAN' && $stmt->statementId === 'STMT-JAN' && $stmt->from->format('Y-m-d H:i') === '2026-01-01 00:00' && $stmt->to->format('Y-m-d H:i') === '2026-01-31 00:00'
    && $stmt->iban === OWN_IBAN && $stmt->currency === 'CHF' && $stmt->openingBalance === '1000.00' && $stmt->closingBalance === '808.55'
    && $stmt->openingDate->format('Y-m-d') === '2026-01-01' && $stmt->closingDate->format('Y-m-d') === '2026-01-31' && count($mv) === 6 && $stmt->skipped === 0);
check('O13 … a QR-bill payment (debit): the CREDITOR and its IBAN as the counterparty, QRR reference, the bank\'s text; no foreign amount',
    !$mv[0]->isCredit && $mv[0]->entryRef === 'A-0001' && $mv[0]->counterpartyName === 'Muster Lieferant AG' && $mv[0]->counterpartyIban === PAYEE_IBAN
    && $mv[0]->referenceType === 'QRR' && $mv[0]->reference === $qrr && $mv[0]->text === 'Zahlung QR-Rechnung' && $mv[0]->originalAmount === null && $mv[0]->exchangeRate === null
    && $mv[0]->bankTxCode === 'PMNT/ICDT/VCOM' && $mv[0]->txCount === 1 && $mv[0]->position === 1);
check('O14 … a card purchase abroad: 94.35 CHF debited, the instructed 100.00 EUR and the bank\'s rate; booking date 08.01., value date 06.01.; the merchant only in the text',
    $mv[1]->amount === '94.35' && $mv[1]->originalAmount === '100.00' && $mv[1]->originalCurrency === 'EUR' && $mv[1]->exchangeRate === '0.9435'
    && $mv[1]->bookingDate->format('Y-m-d') === '2026-01-08' && $mv[1]->valueDate?->format('Y-m-d') === '2026-01-06'
    && $mv[1]->counterpartyName === '' && str_contains($mv[1]->text, 'Bezugsort: EXAMPLE SHOP BERLIN'));
check('O15 … a credit: the ULTIMATE debtor by name (it carries one), the debtor\'s account as the IBAN, the message and the bank\'s text',
    $mv[3]->isCredit && $mv[3]->counterpartyName === 'Kunde Beispiel GmbH' && $mv[3]->counterpartyIban === OTHER_IBAN && $mv[3]->text === "Rechnung 1001\nGutschrift");
check('O16 … a collective entry: ONE movement with the entry\'s amount, two transactions counted, counterparty and reference empty',
    $mv[4]->amount === '300.00' && $mv[4]->txCount === 2 && $mv[4]->counterpartyName === '' && $mv[4]->reference === '' && $mv[4]->entryRef === 'A-0005');
$again = Camt053Reader::read($januaryXml)[0]->movements;
$twins = Camt053Reader::read(camt053([], [
    ['ref' => '', 'date' => '2026-01-10', 'amount' => '9.90', 'info' => 'Abo'],
    ['ref' => '', 'date' => '2026-01-10', 'amount' => '9.90', 'info' => 'Abo'],
    ['ref' => 'DUP', 'date' => '2026-01-11', 'amount' => '1.00'],
    ['ref' => 'DUP', 'date' => '2026-01-11', 'amount' => '1.00'],
]))[0]->movements;
check('O17 … an entry without a reference has none and a fingerprint that is stable across two reads; two identical entries of one day get DIFFERENT fingerprints; a reference repeated in a statement is kept once, the second entry falls back to its fingerprint',
    $mv[5]->entryRef === null && strlen($mv[5]->fingerprint) === 64 && $mv[5]->fingerprint === $again[5]->fingerprint
    && $twins[0]->entryRef === null && $twins[1]->entryRef === null && $twins[0]->fingerprint !== $twins[1]->fingerprint
    && $twins[2]->entryRef === 'DUP' && $twins[3]->entryRef === null
    && BankMovement::dedupKey($twins[2]->entryRef, $twins[2]->fingerprint) === 'DUP' && BankMovement::dedupKey(null, $twins[3]->fingerprint) === 'fp:' . $twins[3]->fingerprint);
$pending = Camt053Reader::read(camt053([], [['ref' => 'P1', 'date' => '2026-01-10', 'amount' => '9.90', 'status' => 'PDNG'], ['ref' => 'B1', 'date' => '2026-01-10', 'amount' => '1.00']]))[0];
check('O18 … an entry that is not booked (PDNG) is left out and counted; the balances are judged on the booked ones',
    count($pending->movements) === 1 && $pending->movements[0]->entryRef === 'B1' && $pending->skipped === 1 && $pending->closingBalance === '999.00');
$refused = static fn(string $xml) => caught(fn() => Camt053Reader::read($xml), BankRefusedException::class);
check('O19 the reader refuses: not XML; a camt.054 (not-camt053); a statement without an opening balance, an entry without a booking date, an entry in another currency, balances that do not add up (incomplete, with the sums named)',
    $refused('nope')?->reason === BankRefusedException::NOT_XML
    && $refused($camt054)?->reason === BankRefusedException::NOT_CAMT053
    && $refused(camt053(['no_opening' => true], []))?->reason === BankRefusedException::INCOMPLETE
    && $refused(camt053([], [['ref' => 'X', 'amount' => '1.00']]))?->reason === BankRefusedException::INCOMPLETE
    && $refused(camt053([], [['ref' => 'X', 'date' => '2026-01-02', 'amount' => '1.00', 'ccy' => 'EUR']]))?->reason === BankRefusedException::INCOMPLETE
    && $refused(camt053(['closing' => '777.00'], $january))?->reason === BankRefusedException::INCOMPLETE
    && str_contains((string) $refused(camt053(['closing' => '777.00'], $january))?->getMessage(), '808.55'));

$bankAccount = new BankAccount(['code' => ' HAUS ', 'label' => ' Hausbank Geschäftskonto ', 'iban' => 'ch93 0076 2011 6238 5295 7', 'currency' => 'chf', 'ledger_account' => ' 1022 ']);
check('O20 BankAccount: code lower-cased, IBAN normalised, currency upper-cased, trimmed; «Kontoauszug einlesen» is OFF by default, active ON — there is no «Gemischtes Konto» switch',
    $bankAccount->getCode() === 'haus' && $bankAccount->getLabel() === 'Hausbank Geschäftskonto' && $bankAccount->getIban() === OWN_IBAN && $bankAccount->getCurrency() === 'CHF'
    && $bankAccount->getLedgerAccount() === '1022' && !$bankAccount->isStatementImport() && !method_exists($bankAccount, 'isMixed') && $bankAccount->isActive());
$shape = static function (array $values): array {
    $validator = new BankAccountValidator(new BankAccount($values + ['code' => 'haus', 'label' => 'Hausbank', 'iban' => OWN_IBAN, 'currency' => 'CHF', 'ledger_account' => '1022']), null, null, 'CHF');
    $validator->isValid();
    return array_keys($validator->getFieldErrors());
};
check('O21 BankAccountValidator (shape): a valid row passes; code, label, IBAN (check digit), currency and ledger account are each refused on their field; a EUR account is refused with the reason',
    $shape([]) === [] && $shape(['code' => 'L']) === ['code'] && $shape(['label' => '']) === ['label'] && $shape(['iban' => 'CH9300762011623852950']) === ['iban']
    && $shape(['ledger_account' => '']) === ['ledger_account'] && $shape(['currency' => 'EUR']) === ['currency']);

$archiveBase = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-bank-archive-' . getmypid();
$archive     = new BankStatementArchive($archiveBase);
$sha         = hash('sha256', $januaryXml);
$stored      = $archive->store($januaryXml, $sha, new \DateTimeImmutable('2026-01-31'), 'haus');
$storedAgain = (new BankStatementArchive($archiveBase))->store($januaryXml, $sha, new \DateTimeImmutable('2026-01-31'), 'haus');
check('O22 the archive: data/archive/financial/bank/<YYYY>/<date>-<code>-<sha16>.xml, the bytes unaltered; the same bytes again answer the same path',
    $stored === 'data/archive/financial/bank/2026/20260131-haus-' . substr($sha, 0, 16) . '.xml' && file_get_contents($archiveBase . '/' . $stored) === $januaryXml && $storedAgain === $stored
    && glob($archiveBase . '/data/archive/financial/bank/2026/*') === [$archiveBase . '/' . $stored], $stored);
check('O23 … never overwritten: other bytes for an existing path are refused and the file stays; a checksum that does not fit the bytes is refused and leaves nothing',
    caught(fn() => (new BankStatementArchive($archiveBase))->store('other', $sha, new \DateTimeImmutable('2026-01-31'), 'haus'), \RuntimeException::class) !== null
    && file_get_contents($archiveBase . '/' . $stored) === $januaryXml
    && caught(fn() => (new BankStatementArchive($archiveBase))->store('x', str_repeat('0', 64), new \DateTimeImmutable('2025-12-31'), 'haus'), \RuntimeException::class) !== null
    && (glob($archiveBase . '/data/archive/financial/bank/2025/*') ?: []) === []);
$second = new BankStatementArchive($archiveBase);
$other  = $second->store('<x/>', hash('sha256', '<x/>'), new \DateTimeImmutable('2026-02-28'), 'haus');
$second->discardWritten();
$archive->discardWritten();
check('O24 … discardWritten() removes what THAT instance wrote (the rollback path) — and nothing else',
    !is_file($archiveBase . '/' . $other) && !is_file($archiveBase . '/' . $stored));
(function (string $dir) { $rm = function (string $d) use (&$rm) { foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $rm($f) : @unlink($f); } @rmdir($d); }; $rm($dir); })($archiveBase);

$zipOf = static function (array $files): string {
    $path = tempnam(sys_get_temp_dir(), 'z77bankzip');
    $zip  = new \ZipArchive();
    $zip->open($path, \ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);
    return $bytes;
};
$zipFiles = CamtArchive::xmlFiles($zipOf(['bank/januar.xml' => $januaryXml, 'liesmich.txt' => 'x', '__MACOSX/._januar.xml' => 'x']));
check('O25 CamtArchive: the XML entries of a ZIP by their base name — other files and macOS metadata skipped; no XML and no ZIP are refused (archive)',
    count($zipFiles) === 1 && $zipFiles[0]['name'] === 'januar.xml' && $zipFiles[0]['xml'] === $januaryXml
    && caught(fn() => CamtArchive::xmlFiles($zipOf(['liesmich.txt' => 'x'])), CamtFormatException::class)?->reason === CamtFormatException::ARCHIVE
    && caught(fn() => CamtArchive::xmlFiles('not a zip'), CamtFormatException::class)?->reason === CamtFormatException::ARCHIVE);

$migrationFile = $root . '/packages/module-financial/res/migrations/Version20261116140000.php';
$migration     = (string) @file_get_contents($migrationFile);
check('O26 ONE migration for stage B1: bank_account, bank_statement, bank_movement — and a down() that drops all three',
    substr_count($migration, 'CREATE TABLE bank_') === 3 && substr_count($migration, 'DROP TABLE bank_') === 3
    && array_filter(['bank_account', 'bank_statement', 'bank_movement'], static fn(string $t) => !str_contains($migration, 'CREATE TABLE ' . $t . ' ') || !str_contains($migration, 'DROP TABLE ' . $t)) === []);

// ── S. the backend screens, offline (boards «Bankkonten», «Kontoauszug einlesen», «Liste Bewegungen», approved 2026-11-17) ──

echo "S. Backend screens — navigation, mounts, templates, help (offline)\n";

$seed   = array_column(json_decode((string) file_get_contents($root . '/packages/module-financial/data/framework/routing/navigation.d/module-financial.json'), true), null, 'key');
check('S1 the navigation seed: «Bankkonto» under Finanzen (bank-movement/list) and «Bankkonten» under Stammdaten › Finanzen (bank-account/list)',
    ($seed['bankkonto']['parent_key'] ?? '') === 'finanzen' && ($seed['bankkonto']['controller'] ?? '') === 'bank-movement' && ($seed['bankkonto']['name'] ?? '') === 'Bankkonto'
    && ($seed['bankkonten']['parent_key'] ?? '') === 'stammdaten-finanzen' && ($seed['bankkonten']['controller'] ?? '') === 'bank-account' && ($seed['bankkonten']['action'] ?? '') === 'list');
$backend = $root . '/packages/module-backend/src/Ui';
$hostA   = (string) @file_get_contents($backend . '/Controllers/Finance/BankAccountController.php');
$hostM   = (string) @file_get_contents($backend . '/Controllers/Finance/BankMovementController.php');
$acl     = (string) file_get_contents($root . '/packages/module-backend/src/App/Config/backendConfig.inc.php');
check('S2 the hosts in module-backend only mount the traits; their layout configs pin the body to the fragments of module-financial; no ACL deviation — the module role ADMIN applies (BANK-ACL-001)',
    str_contains($hostA, 'use BankAccountControllerTrait;') && str_contains($hostM, 'use BankMovementControllerTrait;') && substr_count($hostA, 'function ') === 0 && substr_count($hostM, 'function ') === 0
    && (require $backend . '/Config/Finance/bankAccountControllerConfig.inc.php') === \Z77\Module\Financial\Ui\BankLayout::accounts()
    && (require $backend . '/Config/Finance/bankMovementControllerConfig.inc.php') === \Z77\Module\Financial\Ui\BankLayout::movements()
    && \Z77\Module\Financial\Ui\BankLayout::movements()['levelElements']['body']['main'][0]['path'] === 'Backend/BankMovementController'
    && !str_contains($acl, 'BankAccountController') && !str_contains($acl, 'BankMovementController'));
$tplDir  = $root . '/packages/module-financial/res/view/templates/Backend';
$screenT = ['BankAccountController/listAction', 'BankAccountController/_row', 'BankAccountController/act', 'BankAccountController/edit', 'BankMovementController/listAction', 'BankMovementController/_list', 'BankMovementController/act', 'BankMovementController/toolbar', 'BankMovementController/edit', 'BankMovementController/confirmPrivate', 'partials/bankAccountSelect'];
$helpT   = ['BankAccountController/list.help', 'BankAccountController/edit.help', 'BankMovementController/list.help', 'BankMovementController/edit.help'];
$read    = static fn(array $names): string => implode("\n", array_map(static fn(string $n) => (string) @file_get_contents($tplDir . '/' . $n . '.tpl.php'), $names));
$missing = array_filter([...$screenT, ...$helpT], static fn(string $n) => !is_file($tplDir . '/' . $n . '.tpl.php'));
check('S3 every screen template and help file exists', $missing === [], implode(', ', $missing));
$screenSrc = $read($screenT);
$bodyOf    = static fn(string $tpl): string => (string) preg_replace('~/\*\*.*?\*/~s', '', $tpl);
check('S4 the screens carry labels only (owner 2026-11-17): no hint paragraph, no intro band, no script, no inline handler — and the traits touch no superglobal',
    !str_contains($bodyOf($screenSrc), 'be-form__hint') && !str_contains($bodyOf($screenSrc), '<script') && preg_match('/\son[a-z]+="/', $bodyOf($screenSrc)) === 0
    && !str_contains($bodyOf($screenSrc), 'innerHTML')
    && preg_match('/\$_(SERVER|POST|GET|FILES|COOKIE|REQUEST)\b/', (string) file_get_contents($root . '/packages/module-financial/src/Ui/BankAccountControllerTrait.php') . (string) file_get_contents($root . '/packages/module-financial/src/Ui/BankMovementControllerTrait.php')) === 0);
$editHelp = $read(['BankAccountController/edit.help']);
$listHelp = $read(['BankMovementController/list.help']);
check('S5 the explanations are in the help: a section per field of the bank account window (the switch says that EVERY movement is stored, private ones too) and per search field of the list; the list help says «nichts wird von selbst privat und nichts wird von selbst verbucht» (ADR-061 stage C2) and «immer lückenlos»; the movement window has a section per field of «Geschäftlich buchen» and of «Privat», in everyday words (no «Muster-Schlüssel», no table name)',
    array_filter(['label', 'code', 'iban', 'ledger_account', 'currency', 'statement_import'], static fn(string $f) => !str_contains($editHelp, 'data-help-field="' . $f . '"')) === []
    && str_contains($editHelp, 'auch private') && str_contains($editHelp, '10 Jahre')
    && array_filter(['f_date', 'f_text', 'f_amount'], static fn(string $f) => !str_contains($listHelp, 'data-help-field="' . $f . '"')) === []
    && str_contains($listHelp, 'Nichts wird von selbst privat und nichts wird von selbst verbucht') && str_contains($listHelp, 'immer lückenlos') && str_contains($listHelp, 'camt.053 Upload')
    && array_filter(['kind', 'contra', 'tax_code', 'voucher', 'text', 'flip', 'only_this', 'near_amount', 'near_day'], static fn(string $f) => !str_contains($read(['BankMovementController/edit.help']), 'data-help-field="' . $f . '"')) === []
    && str_contains($read(['BankMovementController/edit.help']), 'Buchung zurücknehmen') && str_contains($read(['BankMovementController/edit.help']), 'der eingelesene Kontoauszug ist der Beleg')
    && preg_match('/pattern_key|bank_pattern|match_key|BankPattern|idempot/i', $bodyOf($read(['BankMovementController/edit.help', 'BankMovementController/list.help']))) === 0);
check('S6 every value of a movement goes through e() in the list, window and confirm templates (bank text is foreign input); the list carries no tabs itself (the views are the toolbar\'s); since stage C2 the state icon of a row opens the movement\'s window',
    preg_match('/<\?=\s*\$(movement|party|text|detail|link|pattern|posting|question|open|linked)\b/', $read(['BankMovementController/_list', 'BankMovementController/edit', 'BankMovementController/confirmPrivate'])) === 0
    && str_contains($bodyOf($read(['BankMovementController/_list'])), 'data-window-open')
    && !str_contains($bodyOf($read(['BankMovementController/_list', 'BankMovementController/listAction'])), 'be-viewtabs'));
$debtorCfg = (string) file_get_contents($root . '/packages/module-debtor/src/App/Config/debtorConfig.inc.php');
check('S7 the contradiction hint is a registry: debtor declares `bankAccountHints` (a class implementing financial\'s interface); financial names no module',
    str_contains($debtorCfg, "'bankAccountHints' => [\\Z77\\Module\\Debtor\\Services\\PaymentTargetBankAccountHint::class]")
    && is_subclass_of(\Z77\Module\Debtor\Services\PaymentTargetBankAccountHint::class, \Z77\Module\Financial\Bank\BankAccountHintInterface::class)
    && \Z77\Module\Financial\Bank\BankAccountHints::CONFIG_KEY === 'bankAccountHints');

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

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-bank-' . getmypid());
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

// The REAL packages: their configs announce the entities, their res/migrations hold the migrations.
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
@mkdir($base . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $seedName) {
    copy($packages['Vat'] . '/data/framework/vat/' . $seedName . '.default.json', $base . '/data/framework/vat/' . $seedName . '.json');
}

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map — and NO AuthService (the actor is passed). */
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
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)   // the shared upload field writes its «auswählen» through t()
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
};
$em = $wire();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$count = fn(string $table, string $where = '1=1') => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$where}");
$row   = fn(string $sql) => $db->fetchAssociative($sql) ?: [];
$files = fn() => array_values(array_filter(glob($base . '/' . BankStatementArchive::DIR . '/*/*') ?: [], 'is_file'));

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
check('M1 migrate exits 0 (vat, mandator, financial in timestamp order)', $code === 0, trim($out));
$migrations = glob($packages['Financial'] . '/res/migrations/Version*.php');
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']);
check('M2 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && glob($packages['Financial'] . '/res/migrations/Version*.php') === $migrations, trim($out));
foreach (array_diff(glob($packages['Financial'] . '/res/migrations/Version*.php'), $migrations) as $stray) {
    @unlink($stray);   // a failed M2 must not leave a generated file in the package
}

// The books: the KMU chart plus 1022 (the bank account of the harness) and a group to refuse.
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
(new \Z77\Module\Financial\Services\AccountService($em))->save(new Account(['number' => '1022', 'name' => 'Bank Beispiel', 'type' => 'asset']));

// ── «Bankkonten» ──
echo "A. Master data «Bankkonten»\n";

$em       = $wire();
$accounts = new BankAccountService($em);
$haus     = new BankAccount(['code' => 'haus', 'label' => 'Beispielbank Geschäftskonto', 'iban' => 'CH93 0076 2011 6238 5295 7', 'ledger_account' => '1022']);
$accounts->save($haus);
$hausRow = $row('SELECT * FROM bank_account');
check('A1 a bank account is stored: code, label, IBAN normalised, the base currency filled in, ledger account 1022; «Kontoauszug einlesen» OFF, active — and no other switch',
    $haus->getId() !== null && $hausRow['code'] === 'haus' && $hausRow['iban'] === OWN_IBAN && $hausRow['currency'] === 'CHF' && $hausRow['ledger_account'] === '1022'
    && (int) $hausRow['statement_import'] === 0 && (int) $hausRow['active'] === 1 && !array_key_exists('mixed', $hausRow));
$invalid = static function (array $values) use ($accounts): array {
    $e = caught(fn() => $accounts->save(new BankAccount($values + ['code' => 'zweit', 'label' => 'Zweitkonto', 'iban' => OTHER_IBAN, 'ledger_account' => '1020'])), InvalidBankAccountException::class);
    return $e instanceof InvalidBankAccountException ? $e->validator->getFieldErrors() : ['(saved)' => ''];
};
check('A2 refused on the field, nothing stored: the same IBAN and the same code (each names the other bank account), a ledger account that does not exist, a group, an invalid IBAN',
    array_keys($invalid(['iban' => OWN_IBAN])) === ['iban'] && str_contains($invalid(['iban' => OWN_IBAN])['iban'], 'Beispielbank Geschäftskonto')
    && array_keys($invalid(['code' => 'haus'])) === ['code']
    && array_keys($invalid(['ledger_account' => '9999999'])) === ['ledger_account']
    && str_contains($invalid(['ledger_account' => '10'])['ledger_account'] ?? '', 'Gruppe')
    && array_keys($invalid(['iban' => 'CH0000000000000000000'])) === ['iban']
    && $count('bank_account') === 1, json_encode($invalid(['ledger_account' => '10'])));
$eur = $invalid(['currency' => 'EUR']);
check('A3 a foreign-currency bank account is refused with a clear sentence (decision 8): the books run in CHF, later stage, a payment method keeps its own account',
    array_keys($eur) === ['currency'] && str_contains($eur['currency'], 'EUR') && str_contains($eur['currency'], 'CHF') && str_contains($eur['currency'], 'Fremdwährungskonten') && $count('bank_account') === 1, json_encode($eur));
$codeChange = caught(fn() => $accounts->update($haus, ['code' => 'neu']), InvalidBankAccountException::class);
check('A4 the code is immutable: a change is refused on the field and never reaches the managed entity',
    $codeChange instanceof InvalidBankAccountException && isset($codeChange->validator->getFieldErrors()['code']) && $haus->getCode() === 'haus' && $row('SELECT code FROM bank_account')['code'] === 'haus');
$badLedger = caught(fn() => $accounts->update($haus, ['label' => 'Neu', 'ledger_account' => '9999999']), InvalidBankAccountException::class);
$em->flush();
check('A5 a refused update leaves the managed entity untouched — a later flush writes nothing of it',
    $badLedger instanceof InvalidBankAccountException && $haus->getLabel() === 'Beispielbank Geschäftskonto' && $row('SELECT label, ledger_account FROM bank_account') === ['label' => 'Beispielbank Geschäftskonto', 'ledger_account' => '1022']);
$accounts->update($haus, ['label' => 'Beispielbank']);
check('A6 a valid update is stored; find() by code and all() answer the bank account',
    $row('SELECT label FROM bank_account') === ['label' => 'Beispielbank'] && $accounts->find(' HAUS ')?->getId() === $haus->getId() && count($accounts->all()) === 1);

// ── the import ──
echo "I. camt.053 import — the seamless chain, movements, archive\n";

$importer = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em) => new BankStatementService($em, 'tester');
$refusal  = static function (string $xml, string $name = 'x.xml') use ($importer, $wire): ?BankRefusedException {
    return caught(fn() => $importer($wire())->import($xml, $name), BankRefusedException::class);
};
$state   = fn() => [$count('bank_statement'), $count('bank_movement'), count($files())];
$nothing = fn() => $state() === [0, 0, 0];

$off = $refusal($januaryXml);
check('I1 «Kontoauszug einlesen» is OFF (the default): the statement is refused (import-off), the sentence names the switch — no statement, no movement, no file',
    $off?->reason === BankRefusedException::IMPORT_OFF && str_contains($off->getMessage(), '«Kontoauszug einlesen»') && str_contains($off->getMessage(), 'Beispielbank') && $nothing(), (string) $off?->getMessage());
$unknown = $refusal(camt053(['iban' => OTHER_IBAN], $january));
check('I2 a file whose statements belong to NO bank account of the installation is refused as not-bank-account (the upload skips it): the sentence says where to create it, `subjects` carries the IBAN',
    $unknown?->reason === BankRefusedException::NOT_BANK_ACCOUNT && str_contains($unknown->getMessage(), 'Stammdaten › Finanzen › Bankkonten') && str_contains($unknown->getMessage(), 'CH56 0483')
    && count($unknown->subjects) === 1 && str_starts_with($unknown->subjects[0], 'CH56 0483') && $nothing(), json_encode($unknown?->subjects));

$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('haus'), ['statement_import' => true, 'reconcile_from' => '2026-01-01']);
$foreign = $refusal(camt053(['ccy' => 'EUR'], []));
check('I3 a statement in EUR is refused (foreign-currency) with a clear sentence — nothing stored',
    $foreign?->reason === BankRefusedException::FOREIGN_CURRENCY && str_contains($foreign->getMessage(), 'EUR') && str_contains($foreign->getMessage(), 'Fremdwährungskonten') && $nothing(), (string) $foreign?->getMessage());
check('I4 a statement that is not consistent in itself (opening + movements ≠ closing) and a file that is no XML store nothing',
    $refusal('nope')?->reason === BankRefusedException::NOT_XML && $refusal(camt053(['closing' => '1.00'], $january))?->reason === BankRefusedException::INCOMPLETE && $nothing());
$em = $wire();
check('I4b before the first import the account is read up to nothing', $importer($em)->coveredTo((new BankAccountService($em))->find('haus')) === null);

$jan    = $importer($wire())->import($januaryXml, 'export-januar.xml');
$janRow = $row('SELECT * FROM bank_statement');
check('I5 the FIRST statement sets the start: January stored — message and statement id, period, opening 1000.00 on 01.01., closing 808.55 on 31.01., file name, SHA-256, who; six movements new; the answer «6 neue Bewegungen, Saldo stimmt (eingelesen bis 31.01.2026 …)»',
    count($jan) === 1 && $jan[0]->statement?->getId() !== null && $jan[0]->newMovements === 6 && $jan[0]->knownMovements === 0
    && $jan[0]->coveredTo->format('Y-m-d') === '2026-01-31' && $jan[0]->balance->toDecimal() === '808.55'
    && str_starts_with($jan[0]->message(), '6 neue Bewegungen, Saldo stimmt (eingelesen bis 31.01.2026, Saldo 808.55)')
    && $janRow['message_id'] === 'MSG-JAN' && $janRow['statement_id'] === 'STMT-JAN' && $janRow['period_from'] === '2026-01-01' && $janRow['period_to'] === '2026-01-31'
    && $janRow['opening_balance'] === '1000.00' && $janRow['opening_date'] === '2026-01-01' && $janRow['closing_balance'] === '808.55' && $janRow['closing_date'] === '2026-01-31'
    && $janRow['file_name'] === 'export-januar.xml' && $janRow['file_sha256'] === hash('sha256', $januaryXml) && $janRow['imported_by'] === 'tester'
    && (int) $janRow['movement_count'] === 6 && (int) $janRow['duplicate_count'] === 0 && (int) $janRow['electronic_sequence'] === 1 && $janRow['legal_sequence'] === null, $jan[0]->message());
check('I6 … its file lies in the archive, unaltered, under data/archive/financial/bank/2026/ — the path on the statement, the checksum verified on reading',
    $files() === [$base . '/' . $janRow['archive_path']] && str_starts_with($janRow['archive_path'], 'data/archive/financial/bank/2026/20260131-haus-')
    && file_get_contents($base . '/' . $janRow['archive_path']) === $januaryXml && (new BankStatementArchive())->bytes($jan[0]->statement) === $januaryXml);
$m1 = $row("SELECT * FROM bank_movement WHERE entry_ref = 'A-0001'");
$m2 = $row("SELECT * FROM bank_movement WHERE entry_ref = 'A-0002'");
$m4 = $row("SELECT * FROM bank_movement WHERE entry_ref = 'A-0004'");
$m6 = $row('SELECT * FROM bank_movement WHERE entry_ref IS NULL');
check('I7 … the movements: the QR-bill payment (debit 250.00, booking date, creditor, its IBAN, QRR, bank code, balance 750.00 after it); the EUR card purchase (94.35 CHF, 100.00 EUR, rate 0.9435 «structured», booking ≠ value date, balance 655.65); the credit; the entry without a reference under its fingerprint key, balance 808.55 — no manual state on a movement',
    $count('bank_movement') === 6
    && $m1['direction'] === 'debit' && $m1['amount'] === '250.00' && $m1['currency'] === 'CHF' && $m1['booking_date'] === '2026-01-05' && $m1['counterparty_name'] === 'Muster Lieferant AG'
    && $m1['counterparty_iban'] === PAYEE_IBAN && $m1['reference_type'] === 'QRR' && $m1['reference'] === $qrr && $m1['bank_tx_code'] === 'PMNT/ICDT/VCOM' && $m1['dedup_key'] === 'A-0001'
    && (int) $m1['position'] === 1 && (int) $m1['tx_count'] === 1 && $m1['original_amount'] === null && $m1['rate_source'] === null && $m1['balance'] === '750.00'
    && $m2['amount'] === '94.35' && $m2['original_amount'] === '100.00' && $m2['original_currency'] === 'EUR' && (float) $m2['exchange_rate'] === 0.9435 && $m2['rate_source'] === 'structured'
    && $m2['booking_date'] === '2026-01-08' && $m2['value_date'] === '2026-01-06' && str_contains($m2['text'], 'Bezugsort: EXAMPLE SHOP BERLIN') && $m2['balance'] === '655.65'
    && $m4['direction'] === 'credit' && $m4['counterparty_name'] === 'Kunde Beispiel GmbH'
    && str_starts_with($m6['dedup_key'], 'fp:') && strlen($m6['dedup_key']) === 67 && $m6['balance'] === '808.55'
    && !array_key_exists('done', $m1));

$sameFile = $refusal($januaryXml, 'nochmal.xml');
$sameMsg  = $refusal(camt053(['msg' => 'MSG-JAN', 'stmt' => 'STMT-JAN'], array_slice($january, 0, 2)), 'anders.xml');
check('I8 the same file again is refused (duplicate-file, names the first import); another file carrying the same message and statement id too (duplicate-message) — nothing added',
    $sameFile?->reason === BankRefusedException::DUPLICATE_FILE && str_contains($sameFile->getMessage(), 'export-januar.xml')
    && $sameMsg?->reason === BankRefusedException::DUPLICATE_MESSAGE && str_contains($sameMsg->getMessage(), 'export-januar.xml') && $state() === [1, 6, 1]);

$february = [
    ['ref' => 'B-0001', 'date' => '2026-02-03', 'amount' => '8.55', 'info' => 'Karte'],
    ['ref' => 'B-0002', 'date' => '2026-02-10', 'amount' => '200.00', 'credit' => true, 'info' => 'Gutschrift'],
    ['ref' => 'B-0003', 'date' => '2026-02-20', 'amount' => '100.00', 'info' => 'Miete'],
];
$feb = $importer($wire())->import(camt053(['msg' => 'MSG-FEB', 'stmt' => 'STMT-FEB', 'opening' => '808.55', 'from' => '2026-01-31', 'to' => '2026-02-28'], $february), 'februar.xml');
check('I9 the next statement starts ON the last stored day (it overlaps it) and joins: February from 31.01. (the stored closing 808.55) — three new, read up to 28.02.2026, balance 900.00',
    $feb[0]->newMovements === 3 && $feb[0]->knownMovements === 0 && $feb[0]->coveredTo->format('Y-m-d') === '2026-02-28' && $feb[0]->balance->toDecimal() === '900.00'
    && $row("SELECT balance FROM bank_movement WHERE entry_ref = 'B-0001'")['balance'] === '800.00' && $state() === [2, 9, 2]);

$march = [
    ['ref' => 'C-0001', 'date' => '2026-03-02', 'amount' => '50.00', 'info' => 'Karte'],
    ['ref' => 'C-0002', 'date' => '2026-03-09', 'amount' => '150.00', 'credit' => true, 'info' => 'Gutschrift'],
];
$gap = $refusal(camt053(['msg' => 'MSG-GAP', 'stmt' => 'STMT-GAP', 'opening' => '900.00', 'from' => '2026-03-05', 'to' => '2026-03-10'], [$march[1]]), 'luecke.xml');
$dayAfter = $refusal(camt053(['msg' => 'MSG-ADJ', 'stmt' => 'STMT-ADJ', 'opening' => '900.00', 'from' => '2026-03-01', 'to' => '2026-03-10'], $march), 'tag-danach.xml');
check('I10 a file that does not OVERLAP the last stored day is REFUSED (no-overlap): read up to 28.02., the file starts on 05.03. — the sentence names both dates and the day to download FROM (the last stored day, «ab dem 28.02.2026 … die Überlappung wird erkannt»); nothing stored, no file (the balance joining by chance does not help)',
    $gap?->reason === BankRefusedException::NO_OVERLAP && str_contains($gap->getMessage(), 'eingelesen bis 28.02.2026') && str_contains($gap->getMessage(), 'beginnt am 05.03.2026')
    && str_contains($gap->getMessage(), 'überlappt den letzten eingelesenen Tag nicht') && str_contains($gap->getMessage(), 'ab dem 28.02.2026 laden — die Überlappung wird erkannt')
    && !str_contains($gap->getMessage(), '01.03.2026') && $state() === [2, 9, 2], (string) $gap?->getMessage());
check('I10b … and so is a file that starts the DAY AFTER (01.03., owner 2026-11-18): no date gap, but the last stored day may have been exported during the day — bookings made on it afterwards that net to zero would pass the balance proof unseen',
    $dayAfter?->reason === BankRefusedException::NO_OVERLAP && str_contains($dayAfter->getMessage(), 'beginnt am 01.03.2026') && str_contains($dayAfter->getMessage(), 'ab dem 28.02.2026') && $state() === [2, 9, 2], (string) $dayAfter?->getMessage());

// An overlapping export 15.01.–10.03.: the January entries from the 20th, all of February, two of March.
$ovl    = $importer($wire())->import(camt053(['msg' => 'MSG-OVL', 'stmt' => 'STMT-OVL', 'opening' => '1113.55', 'from' => '2026-01-15', 'to' => '2026-03-10'], [...array_slice($january, 4), ...$february, ...$march]), 'ueberlappend.xml');
$ovlRow = $row("SELECT * FROM bank_statement WHERE message_id = 'MSG-OVL'");
check('I11 an OVERLAPPING export is compared and cut: five of its seven entries are what the account holds (by AcctSvcrRef — and the one without a reference by its fingerprint), the balance at the covered end agrees (900.00 + the two new = its closing 1000.00) — only the two movements after the covered end are taken; «2 neue Bewegungen, Saldo stimmt (eingelesen bis 10.03.2026 …)»',
    $ovl[0]->newMovements === 2 && $ovl[0]->knownMovements === 5 && $ovl[0]->coveredTo->format('Y-m-d') === '2026-03-10' && $ovl[0]->balance->toDecimal() === '1000.00'
    && str_starts_with($ovl[0]->message(), "2 neue Bewegungen, Saldo stimmt (eingelesen bis 10.03.2026, Saldo 1'000.00)")
    && (int) $ovlRow['movement_count'] === 2 && (int) $ovlRow['duplicate_count'] === 5 && $state() === [3, 11, 3]
    && $row("SELECT balance FROM bank_movement WHERE entry_ref = 'C-0001'")['balance'] === '850.00' && $row("SELECT balance FROM bank_movement WHERE entry_ref = 'C-0002'")['balance'] === '1000.00'
    && (int) $db->fetchOne('SELECT COUNT(DISTINCT dedup_key) FROM bank_movement') === 11, $ovl[0]->message());
check('I12 … and a known movement is left exactly as it was: it still belongs to the statement that delivered it, with its balance',
    $row("SELECT statement_id, balance FROM bank_movement WHERE entry_ref = 'B-0001'") == ['statement_id' => $feb[0]->statement->getId(), 'balance' => '800.00']);

$wrongBalance = $refusal(camt053(['msg' => 'MSG-W1', 'stmt' => 'STMT-W1', 'opening' => '950.00', 'from' => '2026-03-01', 'to' => '2026-03-20'], [...$march, ['ref' => 'N-0001', 'date' => '2026-03-15', 'amount' => '10.00']]), 'falsch.xml');
$missingOne   = $refusal(camt053(['msg' => 'MSG-W2', 'stmt' => 'STMT-W2', 'opening' => '900.00', 'from' => '2026-03-01', 'to' => '2026-03-20'], [$march[0], ['ref' => 'N-0001', 'date' => '2026-03-15', 'amount' => '10.00']]), 'fehlt.xml');
check('I13 an overlap that does NOT agree is REFUSED (chain-mismatch), nothing stored: a file whose balance at the covered end differs (the sentence names the stored balance, what the file would give and what it states), and a file whose overlap lacks a movement the account holds',
    $wrongBalance?->reason === BankRefusedException::CHAIN_MISMATCH && str_contains($wrongBalance->getMessage(), 'eingelesen bis 10.03.2026') && str_contains($wrongBalance->getMessage(), "1'000.00")
    && str_contains($wrongBalance->getMessage(), '990.00') && str_contains($wrongBalance->getMessage(), "1'040.00") && str_contains($wrongBalance->getMessage(), 'Nichts eingelesen')
    && $missingOne?->reason === BankRefusedException::CHAIN_MISMATCH && $state() === [3, 11, 3], (string) $wrongBalance?->getMessage());
$inserted = $refusal(camt053(['msg' => 'MSG-W3', 'stmt' => 'STMT-W3', 'opening' => '808.55', 'from' => '2026-02-01', 'to' => '2026-02-10'], [$february[0], ['ref' => 'Z-0001', 'date' => '2026-02-05', 'amount' => '1.00']]), 'eingeschoben.xml');
$before   = $refusal(camt053(['msg' => 'MSG-W4', 'stmt' => 'STMT-W4', 'opening' => '1020.00', 'from' => '2025-12-01', 'to' => '2026-01-10'], [['ref' => 'Y-0001', 'date' => '2025-12-15', 'amount' => '20.00'], ...array_slice($january, 0, 3)]), 'frueher.xml');
check('I14 nothing is inserted INTO the chain or in front of it: an older file bringing an unknown movement inside the covered period is refused (chain-mismatch); a file bringing a movement from before the account\'s start is refused (before-start, names the start)',
    $inserted?->reason === BankRefusedException::CHAIN_MISMATCH && $before?->reason === BankRefusedException::BEFORE_START && str_contains($before->getMessage(), '01.01.2026') && $state() === [3, 11, 3]);

$known = $importer($wire())->import(camt053(['msg' => 'MSG-OLD', 'stmt' => 'STMT-OLD', 'opening' => '808.55', 'from' => '2026-02-01', 'to' => '2026-02-20'], $february), 'februar-nochmal.xml');
check('I15 a file that ends before the covered end and brings nothing new ADDS NOTHING and says so: no statement, no file — «Nichts Neues … bereits eingelesen bis 10.03.2026»',
    $known[0]->statement === null && $known[0]->newMovements === 0 && $known[0]->knownMovements === 3 && $known[0]->coveredTo->format('Y-m-d') === '2026-03-10'
    && str_contains($known[0]->message(), 'Nichts Neues') && str_contains($known[0]->message(), 'eingelesen bis 10.03.2026') && $state() === [3, 11, 3], $known[0]->message());

// The export of I11 was made DURING 10.03.; the bank booked one more entry on that day afterwards.
$late = $importer($wire())->import(camt053(['msg' => 'MSG-LATE', 'stmt' => 'STMT-LATE', 'opening' => '850.00', 'from' => '2026-03-09', 'to' => '2026-03-10'],
    [$march[1], ['ref' => 'L-0001', 'date' => '2026-03-10', 'amount' => '25.00', 'info' => 'Karte']]), 'nachzuegler.xml');
check('I16 the cut is by the bank\'s reference, not by the date: a movement booked on the covered end day AFTER the earlier export is taken (new reference), the balance proves the join — read up to 10.03. still, balance 975.00',
    $late[0]->newMovements === 1 && $late[0]->knownMovements === 1 && $late[0]->coveredTo->format('Y-m-d') === '2026-03-10' && $late[0]->balance->toDecimal() === '975.00'
    && $row("SELECT balance, booking_date FROM bank_movement WHERE entry_ref = 'L-0001'") === ['balance' => '975.00', 'booking_date' => '2026-03-10'] && $state() === [4, 12, 4]);
$quiet = $importer($wire())->import(camt053(['msg' => 'MSG-QUIET', 'stmt' => 'STMT-QUIET', 'opening' => '975.00', 'from' => '2026-03-10', 'to' => '2026-03-15'], []), 'ruhig.xml');
check('I17 a following statement WITHOUT a movement moves the covered end (its balance must be the stored one): read up to 15.03., «0 neue Bewegungen, Saldo stimmt»',
    $quiet[0]->statement !== null && $quiet[0]->newMovements === 0 && $quiet[0]->coveredTo->format('Y-m-d') === '2026-03-15' && str_starts_with($quiet[0]->message(), '0 neue Bewegungen, Saldo stimmt')
    && $refusal(camt053(['msg' => 'MSG-Q2', 'stmt' => 'STMT-Q2', 'opening' => '970.00', 'from' => '2026-03-15', 'to' => '2026-03-17'], []), 'ruhig-falsch.xml')?->reason === BankRefusedException::CHAIN_MISMATCH
    && $state() === [5, 12, 5]);

// Several statements in ONE file (a bank that sends daily statements in one message).
$day2  = camt053(['raw' => true, 'stmt' => 'STMT-D2', 'opening' => '965.00', 'from' => '2026-03-17', 'to' => '2026-03-17'], [['ref' => 'G-0002', 'date' => '2026-03-17', 'amount' => '40.00']]);
$daily = $importer($wire())->import(camt053(['msg' => 'MSG-DAY', 'stmt' => 'STMT-D1', 'opening' => '975.00', 'from' => '2026-03-15', 'to' => '2026-03-16', 'more' => [$day2]],
    [['ref' => 'G-0001', 'date' => '2026-03-16', 'amount' => '10.00']]), 'tage.xml');
$dailyLate = $refusal(camt053(['msg' => 'MSG-DAY-L', 'stmt' => 'STMT-DL1', 'opening' => '925.00', 'from' => '2026-03-18', 'to' => '2026-03-18',
    'more' => [camt053(['raw' => true, 'stmt' => 'STMT-DL2', 'opening' => '924.00', 'from' => '2026-03-19', 'to' => '2026-03-19'], [])]], [['ref' => 'G-0003', 'date' => '2026-03-18', 'amount' => '1.00']]), 'tage-spaet.xml');
check('I18 two statements in one file: the FIRST overlaps the last stored day (15.03.), the second starts after the first and FOLLOWS it — the one exception to the overlap, inside a file the order is the bank\'s own (a bank that sends one statement per booking day never overlaps itself); both stored in one unit of work, ONE archived file named by both; read up to 17.03., balance 925.00. A file whose FIRST statement does not overlap is refused whole (no-overlap)',
    $dailyLate?->reason === BankRefusedException::NO_OVERLAP && count($daily) === 2 && $daily[0]->statement->getArchivePath() === $daily[1]->statement->getArchivePath() && str_contains($daily[0]->statement->getArchivePath(), '/2026/20260317-haus-')
    && $daily[1]->coveredTo->format('Y-m-d') === '2026-03-17' && $daily[1]->balance->toDecimal() === '925.00' && $state() === [7, 14, 6]);

// A unit of work that fails after the file was written: the second statement repeats the first one's ids.
$twice  = camt053(['raw' => true, 'stmt' => 'STMT-X', 'opening' => '924.00', 'from' => '2026-03-19', 'to' => '2026-03-19'], [['ref' => 'H-0002', 'date' => '2026-03-19', 'amount' => '1.00']]);
$failed = $refusal(camt053(['msg' => 'MSG-X', 'stmt' => 'STMT-X', 'opening' => '925.00', 'from' => '2026-03-17', 'to' => '2026-03-18', 'more' => [$twice]],
    [['ref' => 'H-0001', 'date' => '2026-03-18', 'amount' => '1.00']]), 'doppelt.xml');
$second = camt053(['raw' => true, 'stmt' => 'STMT-V2', 'opening' => '900.00', 'from' => '2026-03-25', 'to' => '2026-03-25'], []);
$partly = $refusal(camt053(['msg' => 'MSG-V', 'stmt' => 'STMT-V1', 'opening' => '925.00', 'from' => '2026-03-17', 'to' => '2026-03-18', 'more' => [$second]],
    [['ref' => 'H-0001', 'date' => '2026-03-18', 'amount' => '1.00']]), 'teilweise.xml');
check('I19 a file is stored WHOLE or not at all: a unit of work that fails in the second statement (the same ids twice; a following statement whose balance does not join the first — the exception inside a file spares the overlap, never the balance proof) leaves no statement, no movement — and the archived file is removed again',
    $failed?->reason === BankRefusedException::DUPLICATE_MESSAGE && $partly?->reason === BankRefusedException::CHAIN_MISMATCH && $state() === [7, 14, 6]
    && $count('bank_movement', "entry_ref IN ('H-0001', 'H-0002')") === 0, (string) $failed?->getMessage());

$chainOk = true;
$running = chf('1000.00');
foreach ($db->fetchAllAssociative('SELECT direction, amount, balance FROM bank_movement ORDER BY id') as $r) {
    $running = $r['direction'] === 'credit' ? $running->add(chf($r['amount'])) : $running->subtract(chf($r['amount']));
    $chainOk = $chainOk && $running->toDecimal() === $r['balance'];
}
$em = $wire();
$at = $importer($em)->coveredTo((new BankAccountService($em))->find('haus'));
check('I20 the chain is seamless: from the first opening balance every movement\'s stored balance is the running sum, and the last one is «eingelesen bis 17.03.2026 · Saldo 925.00» (coveredTo()); statementsOf() lists the newest first',
    $chainOk && $running->toDecimal() === '925.00' && $at?->getToDate()->format('Y-m-d') === '2026-03-17' && $at->getClosingBalance()->toDecimal() === '925.00'
    && $importer($em)->statementsOf((new BankAccountService($em))->find('haus'))[0]->getStatementId() === 'STMT-D2');

$em        = $wire();
$ibanFixed = caught(fn() => (new BankAccountService($em))->update((new BankAccountService($em))->find('haus'), ['iban' => OTHER_IBAN]), InvalidBankAccountException::class);
check('I21 once statements are imported the IBAN of the bank account cannot change (a new IBAN is a new bank account)',
    $ibanFixed instanceof InvalidBankAccountException && isset($ibanFixed->validator->getFieldErrors()['iban']) && $row('SELECT iban FROM bank_account')['iban'] === OWN_IBAN);
$em = $wire();
(new BankAccountService($em))->setActive((new BankAccountService($em))->find('haus'), false);
$inactive = $refusal(camt053(['msg' => 'MSG-INA', 'stmt' => 'STMT-INA', 'opening' => '925.00', 'from' => '2026-03-18', 'to' => '2026-03-18'], []), 'inaktiv.xml');
check('I22 an INACTIVE bank account takes no statement (account-unknown)', $inactive?->reason === BankRefusedException::ACCOUNT_UNKNOWN && $state() === [7, 14, 6]);

// ── SM. the backend screens through host doubles (boards approved 2026-11-17) ─────────────
// The traits run as the backend mounts them: a host double provides html() / fetch() / json() /
// redirect() / em() and the layout, message and help objects; DI gets a Request and a CSRF double.
// The templates render through the REAL TemplateRenderer.

echo "SM. Backend screens — «Bankkonten», the upload, the list «Bewegungen» (database)\n";
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

$useRequest = function (array $get, ?array $post = null, bool $fetch = false, array $json = [], ?\Z77\Shared\ValueObjects\UploadedFile $file = null, string $fileField = 'file') use ($wire): \Z77\Persistence\Resolver\UnifiedEntityManager {
    $em = $wire();
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $fetch, $json, $file, $fileField) {
        public function __construct(private array $get, private ?array $post, private bool $fetch, private array $json, private ?\Z77\Shared\ValueObjects\UploadedFile $file, private string $fileField) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function isPost(): bool { return $this->post !== null; }
        public function getPostParameters(): array { return $this->post ?? []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
        public function getJsonBody(): array { return $this->json; }
        public function getUploadedFile(string $field): ?\Z77\Shared\ValueObjects\UploadedFile { return $field === $this->fileField ? $this->file : null; }
    }, true);
    \Z77\Core\DI::getInstance()->set('CsrfService', fn() => new class {
        public function getToken(): string { return 'tok-session'; }
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
    return $em;
};
/** The members every host double shares (the BackendAbstractController surface the traits use). */
trait BankScreenHostDouble
{
    public array $context = [];
    public object $layoutManager;
    public object $messageService;
    public object $help;
    public ?string $redirectedTo = null;
    public array $sent = [];
    public function __construct()
    {
        $this->help          = new \Z77\Core\Services\HelpService();
        $this->layoutManager = new class {
            public array $sections = [];
            public function removeSection(string $s): void { unset($this->sections[$s]); }
            public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
        };
        $this->messageService = new class {
            public array $flashes = [];
            public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
        };
    }
    protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
    protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
    protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
    protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
    protected function json(array $data, int $status = 200): \Z77\Core\Http\Response\JsonResponse { return new \Z77\Core\Http\Response\JsonResponse($data, $status); }
    protected function bytes(string $content, string $filename, string $mimeType, bool $inline = true): \Z77\Core\Http\Response\BytesResponse { $this->sent = [$content, $filename, $mimeType, $inline]; return new \Z77\Core\Http\Response\BytesResponse($content, $filename, $mimeType, $inline); }
}
$accountHost = fn() => new class {
    use BankScreenHostDouble;
    use \Z77\Module\Financial\Ui\BankAccountControllerTrait { listAction as public; addAction as public; editAction as public; toggleActiveAction as public; }
};
$movementHost = fn() => new class {
    use BankScreenHostDouble;
    use \Z77\Module\Financial\Ui\BankMovementControllerTrait { listAction as public; uploadAction as public; editAction as public; confirmPrivateAction as public; uploadInfoAction as public; voucherAction as public; statementAction as public; reconcileAction as public; }
    private function bankStatements(): BankStatementService { return new BankStatementService($this->em(), 'tester'); }
    private function bankReconciliation(): \Z77\Module\Financial\Bank\BankReconciliation { return new \Z77\Module\Financial\Bank\BankReconciliation($this->em(), 'tester'); }
    private function bankMovementStates(): \Z77\Module\Financial\Bank\BankMovementStates { return new \Z77\Module\Financial\Bank\BankMovementStates($this->em(), 'tester'); }
    private function bankPostings(): \Z77\Module\Financial\Bank\BankPostingService { return new \Z77\Module\Financial\Bank\BankPostingService($this->em(), 'tester'); }
};
$render   = static fn(string $path, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial($path, $context + ['csrfToken' => 'tok-session']);
$envelope = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => (fn() => $this->build())->call($r);
$commands = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => array_column($envelope($r)['commands'] ?? [], 'action');
$jsonOf   = static fn($r): array => $r instanceof \Z77\Core\Http\Response\JsonResponse ? (fn() => $this->data)->call($r) : [];
$upload   = static function (string $name, string $bytes): \Z77\Shared\ValueObjects\UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'z77bankup');
    file_put_contents($path, $bytes);
    return new \Z77\Shared\ValueObjects\UploadedFile($name, $path, strlen($bytes), 'text/xml');
};
$region = \Z77\Module\Financial\Ui\BankAccountListing::definition()->region();

$em = $wire();
(new BankAccountService($em))->setActive((new BankAccountService($em))->find('haus'), true);
$hausId = (int) (new BankAccountService($wire()))->find('haus')->getId();

// ── «Bankkonten» ──
$useRequest([]);
$host = $accountHost();
$host->listAction();
$accList = $render('Backend/BankAccountController/listAction', $host->context);
check('SM1 «Bankkonten»: the standard list — Bezeichnung, the IBAN grouped in fours, Konto with its name, Kontoauszug «bis 17.03.2026», the «Aktiv» switch; «+ Bankkonto» in the action cell; help in the toolbar',
    count($host->context['rows']) === 1 && str_contains($accList, 'data-entity="bankAccount:' . $hausId . '"') && str_contains($accList, 'CH93 0076 2011 6238 5295 7')
    && str_contains($accList, '>1022</span>') && str_contains($accList, 'Bank Beispiel') && str_contains($accList, 'bis 17.03.2026') && str_contains($accList, 'toggle-active?id=' . $hausId)
    && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/BankAccountController/act'] && $host->help->has()
    && str_contains($render('Backend/BankAccountController/act', $host->context), '/backend/finance/bank-account/add'));
$useRequest([], ['code' => 'spar', 'label' => 'Sparkonto', 'iban' => 'CH56 0483 5012 3456 7800 9', 'ledger_account' => '1020', '_origin' => 'region:' . $region], true);
$host  = $accountHost();
$added = $host->addAction();
$spar  = (new BankAccountService($wire()))->find('spar');
check('SM2 «+ Bankkonto» saves through the service: created with the base currency, «Kontoauszug einlesen» OFF (the switch was not ticked), active; the window closes and the list reloads',
    $commands($added) === ['close-window', 'refresh-region'] && $spar !== null && $spar->getIban() === OTHER_IBAN && $spar->getCurrency() === 'CHF' && !$spar->isStatementImport() && $spar->isActive());
$useRequest([], ['code' => 'drei', 'label' => 'Drittkonto', 'iban' => 'CH00 0000 0000 0000 0000 0', 'ledger_account' => '10', 'statement_import' => '1'], true);
$host = $accountHost();
$host->addAction();
$addHtml = $render('Backend/BankAccountController/edit', $host->context);
check('SM3 a refused «Anlegen»: the window again with the message under «IBAN» and under «Konto», what was typed kept (the switch too) — nothing saved',
    $host->context['validator']->hasFieldError('iban') && $host->context['validator']->hasFieldError('ledger_account') && str_contains($addHtml, 'data-window="bank-account-edit"')
    && str_contains($addHtml, 'value="Drittkonto"') && str_contains($addHtml, 'name="statement_import" value="1" checked') && str_contains($addHtml, 'data-z77-field-error')
    && (new BankAccountService($wire()))->find('drei') === null);
$useRequest(['id' => (string) $spar->getId()], ['entity_csrf' => 'tok-bankAccount-' . $spar->getId(), 'code' => 'hacked', 'label' => 'Sparkonto Plus', 'iban' => 'CH56 0483 5012 3456 7800 9', 'ledger_account' => '1020', 'statement_import' => '1', '_origin' => 'region:' . $region], true);
$host   = $accountHost();
$edited = $host->editAction();
$spar   = (new BankAccountService($wire()))->find('spar');
$useRequest(['id' => (string) $spar->getId()], ['entity_csrf' => 'wrong', 'label' => 'X'], true);
$host    = $accountHost();
$foreign = $host->editAction();
check('SM4 the window saves an edit: label and the switch written, the Kürzel NOT (fixed); a foreign entity token is refused',
    $commands($edited) === ['close-window', 'refresh-region'] && $spar !== null && $spar->getLabel() === 'Sparkonto Plus' && $spar->isStatementImport()
    && (new BankAccountService($wire()))->find('hacked') === null && $envelope($foreign)['status'] === 'error' && (new BankAccountService($wire()))->find('spar')->getLabel() === 'Sparkonto Plus');
$useRequest(['id' => (string) $hausId]);
$host = $accountHost();
$host->editAction();
$hausHtml = $render('Backend/BankAccountController/edit', $host->context);
$useRequest(['id' => (string) $hausId], ['entity_csrf' => 'tok-bankAccount-' . $hausId, 'label' => 'Beispielbank', 'iban' => PAYEE_IBAN, 'ledger_account' => '1022', 'statement_import' => '1'], true);
$host = $accountHost();
$host->editAction();
check('SM5 a bank account with statements: the IBAN field is read-only, and a posted other IBAN is refused under «IBAN»; the Kürzel is read-only; the currency is shown, not posted',
    $host->context['validator']->hasFieldError('iban') && $row('SELECT iban FROM bank_account WHERE id = ' . $hausId)['iban'] === OWN_IBAN
    && preg_match('/id="bank-account-iban"[^>]* readonly/', $hausHtml) === 1 && preg_match('/id="bank-account-code"[^>]* readonly/', $hausHtml) === 1
    && preg_match('/id="bank-account-currency" value="CHF" readonly/', $hausHtml) === 1 && !str_contains($hausHtml, 'name="currency"'));
$stubHint = new class implements \Z77\Module\Financial\Bank\BankAccountHintInterface {
    public function hintsFor(BankAccount $account, \Z77\Persistence\Resolver\UnifiedEntityManager $em): array { return ['Zahlungsziel «QR» führt diese IBAN mit Konto 1020.']; }
};
$em       = $wire();
$hints    = (new \Z77\Module\Financial\Bank\BankAccountHints([$stubHint]))->hintsFor((new BankAccountService($em))->find('haus'), $em);
$hintHtml = $render('Backend/BankAccountController/edit', ['hints' => $hints] + $host->context);
check('SM6 a contradiction another module reports (the registry `bankAccountHints`) is ONE short line in the bank account window; without one the window carries no hint — and no explanatory text at all (the help has it)',
    $hints === ['Zahlungsziel «QR» führt diese IBAN mit Konto 1020.'] && substr_count($hintHtml, 'data-bank-account-hint') === 1 && str_contains($hintHtml, 'Zahlungsziel «QR» führt diese IBAN mit Konto 1020.')
    && !str_contains($hausHtml, 'data-bank-account-hint') && !str_contains($hausHtml, 'be-form__hint') && $host->context['hints'] === []
    && \Z77\Module\Financial\Bank\BankAccountHints::fromModules(\Z77\Core\DI::getModuleManager())->hintsFor((new BankAccountService($em))->find('haus'), $em) === []);
// debtor's source of that hint, against a payment-target file: same IBAN (as the QR-IBAN field or the plain one), another account
@mkdir($base . '/data/framework/debtor', 0777, true);
file_put_contents($base . '/data/framework/debtor/payment_targets.json', json_encode([
    ['id' => 1, 'code' => 'qr', 'label' => 'QR-Rechnung', 'iban' => 'CH93 0076 2011 6238 5295 7', 'qr_iban' => '', 'account_number' => '1020', 'active' => true],
    ['id' => 2, 'code' => 'gleich', 'label' => 'Gleiches Konto', 'iban' => OWN_IBAN, 'qr_iban' => '', 'account_number' => '1022', 'active' => true],
    ['id' => 3, 'code' => 'alt', 'label' => 'Alt', 'iban' => OWN_IBAN, 'qr_iban' => '', 'account_number' => '1000', 'active' => false],
    ['id' => 4, 'code' => 'fremd', 'label' => 'Andere Bank', 'iban' => PAYEE_IBAN, 'qr_iban' => '', 'account_number' => '1020', 'active' => true],
]));
$em          = $wire();
$debtorHints = (new \Z77\Module\Debtor\Services\PaymentTargetBankAccountHint())->hintsFor((new BankAccountService($em))->find('haus'), $em);
check('SM6b the hint source of debtor: an ACTIVE payment target with the same IBAN and another ledger account gives one short line; the same account, an inactive target and another IBAN give none',
    $debtorHints === ['Zahlungsziel «QR-Rechnung» führt diese IBAN mit Konto 1020.'], json_encode($debtorHints, JSON_UNESCAPED_UNICODE));
$useRequest(['id' => (string) $spar->getId()], null, true, ['value' => false]);
$host = $accountHost();
$tg   = $host->toggleActiveAction();
check('SM7 the «Aktiv» switch deactivates and answers with its row in place (FETCH-ROW-001)',
    !(new BankAccountService($wire()))->find('spar')->isActive() && $commands($tg) === ['replace-html']
    && str_contains($envelope($tg)['commands'][0]['html'] ?? '', 'data-entity="bankAccount:' . $spar->getId() . '"') && str_contains($envelope($tg)['commands'][0]['html'] ?? '', '· inaktiv'));

// ── the list «Bewegungen» ──
$useRequest([]);
$host = $movementHost();
$host->listAction();
$mvPage = $render('Backend/BankMovementController/listAction', $host->context);
check('SM8 «Finanzen › Bankkonto»: ONE list — the header «eingelesen bis 17.03.2026 · Saldo 925.00», 14 movements newest first, every one «zu prüfen» (nothing is private by silence — ADR-061 decision 4), no tabs in the list (the views are the toolbar\'s — L9); the upload in the action cell (camt.053 Upload, XML or ZIP), the work area takes dropped files, the progress runs in a window (UPLOAD-DIALOG-001); one account → no selection at the top of the rail; help in the toolbar',
    $host->context['account']?->getCode() === 'haus' && count($host->context['movements']) === 14 && $host->context['movements'][0]->getEntryRef() === 'G-0002'
    && str_contains($mvPage, 'eingelesen bis 17.03.2026 · Saldo 925.00') && str_contains($mvPage, 'data-bank-movements') && !str_contains($mvPage, 'data-upload-progress')
    && !str_contains($mvPage, 'be-viewtabs') && !str_contains($mvPage, 'Erledigt') && substr_count($mvPage, 'data-movement-state="review"') === 14 && array_values(array_unique($host->context['states'])) === ['review']
    && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/BankMovementController/act'] && !isset($host->layoutManager->sections['railSelect']) && $host->help->has()
    && $host->context['uploadPolicy']->label === 'camt.053 Upload' && $host->context['uploadPolicy']->accept === ['.xml', '.zip']);
check('SM9 … a row: the booking date, the counterparty with the bank text and the QR reference behind it, the signed amount, the original amount of the EUR purchase, the balance after the movement',
    str_contains($mvPage, '05.01.2026') && str_contains($mvPage, 'Muster Lieferant AG') && str_contains($mvPage, 'Ref. ' . $qrr) && str_contains($mvPage, '−250.00')
    && str_contains($mvPage, '100.00 EUR') && str_contains($mvPage, 'Bezugsort: EXAMPLE SHOP BERLIN') && str_contains($mvPage, '>750.00</span>') && str_contains($mvPage, '>500.00</label>'));
$found = function (array $get) use ($useRequest, $movementHost): array {
    $useRequest($get);
    $host = $movementHost();
    $host->listAction();
    return array_map(static fn(BankMovement $m) => (string) ($m->getEntryRef() ?? 'fp'), $host->context['movements']);
};
check('SM10 search in the database: the amount finds the booked amount AND the original amount (100.00 → the EUR purchase and the 100.00 debit; 250 → the QR payment), the text finds the merchant and the reference, the date a month; sort by amount; an unreadable amount finds nothing new (the field is marked invalid)',
    $found(['f_amount' => '100.00']) === ['B-0003', 'A-0002'] && $found(['f_amount' => '250']) === ['A-0001'] && $found(['f_text' => 'berlin']) === ['A-0002'] && $found(['f_text' => $qrr]) === ['A-0001']
    && $found(['f_date' => '02.2026']) === ['B-0003', 'B-0002', 'B-0001'] && $found(['sort' => 'amount'])[0] === 'A-0004' && $found(['sort' => 'amount', 'dir' => 'asc'])[0] === 'fp'
    && count($found(['f_amount' => 'abc'])) === 14, json_encode([$found(['f_amount' => '100.00']), $found(['f_date' => '02.2026']), $found(['sort' => 'amount'])]));

// ── the upload ──
$next = camt053(['msg' => 'MSG-UP1', 'stmt' => 'STMT-UP1', 'opening' => '925.00', 'from' => '2026-03-17', 'to' => '2026-03-18'], [['ref' => 'U-0001', 'date' => '2026-03-18', 'amount' => '5.00', 'info' => '&lt;b&gt;Fett&lt;/b&gt; &amp; Co']]);
$useRequest([], [], true, [], $upload('auszug-18.xml', $next));
$host = $movementHost();
$up   = $jsonOf($host->uploadAction());
check('SM11 the upload (fetch mode, one file per request) answers with ONE short sentence — «1 neue Bewegung, Saldo stimmt (eingelesen bis 18.03.2026 …)» — and replaces the list in place; bank text is escaped, never HTML',
    ($up['status'] ?? '') === 'ok' && ($up['name'] ?? '') === 'auszug-18.xml' && str_starts_with($up['message'] ?? '', '1 neue Bewegung, Saldo stimmt (eingelesen bis 18.03.2026, Saldo 920.00)')
    && ($up['commands'][0]['action'] ?? '') === 'replace-html' && ($up['commands'][0]['target'] ?? '') === '[data-bank-movements]'
    && str_contains($up['commands'][0]['html'] ?? '', 'eingelesen bis 18.03.2026 · Saldo 920.00') && str_contains($up['commands'][0]['html'] ?? '', '&lt;b&gt;Fett&lt;/b&gt; &amp; Co')
    && !str_contains($up['commands'][0]['html'] ?? '', '<b>Fett</b>') && $count('bank_movement') === 15, json_encode($up['message'] ?? null, JSON_UNESCAPED_UNICODE));
$late = camt053(['msg' => 'MSG-UP2', 'stmt' => 'STMT-UP2', 'opening' => '920.00', 'from' => '2026-03-25', 'to' => '2026-03-31'], [['ref' => 'U-0002', 'date' => '2026-03-26', 'amount' => '1.00']]);
$useRequest([], [], true, [], $upload('auszug-spaet.xml', $late));
$host = $movementHost();
$gapA = $jsonOf($host->uploadAction());
$useRequest([], [], true, [], $upload('notiz.txt', 'x'));
$host = $movementHost();
$txt  = $jsonOf($host->uploadAction());
check('SM12 a refusal is the answer AT the file: the no-overlap sentence with both dates and the day to download from, no command, nothing stored; a file that is neither XML nor ZIP is refused by the upload policy',
    ($gapA['status'] ?? '') === 'error' && str_contains($gapA['message'] ?? '', 'eingelesen bis 18.03.2026, die Datei beginnt am 25.03.2026') && str_contains($gapA['message'] ?? '', 'ab dem 18.03.2026 laden') && !isset($gapA['commands'])
    && ($txt['status'] ?? '') === 'error' && str_contains($txt['message'] ?? '', 'nicht erlaubt') && $count('bank_movement') === 15);
$zipOk  = camt053(['msg' => 'MSG-UP3', 'stmt' => 'STMT-UP3', 'opening' => '920.00', 'from' => '2026-03-18', 'to' => '2026-03-20'], [['ref' => 'U-0003', 'date' => '2026-03-19', 'amount' => '20.00']]);
$zipped = $zipOf(['a-19.xml' => $zipOk, 'b-spaet.xml' => $late, 'liesmich.txt' => 'x',
    'c-fremd.xml' => camt053(['msg' => 'MSG-F1', 'stmt' => 'STMT-F1', 'iban' => 'CH7100762000000001234'], []), 'd-fremd.xml' => camt053(['msg' => 'MSG-F2', 'stmt' => 'STMT-F2', 'iban' => 'CH4431999123000889012'], [])]);
$useRequest([], [], true, [], new \Z77\Shared\ValueObjects\UploadedFile('bank.zip', (function () use ($zipped) { $p = tempnam(sys_get_temp_dir(), 'z77bankzip'); file_put_contents($p, $zipped); return $p; })(), strlen($zipped), 'application/zip'));
$host = $movementHost();
$zipA = $jsonOf($host->uploadAction());
$useRequest([], [], true, [], $upload('fremd.xml', camt053(['msg' => 'MSG-F3', 'stmt' => 'STMT-F3', 'iban' => 'CH7100762000000001234'], [])));
$host     = $movementHost();
$skipOnly = $jsonOf($host->uploadAction());
check('SM13 a ZIP: every XML inside read on its own — ONE answer naming each file: what was read («a-19.xml: 1 neue Bewegung …») and what was refused («b-spaet.xml: … überlappt den letzten eingelesenen Tag nicht»); the list is replaced. Statements of accounts that are NO bank account here (a bank\'s ZIP carries every account of the contract) are SKIPPED and summed up in ONE neutral line at the END — no error sentence per account',
    ($zipA['status'] ?? '') === 'error' && str_contains($zipA['message'] ?? '', 'a-19.xml: 1 neue Bewegung, Saldo stimmt (eingelesen bis 20.03.2026') && str_contains($zipA['message'] ?? '', 'b-spaet.xml: ')
    && str_contains($zipA['message'] ?? '', 'überlappt den letzten eingelesenen Tag nicht') && ($zipA['commands'][0]['action'] ?? '') === 'replace-html' && $count('bank_movement') === 16
    && str_ends_with($zipA['message'] ?? '', '2 Konten übersprungen (nicht als Bankkonto angelegt): CH71 0076 2000 0000 0123 4, CH44 3199 9123 0008 8901 2') && !str_contains($zipA['message'] ?? '', 'kein aktives Bankkonto')
    && ($skipOnly['status'] ?? '') === 'ok' && ($skipOnly['message'] ?? '') === '1 Konto übersprungen (nicht als Bankkonto angelegt): CH71 0076 2000 0000 0123 4' && !isset($skipOnly['commands']), json_encode($zipA['message'] ?? null, JSON_UNESCAPED_UNICODE));
$useRequest([], null, true);
$host = $movementHost();
$host->uploadInfoAction();
$infoWindow = $render('Backend/BankMovementController/uploadInfo', $host->context);
$useRequest([]);
$host = $movementHost();
$host->listAction();
check('SM13b a CLICK on «camt.053 Upload» opens a window BEFORE the file dialog (owner 2026-11-18): the upload policy names the window `upload-info` as `before` (the upload partial writes it as `data-window-open` on the button — tests/upload-component.php; dropped files skip it), the window lists every bank account that takes statements — Bankkonto, IBAN, «Eingelesen bis» and «Laden ab» = the last stored day (20.03.2026, the overlap) — and «Dateien wählen …» names the file field of the cell (`data-upload-pick`); labels only, no form',
    $host->context['uploadPolicy']->before === '/backend/finance/bank-movement/upload-info'
    && str_contains($infoWindow, 'data-window="bank-upload-info"') && str_contains($infoWindow, 'data-upload-account="haus"') && substr_count($infoWindow, 'data-upload-account=') === 1
    && preg_match('/data-upload-from>20\.03\.2026</', $infoWindow) === 1 && str_contains($infoWindow, 'Laden ab') && str_contains($infoWindow, 'Eingelesen bis')
    && str_contains($infoWindow, 'data-upload-pick="' . $host->context['uploadPolicy']->withShape('cell')->inputId() . '"')
    && !str_contains($infoWindow, '<form'), $infoWindow);
$page = camt053(['msg' => 'MSG-UP4', 'stmt' => 'STMT-UP4', 'opening' => '900.00', 'from' => '2026-03-20', 'to' => '2026-03-22'], []);
$useRequest([], [], false, [], $upload('auszug-21.xml', $page));
$host = $movementHost();
$host->uploadAction();
check('SM14 without JavaScript (page mode) the same upload answers with a flash and the list of that bank account',
    $host->redirectedTo === '/backend/finance/bank-movement/list?account=haus' && ($host->messageService->flashes[0][0] ?? '') === 'success'
    && str_contains($host->messageService->flashes[0][1] ?? '', 'auszug-21.xml: 0 neue Bewegungen, Saldo stimmt (eingelesen bis 22.03.2026'));

// two accounts that take statements → the selection at the top of the rail; none → one sentence and no upload
$em = $wire();
(new BankAccountService($em))->setActive((new BankAccountService($em))->find('spar'), true);
$useRequest(['account' => 'spar']);
$host = $movementHost();
$host->listAction();
$sparPage = $render('Backend/BankMovementController/listAction', $host->context);
$select   = $render('Backend/partials/bankAccountSelect', $host->context);
check('SM15 two bank accounts take statements: the selection stands at the top of the rail (links with ?account=…, the chosen one marked); the other account is empty — «Noch kein Kontoauszug eingelesen.»',
    ($host->layoutManager->sections['railSelect'] ?? []) === ['Backend/partials/bankAccountSelect'] && $host->context['account']->getCode() === 'spar' && $host->context['movements'] === []
    && str_contains($sparPage, 'Noch kein Kontoauszug eingelesen.') && str_contains($select, 'list?account=haus') && preg_match('/account=spar"\s+aria-current="true"/', $select) === 1);
$em = $wire();
foreach (['haus', 'spar'] as $code) {
    (new BankAccountService($em))->update((new BankAccountService($em))->find($code), ['statement_import' => false]);
}
$useRequest([]);
$host = $movementHost();
$host->listAction();
$offPage = $render('Backend/BankMovementController/listAction', $host->context);
check('SM16 no bank account takes statements: one sentence with the way to «Bankkonten», the action cell stays EMPTY',
    $host->context['accounts'] === [] && str_contains($offPage, 'Bei keinem Bankkonto ist «Kontoauszug einlesen» eingeschaltet.') && str_contains($offPage, '/backend/finance/bank-account/list')
    && !isset($host->layoutManager->sections['hc1']) && !str_contains($offPage, 'data-upload-area'));

// ── L. the link (ADR-061 decision 9, stage C1) ──
echo "L. The link — a movement and what it pays (ADR-061 stage C1, database)\n";
// From here on every account is run «gemischt geführt» — only a mixed account knows «privat»,
// the views «Geschäftlich» · «Privat» and the column «Stand» (owner 2026-11-19, checked in C36).
$db->executeStatement('UPDATE bank_account SET mixed_use = 1');

$linkMigration = (string) @file_get_contents($root . '/packages/module-financial/res/migrations/Version20261117100000.php');
check('L1 its own migration: bank_link with an AMOUNT, the owner as a key, one link per movement and one movement per owner as unique indexes — and a down() that drops it',
    str_contains($linkMigration, 'CREATE TABLE bank_link ') && str_contains($linkMigration, 'amount NUMERIC(15, 2) NOT NULL') && str_contains($linkMigration, 'owner_key VARCHAR(60) NOT NULL')
    && str_contains($linkMigration, 'UNIQUE INDEX uniq_bank_link_movement (movement_id)') && str_contains($linkMigration, 'UNIQUE INDEX uniq_bank_link_owner (owner_key)') && str_contains($linkMigration, 'DROP TABLE bank_link'));
$linkSrc = (string) file_get_contents($root . '/packages/module-financial/src/Bank/BankLinkService.php') . (string) file_get_contents($root . '/packages/module-financial/src/Entities/BankLink.php')
    . (string) file_get_contents($root . '/packages/module-financial/src/Repositories/BankLinkRepository.php');
check('L2 module-agnostic (Rule 9): the link names no consumer — no creditor, debtor or receipt class or table; the owner is a key `<type>:<id>`',
    preg_match('/Module\\\\(Creditor|Debtor|Receipt)|creditor_|debtor_|receipt_/', $linkSrc) === 0
    && \Z77\Module\Financial\Entities\BankLink::isOwnerKey('creditor', 'creditor:17') && !\Z77\Module\Financial\Entities\BankLink::isOwnerKey('creditor', 'debtor:17')
    && !\Z77\Module\Financial\Entities\BankLink::isOwnerKey('creditor', 'creditor:') && !\Z77\Module\Financial\Entities\BankLink::isOwnerKey('Creditor', 'Creditor:1'));

$linksOf = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em) => new \Z77\Module\Financial\Bank\BankLinkService($em, 'tester');
$refsOf  = static fn(array $movements): array => array_map(static fn(BankMovement $m) => (string) ($m->getEntryRef() ?? 'fp'), $movements);
$mvId    = static fn(string $ref): int => (int) $db->fetchOne('SELECT id FROM bank_movement WHERE entry_ref = ?', [$ref]);
$inUnit  = static function (callable $work) use ($wire) {
    $em = $wire();
    return $em->getTransaction(\Z77\Module\Financial\Entities\BankLink::class)->run(static fn() => $work($em));
};
$linkRefused = static function (callable $work) use ($inUnit): ?BankRefusedException {
    return caught(static fn() => $inUnit($work), BankRefusedException::class);
};

$em = $wire();
check('L3 no bank account reads statements (the switch is off): nothing is free for an owner',
    !$linksOf($em)->anyAccountReads() && $linksOf($em)->freeMovements(BankMovement::DEBIT, references: [$qrr]) === [] && $linksOf($em)->freeMovement($mvId('A-0001')) === null
    && $linksOf($em)->firstFreeBooking(BankMovement::DEBIT) === null);
(new BankAccountService($em))->update((new BankAccountService($em))->find('haus'), ['statement_import' => true]);
$em = $wire();
$l  = $linksOf($em);
check('L4 the free movements: by the QR reference, by the booked amount, by the instructed foreign amount + currency, within a booking-date window; a credit only when credits are asked; the oldest free booking and the free references',
    $l->anyAccountReads() && $refsOf($l->freeMovements(BankMovement::DEBIT, references: [$qrr])) === ['A-0001']
    && $refsOf($l->freeMovements(BankMovement::DEBIT, amount: chf('94.35'))) === ['A-0002']
    && $refsOf($l->freeMovements(BankMovement::DEBIT, originalAmount: '100.00', originalCurrency: 'EUR')) === ['A-0002']
    && $l->freeMovements(BankMovement::DEBIT, originalAmount: '100.00', originalCurrency: 'USD') === []
    && $l->freeMovements(BankMovement::DEBIT, amount: chf('94.35'), from: new \DateTimeImmutable('2026-01-09'), to: new \DateTimeImmutable('2026-01-31')) === []
    && $refsOf($l->freeMovements(BankMovement::DEBIT, amount: chf('94.35'), from: new \DateTimeImmutable('2026-01-08'), to: new \DateTimeImmutable('2026-01-08'))) === ['A-0002']
    && $l->freeMovements(BankMovement::DEBIT, amount: chf('500.00')) === [] && $refsOf($l->freeMovements(BankMovement::CREDIT, amount: chf('500.00'))) === ['A-0004']
    && $l->freeMovements(BankMovement::DEBIT, references: []) === []
    && $l->firstFreeBooking(BankMovement::DEBIT)?->format('Y-m-d') === '2026-01-05' && $l->freeReferences(BankMovement::DEBIT) === [$qrr],
    json_encode([$refsOf($l->freeMovements(BankMovement::DEBIT, references: [$qrr])), $l->freeReferences(BankMovement::DEBIT)]));

check('L4b by the bank\'s ENTRY reference (ADR-061 stage C3b, debtor\'s identity): freeMovementsByEntryRefs() answers the free movements of the side asked whose Ntry/AcctSvcrRef is listed — exactly, never by fingerprint; an empty list reads nothing',
    $refsOf($l->freeMovementsByEntryRefs(BankMovement::CREDIT, ['A-0004', 'A-0001', 'X-NONE'])) === ['A-0004']
    && $refsOf($l->freeMovementsByEntryRefs(BankMovement::DEBIT, ['A-0001', 'A-0005'])) === ['A-0001', 'A-0005']
    && $l->freeMovementsByEntryRefs(BankMovement::CREDIT, []) === []);

$outside = caught(fn() => $linksOf($wire())->link($mvId('A-0001'), 'creditor', 'creditor:17', 'Rechnung 4711', 'reference'), \LogicException::class);
check('L5 a link is set only inside the owner\'s unit of work (the movement row lock must hold until commit) — outside: a LogicException, nothing written',
    $outside instanceof \LogicException && $count('bank_link') === 0);
$inUnit(static fn($em) => $linksOf($em)->link($mvId('A-0001'), 'creditor', 'creditor:17', 'Rechnung <b>4711</b> · Muster Lieferant AG', 'reference'));
$linkRow = $row('SELECT * FROM bank_link');
$em = $wire();
$l  = $linksOf($em);
check('L6 linked: the row stores the movement, the owner key and type, the label, the movement\'s FULL amount, the basis, who and when; the movement is no longer free; the reads answer it by owner and by movement',
    $count('bank_link') === 1 && (int) $linkRow['movement_id'] === $mvId('A-0001') && $linkRow['owner_type'] === 'creditor' && $linkRow['owner_key'] === 'creditor:17' && $linkRow['amount'] === '250.00'
    && $linkRow['basis'] === 'reference' && $linkRow['linked_by'] === 'tester' && $linkRow['linked_at'] !== null
    && $l->freeMovements(BankMovement::DEBIT, references: [$qrr]) === [] && $l->freeMovement($mvId('A-0001')) === null && $l->freeMovement($mvId('A-0002')) !== null
    && $l->ofOwner('creditor:17')?->getMovement()->getEntryRef() === 'A-0001' && $l->ofOwner('creditor:18') === null
    && array_keys($l->ofMovements([$mvId('A-0001'), $mvId('A-0002')])) === [$mvId('A-0001')] && $l->linkedOwners(['creditor:17', 'creditor:18']) === ['creditor:17' => true]
    && $l->ofOwners(['creditor:17'])['creditor:17']->getAmount()->toDecimal() === '250.00', json_encode($linkRow));

$taken   = $linkRefused(static fn($em) => $linksOf($em)->link($mvId('A-0001'), 'creditor', 'creditor:18', 'Rechnung 4712', 'manual'));
$owner   = $linkRefused(static fn($em) => $linksOf($em)->link($mvId('A-0003'), 'creditor', 'creditor:17', 'Rechnung 4711', 'manual'));
$partial = $linkRefused(static fn($em) => $linksOf($em)->link($mvId('A-0003'), 'creditor', 'creditor:19', 'Rechnung 4713', 'manual', chf('40.00')));
$gone    = $linkRefused(static fn($em) => $linksOf($em)->link(999999, 'creditor', 'creditor:20', 'Rechnung 4714', 'manual'));
check('L7 the rule of stage C1, each refused with a German sentence and nothing written: a movement is linked ONCE (link-taken, the sentence names what holds it), an owner holds ONE movement (link-owner), a link takes the FULL amount (link-amount), an unknown movement (link-gone)',
    $taken?->reason === BankRefusedException::LINK_TAKEN && str_contains($taken->getMessage(), 'bereits zugeordnet') && str_contains($taken->getMessage(), 'Rechnung <b>4711</b>')
    && $owner?->reason === BankRefusedException::LINK_OWNER && $partial?->reason === BankRefusedException::LINK_AMOUNT && str_contains($partial->getMessage(), '42.10') && str_contains($partial->getMessage(), '40.00')
    && $gone?->reason === BankRefusedException::LINK_GONE && $count('bank_link') === 1,
    json_encode([$taken?->getMessage(), $owner?->getMessage(), $partial?->getMessage(), $gone?->getMessage()], JSON_UNESCAPED_UNICODE));
$badBasis = caught(fn() => $inUnit(static fn($em) => $linksOf($em)->link($mvId('A-0003'), 'creditor', 'creditor:21', 'x', 'guess')), \LogicException::class);
$badKey   = caught(fn() => $inUnit(static fn($em) => $linksOf($em)->link($mvId('A-0003'), 'creditor', 'debtor:21', 'x', 'manual')), \LogicException::class);
check('L8 a basis outside reference / proposal / manual and an owner key that is not of its owner type are programming errors — nothing written',
    $badBasis instanceof \LogicException && $badKey instanceof \LogicException && $count('bank_link') === 1);

// the screen: the column «Zuordnung», the state of a linked movement and the views
$useRequest([]);
$host = $movementHost();
$host->listAction();
$linkPage = $render('Backend/BankMovementController/listAction', $host->context);
$toolbar  = $render('Backend/BankMovementController/toolbar', $host->context);
check('L9 the list «Bewegungen» shows what a movement is linked to in the column «Zuordnung» — the owner\'s label, ESCAPED — and nothing for the others; the linked movement is «geschäftlich» and carries no selection checkbox; the toolbar carries the four views «Alle» · «Zu prüfen (n)» · «Geschäftlich» · «Privat» (stage C2 — «Ohne Zuordnung» of C1 went into the states)',
    str_contains($linkPage, 'Zuordnung') && str_contains($linkPage, 'data-movement-link="creditor:17">Rechnung &lt;b&gt;4711&lt;/b&gt; · Muster Lieferant AG</span>') && !str_contains($linkPage, '<b>4711</b>')
    && substr_count($linkPage, 'data-movement-link=""') === count($host->context['movements']) - 1
    && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/BankMovementController/toolbar']
    && str_contains($toolbar, 'be-viewtabs') && str_contains($toolbar, '>Alle</a>') && str_contains($toolbar, 'list?account=haus&amp;state=review">Zu prüfen (' . (count($host->context['movements']) - 1) . ')</a>')
    && str_contains($toolbar, 'state=business">Geschäftlich</a>') && str_contains($toolbar, 'state=private">Privat</a>') && !str_contains($toolbar, 'Ohne Zuordnung')
    && $host->context['states'][$mvId('A-0001')] === 'business' && substr_count($linkPage, 'name="mv[]"') === count($host->context['movements']) - 1 && substr_count($linkPage, 'data-movement-state="business"') === 1
    && preg_match('~list\?account=haus"\s+aria-current="page">Alle~', $toolbar) === 1, $toolbar);
$total = count($found([]));
$free  = $found(['state' => 'review']);
$useRequest(['state' => 'review']);
$host = $movementHost();
$host->listAction();
$freePage    = $render('Backend/BankMovementController/listAction', $host->context);
$freeToolbar = $render('Backend/BankMovementController/toolbar', $host->context);
check('L10 the view «Zu prüfen» (?state=review): every movement but the linked one, counted in the badge, kept through search and paging links; «Geschäftlich» (?state=business) is the linked one; an unknown value of `state` shows all, and C1\'s `link=free` is gone',
    count($free) === $total - 1 && !in_array('A-0001', $free, true) && $host->context['paging']->total === $total - 1 && str_contains($freePage, 'Bewegungen zu prüfen')
    && str_contains($freePage, 'state=review') && preg_match('~state=review"\s+aria-current="page">Zu prüfen~', $freeToolbar) === 1
    && $found(['state' => 'review', 'f_amount' => '250']) === [] && $found(['state' => 'review', 'f_amount' => '94.35']) === ['A-0002'] && $found(['state' => 'business']) === ['A-0001'] && $found(['state' => 'private']) === []
    && count($found(['state' => 'x'])) === $total && count($found(['link' => 'free'])) === $total,
    json_encode([$total, count($free)]));

$inUnit(static fn($em) => $linksOf($em)->relabel('creditor:17', "Rechnung 4711-A  ·\nMuster Lieferant AG"));
$relabelled = $row('SELECT owner_label FROM bank_link')['owner_label'];
$released   = $inUnit(static fn($em) => $linksOf($em)->release('creditor:17'));
$again      = $inUnit(static fn($em) => $linksOf($em)->release('creditor:17'));
$em = $wire();
check('L11 relabel keeps the owner\'s words current (whitespace flattened); release removes the link — the movement is free again; releasing an owner without a link answers false',
    $relabelled === 'Rechnung 4711-A · Muster Lieferant AG' && $released === true && $again === false && $count('bank_link') === 0
    && $refsOf($linksOf($em)->freeMovements(BankMovement::DEBIT, references: [$qrr])) === ['A-0001'] && $count('bank_movement') > 0);
$inUnit(static fn($em) => $linksOf($em)->link($mvId('A-0002'), 'creditor', 'creditor:30', 'Rechnung 30', 'proposal'));
$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('haus'), ['statement_import' => false]);
check('L12 switching «Kontoauszug einlesen» off works forward only (ADR-061 decision 3): the link stays, nothing more is free',
    $count('bank_link') === 1 && $linksOf($wire())->ofOwner('creditor:30') !== null && $linksOf($wire())->freeMovements(BankMovement::DEBIT, references: [$qrr]) === []);

// ── C. states, learned patterns, «Geschäftlich buchen …», «Buchung zurücknehmen …» (ADR-061 stage C2) ──
echo "C. States, learned patterns, «Geschäftlich buchen …», «Buchung zurücknehmen …» (ADR-061 stage C2, database)\n";
$db->executeStatement('UPDATE bank_account SET mixed_use = 1');   // still every account «gemischt geführt» (see L)

$c2Migration = (string) @file_get_contents($root . '/packages/module-financial/res/migrations/Version20261117170000.php');
$c2Src       = '';
foreach (['Bank/BankMovementStates.php', 'Bank/BankPostingService.php', 'Bank/BankVoucherArchive.php', 'Entities/BankPattern.php', 'Entities/BankMovementMark.php', 'Entities/BankPosting.php'] as $c2File) {
    $c2Src .= (string) file_get_contents($root . '/packages/module-financial/src/' . $c2File);
}
check('C1 its own migration: bank_pattern (one per bank account, direction and key), bank_movement_mark (one per movement), bank_posting, bank_movement.pattern_key with its index — and a down() that drops them; module-agnostic (Rule 9): states, patterns and the direct posting name no consumer and no DMS; the threshold is read from the config, not written in code',
    str_contains($c2Migration, 'CREATE TABLE bank_pattern ') && str_contains($c2Migration, 'UNIQUE INDEX uniq_bank_pattern_key (bank_account_id, direction, match_key)')
    && str_contains($c2Migration, 'CREATE TABLE bank_movement_mark ') && str_contains($c2Migration, 'UNIQUE INDEX uniq_bank_movement_mark_movement (movement_id)')
    && str_contains($c2Migration, 'CREATE TABLE bank_posting ') && str_contains($c2Migration, 'ALTER TABLE bank_movement ADD pattern_key') && str_contains($c2Migration, 'DROP TABLE bank_pattern')
    && str_contains($c2Migration, 'ALTER TABLE bank_movement DROP pattern_key')
    && preg_match('/Module\\\\(Creditor|Debtor|Receipt|Dms)|creditor_|debtor_|receipt_/', $c2Src) === 0
    && \Z77\Module\Financial\Bank\BankPostingService::voucherFromGross()->toDecimal() === '100.00' && !str_contains($bodyOf($c2Src), "'100.00'") && !str_contains($bodyOf($c2Src), '1090') && !str_contains($bodyOf($c2Src), '2850'));

check('C2 the key a pattern is matched by, from the movement\'s own data: the counterparty\'s IBAN; else the merchant after «Bezugsort:» (case and spacing ignored, the date behind the comma left out); else the counterparty\'s name; else the text without its numbers; nothing left → no key',
    BankPattern::keyOf('ch44 3199 9123 0008 8901 2', 'Wer auch immer', 'Text') === 'iban:' . PAYEE_IBAN
    && BankPattern::keyOf('', '', 'Bezugsort: BEISPIEL MARKT LUZERN, Transaktionsdatum 01.04.2026') === 'merchant:beispiel markt luzern'
    && BankPattern::keyOf('', '', "bezugsort:  Beispiel   Markt Luzern") === 'merchant:beispiel markt luzern'
    && BankPattern::keyOf('', ' Garage  Beispiel AG ', 'Zahlung') === 'name:garage beispiel ag'
    && BankPattern::keyOf('', '', 'Kontoführung 04.2026') === 'text:kontoführung' && BankPattern::keyOf('', '', 'Kontoführung 05.2026') === 'text:kontoführung'
    && BankPattern::keyOf('', '', ' 12.50 / 3 ') === '' && strlen(BankPattern::keyOf('', str_repeat('x', 200), '')) === BankPattern::KEY_LENGTH
    && BankPattern::dayNear(5, 6) && BankPattern::dayNear(30, 2) && !BankPattern::dayNear(5, 20));

// The books of the C section: the year 2026, the mandator (liable to VAT, the KMU tax accounts), the mixed account «spar» reads statements.
$em = $wire();
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new \Z77\Module\Financial\Entities\FiscalYear('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710']);   // a liable mandator needs its UID (2026-11-24)
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('spar'), ['statement_import' => true, 'reconcile_from' => '2026-04-01']);
$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('haus'), ['statement_import' => true]);   // L12 switched it off; C books on both accounts
$sparId = (int) (new BankAccountService($wire()))->find('spar')->getId();

$card  = static fn(string $ref, string $date, string $amount, string $merchant): array => ['ref' => $ref, 'date' => $date, 'amount' => $amount, 'code' => 'PMNT/CCRD/POSD', 'info' => 'Bezugsort: ' . $merchant];
$april = [
    $card('C-01', '2026-04-02', '42.10', 'BEISPIEL MARKT LUZERN, Transaktionsdatum 01.04.2026'),
    $card('C-02', '2026-04-09', '18.50', 'BEISPIEL MARKT LUZERN, Transaktionsdatum 08.04.2026'),
    $card('C-03', '2026-04-16', '63.00', 'Beispiel  Markt Luzern'),
    ['ref' => 'C-04', 'date' => '2026-04-05', 'amount' => '2300.00', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift', 'debtor' => 'Pensionskasse Beispiel', 'debtor_iban' => PAYEE_IBAN, 'ustrd' => 'Rente'],
    ['ref' => 'C-05', 'date' => '2026-04-20', 'amount' => '150.00', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift', 'debtor' => 'Pensionskasse Beispiel', 'debtor_iban' => PAYEE_IBAN, 'ustrd' => 'Nachzahlung'],
    $card('C-06', '2026-04-10', '216.20', 'BUEROBEDARF MUSTER ZUERICH'),
    $card('C-07', '2026-04-11', '54.05', 'BUEROBEDARF MUSTER ZUERICH'),
    ['ref' => 'C-08', 'date' => '2026-04-30', 'amount' => '12.00', 'code' => 'PMNT/MDOP/CHRG', 'info' => 'Kontoführung 04.2026'],
    ['ref' => 'C-09', 'date' => '2026-04-15', 'amount' => '1000.00', 'info' => 'Übertrag', 'creditor' => 'Eigenes Konto', 'iban' => OWN_IBAN],
    ['ref' => 'C-10', 'date' => '2026-04-22', 'amount' => '80.00', 'info' => 'Zahlung', 'creditor' => 'Garage Beispiel AG'],
    ['ref' => 'C-11', 'date' => '2026-04-25', 'amount' => '30.00', 'info' => 'Zahlung', 'creditor' => 'Garage Beispiel AG'],
    ['ref' => 'C-12', 'date' => '2026-04-28', 'amount' => '108.10', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift', 'debtor' => 'Kunde Bar'],
    $card('C-13', '2026-04-29', '7.00', 'BEISPIEL MARKT LUZERN'),
    $card('C-14', '2026-04-12', '100.00', 'BUEROBEDARF MUSTER ZUERICH'),
    ['ref' => 'C-15', 'date' => '2026-04-17', 'amount' => '42.10', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Übertrag', 'debtor' => 'Eigenes Konto', 'debtor_iban' => OWN_IBAN],
];
$importer($wire())->import(camt053(['msg' => 'MSG-APR', 'stmt' => 'STMT-APR', 'iban' => OTHER_IBAN, 'opening' => '5000.00', 'from' => '2026-04-01', 'to' => '2026-04-30'], $april), 'april.xml');

$statesOf = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em) => new BankMovementStates($em, 'tester');
$postsOf  = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em) => new BankPostingService($em, 'tester');
$stand    = static function (string ...$refs) use ($wire, $mvId): array {
    $ids    = array_map($mvId, $refs);
    $states = $wire()->getRepository(BankMovement::class)->statesOf($ids);

    return array_map(static fn(int $id) => $states[$id] ?? '?', $ids);
};
$rowS     = static fn(string $sql): array => array_map(static fn($v) => $v === null ? null : (string) $v, $row($sql));
$keys     = static fn(): array => $db->fetchAllKeyValue('SELECT COALESCE(entry_ref, dedup_key), pattern_key FROM bank_movement ORDER BY id');
$sparCounts = static fn(): array => $wire()->getRepository(BankMovement::class)->stateCounts($sparId);
$c2Keys   = $keys();
check('C3 an imported movement carries its key; EVERY movement starts «zu prüfen» — nothing is private by silence (decision 4), a credit too (decision 6)',
    $c2Keys['C-01'] === 'merchant:beispiel markt luzern' && $c2Keys['C-03'] === 'merchant:beispiel markt luzern' && $c2Keys['C-04'] === 'iban:' . PAYEE_IBAN && $c2Keys['C-08'] === 'text:kontoführung'
    && $c2Keys['C-10'] === 'name:garage beispiel ag' && $c2Keys['A-0001'] === 'iban:' . PAYEE_IBAN && $c2Keys['A-0003'] === 'merchant:beispiel markt luzern'
    && $sparCounts() === ['review' => 15, 'business' => 0, 'private' => 0] && $count('bank_pattern') === 0 && $count('bank_movement_mark') === 0, json_encode([$c2Keys, $sparCounts()]));

// ── «Privat» ──
$done = $statesOf($wire())->markPrivate($mvId('C-01'));
check('C4 ONE confirmation as private classifies the pattern (decision 5): «Privat» on one purchase at the merchant makes all four of this bank account private — the pattern is stored (label, kind private, who), no mark; the same merchant on ANOTHER bank account stays «zu prüfen»',
    $done['pattern']?->getLabel() === 'BEISPIEL MARKT LUZERN' && $done['others'] === 3 && $stand('C-01', 'C-02', 'C-03', 'C-13') === ['private', 'private', 'private', 'private']
    && $stand('A-0003') === ['review'] && $count('bank_movement_mark') === 0
    && $rowS('SELECT bank_account_id, direction, match_key, kind, confirmed_by, amount_min, day_of_month FROM bank_pattern') === ['bank_account_id' => (string) $sparId, 'direction' => 'debit', 'match_key' => 'merchant:beispiel markt luzern', 'kind' => 'private', 'confirmed_by' => 'tester', 'amount_min' => null, 'day_of_month' => null],
    json_encode([$done['others'], $stand('C-01', 'C-02', 'C-03', 'C-13', 'A-0003')]));

$pension = $statesOf($wire())->markPrivate($mvId('C-04'), false, true, true);
check('C5 a credit is private only by a confirmed pattern (decision 6): the monthly pension — narrowed to the approximate amount (± 10 %) and the day of the month — is private; another credit of the same sender (other amount, other day) stays «zu prüfen»',
    $pension['pattern'] !== null && $pension['others'] === 0 && $stand('C-04', 'C-05') === ['private', 'review']
    && $rowS("SELECT amount_min, amount_max, day_of_month, direction FROM bank_pattern WHERE match_key LIKE 'iban:%'") === ['amount_min' => '2070.00', 'amount_max' => '2530.00', 'day_of_month' => '5', 'direction' => 'credit']);

$hand = $statesOf($wire())->markPrivate($mvId('C-08'), true);
check('C6 «privat» BY HAND: this movement only — a mark, no pattern',
    $hand === ['pattern' => null, 'others' => 0] && $stand('C-08') === ['private'] && $count('bank_pattern') === 2
    && $row('SELECT state, marked_by FROM bank_movement_mark') === ['state' => 'private', 'marked_by' => 'tester']);

$importer($wire())->import(camt053(['msg' => 'MSG-MAY', 'stmt' => 'STMT-MAY', 'iban' => OTHER_IBAN, 'opening' => $row('SELECT closing_balance FROM bank_statement ORDER BY id DESC LIMIT 1')['closing_balance'], 'from' => '2026-04-30', 'to' => '2026-05-31'], [
    $card('C-20', '2026-05-04', '9.90', 'BEISPIEL MARKT LUZERN'),
    ['ref' => 'C-21', 'date' => '2026-05-06', 'amount' => '2310.00', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift', 'debtor' => 'Pensionskasse Beispiel', 'debtor_iban' => PAYEE_IBAN, 'ustrd' => 'Rente'],
    ['ref' => 'C-22', 'date' => '2026-05-20', 'amount' => '2300.00', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift', 'debtor' => 'Pensionskasse Beispiel', 'debtor_iban' => PAYEE_IBAN, 'ustrd' => 'Rente'],
    ['ref' => 'C-23', 'date' => '2026-05-31', 'amount' => '12.00', 'code' => 'PMNT/MDOP/CHRG', 'info' => 'Kontoführung 05.2026'],
]), 'mai.xml');
check('C7 the pattern holds for what comes later: the next statement\'s purchase at the merchant and the pension of the next month (a day later, a few francs more) are private as they arrive; the same amount on the 20th is not; the charge marked by hand taught nothing',
    $stand('C-20', 'C-21', 'C-22', 'C-23') === ['private', 'private', 'review', 'review']);

$statesOf($wire())->setReview($mvId('C-02'));
$held = $stand('C-01', 'C-02');
$back = $statesOf($wire())->markPrivate($mvId('C-02'));
check('C8 one movement back to «zu prüfen» although its pattern is private (it is held by a mark, the pattern stays); «Privat» again lifts the mark — private by the pattern as before',
    $held === ['private', 'review'] && $back['pattern']?->getLabel() === 'BEISPIEL MARKT LUZERN' && $stand('C-02') === ['private'] && $count('bank_movement_mark') === 1);

$linkedPrivate = caught(fn() => $statesOf($wire())->markPrivate($mvId('A-0002')), BankRefusedException::class);
$linkedReview  = caught(fn() => $statesOf($wire())->setReview($mvId('A-0002')), BankRefusedException::class);
$many = $statesOf($wire())->markPrivateMany([$mvId('C-10'), $mvId('A-0002'), $mvId('C-10'), 0]);
check('C9 a LINKED movement is «geschäftlich» and cannot be set private or «zu prüfen» by hand (state-business, the sentence names what holds it); «Privat …» for several: each as the single one, the business one left and counted — the payee of C-10 is learned, its other payment is private too',
    $linkedPrivate?->reason === BankRefusedException::STATE_BUSINESS && str_contains($linkedPrivate->getMessage(), 'Rechnung 30') && $linkedReview?->reason === BankRefusedException::STATE_BUSINESS
    && $many === ['private' => 1, 'refused' => 1, 'patterns' => ['Garage Beispiel AG'], 'claimed' => 0, 'claims' => []] && $stand('C-10', 'C-11', 'A-0002') === ['private', 'private', 'business'],
    json_encode([$linkedPrivate?->getMessage(), $many], JSON_UNESCAPED_UNICODE));

/** A module that claims the movement C-12 — what debtor declares under `bankMovementClaims` for its collective credit. */
final class HarnessBankClaim implements \Z77\Module\Financial\Bank\BankMovementClaimInterface
{
    public function claimOn(BankMovement $movement, \Z77\Persistence\Resolver\UnifiedEntityManager $em): ?string
    {
        return $movement->getEntryRef() === 'C-12' ? 'Die Gutschrift gehört dem Testmodul — nicht privat.' : null;
    }
}
$claimsOf     = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em) => new BankMovementStates($em, 'tester', new \Z77\Module\Financial\Bank\BankMovementClaims([HarnessBankClaim::class]));
$claimBefore  = $stand('C-12');
$claimed      = caught(fn() => $claimsOf($wire())->markPrivate($mvId('C-12')), BankRefusedException::class);
$claimedHand  = caught(fn() => $claimsOf($wire())->markPrivate($mvId('C-12'), true), BankRefusedException::class);
$claimedMany  = $claimsOf($wire())->markPrivateMany([$mvId('C-12')]);
$badClaim     = caught(fn() => new \Z77\Module\Financial\Bank\BankMovementClaims([\stdClass::class]), \RuntimeException::class);
check('C9b a movement another module CLAIMS (`bankMovementClaims`, owner 2026-11-18 — debtor\'s collective credit of QR payments) cannot be set «privat»: refused with that module\'s sentence (claimed), as a pattern AND by hand — no pattern, no mark, the state unchanged; «Privat …» for several counts it apart with the sentence; financial names no module (a registry of declared classes, a bad entry fails at registration); no claim declared → «Privat» as before',
    $claimed?->reason === BankRefusedException::CLAIMED && $claimed->getMessage() === 'Die Gutschrift gehört dem Testmodul — nicht privat.' && $claimedHand?->reason === BankRefusedException::CLAIMED
    && $claimedMany === ['private' => 0, 'refused' => 0, 'patterns' => [], 'claimed' => 1, 'claims' => ['Die Gutschrift gehört dem Testmodul — nicht privat.']]
    && $stand('C-12') === $claimBefore && $badClaim instanceof \RuntimeException && (new \Z77\Module\Financial\Bank\BankMovementClaims())->claimOn($wire()->getRepository(BankMovement::class)->find($mvId('C-12')), $wire()) === null,
    json_encode([$claimed?->getMessage(), $claimedMany, $claimBefore], JSON_UNESCAPED_UNICODE));

// ── a document beats a pattern (decisions 7, 8) ──
$inUnit(static fn($em) => $linksOf($em)->link($mvId('C-11'), 'creditor', 'creditor:77', 'Rechnung 77 · Garage Beispiel AG', 'proposal'));
$em        = $wire();
$questions = $statesOf($em)->openQuestions($sparId);
$useRequest(['account' => 'spar']);
$host = $movementHost();
$host->listAction();
$qPage = $render('Backend/BankMovementController/listAction', $host->context);
$useRequest(['id' => (string) $mvId('C-11')], null, true);
$host = $movementHost();
$host->editAction();
$qWindow = $render('Backend/BankMovementController/edit', $host->context);
check('C10 a DOCUMENT beats a pattern (decision 7): an owner links a movement its pattern made private → «geschäftlich»; the question of decision 8 is OPEN — named above the list with the way into the movement\'s window, where it stands with «Ja» / «Nein» («Die restlichen 1 …»: the other payment)',
    $stand('C-11', 'C-10') === ['business', 'private'] && count($questions) === 1 && $questions[0]['movement']->getEntryRef() === 'C-11' && $questions[0]['others'] === 1
    && str_contains($qPage, 'data-pattern-question="' . $mvId('C-11') . '"') && str_contains($qPage, '«Garage Beispiel AG» ist als privat gemerkt') && str_contains($qPage, 'Rechnung 77 · Garage Beispiel AG')
    && str_contains($qWindow, '«Garage Beispiel AG» ist als privat gemerkt — 1 weitere Bewegung ist dadurch privat. Gibt es bei «Garage Beispiel AG» auch Geschäftliches?') && str_contains($qWindow, 'name="flip" value="yes" class="be-btn be-btn--primary">Ja — jedes Mal prüfen</button>') && str_contains($qWindow, '>Nein — nur diese Bewegung</button>') && str_contains($qWindow, 'name="flip" value="yes"') && str_contains($qWindow, 'name="flip" value="no"')
    && str_contains($qWindow, 'name="op" value="answer"') && !str_contains($qWindow, 'Buchung zurücknehmen') && !str_contains($qWindow, 'name="contra"'), $qWindow);

$useRequest(['id' => (string) $mvId('C-11')], ['op' => 'answer', 'flip' => 'no', '_origin' => 'region:' . \Z77\Module\Financial\Ui\BankMovementListing::definition('CHF')->region()], true);
$host = $movementHost();
$no   = $host->editAction();
$afterNo = [$stand('C-11', 'C-10'), count($statesOf($wire())->openQuestions($sparId)), $row("SELECT kind FROM bank_pattern WHERE match_key = 'name:garage beispiel ag'")['kind']];
$inUnit(static fn($em) => $linksOf($em)->release('creditor:77'));
$afterRelease = $stand('C-11');
check('C11 «Nein» → this movement is an EXCEPTION: the pattern stays private, the other payment stays private, the question is not asked again; when the owner releases its link the movement is private again by its pattern',
    $commands($no) === ['close-window', 'refresh-region'] && $afterNo === [['business', 'private'], 0, 'private'] && $afterRelease === ['private'], json_encode([$afterNo, $afterRelease]));

$inUnit(static fn($em) => $linksOf($em)->link($mvId('C-10'), 'creditor', 'creditor:78', 'Rechnung 78 · Garage Beispiel AG', 'proposal'));
$openAgain = $statesOf($wire())->openQuestions($sparId);
$statesOf($wire())->answer($mvId('C-10'), true);
$afterYes = [$stand('C-10', 'C-11'), $row("SELECT kind FROM bank_pattern WHERE match_key = 'name:garage beispiel ag'")['kind'], count($statesOf($wire())->openQuestions($sparId))];
$inUnit(static fn($em) => $linksOf($em)->release('creditor:78'));
$answered = caught(fn() => $statesOf($wire())->answer($mvId('C-10'), true), BankRefusedException::class);
check('C12 «Ja» → the pattern is «gemischt»: its movements are ALWAYS «zu prüfen» from then on — the other payment at once, the linked one as soon as its link is released; nothing more to answer (no-pattern)',
    count($openAgain) === 1 && $openAgain[0]['others'] === 1 && $afterYes === [['business', 'review'], 'mixed', 0] && $stand('C-10', 'C-11') === ['review', 'review'] && $answered?->reason === BankRefusedException::NO_PATTERN,
    json_encode([$afterYes, $stand('C-10', 'C-11')]));
$mixedHand = $statesOf($wire())->markPrivate($mvId('C-11'));
check('C13 on a «gemischt» pattern «Privat» decides this ONE movement by hand — the other stays «zu prüfen»',
    $mixedHand === ['pattern' => null, 'others' => 0] && $stand('C-10', 'C-11') === ['review', 'private']);

// ── «Geschäftlich buchen …» ──
$entryOf   = static fn(string $ref) => $db->fetchAssociative('SELECT e.*, y.code AS year FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id JOIN bank_posting p ON p.entry_year = y.code AND p.entry_number = e.number WHERE p.movement_id = ? AND p.taken_back_at IS NULL', [$mvId($ref)]) ?: [];
$linesOf   = static fn(int $entryId): array => array_map(
    static fn(array $l): string => $l['number'] . ' ' . ($l['debit'] !== '0.00' ? 'S ' . $l['debit'] : 'H ' . $l['credit']) . ($l['tax_code'] !== null ? ' ' . $l['tax_code'] . ' ' . $l['tax_base'] . '/' . $l['tax_amount'] : ''),
    $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit, l.tax_code, l.tax_base, l.tax_amount FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.position', [$entryId])
);
$booksState = static fn(): array => [$count('journal_entry'), $count('bank_posting'), $count('bank_link'), (string) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'journal-entry.2026'")];
$postRefused = static function (callable $work) use ($wire, $postsOf): ?\Throwable {
    return caught(static fn() => $work($postsOf($wire())), \Z77\Module\Financial\Services\FinancialException::class);
};
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

$before  = $booksState();
$charge  = $postsOf($wire())->post($mvId('C-08'), 'account', '6940', null);
$chargeE = $entryOf('C-08');
$chargeL = $row('SELECT owner_type, owner_key, owner_label, amount, basis FROM bank_link WHERE movement_id = ' . $mvId('C-08'));
$archiveName = basename($row("SELECT archive_path FROM bank_statement WHERE message_id = 'MSG-APR'")['archive_path']);
check('C14 «Geschäftlich buchen …» WITHOUT a tax code (decision 14, 16): a GENERATED entry dated the booking date — «6940 an Bank» for a debit, the bank side being the ledger account of the bank account —, source `bank`, its origin naming bank account, movement and the archived statement file (the voucher), one idempotency key per posting; the posting is the OWNER of the movement\'s link (through BankLinkService) with the full amount; no file needed',
    $chargeE['kind'] === 'generated' && $chargeE['entry_date'] === '2026-04-30' && $chargeE['source_type'] === 'bank' && $chargeE['source_ref'] === 'spar/' . $mvId('C-08') . ' · ' . $archiveName
    && $chargeE['idempotency_key'] === 'bank-posting:' . $charge->getId() && $chargeE['text'] === 'Kontoführung 04.2026' && $chargeE['created_by'] === 'tester'
    && $linesOf((int) $chargeE['id']) === ['6940 S 12.00', '1020 H 12.00']
    && $chargeL === ['owner_type' => 'posting', 'owner_key' => 'posting:' . $charge->getId(), 'owner_label' => 'Buchung 2026/' . $chargeE['number'] . ' · 6940 Bankspesen', 'amount' => '12.00', 'basis' => 'manual']
    && !$charge->hasVoucher() && $charge->entryRef()?->number === (int) $chargeE['number'] && $booksState()[0] === $before[0] + 1,
    json_encode([$chargeE, $chargeL, $linesOf((int) ($chargeE['id'] ?? 0))], JSON_UNESCAPED_UNICODE));
check('C15 «privat» but business (decision 18): the movement marked private by hand is «geschäftlich» once booked, its mark is gone; what was booked is LEARNED for its text — the next charge is proposed the same account, and nothing was posted for it',
    $stand('C-08', 'C-23') === ['business', 'review'] && $count('bank_movement_mark', 'movement_id = ' . $mvId('C-08')) === 0
    && $row("SELECT kind, contra_account, tax_code FROM bank_pattern WHERE match_key = 'text:kontoführung'") === ['kind' => 'business', 'contra_account' => '6940', 'tax_code' => null]
    && $postsOf($wire())->proposalFor($wire()->getRepository(BankMovement::class)->find($mvId('C-23'))) === ['kind' => 'account', 'contra' => '6940', 'taxCode' => '', 'text' => 'Kontoführung 05.2026']
    && $count('bank_posting', 'movement_id = ' . $mvId('C-23')) === 0);

$before    = $booksState();
$noVoucher = $postRefused(static fn($p) => $p->post($mvId('C-06'), 'account', '6500', 'VI'));
$atLimit   = $postRefused(static fn($p) => $p->post($mvId('C-14'), 'account', '6500', 'VI'));
$notAFile  = $postRefused(static fn($p) => $p->post($mvId('C-06'), 'account', '6500', 'VI', '', ['name' => 'notiz.txt', 'bytes' => 'nur Text']));
check('C16 THE ATTACHMENT RULE (decision 15): with a tax code and a gross of CHF 100 or more a file must come with it — refused under «Beleg» (216.20, and exactly 100.00), nothing saved: no entry, no posting row, no link, no number drawn; a file that is no PDF / JPEG / PNG is refused too',
    $noVoucher instanceof BankPostingRefusedException && $noVoucher->reason === BankPostingRefusedException::VOUCHER && $noVoucher->field === 'voucher' && str_contains($noVoucher->getMessage(), '100.00')
    && $atLimit instanceof BankPostingRefusedException && $atLimit->reason === BankPostingRefusedException::VOUCHER
    && $notAFile instanceof BankPostingRefusedException && $notAFile->reason === BankPostingRefusedException::VOUCHER && $booksState() === $before && $stand('C-06', 'C-14') === ['review', 'review']
    && (glob($base . '/' . BankVoucherArchive::DIR . '/*/*') ?: []) === [], json_encode([$noVoucher?->getMessage(), $booksState(), $before], JSON_UNESCAPED_UNICODE));

$office  = $postsOf($wire())->post($mvId('C-06'), 'account', '6500 Büromaterial', 'VI', 'Toner und Papier', ['name' => "Quittung\tBüro.pdf", 'bytes' => $pdf]);
$officeE = $entryOf('C-06');
$voucher = 'data/archive/financial/bank-voucher/2026/20260410-spar-' . $mvId('C-06') . '-' . substr(hash('sha256', $pdf), 0, 16) . '.pdf';
$read    = $postsOf($wire())->voucher((int) $office->getId());
check('C17 … WITH a tax code and the file: the gross of the bank split by the ledger\'s one VAT builder — «6500 net + 1171 Vorsteuer an Bank», tax data on the net line; the file is archived UNALTERED in the write-once archive (data/archive/financial/bank-voucher/<YYYY>/<date>-<code>-<movement>-<sha16>.pdf), the posting names path, SHA-256 and the original name, and it is read back verified',
    $linesOf((int) $officeE['id']) === ['6500 S 200.00 VI 200.00/16.20', '1171 S 16.20', '1020 H 216.20'] && $officeE['text'] === 'Toner und Papier' && $officeE['entry_date'] === '2026-04-10'
    && $office->getVoucherPath() === $voucher && is_file($base . '/' . $voucher) && file_get_contents($base . '/' . $voucher) === $pdf && $office->getVoucherSha256() === hash('sha256', $pdf)
    && $office->getVoucherName() === 'Quittung Büro.pdf' && $read === ['bytes' => $pdf, 'name' => 'Quittung Büro.pdf', 'type' => 'application/pdf'] && $stand('C-06') === ['business'],
    json_encode([$linesOf((int) ($officeE['id'] ?? 0)), $office->getVoucherPath()], JSON_UNESCAPED_UNICODE));

$em       = $wire();
$proposed = $postsOf($em)->proposalFor($em->getRepository(BankMovement::class)->find($mvId('C-07')));
$small    = $postsOf($wire())->post($mvId('C-07'), $proposed['kind'], $proposed['contra'], $proposed['taxCode']);
check('C18 the same merchant → the same PROPOSAL next time (account and tax code, decision 14); below CHF 100 the file is voluntary — saved without one',
    $proposed === ['kind' => 'account', 'contra' => '6500', 'taxCode' => 'VI', 'text' => 'Bezugsort: BUEROBEDARF MUSTER ZUERICH'] && !$small->hasVoucher()
    && $linesOf((int) $entryOf('C-07')['id']) === ['6500 S 50.00 VI 50.00/4.05', '1171 S 4.05', '1020 H 54.05']);

$credit  = $postsOf($wire())->post($mvId('C-12'), 'account', '3200', 'UN', '', ['name' => 'beleg.png', 'bytes' => $png]);
check('C19 a CREDIT books «Bank an contra»: the revenue net with the tax data and the VAT owed on the Haben side; its voucher is a photo (PNG)',
    $linesOf((int) $entryOf('C-12')['id']) === ['1020 S 108.10', '3200 H 100.00 UN 100.00/8.10', '2200 H 8.10'] && str_ends_with((string) $credit->getVoucherPath(), '.png')
    && $postsOf($wire())->voucher((int) $credit->getId())['type'] === 'image/png', json_encode($linesOf((int) ($entryOf('C-12')['id'] ?? 0))));

$before     = $booksState();
$noContra   = $postRefused(static fn($p) => $p->post($mvId('C-05'), 'account', '', null));
$group      = $postRefused(static fn($p) => $p->post($mvId('C-05'), 'account', '10', null));
$badCode    = $postRefused(static fn($p) => $p->post($mvId('C-05'), 'account', '3200', 'XX'));
$noPrivate  = $postRefused(static fn($p) => $p->post($mvId('C-09'), 'private', '', null));
$twice      = $postRefused(static fn($p) => $p->post($mvId('C-06'), 'account', '6500', null));
$foreign    = $postRefused(static fn($p) => $p->post($mvId('A-0002'), 'account', '6500', null));
check('C20 refusals, each a German sentence under its field and nothing written: no contra account, a group, an unknown tax code; «Privat» while the mandator names no private account (the sentence says where to set it — no number is assumed, stage C4); a movement that is linked already — booked before, or linked by another module (link-taken names what holds it)',
    $noContra instanceof BankPostingRefusedException && $noContra->field === 'contra' && $group instanceof BankPostingRefusedException && str_contains($group->getMessage(), 'Gruppe')
    && $badCode instanceof BankPostingRefusedException && $badCode->field === 'tax_code'
    && $noPrivate instanceof BankPostingRefusedException && $noPrivate->reason === BankPostingRefusedException::CONTRA && $noPrivate->field === 'kind' && str_contains($noPrivate->getMessage(), 'im Mandanten hinterlegen') && preg_match('/\d{4}/', $noPrivate->getMessage()) === 0
    && $postsOf($wire())->namedAccount('private') === '' && $postsOf($wire())->namedAccount('transfer') === ''
    && $twice instanceof BankRefusedException && $twice->reason === BankRefusedException::LINK_TAKEN && str_contains($twice->getMessage(), 'Buchung 2026/')
    && $foreign instanceof BankRefusedException && $foreign->reason === BankRefusedException::LINK_TAKEN && str_contains($foreign->getMessage(), 'Rechnung 30') && $booksState() === $before,
    json_encode([$noContra?->getMessage(), $noPrivate?->getMessage(), $twice?->getMessage(), $booksState(), $before], JSON_UNESCAPED_UNICODE));

// «Privat» and the transfer clearing account — the accounts stand on the MANDATOR record (stage C4; Rule 2), the chart must hold them.
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->save(new Account(['number' => '2850', 'name' => 'Privat', 'type' => 'equity']));
(new \Z77\Module\Financial\Services\AccountService($em))->save(new Account(['number' => '1090', 'name' => 'Transferkonto', 'type' => 'asset']));
$em = $wire();
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['account_private' => '2850', 'account_bank_transfer' => '1090']);
$privateTax = $postRefused(static fn($p) => $p->post($mvId('C-09'), 'private', '', 'VI'));
$postsOf($wire())->post($mvId('C-09'), 'private', '6500', null);
$postsOf($wire())->post($mvId('C-05'), 'private', '', null);
$em = $wire();
check('C21 contra «Privat» (decision 17): a debit is a Privatbezug («Privat an Bank»), a credit a Privateinlage («Bank an Privat») — against the private account of the mandator whatever was typed as contra, always without a tax code (a code is refused); learnable: the transfer to the own IBAN proposes «Privat» next time',
    $privateTax instanceof BankPostingRefusedException && $privateTax->field === 'tax_code'
    && $linesOf((int) $entryOf('C-09')['id']) === ['2850 S 1000.00', '1020 H 1000.00'] && $linesOf((int) $entryOf('C-05')['id']) === ['1020 S 150.00', '2850 H 150.00']
    && $postsOf($wire())->namedAccount('private') === '2850'
    && $row("SELECT kind, contra_account FROM bank_pattern WHERE match_key = 'iban:" . OWN_IBAN . "' AND direction = 'debit'") === ['kind' => 'business', 'contra_account' => '2850']
    && $postsOf($em)->proposalFor($em->getRepository(BankMovement::class)->find($mvId('C-09')))['kind'] === 'private');

$postsOf($wire())->post($mvId('A-0003'), 'transfer', '', null);
$postsOf($wire())->post($mvId('C-15'), 'transfer', '', null);
check('C22 a transfer between two business accounts: each side against the transfer clearing account of the mandator — «1090 an Bank» on the sending account (its own ledger account 1022), «Bank an 1090» on the receiving one; both booked, 1090 stands at zero',
    $linesOf((int) $entryOf('A-0003')['id']) === ['1090 S 42.10', '1022 H 42.10'] && $linesOf((int) $entryOf('C-15')['id']) === ['1020 S 42.10', '1090 H 42.10']
    && $postsOf($wire())->namedAccount('transfer') === '1090'
    && (string) $db->fetchOne("SELECT SUM(l.debit) - SUM(l.credit) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '1090'") === '0.00');

// ── the pattern asks before it flips (decision 8) ──
$before   = $booksState();
$asked    = $postRefused(static fn($p) => $p->post($mvId('C-03'), 'account', '6500', null));
$afterAsk = [$booksState() === $before, $stand('C-03')];
$postsOf($wire())->post($mvId('C-03'), 'account', '6500', null, '', null, false);
$afterNein = [$stand('C-01', 'C-02', 'C-03', 'C-13', 'C-20'), $row("SELECT kind, contra_account FROM bank_pattern WHERE match_key = 'merchant:beispiel markt luzern'"), $row('SELECT state FROM bank_movement_mark WHERE movement_id = ' . $mvId('C-03'))];
check('C23 booking a movement of a PRIVATE pattern as business ASKS first (decision 8): without an answer it is refused with the question — «Die restlichen 4 privaten Bewegungen von «BEISPIEL MARKT LUZERN» auf «zu prüfen» setzen?» — and nothing is written; «Nein» → booked as an EXCEPTION, the pattern stays private, the other four stay private',
    $asked instanceof BankRefusedException && $asked->reason === BankRefusedException::PATTERN_QUESTION
    && $asked->getMessage() === '«BEISPIEL MARKT LUZERN» ist als privat gemerkt — 4 weitere Bewegungen sind dadurch privat. Gibt es bei «BEISPIEL MARKT LUZERN» auch Geschäftliches?' && $afterAsk === [true, ['private']]
    // the pattern's NAME without one movement's date and time (owner 2026-11-19) — on new patterns and on read
    && BankPattern::nameOf('Einkauf TWINT DIGITEC GALAXUS, ZURICH 26.10.26 / 14:56:07') === 'Einkauf TWINT DIGITEC GALAXUS, ZURICH'
    && BankPattern::nameOf('Zahlung 30.09.2026 Muster AG') === 'Zahlung Muster AG' && BankPattern::nameOf('Garage Beispiel AG') === 'Garage Beispiel AG' && BankPattern::nameOf('26.10.26 / 14:56') === '26.10.26 / 14:56'
    && $afterNein === [['private', 'private', 'business', 'private', 'private'], ['kind' => 'private', 'contra_account' => '6500'], ['state' => 'exception']],
    json_encode([$asked?->getMessage(), $afterAsk, $afterNein], JSON_UNESCAPED_UNICODE));

// ── «Buchung zurücknehmen …» ──
$firstEntry = $entryOf('C-03');
$reversal   = $postsOf($wire())->takeBack($mvId('C-03'));
$reversalE  = $db->fetchAssociative('SELECT e.* FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE y.code = ? AND e.number = ?', [$reversal->fiscalYear, $reversal->number]);
$takenRow   = $rowS('SELECT reversal_year, reversal_number, taken_back_by, taken_back_at IS NOT NULL AS taken FROM bank_posting WHERE movement_id = ' . $mvId('C-03'));
check('C24 «Buchung zurücknehmen …» (decision 19): the REVERSING entry on the entry\'s own date (debit and credit swapped, linked to the entry it reverses), the link RELEASED in the same unit of work, the movement back to «zu prüfen» — also where its pattern is private (it is held); the posting row stays and names the reversal',
    (int) $reversalE['reversal_of_id'] === (int) $firstEntry['id'] && $reversalE['entry_date'] === '2026-04-16' && $linesOf((int) $reversalE['id']) === ['6500 H 63.00', '1020 S 63.00']
    && str_starts_with($reversalE['text'], 'Zurückgenommen: ') && $count('bank_link', 'movement_id = ' . $mvId('C-03')) === 0 && $stand('C-03', 'C-01') === ['review', 'private']
    && $takenRow === ['reversal_year' => '2026', 'reversal_number' => (string) $reversal->number, 'taken_back_by' => 'tester', 'taken' => '1']
    && $postsOf($wire())->currentOf($mvId('C-03')) === null, json_encode([$reversalE, $takenRow, $stand('C-03', 'C-01')], JSON_UNESCAPED_UNICODE));

$nothing1 = $postRefused(static fn($p) => $p->takeBack($mvId('C-03')));
$nothing2 = $postRefused(static fn($p) => $p->takeBack($mvId('A-0002')));
$postsOf($wire())->post($mvId('C-03'), 'account', '6500', null, '', null, false);
$again = $db->fetchAllAssociative('SELECT e.idempotency_key FROM journal_entry e WHERE e.idempotency_key LIKE ? ORDER BY e.id', ['bank-posting:%']);
check('C25 nothing to take back: a movement without a direct posting, and one linked by another module (its link is released where it was set) — refused, nothing changed; a movement taken back can be booked AGAIN — a new posting row with its own idempotency key, the question asked again',
    $nothing1 instanceof BankPostingRefusedException && $nothing1->reason === BankPostingRefusedException::NOTHING && $nothing2 instanceof BankPostingRefusedException && $nothing2->reason === BankPostingRefusedException::NOTHING
    && $count('bank_link', "owner_key = 'creditor:30'") === 1 && $count('bank_posting', 'movement_id = ' . $mvId('C-03')) === 2 && $stand('C-03') === ['business']
    && count(array_unique(array_column($again, 'idempotency_key'))) === count($again));

$postsOf($wire())->post($mvId('C-13'), 'account', '6500', null, '', null, true);
$statesOf($wire())->markPrivate($mvId('C-01'));
check('C26 «Ja» while booking → the pattern is «gemischt»: its other movements are «zu prüfen» at once and stay so (the new one of May too), the two booked ones are business; «Privat» there is by hand, one movement at a time; booking another one asks nothing more',
    $row("SELECT kind FROM bank_pattern WHERE match_key = 'merchant:beispiel markt luzern'")['kind'] === 'mixed' && $stand('C-01', 'C-02', 'C-03', 'C-13', 'C-20') === ['private', 'review', 'business', 'business', 'review']
    && $postRefused(static fn($p) => $p->post($mvId('C-02'), 'account', '6500', null)) === null && $stand('C-02') === ['business'], json_encode($stand('C-01', 'C-02', 'C-03', 'C-13', 'C-20')));

$lifted = $statesOf($wire())->liftPattern($mvId('C-21'));
$noMore = caught(fn() => $statesOf($wire())->liftPattern($mvId('C-21')), BankRefusedException::class);
check('C27 «Muster aufheben»: the private pattern of the pension is taken back — both of its movements are «zu prüfen» again; the pattern keeps only the booking proposal it learned from the Privateinlage of the same sender (kind business, the narrowing gone); a second time there is none (no-pattern)',
    $lifted === 2 && $stand('C-04', 'C-21') === ['review', 'review']
    && $rowS("SELECT kind, contra_account, amount_min, day_of_month FROM bank_pattern WHERE match_key = 'iban:" . PAYEE_IBAN . "' AND direction = 'credit'") === ['kind' => 'business', 'contra_account' => '2850', 'amount_min' => null, 'day_of_month' => null]
    && $noMore?->reason === BankRefusedException::NO_PATTERN);

// ── a closed year, a settled VAT period (decision 21) ──
$setPeriod = static fn(string $ymd, string $state) => $db->executeStatement(
    'UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = ? WHERE y.code = ? AND ? BETWEEN p.start_date AND p.end_date',
    [$state, '2026', $ymd]
);
// «Settled» is read from the posted returns since stage S2 (ADR-042 addendum 2026-11-24): a LIVE ordinary return covering
// the 2nd quarter, inserted directly — the period rows stay `open`.
$db->insert('vat_return', [
    'period_from' => '2026-04-01', 'period_to' => '2026-06-30', 'revision' => 1, 'state' => 'posted', 'live' => 1,
    'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
    'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
    'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => '2026-07-10 10:00:00', 'figures_fingerprint' => str_repeat('b', 64),
    'voucher_path' => 'data/archive/financial/vat-return/harness.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness.pdf',
]);
$before     = $booksState();
$settledTax = $postRefused(static fn($p) => $p->takeBack($mvId('C-06')));
$settledNew = $postRefused(static fn($p) => $p->post($mvId('C-14'), 'account', '6500', 'VI', '', ['name' => 'q.pdf', 'bytes' => $pdf]));
$afterTax   = [$booksState() === $before, $stand('C-06', 'C-14'), count(glob($base . '/' . BankVoucherArchive::DIR . '/*/*'))];
$plain      = $postRefused(static fn($p) => $p->takeBack($mvId('C-09')));
$db->executeStatement("DELETE FROM vat_return WHERE period_from = '2026-04-01'");
$setPeriod('2026-04-15', 'closed');
$before     = $booksState();
$closedBack = $postRefused(static fn($p) => $p->takeBack($mvId('C-07')));
$closedPost = $postRefused(static fn($p) => $p->post($mvId('C-10'), 'account', '6500', null));
$afterClose = [$booksState() === $before, $stand('C-07', 'C-10')];
$setPeriod('2026-04-15', 'open');
check('C28 a SETTLED VAT period (a live return covers the date — read from the returns, the period row is open) refuses the take-back of an entry with a tax code and a new posting with one — the ledger\'s reason vat-period-settled, ONE German sentence naming the VAT period by its label (LedgerService::periodRefusalText()), what did not happen («Nichts zurückgenommen» / «Nichts gebucht») and the bank\'s way out, nothing changed, no file left in the voucher archive for the refused posting; an entry WITHOUT a tax code is still taken back there (as for every posting). A CLOSED year refuses both, with the way out («Buchung von Hand im offenen Jahr»)',
    $settledTax instanceof BankPostingRefusedException && $settledTax->reason === BankPostingRefusedException::PERIOD && $settledTax->getMessage() === 'Die MWST-Periode 2. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Nichts zurückgenommen — die Korrektur ist eine Buchung von Hand in der offenen Periode.'
    && $settledTax->getPrevious() instanceof \Z77\Module\Financial\Services\PostingRefusedException && $settledTax->getPrevious()->reason === 'vat-period-settled'
    && $settledNew instanceof BankPostingRefusedException && $settledNew->reason === BankPostingRefusedException::PERIOD && $afterTax === [true, ['business', 'review'], 2]
    && $settledNew->getMessage() === 'Die MWST-Periode 2. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Nichts gebucht — die Korrektur ist eine Buchung von Hand in der offenen Periode.'
    && $plain === null && $stand('C-09') === ['review']
    && $closedBack instanceof BankPostingRefusedException && $closedBack->reason === BankPostingRefusedException::PERIOD && str_contains($closedBack->getMessage(), 'abgeschlossenen Geschäftsjahr') && str_contains($closedBack->getMessage(), 'von Hand im offenen Jahr')
    && $closedPost instanceof BankPostingRefusedException && $closedPost->reason === BankPostingRefusedException::PERIOD && $afterClose === [true, ['business', 'review']],
    json_encode([$settledTax?->getMessage(), $closedBack?->getMessage(), $afterTax, $afterClose, $plain?->getMessage()], JSON_UNESCAPED_UNICODE));

// ── the screens through the host double ──
$bmRegion = \Z77\Module\Financial\Ui\BankMovementListing::definition('CHF')->region();
$useRequest(['account' => 'spar']);
$host = $movementHost();
$host->listAction();
$c2Page    = $render('Backend/BankMovementController/listAction', $host->context);
$c2Toolbar = $render('Backend/BankMovementController/toolbar', $host->context);
$counts    = $host->context['counts'];
check('C29 the list «Bewegungen»: the column «Stand» per row (zu prüfen / geschäftlich / privat), the state icon opens the movement\'s window, a checkbox on every row that is not business, the selection bar «Privat …» (a GET form that opens a window); the toolbar counts «Zu prüfen (n)»; the views filter in the database and keep search and paging',
    str_contains($c2Page, '>zu prüfen</strong>') && str_contains($c2Page, '>geschäftlich</span>') && str_contains($c2Page, '>privat</span>')
    && str_contains($c2Page, 'data-window-open="/backend/finance/bank-movement/edit?id=' . $mvId('C-01') . '"') && str_contains($c2Page, 'data-origin="region:' . $bmRegion . '"')
    && substr_count($c2Page, 'name="mv[]"') === $counts['review'] + $counts['private'] && str_contains($c2Page, 'id="bank-movement-selection" method="get" action="/backend/finance/bank-movement/confirm-private" data-window-form')
    && str_contains($c2Page, '>Privat …</button>') && str_contains($c2Toolbar, 'Zu prüfen (' . $counts['review'] . ')') && array_sum($counts) === 19
    && $found(['account' => 'spar', 'state' => 'private']) === ['C-11', 'C-01'] && count($found(['account' => 'spar', 'state' => 'business'])) === $counts['business']
    && $found(['account' => 'spar', 'state' => 'review', 'f_text' => 'pensionskasse']) === ['C-22', 'C-21', 'C-04'], json_encode([$counts, $found(['account' => 'spar', 'state' => 'private'])]));

$useRequest(['id' => (string) $mvId('C-14')], null, true);
$host = $movementHost();
$host->editAction();
$bookWindow = $render('Backend/BankMovementController/edit', $host->context);
check('C30 the window of a movement «zu prüfen»: «Geschäftlich buchen» — «Buchen als» (Konto · Privat · Übertrag eigenes Konto, each naming its configured account), Gegenkonto and MWST-Code PREFILLED from what the merchant was booked with, the text from the movement, the file field; «Buchen» is the primary action, «Privat» beside it with its three options; labels only, the help attached',
    str_contains($bookWindow, 'data-window="bank-movement-edit"') && str_contains($bookWindow, 'data-window-entity="bankMovement:' . $mvId('C-14') . '"') && str_contains($bookWindow, 'name="op" value="book"')
    && str_contains($bookWindow, 'Privat · 2850 Privat') && str_contains($bookWindow, 'Übertrag eigenes Konto · 1090 Transferkonto')
    && preg_match('/name="contra"[^>]*value="6500"/', $bookWindow) === 1 && preg_match('/<option value="VI" selected>/', $bookWindow) === 1
    && str_contains($bookWindow, 'value="Bezugsort: BUEROBEDARF MUSTER ZUERICH"') && str_contains($bookWindow, 'id="bank-movement-voucher" type="file" name="voucher"') && str_contains($bookWindow, 'enctype="multipart/form-data"')
    && str_contains($bookWindow, '>Buchen</button>') && str_contains($bookWindow, 'form="bank-movement-private"') && str_contains($bookWindow, 'name="only_this"') && str_contains($bookWindow, 'name="near_amount"') && str_contains($bookWindow, 'name="near_day"')
    && !str_contains($bodyOf($bookWindow), 'be-form__hint') && !str_contains($bookWindow, 'name="flip"') && $host->help->has(), $bookWindow);

$bookPost = ['op' => 'book', 'kind' => 'account', 'contra' => '6500', 'tax_code' => 'VI', 'text' => 'Ordner', '_origin' => 'region:' . $bmRegion];
$useRequest(['id' => (string) $mvId('C-14')], $bookPost, true);
$host = $movementHost();
$host->editAction();
$refusedWindow = $render('Backend/BankMovementController/edit', $host->context);
$useRequest(['id' => (string) $mvId('C-14')], $bookPost, true, [], $upload('quittung.pdf', $pdf), 'voucher');
$host   = $movementHost();
$booked = $host->editAction();
check('C31 «Buchen» through the screen: without the file the window comes back with the sentence under «Beleg» and what was typed kept — nothing saved; with the file it books, closes the window and reloads the list («Gebucht: Buchung 2026/n.»)',
    str_contains($refusedWindow, 'braucht die Buchung einen Beleg') && preg_match('/id="bank-movement-voucher"[^>]*aria-invalid="true"/', $refusedWindow) === 1 && str_contains($refusedWindow, 'value="Ordner"')
    && $commands($booked) === ['close-window', 'refresh-region'] && str_starts_with($host->messageService->flashes[0][1], 'Gebucht: Buchung 2026/') && $stand('C-14') === ['business']
    && $entryOf('C-14')['text'] === 'Ordner' && $row('SELECT voucher_name FROM bank_posting WHERE movement_id = ' . $mvId('C-14'))['voucher_name'] === 'quittung.pdf');

$useRequest(['id' => (string) $mvId('C-10')], ['op' => 'book', 'kind' => 'account', 'contra' => '6500', 'tax_code' => '', 'text' => ''], true, [], new \Z77\Shared\ValueObjects\UploadedFile('gross.pdf', '', 0, 'application/pdf', UPLOAD_ERR_INI_SIZE), 'voucher');
$host = $movementHost();
$host->editAction();
$brokenWindow = $render('Backend/BankMovementController/edit', $host->context);
check('C31b a file that was chosen but did not arrive (too large for the server) never books silently without it — the window comes back with the sentence under «Beleg», nothing booked, although without a tax code no file would have been needed',
    str_contains($brokenWindow, 'Der Beleg ist nicht angekommen') && $stand('C-10') === ['review'] && $count('bank_posting', 'movement_id = ' . $mvId('C-10')) === 0);

$useRequest(['id' => (string) $mvId('C-14')], null, true);
$host = $movementHost();
$host->editAction();
$doneWindow = $render('Backend/BankMovementController/edit', $host->context);
$postingId  = (int) $row('SELECT id FROM bank_posting WHERE movement_id = ' . $mvId('C-14'))['id'];
$useRequest(['id' => (string) $postingId]);
$host = $movementHost();
$host->voucherAction();
$sentVoucher = $host->sent;
$useRequest(['id' => (string) $mvId('C-14')]);
$host = $movementHost();
$host->statementAction();
$sentStatement = $host->sent;
$useRequest(['id' => (string) $mvId('C-14')], ['op' => 'take-back', '_origin' => 'region:' . $bmRegion], true);
$host  = $movementHost();
$taken = $host->editAction();
check('C32 the window of a booked movement: the entry, its contra account and tax code, the file as a link (it opens the archived bytes, verified) — and «Buchung zurücknehmen» as its one action; the archived camt.053 of the movement downloads verified; taking back answers «Buchung zurückgenommen (Storno …) — … wieder «zu prüfen»»',
    str_contains($doneWindow, 'Buchung 2026/') && str_contains($doneWindow, '6500 · MWST VI') && str_contains($doneWindow, 'voucher?id=' . $postingId) && str_contains($doneWindow, '>quittung.pdf</a>')
    && str_contains($doneWindow, 'name="op" value="take-back"') && str_contains($doneWindow, '>Buchung zurücknehmen</button>') && !str_contains($doneWindow, 'name="contra"')
    && $sentVoucher === [$pdf, 'quittung.pdf', 'application/pdf', true]
    && ($sentStatement[1] ?? '') === $archiveName && ($sentStatement[2] ?? '') === 'application/xml' && ($sentStatement[3] ?? null) === false && str_contains((string) ($sentStatement[0] ?? ''), 'MSG-APR')
    && $commands($taken) === ['close-window', 'refresh-region'] && str_contains($host->messageService->flashes[0][1], 'Buchung zurückgenommen (Storno 2026/') && $stand('C-14') === ['review'], $doneWindow);

$learned  = $statesOf($wire())->markPrivate($mvId('C-23'));
$earlier  = $statesOf($wire())->openQuestions($sparId);
$useRequest(['id' => (string) $mvId('C-08')], null, true);
$host = $movementHost();
$host->editAction();
$earlierWindow = $render('Backend/BankMovementController/edit', $host->context);
$statesOf($wire())->answer($mvId('C-08'), false);
check('C32b «Privat» on a movement whose pattern so far only carried a booking proposal (the charge booked in C14): the pattern is private now and keeps its proposal; the charge booked BEFORE stays «geschäftlich» by its link, and decision 8 asks about it — in its window beside «Buchung zurücknehmen»; «Nein» → an exception, nothing more to ask',
    $learned['pattern']?->getLabel() === 'Kontoführung 04.2026' && $learned['others'] === 0 && $stand('C-23', 'C-08') === ['private', 'business']
    && $row("SELECT kind, contra_account FROM bank_pattern WHERE match_key = 'text:kontoführung'") === ['kind' => 'private', 'contra_account' => '6940']
    && count($earlier) === 1 && $earlier[0]['movement']->getEntryRef() === 'C-08' && $earlier[0]['others'] === 1
    && str_contains($earlierWindow, '«Kontoführung 04.2026» ist als privat gemerkt — 1 weitere Bewegung ist dadurch privat. Gibt es bei «Kontoführung 04.2026» auch Geschäftliches?') && str_contains($earlierWindow, 'form="bank-movement-answer" name="flip" value="yes"')
    && str_contains($earlierWindow, '>Buchung zurücknehmen</button>') && $statesOf($wire())->openQuestions($sparId) === [], json_encode([$stand('C-23', 'C-08'), count($earlier)]));

$useRequest(['id' => (string) $mvId('C-22')], ['op' => 'private', '_origin' => 'region:' . $bmRegion], true);
$host    = $movementHost();
$private = $host->editAction();
$privateFlash = $host->messageService->flashes[0][1] ?? '';
$privateStand = $stand('C-22', 'C-21', 'C-04');
$useRequest(['id' => (string) $mvId('C-22')], null, true);
$host = $movementHost();
$host->editAction();
$privateWindow = $render('Backend/BankMovementController/edit', $host->context);
$useRequest(['id' => (string) $mvId('C-22')], ['op' => 'book', 'kind' => 'account', 'contra' => '3200', 'tax_code' => '', 'text' => ''], true);
$host = $movementHost();
$host->editAction();
$askWindow = $render('Backend/BankMovementController/edit', $host->context);
$useRequest(['id' => (string) $mvId('C-22')], ['op' => 'lift', '_origin' => 'region:' . $bmRegion], true);
$host = $movementHost();
$host->editAction();
$liftFlash = $host->messageService->flashes[0][1] ?? '';
check('C33 «Privat» through the screen confirms the pattern and says so; the window of a private movement names its pattern and offers «Buchen», «Zu prüfen» and «Muster aufheben»; the QUESTION of decision 8 stands in the booking form («Ja — jedes Mal prüfen» / «Nein — nur diese Bewegung») — «Buchen» without an answer comes back asking for one of the two, nothing booked; «Muster aufheben» answers how many are «zu prüfen» again',
    $commands($private) === ['close-window', 'refresh-region'] && $privateFlash === 'Privat: «Pensionskasse Beispiel» — damit sind 2 weitere Bewegungen privat.' && $privateStand === ['private', 'private', 'private']
    && str_contains($privateWindow, 'privat <small class="be-list__cell--muted">· Muster «Pensionskasse Beispiel»') && str_contains($privateWindow, 'form="bank-movement-review"') && str_contains($privateWindow, 'form="bank-movement-lift"')
    && str_contains($privateWindow, '«Pensionskasse Beispiel» ist als privat gemerkt — 2 weitere Bewegungen sind dadurch privat. Gibt es bei «Pensionskasse Beispiel» auch Geschäftliches?') && str_contains($privateWindow, '>Ja — jedes Mal prüfen</span>') && str_contains($privateWindow, 'name="flip" value="yes"') && str_contains($privateWindow, 'name="op" value="book"')
    && str_contains($askWindow, 'Bitte eine Antwort wählen: «Ja — jedes Mal prüfen» oder «Nein — nur diese Bewegung».') && $count('bank_posting', 'movement_id = ' . $mvId('C-22')) === 0
    // the voucher is the shared upload FIELD (owner 2026-11-19): no bare browser file field, it travels with the booking form
    && str_contains($privateWindow, 'class="z77-upload z77-upload--field" data-upload-inline') && str_contains($privateWindow, 'id="bank-movement-voucher" type="file" name="voucher" accept=".pdf,.jpg,.jpeg,.png"')
    && str_contains($privateWindow, 'for="bank-movement-voucher" data-upload-zone') && str_contains($privateWindow, 'PDF, JPG, PNG · bis 10 MB') && !str_contains($privateWindow, 'data-upload="field"')
    && $liftFlash === 'Muster aufgehoben — 3 Bewegungen sind wieder «zu prüfen».' && $stand('C-22', 'C-21', 'C-04') === ['review', 'review', 'review'], json_encode([$privateFlash, $liftFlash, $stand('C-22')], JSON_UNESCAPED_UNICODE));

$useRequest(['mv' => [(string) $mvId('C-22'), (string) $mvId('C-08'), 'x']], null, true);
$host = $movementHost();
$host->confirmPrivateAction();
$confirmWindow = $render('Backend/BankMovementController/confirmPrivate', $host->context);
$useRequest([], ['mv' => [(string) $mvId('C-22'), (string) $mvId('C-08')], '_origin' => 'region:' . $bmRegion], true);
$host      = $movementHost();
$confirmed = $host->confirmPrivateAction();
$useRequest(['mv' => []], null, true);
$host = $movementHost();
$host->confirmPrivateAction();
check('C34 «Privat …» for the ticked rows: a window that names them (2 Bewegungen privat?), its POST marks each — the business one is left and counted in the answer; nothing ticked → the window says so',
    str_contains($confirmWindow, 'data-window-title="2 Bewegungen privat?"') && substr_count($confirmWindow, 'name="mv[]"') === 2 && str_contains($confirmWindow, 'Pensionskasse Beispiel') && str_contains($confirmWindow, '>Privat</button>')
    && $commands($confirmed) === ['close-window', 'refresh-region'] && $stand('C-22', 'C-08') === ['private', 'business']
    && str_contains($render('Backend/BankMovementController/confirmPrivate', $host->context), 'Keine Bewegung angekreuzt.'));

// ── «gemischt geführt» (owner 2026-11-19, ADR-061 addendum) ──
$p7Mig     = (string) file_get_contents($root . '/packages/module-financial/res/migrations/Version20261119100000.php');
$p7Off     = caught(fn() => (new BankAccountService($wire()))->update((new BankAccountService($wire()))->find('spar'), ['mixed_use' => false]), InvalidBankAccountException::class);
$p7Still   = (int) $db->fetchOne("SELECT mixed_use FROM bank_account WHERE code = 'spar'");
$p7Review  = (string) $db->fetchOne("SELECT m.entry_ref FROM bank_movement m JOIN bank_account a ON a.id = m.bank_account_id WHERE a.code = 'spar' AND m.id NOT IN (SELECT movement_id FROM bank_link) AND m.id NOT IN (SELECT movement_id FROM bank_movement_mark) ORDER BY m.id DESC LIMIT 1");
$p7Before  = $stand($p7Review);
$db->executeStatement("UPDATE bank_account SET mixed_use = 0 WHERE code = 'spar'");   // as if it had been run purely business — the screens and «Privat»
$p7Refused = caught(fn() => $statesOf($wire())->markPrivate($mvId($p7Review)), BankRefusedException::class);
$useRequest(['account' => 'spar']);
$host = $movementHost();
$host->listAction();
$p7List    = $render('Backend/BankMovementController/_list', $host->context);
$p7Bar     = $render('Backend/BankMovementController/toolbar', $host->context);
$useRequest(['account' => 'spar', 'state' => 'private']);
$host = $movementHost();
$host->listAction();
$p7View    = $host->context['view'];
$useRequest(['id' => (string) $mvId($p7Review)], null, true);
$host = $movementHost();
$host->editAction();
$p7Window  = $render('Backend/BankMovementController/edit', $host->context);
$db->executeStatement("UPDATE bank_account SET mixed_use = 1 WHERE code = 'spar'");
$useRequest(['account' => 'spar']);
$host = $movementHost();
$host->listAction();
$p7Mixed   = $render('Backend/BankMovementController/_list', $host->context);
$p7MixBar  = $render('Backend/BankMovementController/toolbar', $host->context);
check('C36 «gemischt geführt» (owner 2026-11-19): its own migration (`bank_account.mixed_use`, default off, ON where a private mark or a private / mixed pattern exists); switching it OFF is refused while the account holds private decisions (the sentence counts them); OFF: «Privat» is refused (not-mixed) and nothing is written, the list has no selection and no column «Stand», the toolbar only «Alle» · «Zu prüfen», a view «Privat» falls back to «Alle», the movement window offers no «Privat»; ON: as before; the column «Original» is «FW»',
    str_contains($p7Mig, 'ADD mixed_use TINYINT(1) DEFAULT 0 NOT NULL') && str_contains($p7Mig, "p.kind IN ('private', 'mixed')") && str_contains($p7Mig, "k.state = 'private'") && str_contains($p7Mig, 'DROP mixed_use')
    && $p7Off instanceof InvalidBankAccountException && str_contains($p7Off->validator->getFieldErrors()['mixed_use'] ?? '', 'lässt sich erst ausschalten') && $p7Still === 1
    && $p7Refused?->reason === BankRefusedException::NOT_MIXED && str_contains($p7Refused->getMessage(), 'nicht «gemischt geführt»') && $stand($p7Review) === $p7Before
    && !str_contains($p7List, 'name="mv[]"') && !str_contains($p7List, 'data-movement-selection') && !str_contains($p7List, '>Stand<') && str_contains($p7List, '>FW<') && !str_contains($p7List, '>Original<')
    && str_contains($p7Bar, '>Zu prüfen') && !str_contains($p7Bar, '>Geschäftlich<') && !str_contains($p7Bar, '>Privat<') && $p7View === null
    && !str_contains($p7Window, 'form="bank-movement-private"') && !str_contains($p7Window, 'data-movement-private-options') && str_contains($p7Window, '>Buchen</button>')
    && str_contains($p7Mixed, '>Stand<') && str_contains($p7Mixed, '>FW<') && str_contains($p7MixBar, '>Privat<') && str_contains($p7MixBar, '>Geschäftlich<'),
    json_encode([$p7Off?->validator->getFieldErrors(), $p7Refused?->getMessage(), $p7Review, $p7View], JSON_UNESCAPED_UNICODE));

// ── R. «Abgleich ab», the anchor, the check, the close (ADR-061 stage C4) ──
echo "R. «Abgleich ab», the anchor, the check, the close blocks (ADR-061 stage C4, database)\n";

/** A posting source no module of this harness knows — its entries belong to the link `test:<ref>` (what an owning module declares under `bankEntryOwners`). */
final class HarnessBankEntryOwner implements \Z77\Module\Financial\Bank\BankEntryOwnerInterface
{
    public function ownerKeys(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === 'test') {
                $out[$entry['id']] = 'test:' . $entry['sourceRef'];
            }
        }

        return $out;
    }
}

$c4Migration = (string) @file_get_contents($root . '/packages/module-financial/res/migrations/Version20261117200000.php');
$c4Src       = '';
foreach (['Bank/BankReconciliation.php', 'Bank/BankCheck.php', 'Bank/BankEntryOwners.php', 'Bank/BankEntryOwnerInterface.php', 'Close/BankReconciliationCheck.php', 'Entities/BankAnchor.php'] as $c4File) {
    $c4Src .= (string) file_get_contents($root . '/packages/module-financial/src/' . $c4File);
}
$finConfig = \Z77\Core\DI::getModuleManager()->getModuleConfig('financial');
check('R1 its own migration: bank_account.reconcile_from (nullable — every existing account stays what it was) and bank_anchor (one row per bank account and day), with a down(); module-agnostic (Rule 9): the check names no consumer and NO account number — the two bank accounts come from the mandator record, financialConfig carries only the voucher threshold; the close check is registered for `period-close` and `vat-return`',
    str_contains($c4Migration, 'ALTER TABLE bank_account ADD reconcile_from DATE DEFAULT NULL') && str_contains($c4Migration, 'CREATE TABLE bank_anchor ') && str_contains($c4Migration, 'UNIQUE INDEX uniq_bank_anchor_day (bank_account_id, as_of)')
    && str_contains($c4Migration, 'DROP TABLE bank_anchor') && str_contains($c4Migration, 'ALTER TABLE bank_account DROP reconcile_from')
    && preg_match('/Module\\\\(Creditor|Debtor|Receipt|Dms)|creditor_|debtor_|receipt_/', $c4Src) === 0 && !str_contains($bodyOf($c4Src), '1090') && !str_contains($bodyOf($c4Src), '2850')
    && $finConfig->get('bankPosting') === ['voucherFromGross' => '100.00']
    && in_array(\Z77\Module\Financial\Close\BankReconciliationCheck::class, $finConfig->get('openWorkChecks')['period-close'], true)
    && $finConfig->get('openWorkChecks')['vat-return'] === [\Z77\Module\Financial\Close\BankReconciliationCheck::class]
    && in_array(\Z77\Module\Financial\Entities\BankAnchor::class, $finConfig->get('doctrineEntities'), true));

// The other two bank accounts are switched off for this section: a switched-off account says nothing at the close (decision 3).
foreach (['haus', 'spar'] as $code) {
    $em = $wire();
    (new BankAccountService($em))->update((new BankAccountService($em))->find($code), ['statement_import' => false]);
}
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->save(new Account(['number' => '1023', 'name' => 'Bank Abgleich', 'type' => 'asset']));
const THIRD_IBAN = 'CH7100762000000001234';
$em = $wire();
(new BankAccountService($em))->save(new BankAccount(['code' => 'abgl', 'label' => 'Abgleichkonto', 'iban' => THIRD_IBAN, 'ledger_account' => '1023', 'statement_import' => true, 'mixed_use' => true]));
// The account as the CURRENT request reads it (`$reconOf($wire())->…($abgl())` — one EntityManager for both).
$abgl   = static fn(): BankAccount => (new BankAccountService(\Z77\Core\DI::getUnifiedEntityManager()))->find('abgl');
$abglId = (int) $abgl()->getId();
$book   = static function (string $date, string $text, array $lines, ?array $source = null) use ($wire): \Z77\Module\Financial\Ledger\EntryRef {
    $em      = $wire();
    $request = $source === null
        ? \Z77\Module\Financial\Ledger\PostingRequest::manual(new \DateTimeImmutable($date), $text, $lines)
        : \Z77\Module\Financial\Ledger\PostingRequest::generated(new \DateTimeImmutable($date), $text, $source[0], $source[1], $source[0] . ':' . $source[1], $lines);

    return $em->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(static fn() => (new \Z77\Module\Financial\Services\LedgerService($em, 'tester'))->post($request));
};
$S = static fn(string $account, string $amount) => \Z77\Module\Financial\Ledger\PostingLine::debit($account, chf($amount));
$H = static fn(string $account, string $amount) => \Z77\Module\Financial\Ledger\PostingLine::credit($account, chf($amount));
// The books knew the account before the bank drives it: 1'000.00 on 1023 at the end of June.
$book('2026-06-30', 'Stand vor dem Abgleich', [$S('1023', '1000.00'), $H('2850', '1000.00')]);
$july = [
    ['ref' => 'R-01', 'date' => '2026-06-20', 'amount' => '100.00', 'info' => 'Zahlung', 'creditor' => 'Alt Lieferant'],
    $card('R-02', '2026-07-03', '200.00', 'PAPETERIE MUSTER BERN'),
    ['ref' => 'R-03', 'date' => '2026-07-10', 'amount' => '500.00', 'credit' => true, 'code' => 'PMNT/RCDT/DMCT', 'info' => 'Gutschrift', 'debtor' => 'Einlage Beispiel'],
    $card('R-04', '2026-07-15', '50.00', 'KIOSK BEISPIEL'),
    ['ref' => 'R-05', 'date' => '2026-07-20', 'amount' => '80.00', 'info' => 'Zahlung', 'creditor' => 'Fremd AG'],
    $card('R-06', '2026-07-28', '54.05', 'BUERO KLEIN'),
    ['ref' => 'R-08', 'date' => '2026-07-29', 'amount' => '400.00', 'info' => 'Übertrag', 'creditor' => 'Eigenes Konto'],
];
$importer($wire())->import(camt053(['msg' => 'MSG-R1', 'stmt' => 'STMT-R1', 'iban' => THIRD_IBAN, 'opening' => '4000.00', 'from' => '2026-06-15', 'to' => '2026-07-31'], $july), 'abgleich-juli.xml');
$reconOf = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em, bool $withOwner = true) => new \Z77\Module\Financial\Bank\BankReconciliation(
    $em, 'tester', new \Z77\Module\Financial\Bank\BankEntryOwners($withOwner ? [HarnessBankEntryOwner::class] : [])
);
$day = static fn(string $ymd): \DateTimeImmutable => new \DateTimeImmutable($ymd);
$rAll = ['R-01', 'R-02', 'R-03', 'R-04', 'R-05', 'R-06', 'R-08'];

$noDate       = [$abgl()->isBankDriven(), $stand(...$rAll), $wire()->getRepository(BankMovement::class)->stateCounts($abglId)];
$noDatePost   = caught(static fn() => $postsOf($wire())->post($mvId('R-02'), 'account', '6500', null), BankRefusedException::class);
$noDateCheck  = $reconOf($wire())->check($abgl(), $day('2026-07-01'), $day('2026-07-31'));
$em           = $wire();
$badDate      = caught(static fn() => (new BankAccountService($em))->update((new BankAccountService($em))->find('abgl'), ['reconcile_from' => '31.13.2026']), InvalidBankAccountException::class);
$em           = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('abgl'), ['reconcile_from' => '01.07.2026']);
check('R2 «Abgleich ab» (decision 1 — no modes): with the switch on but NO date the account is an information source as before — no movement has a state, nothing can be decided (a German sentence naming the missing date), no check; a value that is no date is refused on the field; set (also typed TT.MM.JJJJ), the account is bank-driven from that day',
    $noDate === [false, array_fill(0, 7, ''), ['review' => 0, 'business' => 0, 'private' => 0]]
    && $noDatePost instanceof BankRefusedException && $noDatePost->reason === BankRefusedException::NOT_DRIVEN && str_contains($noDatePost->getMessage(), 'kein «Abgleich ab» gesetzt')
    && $noDateCheck === null && $badDate instanceof InvalidBankAccountException && isset($badDate->validator->getFieldErrors()['reconcile_from'])
    && $row("SELECT reconcile_from FROM bank_account WHERE code = 'abgl'") === ['reconcile_from' => '2026-07-01'] && $abgl()->isBankDriven()
    && $abgl()->drives($day('2026-07-01')) && !$abgl()->drives($day('2026-06-30')), json_encode([$noDate, $noDatePost?->getMessage()], JSON_UNESCAPED_UNICODE));

$beforePrivate = caught(static fn() => $statesOf($wire())->markPrivate($mvId('R-01')), BankRefusedException::class);
$beforePost    = caught(static fn() => $postsOf($wire())->post($mvId('R-01'), 'account', '6500', null), BankRefusedException::class);
check('R3 the states are DERIVED under the switch and the date: every movement from «Abgleich ab» is «zu prüfen» the moment the date is set, the one before it has NO state — information only: not counted, «Privat» and «Geschäftlich buchen» refused with a sentence naming the date, nothing written',
    $stand(...$rAll) === ['', 'review', 'review', 'review', 'review', 'review', 'review']
    && $wire()->getRepository(BankMovement::class)->stateCounts($abglId) === ['review' => 6, 'business' => 0, 'private' => 0]
    && $beforePrivate instanceof BankRefusedException && $beforePrivate->reason === BankRefusedException::NOT_DRIVEN && str_contains($beforePrivate->getMessage(), 'liegt vor «Abgleich ab» (01.07.2026)')
    && $beforePost instanceof BankRefusedException && $beforePost->reason === BankRefusedException::NOT_DRIVEN
    && $count('bank_movement_mark', 'movement_id = ' . $mvId('R-01')) === 0 && $count('bank_posting', 'movement_id = ' . $mvId('R-01')) === 0, json_encode($stand(...$rAll)));

// ── the anchor (decision 22) ──
$open      = $reconOf($wire())->anchor($abgl());
$useRequest(['account' => 'abgl']);
$host = $movementHost();
$host->listAction();
$openPage  = $render('Backend/BankMovementController/listAction', $host->context);
$wrongSum  = caught(static fn() => $reconOf($wire())->confirm($abgl(), $day('2026-07-01'), '1.00'), BankRefusedException::class);
$wrongDay  = caught(static fn() => $reconOf($wire())->confirm($abgl(), $day('2026-08-01'), '2900.00'), BankRefusedException::class);
$noAnchor  = $count('bank_anchor');
$reconOf($wire())->confirm($abgl(), $day('2026-07-01'), '2900.00');
$confirmed = $reconOf($wire())->anchor($abgl());
$again     = caught(static fn() => $reconOf($wire())->confirm($abgl(), $day('2026-07-01'), '2900.00'), BankRefusedException::class);
check('R4 the anchor: «Bank 3900.00 · Fibu 1000.00 · Differenz 2900.00» at the opening of «Abgleich ab» — the bank balance from the first statement and the movements BEFORE the day, the ledger account as the books stood; confirmed, the difference is stored (who / when); a difference the office did not see, another day, and a second confirmation are refused — nothing stored',
    $open['state'] === 'open' && $open['asOf']->format('Y-m-d') === '2026-07-01' && $open['bank']->toDecimal() === '3900.00' && $open['ledger']->toDecimal() === '1000.00' && $open['difference']->toDecimal() === '2900.00' && $open['expected'] === null
    && $wrongSum instanceof BankRefusedException && $wrongSum->reason === BankRefusedException::ANCHOR && $wrongDay instanceof BankRefusedException && $noAnchor === 0
    && $rowS('SELECT as_of, bank_balance, ledger_balance, difference, confirmed_by FROM bank_anchor') === ['as_of' => '2026-07-01', 'bank_balance' => '3900.00', 'ledger_balance' => '1000.00', 'difference' => '2900.00', 'confirmed_by' => 'tester']
    && $confirmed['state'] === 'confirmed' && $confirmed['expected']->toDecimal() === '2900.00' && $again instanceof BankRefusedException && $count('bank_anchor') === 1
    // … and while it is open, the list «Bewegungen» says so in ONE line with «Bestätigen …» into the window «Abgleich …»
    && substr_count($openPage, 'data-anchor-open') === 1 && str_contains($openPage, e("Differenz 2'900.00")) && str_contains($openPage, 'data-window-open="/backend/finance/bank-movement/reconcile?account=abgl"')
    // … «Bestätigen …» is a BUTTON, not a link at the end of the sentence (owner 2026-11-18: it was not seen as clickable)
    && preg_match('/<a class="be-btn be-btn--ghost be-btn--sm"[^>]*>Bestätigen …<\/a>/', $openPage) === 1,
    json_encode([$open['state'], $open['bank']?->toDecimal(), $open['ledger']->toDecimal(), $wrongSum?->getMessage(), $again?->getMessage()], JSON_UNESCAPED_UNICODE));

// ── the check (decisions 23, 25) ──
$julyCheck = static fn(bool $withOwner = true) => $reconOf($wire(), $withOwner)->check($abgl(), $day('2026-07-01'), $day('2026-07-31'));
$figures   = static fn(?\Z77\Module\Financial\Bank\BankCheck $c): array => $c === null ? [] : [
    'review' => $c->review, 'business' => $c->business->toDecimal(), 'private' => $c->private->toDecimal(), 'ledger' => $c->ledger->toDecimal(),
    'difference' => $c->difference()->toDecimal(), 'entries' => $c->entryCount, 'unbooked' => $c->unbooked['count'], 'passes' => $c->passes(),
];
$first   = $julyCheck();
$clamped = $reconOf($wire())->check($abgl(), $day('2026-06-01'), $day('2026-07-31'));
$august  = $reconOf($wire())->check($abgl(), $day('2026-08-01'), $day('2026-08-31'));
check('R5 the check per period: only the bank-driven part counts (a period before «Abgleich ab» has no check, a period across it starts at the date); undecided movements are counted and fail it; a period the statement does not reach fails with the date it is read to',
    $figures($first) === ['review' => 6, 'business' => '0.00', 'private' => '0.00', 'ledger' => '0.00', 'difference' => '0.00', 'entries' => 0, 'unbooked' => 0, 'passes' => false]
    && count($first->problems()) === 1 && str_contains($first->problems()[0], '6 Bewegungen sind noch «zu prüfen»') && str_contains($first->problems()[0], 'Abgleichkonto')
    && $reconOf($wire())->check($abgl(), $day('2026-06-01'), $day('2026-06-30')) === null && $clamped->from->format('Y-m-d') === '2026-07-01' && $clamped->review === 6
    && $first->isReadFrom() && $first->isReadTo() && !$august->isReadTo() && str_contains(implode(' ', $august->problems()), 'nur bis 31.07.2026 eingelesen — bis 31.08.2026 einlesen'),
    json_encode([$figures($first), $first->problems(), $august->problems()], JSON_UNESCAPED_UNICODE));

$transferBefore = (string) $db->fetchOne("SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '1090'");
$postsOf($wire())->post($mvId('R-02'), 'account', '6500', null);
$statesOf($wire())->markPrivate($mvId('R-03'), true);
$statesOf($wire())->markPrivate($mvId('R-04'), true);
$inUnit(static fn($em) => $linksOf($em)->link($mvId('R-05'), 'test', 'test:1', 'Fremdbeleg 1', 'manual'));
$postsOf($wire())->post($mvId('R-06'), 'account', '6500', 'VI');
$postsOf($wire())->post($mvId('R-08'), 'transfer', '', null);
$decided = $julyCheck();
check('R6 every movement decided: the business movements (two direct postings, a transfer, one linked by another module) against the change of the ledger account — the linked record is NOT posted yet, so the check names the difference and «1 geschäftliche Bewegung ohne Buchung»; private movements are in neither sum',
    $figures($decided) === ['review' => 0, 'business' => '-734.05', 'private' => '450.00', 'ledger' => '-654.05', 'difference' => '80.00', 'entries' => 0, 'unbooked' => 1, 'passes' => false]
    && $decided->unbooked['sum']->toDecimal() === '-80.00' && count($decided->problems()) === 1 && str_contains($decided->problems()[0], 'Differenz 80.00')
    && str_contains($decided->problems()[0], '1 geschäftliche Bewegung ohne Buchung') && str_contains($decided->problems()[0], 'Konto 1023'),
    json_encode([$figures($decided), $decided->problems()], JSON_UNESCAPED_UNICODE));

$foreignRef = $book('2026-07-20', 'Fremdbeleg 1', [$S('6500', '80.00'), $H('1023', '80.00')], ['test', '1']);
$joined     = $julyCheck();
$unknown    = $julyCheck(false);
check('R7 the owning module posts its record: its entry is joined to the movement through the link\'s owner key (`bankEntryOwners` — financial resolves no key itself) and the check PASSES; without the module\'s answer the same posting is a «Buchung ohne Bewegung», named by its number, and the check fails although the sums agree',
    $figures($joined) === ['review' => 0, 'business' => '-734.05', 'private' => '450.00', 'ledger' => '-734.05', 'difference' => '0.00', 'entries' => 0, 'unbooked' => 0, 'passes' => true]
    && $figures($unknown) === ['review' => 0, 'business' => '-734.05', 'private' => '450.00', 'ledger' => '-734.05', 'difference' => '0.00', 'entries' => 1, 'unbooked' => 1, 'passes' => false]
    && $unknown->entries[0]['ref'] === $foreignRef->fiscalYear . '/' . $foreignRef->number && $unknown->entries[0]['amount'] === '-80.00' && $unknown->entries[0]['note'] === ''
    && str_contains($unknown->problems()[0], '1 Buchung ohne Bewegung: ' . $foreignRef->fiscalYear . '/' . $foreignRef->number),
    json_encode([$figures($joined), $figures($unknown), $unknown->problems()], JSON_UNESCAPED_UNICODE));

$strayRef = $book('2026-07-25', 'Buchung ohne Bank', [$S('6500', '30.00'), $H('1023', '30.00')], ['other', 'x1']);
$stray    = $julyCheck();
$em       = $wire();
$em->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(static fn() => (new \Z77\Module\Financial\Services\LedgerService($em, 'tester'))->reverse($strayRef, new \DateTimeImmutable('2026-07-25'), 'Storno Buchung ohne Bank'));
$undone   = $julyCheck();
check('R8 a posting on the bank\'s ledger account without a bank movement: the difference and the posting are named («Buchung ohne Bewegung»); reversed on its own date, entry and reversal net to zero and are no finding — the check passes again',
    $figures($stray) === ['review' => 0, 'business' => '-734.05', 'private' => '450.00', 'ledger' => '-764.05', 'difference' => '-30.00', 'entries' => 1, 'unbooked' => 0, 'passes' => false]
    && $stray->entries[0]['ref'] === $strayRef->fiscalYear . '/' . $strayRef->number && $stray->entries[0]['text'] === 'Buchung ohne Bank'
    && $figures($undone)['passes'] === true && $figures($undone)['entries'] === 0 && $figures($undone)['ledger'] === '-734.05',
    json_encode([$figures($stray), $figures($undone)], JSON_UNESCAPED_UNICODE));

// ── the close (decisions 25, 26) ──
$closeOf  = static fn() => new \Z77\Module\Financial\Close\BankReconciliationCheck($wire(), new \Z77\Module\Financial\Bank\BankEntryOwners([HarnessBankEntryOwner::class]));
$findings = static function (string $scope, string $from, string $to) use ($closeOf): array {
    $out = ['blocking' => [], 'warning' => []];
    foreach ($closeOf()->check($scope, ['fiscalYear' => '2026', 'from' => new \DateTimeImmutable($from), 'to' => new \DateTimeImmutable($to)]) as $finding) {
        $out[$finding->severity === \Z77\Persistence\Doctrine\OpenWork\Severity::Blocking ? 'blocking' : 'warning'][] = $finding->message;
    }

    return $out;
};
$oneSided    = $findings('vat-return', '2026-07-01', '2026-07-31');
$transferNow = chf($transferBefore)->add(chf('400.00'));
// The other side of the transfer (the receiving account is not bank-driven here): booked by hand, the transfer account is at zero.
$book('2026-07-29', 'Übertrag Gegenseite', [$S('1020', $transferNow->toDecimal()), $H('1090', $transferNow->toDecimal())]);
$vatJuly = $findings('vat-return', '2026-07-01', '2026-07-31');
$yearEnd = $findings('period-close', '2026-01-01', '2026-12-31');
$june    = $findings('vat-return', '2026-04-01', '2026-06-30');
$registry = \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks::fromModules(\Z77\Core\DI::getModuleManager());
$viaVat   = $registry->ask('vat-return', ['from' => new \DateTimeImmutable('2026-07-01'), 'to' => new \DateTimeImmutable('2026-07-31')]);
$viaYear  = $registry->ask('period-close', ['fiscalYear' => '2026', 'from' => new \DateTimeImmutable('2026-01-01'), 'to' => new \DateTimeImmutable('2026-12-31')]);
$messages = static fn(array $list): string => implode(' | ', array_map(static fn($f) => $f->message, $list));
check('R9 the close through the open-work registry: a transfer with one side missing BLOCKS (the transfer account of the mandator, named with its balance — no number assumed); with both sides booked the VAT return of July is free and carries the control line «1 Buchung mit Vorsteuer ohne Beleg · CHF 4.05 Vorsteuer» as a WARNING; the year close is BLOCKED because the statement is read to 31.07. only; a period before «Abgleich ab» says nothing; the check answers the scopes `vat-return` and `period-close` of the real registry (there, without the owning module, the foreign posting blocks)',
    count($oneSided['blocking']) === 1 && str_contains($oneSided['blocking'][0], 'Übertrag eigene Bankkonten: Konto 1090 Transferkonto steht per 31.07.2026 auf ' . \Z77\Shared\Money\AmountFormat::of($transferNow) . ' statt 0.00')
    && $vatJuly === ['blocking' => [], 'warning' => ['1 Buchung mit Vorsteuer ohne Beleg · CHF 4.05 Vorsteuer']]
    && count($yearEnd['blocking']) === 1 && str_contains($yearEnd['blocking'][0], 'nur bis 31.07.2026 eingelesen — bis 31.12.2026 einlesen') && $yearEnd['warning'] === []
    && $june === ['blocking' => [], 'warning' => []]
    && $viaVat->isBlocked() && str_contains($messages($viaVat->blocking()), '1 Buchung ohne Bewegung') && str_contains($messages($viaVat->warnings()), 'mit Vorsteuer ohne Beleg')
    && $viaYear->isBlocked() && str_contains($messages($viaYear->blocking()), 'bis 31.12.2026 einlesen'),
    json_encode([$oneSided, $vatJuly, $yearEnd, $june, $messages($viaVat->blocking())], JSON_UNESCAPED_UNICODE));

// ── a posting in another period than its movement (what a module that posts on its own date produces) ──
$importer($wire())->import(camt053(['msg' => 'MSG-R2', 'stmt' => 'STMT-R2', 'iban' => THIRD_IBAN, 'opening' => '3615.95', 'from' => '2026-07-31', 'to' => '2026-08-31'], [
    ['ref' => 'R-07', 'date' => '2026-08-03', 'amount' => '300.00', 'credit' => true, 'code' => 'PMNT/RCDT/VCOM', 'info' => 'Sammelgutschrift'],
]), 'abgleich-august.xml');
$inUnit(static fn($em) => $linksOf($em)->link($mvId('R-07'), 'test', 'test:2', 'Zahlungseingang · 1 Zahlung', 'reference'));
$shiftRef = $book('2026-07-31', 'Zahlung Kunde', [$S('1023', '300.00'), $H('3200', '300.00')], ['test', '2']);
$shiftJul = $julyCheck();
$shiftAug = $reconOf($wire())->check($abgl(), $day('2026-08-01'), $day('2026-08-31'));
$shiftAll = $reconOf($wire())->check($abgl(), $day('2026-07-01'), $day('2026-08-31'));
check('R10 a posting dated in ANOTHER period than the bank books its movement (a customer payment posted on its own date): nothing is hidden — the posting\'s period names it as «Buchung ohne Bewegung» with the day of its movement, the movement\'s period counts a business movement without a posting, each with the difference; over both periods the check passes',
    $figures($shiftJul) === ['review' => 0, 'business' => '-734.05', 'private' => '450.00', 'ledger' => '-434.05', 'difference' => '300.00', 'entries' => 1, 'unbooked' => 0, 'passes' => false]
    && $shiftJul->entries[0]['ref'] === $shiftRef->fiscalYear . '/' . $shiftRef->number && $shiftJul->entries[0]['note'] === 'Bewegung am 03.08.2026'
    && $figures($shiftAug) === ['review' => 0, 'business' => '300.00', 'private' => '0.00', 'ledger' => '0.00', 'difference' => '-300.00', 'entries' => 0, 'unbooked' => 1, 'passes' => false]
    && $figures($shiftAll)['passes'] === true && $figures($shiftAll)['difference'] === '0.00',
    json_encode([$figures($shiftJul), $figures($shiftAug), $figures($shiftAll)], JSON_UNESCAPED_UNICODE));

// ── the year change (decision 24) ──
$importer($wire())->import(camt053(['msg' => 'MSG-R3', 'stmt' => 'STMT-R3', 'iban' => THIRD_IBAN, 'opening' => '3915.95', 'from' => '2026-08-31', 'to' => '2026-12-31'], []), 'abgleich-rest.xml');
$yearOk = $findings('period-close', '2026-01-01', '2026-12-31');
$em = $wire();
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new \Z77\Module\Financial\Entities\FiscalYear('2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31')));
$noOpening = $reconOf($wire())->anchor($abgl(), $day('2027-01-01'));
// The year opening of 2027 carries the ledger account's closing balance (1000.00 − 434.05) against the opening contra account.
$book('2027-01-01', 'Eröffnung 1023', [$S('1023', '565.95'), $H('9100', '565.95')], ['opening', '2027']);
$computed  = $reconOf($wire())->anchor($abgl(), $day('2027-01-01'));
$nothing   = caught(static fn() => $reconOf($wire())->confirm($abgl(), $day('2027-01-01'), '3350.00'), BankRefusedException::class);
check('R11 the year change: read to the year end and everything decided, the year close is free; the anchor of the new year is COMPUTED — the confirmed difference plus the private net of the year (2900.00 + 450.00) — and compared with the bank balance and the new year\'s opening on the ledger account: without the opening it does not agree (a mismatch, shown), with it the state is «computed» and there is NOTHING to confirm; the opening entry is no change of the period',
    $yearOk === ['blocking' => [], 'warning' => []]
    && $noOpening['state'] === 'mismatch' && $noOpening['asOf']->format('Y-m-d') === '2027-01-01' && $noOpening['bank']->toDecimal() === '3915.95' && $noOpening['ledger']->toDecimal() === '0.00' && $noOpening['expected']->toDecimal() === '3350.00'
    && $computed['state'] === 'computed' && $computed['ledger']->toDecimal() === '565.95' && $computed['difference']->toDecimal() === '3350.00' && $computed['expected']->toDecimal() === '3350.00'
    && $nothing instanceof BankRefusedException && $nothing->reason === BankRefusedException::ANCHOR && $count('bank_anchor') === 1
    && $reconOf($wire())->check($abgl(), $day('2027-01-01'), $day('2027-01-31'))->ledger->toDecimal() === '0.00',
    json_encode([$yearOk, $noOpening['state'], $noOpening['expected']?->toDecimal(), $computed['state'], $computed['difference']?->toDecimal()], JSON_UNESCAPED_UNICODE));

// ── the screens through the host doubles ──
$useRequest(['id' => (string) $abglId], null, true);
$host = $accountHost();
$host->editAction();
$accountWindow = $render('Backend/BankAccountController/edit', $host->context);
$useRequest(['id' => (string) $abglId], ['label' => 'Abgleichkonto', 'iban' => THIRD_IBAN, 'ledger_account' => '1023', 'statement_import' => '1', 'mixed_use' => '1', 'reconcile_from' => '2026-02-30', 'entity_csrf' => 'tok-bankAccount-' . $abglId, '_origin' => 'region:' . $region], true);
$host = $accountHost();
$host->editAction();
$accountRefused = $render('Backend/BankAccountController/edit', $host->context);
$useRequest(['id' => (string) $abglId], ['label' => 'Abgleichkonto', 'iban' => THIRD_IBAN, 'ledger_account' => '1023', 'statement_import' => '1', 'mixed_use' => '1', 'entity_csrf' => 'tok-bankAccount-' . $abglId, '_origin' => 'region:' . $region], true);
$host = $accountHost();
$host->editAction();
$keptDate = $row("SELECT reconcile_from FROM bank_account WHERE code = 'abgl'")['reconcile_from'];
$useRequest([]);
$host = $accountHost();
$host->listAction();
$accountList = $render('Backend/BankAccountController/listAction', $host->context);
$accountHelp = $render('Backend/BankAccountController/edit.help', []);
check('S12 «Bankkonten»: the window carries the date field «Abgleich ab» next to the switch (a label, nothing else — the help explains it in a field section); a value that is no date re-renders the window with the sentence on the field and stores nothing; a form WITHOUT the field leaves the date alone; the list row says «Abgleich ab 01.07.2026» for a bank-driven account only',
    preg_match('/<label for="bank-account-reconcile_from">Abgleich ab<\/label>\s*<input type="date" id="bank-account-reconcile_from" name="reconcile_from" value="2026-07-01"/', $accountWindow) === 1
    && str_contains($accountRefused, 'ist kein Datum') && str_contains($accountRefused, 'aria-invalid="true"') && $keptDate === '2026-07-01'
    && substr_count($accountList, 'data-bank-driven') === 1 && str_contains($accountList, 'Abgleich ab 01.07.2026')
    && str_contains($accountHelp, 'data-help-field="reconcile_from"') && str_contains($accountHelp, 'nur noch von der Bank'));

$useRequest(['account' => 'abgl']);
$host = $movementHost();
$host->listAction();
$abglPage    = $render('Backend/BankMovementController/listAction', $host->context);
$abglToolbar = $render('Backend/BankMovementController/toolbar', $host->context);
$abglCounts  = $host->context['counts'];
$useRequest(['id' => (string) $mvId('R-01')], null, true);
$host = $movementHost();
$host->editAction();
$infoWindow = $render('Backend/BankMovementController/edit', $host->context);
$useRequest(['id' => (string) $mvId('R-01')], ['op' => 'private', '_origin' => 'region:' . $bmRegion], true);
$host = $movementHost();
$infoPost = $host->editAction();
check('S13 «Bankkonto» of a bank-driven account: the list head names «Abgleich ab», the row BEFORE the date has an empty «Stand» and no checkbox, its window says «nur Auskunft» and offers nothing to decide (a forged POST is refused with the sentence); the toolbar carries the tool «Abgleich …» — a window',
    str_contains($abglPage, 'Abgleich ab 01.07.2026') && !str_contains($abglPage, 'data-anchor-open') && preg_match('/data-movement-id="' . $mvId('R-01') . '" data-movement-state=""/', $abglPage) === 1
    && substr_count($abglPage, 'name="mv[]"') === $abglCounts['review'] + $abglCounts['private'] && $abglCounts === ['review' => 0, 'business' => 5, 'private' => 2]
    && str_contains($infoWindow, 'data-movement-window=""') && str_contains($infoWindow, 'nur Auskunft') && !str_contains($infoWindow, 'name="op" value="book"') && !str_contains($infoWindow, '>Buchen</button>')
    && $envelope($infoPost)['status'] === 'error' && str_contains($host->messageService->flashes[0][1], 'liegt vor «Abgleich ab»') && $stand('R-01') === ['']
    && str_contains($abglToolbar, 'data-window-open="/backend/finance/bank-movement/reconcile?account=abgl"') && str_contains($abglToolbar, '>Abgleich …</a>'),
    json_encode([$abglCounts, $host->messageService->flashes], JSON_UNESCAPED_UNICODE));

$useRequest(['account' => 'abgl', 'year' => '2026'], null, true);
$host = $movementHost();
$host->reconcileAction();
$recon2026 = $render('Backend/BankMovementController/reconcile', $host->context);
// The opening is corrected after the fact: the computed anchor no longer agrees — the window asks, the office confirms.
$book('2027-01-02', 'Eröffnung 1023 Korrektur', [$S('1023', '10.00'), $H('9100', '10.00')]);
$useRequest(['account' => 'abgl', 'year' => '2027'], null, true);
$host = $movementHost();
$host->reconcileAction();
$recon2027 = $render('Backend/BankMovementController/reconcile', $host->context);
$useRequest(['account' => 'abgl', 'year' => '2027'], ['as_of' => '2027-01-01', 'difference' => '3340.00', '_origin' => 'region:' . $bmRegion], true);
$host = $movementHost();
$host->reconcileAction();
$reconDone = $render('Backend/BankMovementController/reconcile', $host->context);
$useRequest(['account' => 'spar'], null, true);
$host = $movementHost();
$notDriven = $host->reconcileAction();
$reconHelp = $render('Backend/BankMovementController/reconcile.help', []);
check('S14 the window «Abgleich …»: the anchor line «Bank … · Fibu … · Differenz …» with its state, one row per period of the fiscal year (Bank geschäftlich · Fibu · Differenz · Zu prüfen · Ohne Bewegung · Stand), the postings without a movement as links into the journal, the transfer account and «Vorsteuer ohne Beleg»; labels and figures only — the help explains; «Differenz bestätigen» appears only while there is something to confirm (the new year after its opening changed), its POST stores the anchor of that day; an account that is not bank-driven has no such window',
    str_contains($recon2026, 'data-window="bank-reconcile"') && str_contains($recon2026, 'data-reconcile-anchor="confirmed"') && str_contains($recon2026, e("Bank 3'900.00 · Fibu 1'000.00 · "))
    && str_contains($recon2026, e("Differenz 2'900.00")) && !str_contains($recon2026, 'Differenz bestätigen') && substr_count($recon2026, 'data-reconcile-period="') === 6
    && preg_match('/data-reconcile-period="2026-09" data-reconcile-ok="1"/', $recon2026) === 1 && preg_match('/data-reconcile-period="2026-07" data-reconcile-ok="0"/', $recon2026) === 1
    && substr_count($recon2026, 'data-reconcile-entry="2026/') === 2 && str_contains($recon2026, 'data-window-open="/backend/finance/journal/detail?ref=' . rawurlencode($shiftRef->fiscalYear . '/' . $shiftRef->number) . '"')
    && str_contains($recon2026, 'data-reconcile-transfer') && str_contains($recon2026, '1 Buchung · CHF 4.05 Vorsteuer') && preg_match('/<p[^>]*>[^<]{60,}<\/p>/', $recon2026) === 0
    && str_contains($recon2027, 'data-reconcile-anchor="mismatch"') && str_contains($recon2027, '>Differenz bestätigen</button>') && str_contains($recon2027, 'name="as_of" value="2027-01-01"') && str_contains($recon2027, 'name="difference" value="3340.00"')
    && str_contains($reconDone, 'data-reconcile-anchor="confirmed"') && !str_contains($reconDone, 'Differenz bestätigen') && $count('bank_anchor') === 2
    && $rowS("SELECT difference, confirmed_by FROM bank_anchor WHERE as_of = '2027-01-01'") === ['difference' => '3340.00', 'confirmed_by' => 'tester']
    && $envelope($notDriven)['status'] === 'error' && str_contains($reconHelp, 'Buchungen ohne Bewegung') && str_contains($reconHelp, 'gesperrt'),
    json_encode([substr_count($recon2026, 'data-reconcile-period="'), substr_count($recon2026, 'data-reconcile-entry="2026/'), $count('bank_anchor')]));

// ── switching off (decision 3) ──
$linksBefore = [$count('bank_link'), $count('bank_posting'), $count('bank_anchor')];
$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('abgl'), ['statement_import' => false]);
$offStates = $stand(...$rAll);
$offCheck  = $reconOf($wire())->check($abgl(), $day('2026-07-01'), $day('2026-07-31'));
$offClose  = $findings('period-close', '2026-01-01', '2026-12-31');
$offPost   = caught(static fn() => $statesOf($wire())->setReview($mvId('R-03')), BankRefusedException::class);
$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('abgl'), ['statement_import' => true]);
check('R12 switching «Kontoauszug einlesen» off works forward only (decision 3): links, postings and the anchor stay; no movement has a state any more, there is no check and the close says nothing about the account; switched on again, everything is back as it was — nothing was stored on a movement',
    $linksBefore === [$count('bank_link'), $count('bank_posting'), $count('bank_anchor')] && $offStates === array_fill(0, 7, '') && $offCheck === null && $offClose === ['blocking' => [], 'warning' => []]
    && $offPost instanceof BankRefusedException && $offPost->reason === BankRefusedException::NOT_DRIVEN && str_contains($offPost->getMessage(), '«Kontoauszug einlesen» ausgeschaltet')
    && $stand(...$rAll) === ['', 'business', 'private', 'private', 'business', 'business', 'business'], json_encode([$offStates, $stand(...$rAll)]));

// ── not «gemischt geführt»: a difference is corrected, never confirmed (owner 2026-11-19, ADR-061 addendum 2026-11-19) ──
$anchorRows = fn(): array => $db->fetchAllAssociative('SELECT as_of, difference, confirmed_by FROM bank_anchor ORDER BY as_of');
$reconWindow = static function (?array $post = null) use ($useRequest, $movementHost, $render, $bmRegion): string {
    $useRequest(['account' => 'abgl', 'year' => '2027'], $post === null ? null : $post + ['_origin' => 'region:' . $bmRegion], true);
    $host = $movementHost();
    $host->reconcileAction();

    return $render('Backend/BankMovementController/reconcile', $host->context);
};
$abglList = static function () use ($useRequest, $movementHost, $render): string {
    $useRequest(['account' => 'abgl']);
    $host = $movementHost();
    $host->listAction();

    return $render('Backend/BankMovementController/listAction', $host->context);
};
// As if the account had been run purely business (the service refuses switching off while its private marks exist).
$db->executeStatement("UPDATE bank_account SET mixed_use = 0 WHERE code = 'abgl'");
$nmRows   = $anchorRows();
$nmKept   = $reconOf($wire())->anchor($abgl(), $day('2027-01-01'));
// The year opening is corrected once more: the 2027 anchor no longer agrees.
$book('2027-01-03', 'Eröffnung 1023 Korrektur 2', [$S('1023', '5.00'), $H('9100', '5.00')]);
$nmWrong  = $reconOf($wire())->anchor($abgl(), $day('2027-01-01'));
$nmRefuse = caught(static fn() => $reconOf($wire())->confirm($abgl(), $day('2027-01-01'), '3335.00'), BankRefusedException::class);
$nmSay    = e((string) $nmRefuse?->getMessage());
$nmWindow = $reconWindow();
$nmForged = $reconWindow(['as_of' => '2027-01-01', 'difference' => '3335.00']);
$nmClose  = $findings('period-close', '2027-01-01', '2027-12-31');
// «Abgleich ab» on a day without a confirmed row: the expected difference is 0.00 — nothing is «open», nothing to confirm.
$db->executeStatement("UPDATE bank_account SET reconcile_from = '2026-08-01' WHERE code = 'abgl'");
$nmBase     = $reconOf($wire())->anchor($abgl());
$nmBaseNo   = caught(static fn() => $reconOf($wire())->confirm($abgl(), $day('2026-08-01'), '3050.00'), BankRefusedException::class);
$nmBaseList = $abglList();
$nmFix      = $book('2026-07-31', 'Korrektur Bank', [$S('1023', '3050.00'), $H('2850', '3050.00')], ['other', 'x2']);
$nmAgrees   = $reconOf($wire())->anchor($abgl());
$nmAgreeCl  = $findings('period-close', '2026-01-01', '2026-12-31');
$nmAgreeLs  = $abglList();
$em = $wire();
$em->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(static fn() => (new \Z77\Module\Financial\Services\LedgerService($em, 'tester'))->reverse($nmFix, new \DateTimeImmutable('2026-07-31'), 'Storno Korrektur Bank'));
$nmRowsAfter = $anchorRows();
// Back to «gemischt geführt»: the same mismatch is confirmed as before.
$db->executeStatement("UPDATE bank_account SET mixed_use = 1, reconcile_from = '2026-07-01' WHERE code = 'abgl'");
$mxWindow = $reconWindow();
$reconOf($wire())->confirm($abgl(), $day('2027-01-01'), '3335.00');
check('R14 a bank account that is NOT «gemischt geführt» confirms no difference (owner 2026-11-19): `confirm()` is refused `not-mixed` with ONE sentence (correct it by a booking, or switch «gemischt geführt» on) and nothing is written; the window shows the difference and that sentence instead of «Differenz bestätigen» (a forged POST answers it once); confirmed rows stay and count; the year close WARNS (does not block) with the same advice; at «Abgleich ab» without a row the expected difference is 0.00 — a difference is a mismatch the list names with «Abgleich …», 0.00 agrees with nothing to do and no warning; on a mixed account the button and the confirmation work as before',
    $nmKept['state'] === 'confirmed' && $nmWrong['state'] === 'mismatch' && $nmWrong['difference']->toDecimal() === '3335.00' && $nmWrong['expected']->toDecimal() === '3340.00'
    && $nmRefuse instanceof BankRefusedException && $nmRefuse->reason === BankRefusedException::NOT_MIXED
    && str_contains($nmRefuse->getMessage(), 'nicht «gemischt geführt»') && str_contains($nmRefuse->getMessage(), 'mit einer Buchung korrigiert') && str_contains($nmRefuse->getMessage(), '«gemischt geführt» einschalten')
    && str_contains($nmWindow, 'data-reconcile-anchor="mismatch"') && str_contains($nmWindow, e("Differenz 3'335.00")) && !str_contains($nmWindow, 'Differenz bestätigen') && !str_contains($nmWindow, 'name="as_of"')
    && str_contains($nmWindow, 'data-reconcile-not-mixed') && substr_count($nmWindow, $nmSay) === 1
    && !str_contains($nmForged, 'Differenz bestätigen') && !str_contains($nmForged, 'data-reconcile-not-mixed') && substr_count($nmForged, $nmSay) === 1
    && count($nmClose['warning']) === 1 && str_contains($nmClose['warning'][0], "(3'335.00) ist nicht 3'340.00") && str_contains($nmClose['warning'][0], 'mit einer Buchung korrigieren') && !str_contains($nmClose['warning'][0], 'bestätigen')
    && $nmBase['state'] === 'mismatch' && $nmBase['anchor'] === null && $nmBase['expected']->toDecimal() === '0.00' && $nmBase['difference']->toDecimal() === '3050.00'
    && $nmBaseNo instanceof BankRefusedException && $nmBaseNo->reason === BankRefusedException::NOT_MIXED
    && substr_count($nmBaseList, 'data-anchor-open') === 1 && str_contains($nmBaseList, '>Abgleich …</a>') && !str_contains($nmBaseList, 'Bestätigen …')
    && $nmAgrees['state'] === 'computed' && $nmAgrees['difference']->toDecimal() === '0.00' && $nmAgreeCl['warning'] === [] && !str_contains($nmAgreeLs, 'data-anchor-open')
    && $nmRowsAfter === $nmRows && count($nmRows) === 2
    && str_contains($mxWindow, '>Differenz bestätigen</button>') && !str_contains($mxWindow, 'data-reconcile-not-mixed')
    && $rowS("SELECT difference, confirmed_by FROM bank_anchor WHERE as_of = '2027-01-01'") === ['difference' => '3335.00', 'confirmed_by' => 'tester'] && $count('bank_anchor') === 2,
    json_encode([$nmKept['state'], $nmWrong['state'], $nmWrong['difference']?->toDecimal(), $nmRefuse?->getMessage(), $nmClose, $nmBase['state'], $nmBase['difference']?->toDecimal(), $nmAgrees['state'], $nmAgreeCl, $nmRowsAfter], JSON_UNESCAPED_UNICODE));

// The section's own account is switched off again; the other two read statements as before.
$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('abgl'), ['statement_import' => false]);
foreach (['haus', 'spar'] as $code) {
    $em = $wire();
    (new BankAccountService($em))->update((new BankAccountService($em))->find($code), ['statement_import' => true]);
}

// ── the archive on the shared store, and the lost race ──
$archiveSrc = (string) file_get_contents($root . '/packages/module-financial/src/Bank/BankStatementArchive.php');
$importSrc  = (string) file_get_contents($root . '/packages/module-financial/src/Bank/BankStatementService.php');
$raceXml    = camt053(['msg' => 'MSG-RACE', 'stmt' => 'STMT-RACE', 'iban' => OTHER_IBAN, 'opening' => $row('SELECT closing_balance FROM bank_statement WHERE bank_account_id = ' . $sparId . ' ORDER BY id DESC LIMIT 1')['closing_balance'], 'from' => '2026-05-31', 'to' => '2026-06-30'], [$card('C-30', '2026-06-02', '5.00', 'BEISPIEL MARKT LUZERN')]);
$racePath   = BankStatementArchive::pathFor(hash('sha256', $raceXml), new \DateTimeImmutable('2026-06-30'), 'spar');
// What the LOSER of two imports of one file does after its unit of work rolled back: it wrote the file, the winner's row names it.
$loser = new BankStatementService($wire(), 'tester');
$lost  = new BankStatementArchive();
$lost->store($raceXml, hash('sha256', $raceXml), new \DateTimeImmutable('2026-06-30'), 'spar');
$discard = new \ReflectionMethod($loser, 'discardUnnamed');
$importer($wire())->import($raceXml, 'race.xml');                         // the winner commits a row naming the file
$discard->invoke($loser, $lost, hash('sha256', $raceXml));
$keptForWinner = is_file($base . '/' . $racePath);
$orphan = new BankStatementArchive();
$orphanPath = $orphan->store('<orphan/>', hash('sha256', '<orphan/>'), new \DateTimeImmutable('2026-06-30'), 'spar');
$discard->invoke($loser, $orphan, hash('sha256', '<orphan/>'));
check('C35 financial\'s statement archive writes and reads through the shared WriteOnceStore (no file function of its own); two imports of ONE file at once: the one that lost does NOT delete the archived file the winner\'s row points to — a file no statement row names is still removed after a rollback',
    str_contains($archiveSrc, 'WriteOnceStore') && preg_match('/file_put_contents|rename\(|unlink\(|hash_file/', $bodyOf($archiveSrc)) === 0
    && $keptForWinner && file_get_contents($base . '/' . $racePath) === $raceXml && (new BankStatementArchive())->bytes($wire()->getRepository(BankStatement::class)->findBySha256(hash('sha256', $raceXml))) === $raceXml
    && !is_file($base . '/' . $orphanPath) && substr_count($importSrc, '$archive->discardWritten()') === 1);

// ── PK. the VAT accounts not offered, not taken — «Geschäftlich buchen …» and the one-line entry (VAT return stage S4, package W1-F) ──
// ADR-042 addendum 2 of 2026-11-25, point 7; ADR-061 addendum 2026-11-25; boards S4-4 (parts 1, 3) and S4-3 (part 4).
// «Abrechnung ab» is set on the mandator by SQL for this block only (the service asks for method, rhythm … as well) and
// cleared at its end. A July statement on «spar»: three payments to the tax office, one before the day is set.
echo "PK. The VAT accounts not offered and not taken as contra account; a learned proposal on one dropped; the one-line entry (database)\n";
$pkSetFrom = static fn(?string $day) => $db->executeStatement('UPDATE mandator SET vat_settlement_from = ?', [$day]);
$importer($wire())->import(camt053(['msg' => 'MSG-PK', 'stmt' => 'STMT-PK', 'iban' => OTHER_IBAN, 'opening' => $row('SELECT closing_balance FROM bank_statement WHERE bank_account_id = ' . $sparId . ' ORDER BY id DESC LIMIT 1')['closing_balance'], 'from' => '2026-06-30', 'to' => '2026-07-31'], [
    ['ref' => 'P-01', 'date' => '2026-07-05', 'amount' => '1000.00', 'info' => 'MWST 2. Quartal 2026', 'creditor' => 'ESTV Bern'],
    ['ref' => 'P-02', 'date' => '2026-07-20', 'amount' => '500.00', 'info' => 'MWST Akonto', 'creditor' => 'ESTV Bern'],
    ['ref' => 'P-03', 'date' => '2026-07-28', 'amount' => '250.00', 'info' => 'MWST Akonto', 'creditor' => 'ESTV Bern'],
]), 'pk.xml');
$pkWindow = function (string $ref, ?array $post = null) use ($useRequest, $movementHost, $mvId): object {
    $useRequest(['id' => (string) $mvId($ref)], $post, true);
    $host = $movementHost();
    $host->editAction();
    return $host;
};
$pkVatAccounts = ['1170', '1171', '2200'];
$pkOffered     = static fn(array $context): array => array_values(array_intersect($pkVatAccounts, array_map(static fn(Account $a) => $a->getNumber(), $context['accounts'])));
$pkHasRc       = static fn(array $context): bool => array_filter($context['taxCodes'], static fn($c) => $c->category() === \Z77\Module\Vat\Entities\TaxCategory::ReverseCharge) !== [];

// Before «Abrechnung ab» is set: the payment to the tax office is booked on 2200 — the pattern «ESTV Bern» learns it.
$pkSetFrom(null);
$pkPosted = $postsOf($wire())->post($mvId('P-01'), 'account', '2200', null);
$pkBefore = $pkWindow('P-02');
check('PK1 while «Abrechnung ab» is not set nothing changes: a payment to the tax office books on 2200 and the pattern learns it; the next movement of the pattern proposes 2200, the list offers 2200 / 1170 / 1171, no line under the field — but no reverse-charge code in the MWST-Code list (it goes on both sides, the posting refuses it)',
    $pkPosted->entryRef() !== null && $pkBefore->context['form']['contra'] === '2200' && $pkBefore->context['notes'] === [] && $pkOffered($pkBefore->context) === $pkVatAccounts
    && !$pkHasRc($pkBefore->context) && array_filter($pkBefore->context['taxCodes'], static fn($c) => $c->category()?->isManualOnly()) === [],
    json_encode([$pkBefore->context['form'], $pkOffered($pkBefore->context)]));

// From «Abrechnung ab» (1.7.2026) on: the proposal is dropped with one line, the VAT accounts are not in the list.
$pkSetFrom('2026-07-01');
$pkAfter  = $pkWindow('P-02');
$pkHtml   = $render('Backend/BankMovementController/edit', $pkAfter->context);
$pkAccts  = array_map(static fn(Account $a) => $a->getNumber(), $pkAfter->context['accounts']);
check('PK2 from «Abrechnung ab» on (board S4-4, part 1; ADR-061 addendum 2026-11-25): the learned proposal 2200 is DROPPED — «Gegenkonto» and «MWST-Code» empty, one line under the field pointing to the settlement account of the mandator («MWST-Konto 2200 nicht mehr wählbar — für die Zahlung an die ESTV das Abrechnungskonto 2201.»); 2200 / 1170 / 1171 are not in the list, 2201 is; the window renders; no migration — the pattern still names 2200',
    $pkAfter->context['form']['contra'] === '' && $pkAfter->context['form']['tax_code'] === ''
    && $pkAfter->context['notes'] === ['contra' => 'MWST-Konto 2200 nicht mehr wählbar — für die Zahlung an die ESTV das Abrechnungskonto 2201.']
    && $pkOffered($pkAfter->context) === [] && in_array('2201', $pkAccts, true) && in_array('1020', $pkAccts, true)
    && preg_match('/name="contra"[^>]*value=""/', $pkHtml) === 1 && !str_contains($pkHtml, '<option value="2200"') && str_contains($pkHtml, '<option value="2201"')
    && $row("SELECT contra_account FROM bank_pattern WHERE match_key = 'name:estv bern'")['contra_account'] === '2200',
    json_encode([$pkAfter->context['form'], $pkAfter->context['notes'], $pkOffered($pkAfter->context)], JSON_UNESCAPED_UNICODE));

$pkBooks   = $booksState();
$pkOwed    = $pkWindow('P-02', ['op' => 'book', 'kind' => 'account', 'contra' => '2200', 'tax_code' => '', 'text' => '']);
$pkInput   = $pkWindow('P-02', ['op' => 'book', 'kind' => 'account', 'contra' => '1170 Vorsteuer', 'tax_code' => '', 'text' => '']);
$pkUntouched = $booksState() === $pkBooks && $stand('P-02') === ['review'];
$pkOwedHtml = $render('Backend/BankMovementController/edit', $pkOwed->context);
$pkGood    = $pkWindow('P-02', ['op' => 'book', 'kind' => 'account', 'contra' => '2201', 'tax_code' => '', 'text' => '', '_origin' => 'region:' . $bmRegion]);
check('PK3 the server refuses as well (Rule 7): «Buchen» with 2200 (or «1170 Vorsteuer» — a leading number is enough) as contra account comes back with ONE sentence under «Gegenkonto» («Nicht gebucht: 2200 ist ein MWST-Konto — die Zahlung an die ESTV geht auf das Abrechnungskonto 2201.»), what was typed kept, nothing written; with 2201 it books',
    $pkOwed->context['errors'] === ['contra' => 'Nicht gebucht: 2200 ist ein MWST-Konto — die Zahlung an die ESTV geht auf das Abrechnungskonto 2201.']
    && $pkInput->context['errors'] === ['contra' => 'Nicht gebucht: 1170 ist ein MWST-Konto — die Zahlung an die ESTV geht auf das Abrechnungskonto 2201.']
    && $pkUntouched && preg_match('/id="bank-movement-contra"[^>]*value="2200"[^>]*aria-invalid="true"/', $pkOwedHtml) === 1
    && $pkGood->messageService->flashes !== [] && str_starts_with($pkGood->messageService->flashes[0][1], 'Gebucht: Buchung 2026/') && $stand('P-02') === ['business'],
    json_encode([$pkOwed->context['errors'], $pkInput->context['errors'], $stand('P-02')], JSON_UNESCAPED_UNICODE));

// The day decides: a movement BEFORE «Abrechnung ab» still offers them (board S4-4: «Vorher … wie heute»).
$pkSetFrom('2026-08-01');
$pkEarlier = $pkWindow('P-03');
$pkSetFrom('2026-07-01');
$pkLater   = $pkWindow('P-03');
check('PK4 the day of the movement decides: «Abrechnung ab» 1.8.2026 → the movement of 28.7. offers 2200 / 1170 / 1171 as before; «Abrechnung ab» 1.7.2026 → it does not; a mandator not liable → as before',
    $pkOffered($pkEarlier->context) === $pkVatAccounts && $pkOffered($pkLater->context) === []
    && (function () use ($db, $pkWindow, $pkOffered, $pkVatAccounts): bool {
        $db->executeStatement('UPDATE mandator SET liable_to_vat = 0');
        $offered = $pkOffered($pkWindow('P-03')->context);
        $db->executeStatement('UPDATE mandator SET liable_to_vat = 1');
        return $offered === $pkVatAccounts;
    })());

// The one-line entry (financial's form; board S4-3, part 4): the datalist and the refusal at the field, by the entry's date.
$pkOneLine = static function (bool $withMap = true) use ($wire): \Z77\Module\Financial\Ui\OneLineEntryForm {
    $em = $wire();
    return new \Z77\Module\Financial\Ui\OneLineEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(\Z77\Module\Vat\Entities\TaxCode::class),
        \Z77\Module\Vat\Services\VatRates::from($em), new \Z77\Module\Financial\Services\LedgerService($em), $withMap ? new \Z77\Module\Financial\Services\VatAccountMap($em) : null);
};
$pkNumbers   = static fn(array $accounts): array => array_values(array_intersect($pkVatAccounts, array_map(static fn(Account $a) => $a->getNumber(), $accounts)));
$pkRow       = static fn(string $date, string $debit, string $credit, string $code = '') => ['date' => $date, 'text' => 'Vorsteuer Beleg 27-0390', 'debit' => $debit, 'credit' => $credit, 'amount' => '40.50']
    + ($code !== '' ? ['vat' => '1', 'tax_code' => $code] : []);
$pkField     = 'MWST-Konto 1170 — die Steuerzeile schreibt z77 (den MWST-Code auf die Aufwandzeile setzen). Einfuhrsteuer und Korrekturen als Sammelbuchung.';
$pkLocked    = $pkOneLine()->fromPost($pkRow('2026-07-10', '1170', '1020'));
$pkLockedReq = $pkLocked->toRequest();
$pkOwedForm  = $pkOneLine()->fromPost($pkRow('2026-07-10', '1020', '2200'));
$pkOwedReq   = $pkOwedForm->toRequest();
$pkEarly     = $pkOneLine()->fromPost($pkRow('2026-06-10', '1170', '1020'))->toRequest();
$pkRc        = $pkOneLine()->fromPost($pkRow('2026-07-10', '6500', '1020', 'BM'));
$pkRcReq     = $pkRc->toRequest();
$pkVk        = $pkOneLine()->fromPost($pkRow('2026-07-10', '6500', '1020', 'VK'));
$pkVkReq     = $pkVk->toRequest();
$pkCodes     = array_map(static fn($c) => $c->getCode(), $pkOneLine()->selectableCodes());
check('PK5 the one-line entry: its datalist leaves out 2200 / 1170 / 1171 on a date from «Abrechnung ab» on (offers them before); Soll 1170 or Haben 2200 on such a date is refused AT THE FIELD («MWST-Konto 1170 — die Steuerzeile schreibt z77 (den MWST-Code auf die Aufwandzeile setzen). Einfuhrsteuer und Korrekturen als Sammelbuchung.»), before the day it builds; a reverse-charge code is refused at «MWST-Code» naming the three ways («als Sammelbuchung, Beleg oder Kreditorenrechnung»), VK as the Sammelbuchung\'s; the code list has neither; a caller that passes no map gets the form as before',
    $pkNumbers($pkOneLine()->startBlank(new \DateTimeImmutable('2026-07-10'))->postableAccounts()) === []
    && $pkNumbers($pkOneLine()->startBlank(new \DateTimeImmutable('2026-06-10'))->postableAccounts()) === $pkVatAccounts
    && $pkLockedReq === null && $pkLocked->error('debit') === $pkField && $pkLocked->error('credit') === ''
    && $pkOwedReq === null && $pkOwedForm->error('credit') === str_replace('1170', '2200', $pkField)
    && $pkEarly !== null
    && $pkRcReq === null && $pkRc->error('tax_code') === 'Bezugsteuer (BM) wird auf beiden Seiten gebucht — als Sammelbuchung, Beleg oder Kreditorenrechnung erfassen.'
    && $pkVkReq === null && $pkVk->error('tax_code') === 'MWST-Code VK ist eine Vorsteuerkorrektur — als Sammelbuchung erfassen.'
    && array_intersect($pkCodes, ['BM', 'BI', 'BM2', 'BI2', 'VE', 'VK', 'VN']) === [] && in_array('VM', $pkCodes, true)
    && $pkNumbers($pkOneLine(false)->startBlank(new \DateTimeImmutable('2026-07-10'))->postableAccounts()) === $pkVatAccounts,
    json_encode([$pkLocked->error('debit'), $pkOwedForm->error('credit'), $pkRc->error('tax_code'), $pkVk->error('tax_code'), $pkCodes], JSON_UNESCAPED_UNICODE));
$pkSetFrom(null);

// ── down() / up() of the period rename (Version20261119140000) ──
// BY NAME to the migration before it (the rename is the newest of vat, mandator and financial today),
// so exactly its down() runs; then `migrate` runs its up() again — the dates of every statement survive.
$statementCols = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = '{$dbName}' AND table_name = 'bank_statement' ORDER BY ordinal_position"));
$periodsBefore = $db->fetchAllNumeric('SELECT id, period_from, period_to FROM bank_statement ORDER BY id');
[$pdCode, $pdOut] = $run(['command' => 'migrate', 'version' => 'Z77\\Module\\Financial\\Migrations\\Version20261119100000']);
$pdDown = $pdCode === 0 && in_array('to_date', $statementCols(), true) && in_array('from_date', $statementCols(), true) && !in_array('period_to', $statementCols(), true)
    && $db->fetchAllNumeric('SELECT id, from_date, `to_date` FROM bank_statement ORDER BY id') === $periodsBefore;
[$puCode, $puOut] = $run(['command' => 'migrate']);
$renameSrc = (string) file_get_contents($root . '/packages/module-financial/res/migrations/Version20261119140000.php');
check('R12b the period rename (TO_DATE is a keyword since MariaDB 12.3): up() turns from_date / to_date into period_from / period_to by CHANGE, down() back — the dates of every statement kept both ways; the B1 migration and the rename quote `to_date` (a fresh install on MariaDB 12.3+ / 13 runs them)',
    $periodsBefore !== [] && $pdDown && $puCode === 0 && !in_array('to_date', $statementCols(), true) && !in_array('from_date', $statementCols(), true)
    && $db->fetchAllNumeric('SELECT id, period_from, period_to FROM bank_statement ORDER BY id') === $periodsBefore
    && str_contains($migration, '`to_date` DATE NOT NULL') && substr_count($renameSrc, 'CHANGE `to_date` period_to') === 1 && substr_count($renameSrc, 'CHANGE period_to `to_date`') === 1,
    trim($pdOut) . ' / ' . trim($puOut));

// ── down() / up() of the C4 migration ──
// BY NAME first (it is the newest of all modules today — a newer one of another module may follow), then `prev` runs exactly its down().
$bankTablesNow = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_name LIKE 'bank\\_%' ORDER BY table_name"));
$accountCols   = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = '{$dbName}' AND table_name = 'bank_account'"));
$bankRows      = [$count('bank_account'), $count('bank_movement'), $count('bank_link'), $count('bank_posting')];
$run(['command' => 'migrate', 'version' => 'Z77\\Module\\Financial\\Migrations\\Version20261117200000']);
[$c4Code, $c4Out] = $run(['command' => 'migrate', 'version' => 'prev']);
$c4Down = $c4Code === 0 && !in_array('reconcile_from', $accountCols(), true) && !in_array('bank_anchor', $bankTablesNow(), true) && in_array('bank_posting', $bankTablesNow(), true)
    && $bankRows === [$count('bank_account'), $count('bank_movement'), $count('bank_link'), $count('bank_posting')];
[$c4UpCode, $c4UpOut] = $run(['command' => 'migrate']);
check('R13 down() of the C4 migration drops bank_anchor and bank_account.reconcile_from — accounts, movements, links and postings stay; up() brings both back, the date empty on every account (no account becomes bank-driven by a migration)',
    $c4Down && $c4UpCode === 0 && in_array('reconcile_from', $accountCols(), true) && in_array('bank_anchor', $bankTablesNow(), true) && $count('bank_anchor') === 0
    && $count('bank_account', 'reconcile_from IS NOT NULL') === 0 && $bankRows === [$count('bank_account'), $count('bank_movement'), $count('bank_link'), $count('bank_posting')], trim($c4Out) . ' / ' . trim($c4UpOut));

// ── down() / up() of the C2 migration ──
// Down to the C2 migration BY NAME: the C4 migrations of financial and of the mandator are newer.
// The mandator table has been down and up twice above; since Version20261127140000 (one more mandator
// column cycled each time) the THIRD cycle already hits «Row size too large» on the way up — the same
// accumulation of instantly dropped columns as explained before the link migration below
// (MANDATOR-ROWSIZE-001). Rebuild first; an installation never cycles its migrations.
$db->executeStatement('ALTER TABLE mandator FORCE');
$run(['command' => 'migrate', 'version' => 'Z77\\Module\\Financial\\Migrations\\Version20261117170000']);
$keysBefore = $keys();
[$code, $out] = $run(['command' => 'migrate', 'version' => 'prev']);
$columns = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = '{$dbName}' AND table_name = 'bank_movement'"));
$downOk  = $code === 0 && !in_array('pattern_key', $columns(), true) && $count('bank_movement') === count($keysBefore) && $count('bank_link') > 0
    && array_map('strval', $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_name LIKE 'bank\\_%' ORDER BY table_name")) === ['bank_account', 'bank_link', 'bank_movement', 'bank_statement'];
// A DRY RUN first: up() is not executed, postUp() still runs — it must find nothing to fill and fail on nothing (fixed 2026-11-17).
[$dryCode, $dryOut] = $run(['command' => 'migrate', '--dry-run' => true]);
$dryOk = $dryCode === 0 && !in_array('pattern_key', $columns(), true) && !str_contains($dryOut, 'Unknown column');
[$upCode, $upOut] = $run(['command' => 'migrate']);
check('C36 down() of the C2 migration drops its three tables and the column — movements, statements and links stay; a DRY RUN of the way up exits 0 and changes nothing (its postUp() writes directly and is guarded); up() again brings them back and FILLS pattern_key for the movements already stored, by the same rule the entity uses',
    $downOk && $dryOk && $upCode === 0 && $keys() === $keysBefore && $count('bank_pattern') === 0 && in_array('pattern_key', $columns(), true), trim($out) . ' / ' . trim($dryOut) . ' / ' . trim($upOut));
// Down to the link migration BY NAME: it takes the C2 migration and the two C4 migrations (financial, mandator) with it.
// The mandator table has been down and up three times above. MariaDB's INSTANT ADD / DROP COLUMN (10.4+)
// keeps every dropped column hidden in the clustered index until the table is rebuilt, and counts it in the
// worst-case row size (8126 bytes, innodb_strict_mode): the fourth instant DROP of the mandator migrations
// then fails with «Row size too large» — although the live table has room for ~160 more columns. An
// installation never cycles its migrations; the rebuild resets what the harness alone accumulated.
$db->executeStatement('ALTER TABLE mandator FORCE');
[$code, $out] = $run(['command' => 'migrate', 'version' => 'Z77\\Module\\Financial\\Migrations\\Version20261117100000']);

// ── down() ──
// Everything newer went down by name after C36 — now the link migration is the newest: `migrate prev`
// runs exactly its down(); `prev` again runs the down() of the bank account service.
$bankTables = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_name LIKE 'bank\\_%' ORDER BY table_name"));
[$code, $out] = $run(['command' => 'migrate', 'version' => 'prev']);
check('L13 down() of the link migration drops bank_link only — accounts, statements and movements stay', $code === 0 && $bankTables() === ['bank_account', 'bank_movement', 'bank_statement'] && $count('bank_movement') > 0, trim($out));
[$code, $out] = $run(['command' => 'migrate', 'version' => 'prev']);
$tables       = $bankTables();
check('M3 down() of the migration drops the three tables and leaves the rest of the schema', $code === 0 && $tables === [] && $count('account') > 0, trim($out));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
