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
 *     label (escaped) in «Zuordnung» and the view «Ohne Zuordnung»; the migration up and down.
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
use Z77\Module\Financial\Bank\BankRefusedException;
use Z77\Module\Financial\Bank\BankStatementArchive;
use Z77\Module\Financial\Bank\BankStatementService;
use Z77\Module\Financial\Bank\Camt053Reader;
use Z77\Module\Financial\Bank\InvalidBankAccountException;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankMovement;
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
$screenT = ['BankAccountController/listAction', 'BankAccountController/_row', 'BankAccountController/act', 'BankAccountController/edit', 'BankMovementController/listAction', 'BankMovementController/_list', 'BankMovementController/act', 'partials/bankAccountSelect'];
$helpT   = ['BankAccountController/list.help', 'BankAccountController/edit.help', 'BankMovementController/list.help'];
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
check('S5 the explanations are in the help: a section per field of the bank account window (the switch says that EVERY movement is stored, private ones too) and per search field of the list; the list help says «verbucht wird hier nichts» and «immer lückenlos»',
    array_filter(['label', 'code', 'iban', 'ledger_account', 'currency', 'statement_import'], static fn(string $f) => !str_contains($editHelp, 'data-help-field="' . $f . '"')) === []
    && str_contains($editHelp, 'auch private') && str_contains($editHelp, '10 Jahre')
    && array_filter(['f_date', 'f_text', 'f_amount'], static fn(string $f) => !str_contains($listHelp, 'data-help-field="' . $f . '"')) === []
    && str_contains($listHelp, 'Verbucht wird hier nichts') && str_contains($listHelp, 'immer lückenlos') && str_contains($listHelp, 'camt.053 Upload'));
check('S6 every value of a movement goes through e() in the list template (bank text is foreign input); the list has no row window and carries no tabs itself (the two views of stage C1 are the toolbar\'s)',
    preg_match('/<\?=\s*\$(movement|party|text|detail)\b/', $read(['BankMovementController/_list'])) === 0
    && !str_contains($bodyOf($read(['BankMovementController/_list', 'BankMovementController/listAction'])), 'data-window-open')
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
check('I2 a statement of an IBAN that is no bank account is refused (account-unknown) and says where to create it',
    $unknown?->reason === BankRefusedException::ACCOUNT_UNKNOWN && str_contains($unknown->getMessage(), 'Stammdaten › Finanzen › Bankkonten') && str_contains($unknown->getMessage(), 'CH56 0483') && $nothing());

$em = $wire();
(new BankAccountService($em))->update((new BankAccountService($em))->find('haus'), ['statement_import' => true]);
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
    && $janRow['message_id'] === 'MSG-JAN' && $janRow['statement_id'] === 'STMT-JAN' && $janRow['from_date'] === '2026-01-01' && $janRow['to_date'] === '2026-01-31'
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
$feb = $importer($wire())->import(camt053(['msg' => 'MSG-FEB', 'stmt' => 'STMT-FEB', 'opening' => '808.55', 'from' => '2026-02-01', 'to' => '2026-02-28'], $february), 'februar.xml');
check('I9 the next statement starts the day after the covered end and joins: February (opening = the stored closing 808.55) — three new, read up to 28.02.2026, balance 900.00',
    $feb[0]->newMovements === 3 && $feb[0]->knownMovements === 0 && $feb[0]->coveredTo->format('Y-m-d') === '2026-02-28' && $feb[0]->balance->toDecimal() === '900.00'
    && $row("SELECT balance FROM bank_movement WHERE entry_ref = 'B-0001'")['balance'] === '800.00' && $state() === [2, 9, 2]);

$march = [
    ['ref' => 'C-0001', 'date' => '2026-03-02', 'amount' => '50.00', 'info' => 'Karte'],
    ['ref' => 'C-0002', 'date' => '2026-03-09', 'amount' => '150.00', 'credit' => true, 'info' => 'Gutschrift'],
];
$gap = $refusal(camt053(['msg' => 'MSG-GAP', 'stmt' => 'STMT-GAP', 'opening' => '900.00', 'from' => '2026-03-05', 'to' => '2026-03-10'], [$march[1]]), 'luecke.xml');
check('I10 a GAP is REFUSED: read up to 28.02., the file starts on 05.03. — the sentence names both dates and the day the next file must start on; nothing stored, no file (the balance joining by chance does not help)',
    $gap?->reason === BankRefusedException::GAP && str_contains($gap->getMessage(), 'eingelesen bis 28.02.2026') && str_contains($gap->getMessage(), 'beginnt am 05.03.2026')
    && str_contains($gap->getMessage(), 'fehlen Bewegungen') && str_contains($gap->getMessage(), '01.03.2026') && $state() === [2, 9, 2], (string) $gap?->getMessage());

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
$quiet = $importer($wire())->import(camt053(['msg' => 'MSG-QUIET', 'stmt' => 'STMT-QUIET', 'opening' => '975.00', 'from' => '2026-03-11', 'to' => '2026-03-15'], []), 'ruhig.xml');
check('I17 a following statement WITHOUT a movement moves the covered end (its balance must be the stored one): read up to 15.03., «0 neue Bewegungen, Saldo stimmt»',
    $quiet[0]->statement !== null && $quiet[0]->newMovements === 0 && $quiet[0]->coveredTo->format('Y-m-d') === '2026-03-15' && str_starts_with($quiet[0]->message(), '0 neue Bewegungen, Saldo stimmt')
    && $refusal(camt053(['msg' => 'MSG-Q2', 'stmt' => 'STMT-Q2', 'opening' => '970.00', 'from' => '2026-03-16', 'to' => '2026-03-17'], []), 'ruhig-falsch.xml')?->reason === BankRefusedException::CHAIN_MISMATCH
    && $state() === [5, 12, 5]);

// Several statements in ONE file (a bank that sends daily statements in one message).
$day2  = camt053(['raw' => true, 'stmt' => 'STMT-D2', 'opening' => '965.00', 'from' => '2026-03-17', 'to' => '2026-03-17'], [['ref' => 'G-0002', 'date' => '2026-03-17', 'amount' => '40.00']]);
$daily = $importer($wire())->import(camt053(['msg' => 'MSG-DAY', 'stmt' => 'STMT-D1', 'opening' => '975.00', 'from' => '2026-03-16', 'to' => '2026-03-16', 'more' => [$day2]],
    [['ref' => 'G-0001', 'date' => '2026-03-16', 'amount' => '10.00']]), 'tage.xml');
check('I18 two statements in one file: both stored in one unit of work, the second joins the first, ONE archived file named by both; read up to 17.03., balance 925.00',
    count($daily) === 2 && $daily[0]->statement->getArchivePath() === $daily[1]->statement->getArchivePath() && str_contains($daily[0]->statement->getArchivePath(), '/2026/20260317-haus-')
    && $daily[1]->coveredTo->format('Y-m-d') === '2026-03-17' && $daily[1]->balance->toDecimal() === '925.00' && $state() === [7, 14, 6]);

// A unit of work that fails after the file was written: the second statement repeats the first one's ids.
$twice  = camt053(['raw' => true, 'stmt' => 'STMT-X', 'opening' => '924.00', 'from' => '2026-03-19', 'to' => '2026-03-19'], [['ref' => 'H-0002', 'date' => '2026-03-19', 'amount' => '1.00']]);
$failed = $refusal(camt053(['msg' => 'MSG-X', 'stmt' => 'STMT-X', 'opening' => '925.00', 'from' => '2026-03-18', 'to' => '2026-03-18', 'more' => [$twice]],
    [['ref' => 'H-0001', 'date' => '2026-03-18', 'amount' => '1.00']]), 'doppelt.xml');
$second = camt053(['raw' => true, 'stmt' => 'STMT-V2', 'opening' => '924.00', 'from' => '2026-03-25', 'to' => '2026-03-25'], []);
$partly = $refusal(camt053(['msg' => 'MSG-V', 'stmt' => 'STMT-V1', 'opening' => '925.00', 'from' => '2026-03-18', 'to' => '2026-03-18', 'more' => [$second]],
    [['ref' => 'H-0001', 'date' => '2026-03-18', 'amount' => '1.00']]), 'teilweise.xml');
check('I19 a file is stored WHOLE or not at all: a unit of work that fails in the second statement (the same ids twice; a gap after the first) leaves no statement, no movement — and the archived file is removed again',
    $failed?->reason === BankRefusedException::DUPLICATE_MESSAGE && $partly?->reason === BankRefusedException::GAP && $state() === [7, 14, 6]
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

$useRequest = function (array $get, ?array $post = null, bool $fetch = false, array $json = [], ?\Z77\Shared\ValueObjects\UploadedFile $file = null) use ($wire): \Z77\Persistence\Resolver\UnifiedEntityManager {
    $em = $wire();
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $fetch, $json, $file) {
        public function __construct(private array $get, private ?array $post, private bool $fetch, private array $json, private ?\Z77\Shared\ValueObjects\UploadedFile $file) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function isPost(): bool { return $this->post !== null; }
        public function getPostParameters(): array { return $this->post ?? []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
        public function getJsonBody(): array { return $this->json; }
        public function getUploadedFile(string $field): ?\Z77\Shared\ValueObjects\UploadedFile { return $field === 'file' ? $this->file : null; }
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
}
$accountHost = fn() => new class {
    use BankScreenHostDouble;
    use \Z77\Module\Financial\Ui\BankAccountControllerTrait { listAction as public; addAction as public; editAction as public; toggleActiveAction as public; }
};
$movementHost = fn() => new class {
    use BankScreenHostDouble;
    use \Z77\Module\Financial\Ui\BankMovementControllerTrait { listAction as public; uploadAction as public; }
    private function bankStatements(): BankStatementService { return new BankStatementService($this->em(), 'tester'); }
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
check('SM8 «Finanzen › Bankkonto»: ONE list — the header «eingelesen bis 17.03.2026 · Saldo 925.00», 14 movements newest first, no tabs in the list (the two views are the toolbar\'s — L9), no row window; the upload in the action cell (camt.053 Upload, XML or ZIP), the work area takes dropped files, the progress runs in a window (UPLOAD-DIALOG-001); one account → no selection at the top of the rail; help in the toolbar',
    $host->context['account']?->getCode() === 'haus' && count($host->context['movements']) === 14 && $host->context['movements'][0]->getEntryRef() === 'G-0002'
    && str_contains($mvPage, 'eingelesen bis 17.03.2026 · Saldo 925.00') && str_contains($mvPage, 'data-bank-movements') && !str_contains($mvPage, 'data-upload-progress')
    && !str_contains($mvPage, 'be-viewtabs') && !str_contains($mvPage, 'data-window-open') && !str_contains($mvPage, 'Erledigt')
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
$next = camt053(['msg' => 'MSG-UP1', 'stmt' => 'STMT-UP1', 'opening' => '925.00', 'from' => '2026-03-18', 'to' => '2026-03-18'], [['ref' => 'U-0001', 'date' => '2026-03-18', 'amount' => '5.00', 'info' => '&lt;b&gt;Fett&lt;/b&gt; &amp; Co']]);
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
check('SM12 a refusal is the answer AT the file: the gap sentence with both dates, no command, nothing stored; a file that is neither XML nor ZIP is refused by the upload policy',
    ($gapA['status'] ?? '') === 'error' && str_contains($gapA['message'] ?? '', 'eingelesen bis 18.03.2026, die Datei beginnt am 25.03.2026') && !isset($gapA['commands'])
    && ($txt['status'] ?? '') === 'error' && str_contains($txt['message'] ?? '', 'nicht erlaubt') && $count('bank_movement') === 15);
$zipOk  = camt053(['msg' => 'MSG-UP3', 'stmt' => 'STMT-UP3', 'opening' => '920.00', 'from' => '2026-03-19', 'to' => '2026-03-20'], [['ref' => 'U-0003', 'date' => '2026-03-19', 'amount' => '20.00']]);
$zipped = $zipOf(['a-19.xml' => $zipOk, 'b-spaet.xml' => $late, 'liesmich.txt' => 'x']);
$useRequest([], [], true, [], new \Z77\Shared\ValueObjects\UploadedFile('bank.zip', (function () use ($zipped) { $p = tempnam(sys_get_temp_dir(), 'z77bankzip'); file_put_contents($p, $zipped); return $p; })(), strlen($zipped), 'application/zip'));
$host = $movementHost();
$zipA = $jsonOf($host->uploadAction());
check('SM13 a ZIP: every XML inside read on its own — ONE answer naming each file: what was read («a-19.xml: 1 neue Bewegung …») and what was refused («b-spaet.xml: … fehlen Bewegungen»); the list is replaced',
    ($zipA['status'] ?? '') === 'error' && str_contains($zipA['message'] ?? '', 'a-19.xml: 1 neue Bewegung, Saldo stimmt (eingelesen bis 20.03.2026') && str_contains($zipA['message'] ?? '', 'b-spaet.xml: ')
    && str_contains($zipA['message'] ?? '', 'fehlen Bewegungen') && ($zipA['commands'][0]['action'] ?? '') === 'replace-html' && $count('bank_movement') === 16, json_encode($zipA['message'] ?? null, JSON_UNESCAPED_UNICODE));
$page = camt053(['msg' => 'MSG-UP4', 'stmt' => 'STMT-UP4', 'opening' => '900.00', 'from' => '2026-03-21', 'to' => '2026-03-22'], []);
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

// the screen: the column «Zuordnung» and the view «Ohne Zuordnung»
$useRequest([]);
$host = $movementHost();
$host->listAction();
$linkPage = $render('Backend/BankMovementController/listAction', $host->context);
$toolbar  = $render('Backend/BankMovementController/toolbar', $host->context);
check('L9 the list «Bewegungen» shows what a movement is linked to in the column «Zuordnung» — the owner\'s label, ESCAPED — and nothing for the others; the toolbar carries the two views «Alle» · «Ohne Zuordnung»',
    str_contains($linkPage, 'Zuordnung') && str_contains($linkPage, 'data-movement-link="creditor:17">Rechnung &lt;b&gt;4711&lt;/b&gt; · Muster Lieferant AG</span>') && !str_contains($linkPage, '<b>4711</b>')
    && substr_count($linkPage, 'data-movement-link=""') === count($host->context['movements']) - 1
    && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/BankMovementController/toolbar']
    && str_contains($toolbar, 'be-viewtabs') && str_contains($toolbar, '>Alle</a>') && str_contains($toolbar, 'list?account=haus&amp;link=free') && str_contains($toolbar, '>Ohne Zuordnung</a>')
    && preg_match('~list\?account=haus"\s+aria-current="page">Alle~', $toolbar) === 1, $toolbar);
$total = count($found([]));
$free  = $found(['link' => 'free']);
$useRequest(['link' => 'free']);
$host = $movementHost();
$host->listAction();
$freePage    = $render('Backend/BankMovementController/listAction', $host->context);
$freeToolbar = $render('Backend/BankMovementController/toolbar', $host->context);
check('L10 the view «Ohne Zuordnung» (?link=free): every movement but the linked one, counted in the badge, kept through search and paging links; an unknown value of `link` shows all',
    count($free) === $total - 1 && !in_array('A-0001', $free, true) && $host->context['paging']->total === $total - 1 && str_contains($freePage, 'Bewegungen ohne Zuordnung')
    && str_contains($freePage, 'link=free') && preg_match('~link=free"\s+aria-current="page">Ohne Zuordnung~', $freeToolbar) === 1
    && $found(['link' => 'free', 'f_amount' => '250']) === [] && $found(['link' => 'free', 'f_amount' => '94.35']) === ['A-0002'] && count($found(['link' => 'x'])) === $total,
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

// ── down() ──
// The link migration is the newest of the set — `migrate prev` runs exactly its down(); `prev`
// again runs the down() of the bank account service.
$bankTables = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_name LIKE 'bank\\_%' ORDER BY table_name"));
[$code, $out] = $run(['command' => 'migrate', 'version' => 'prev']);
check('L13 down() of the link migration drops bank_link only — accounts, statements and movements stay', $code === 0 && $bankTables() === ['bank_account', 'bank_movement', 'bank_statement'] && $count('bank_movement') > 0, trim($out));
[$code, $out] = $run(['command' => 'migrate', 'version' => 'prev']);
$tables       = $bankTables();
check('M3 down() of the migration drops the three tables and leaves the rest of the schema', $code === 0 && $tables === [] && $count('account') > 0, trim($out));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
