<?php

namespace Z77\Module\Financial\Bank;

use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Camt\CamtBalance;
use Z77\Shared\Payment\Camt\CamtEntry;
use Z77\Shared\Payment\Camt\CamtFormatException;
use Z77\Shared\Payment\Camt\CamtMessage;
use Z77\Shared\Payment\Camt\CamtParser;
use Z77\Shared\Payment\Camt\CamtParty;
use Z77\Shared\Payment\Camt\CamtReport;

/**
 * Reads a camt.053 file — the ISO 20022 «Bank to Customer Statement» as Swiss banks deliver it
 * (camt.053.001.04 and .08) — into {@see StatementData} / {@see MovementData}: pure data, nothing
 * stored, nothing resolved (ADR-060). The XML is read by the shared
 * {@see CamtParser} (the same parser debtor's camt.054 reader uses); this class is the
 * STATEMENT view of it:
 *
 *   - one {@see StatementData} per `Stmt` (a bank may put several into one message; an e-banking
 *     export is ONE statement over a free period);
 *   - one {@see MovementData} per BOOKED entry (`Ntry`, `Sts` = `BOOK`) — the entry is what moves
 *     the balance. A plain booking carries one transaction (`TxDtls`): its counterparty, its
 *     structured reference and its instructed foreign amount fill the movement. A collective
 *     entry (several transactions) keeps the entry's amount and text and counts its transactions
 *     — counterparty and reference stay empty;
 *   - the counterparty of a DEBIT is the creditor, of a CREDIT the debtor (the ultimate party
 *     when it carries a name), with the account (`CdtrAcct` / `DbtrAcct`) as its IBAN;
 *   - the foreign amount is `AmtDtls/InstdAmt` when its currency differs from the account's; the
 *     rate is the bank's `CcyXchg/XchgRate`, kept only when it is a plain decimal;
 *   - the text is the remittance message and `AddtlNtryInf` — on a card purchase the only place
 *     that names the merchant.
 *
 * Refused with a German reason: what is not XML, not a camt.053, or a statement the import cannot
 * rely on — no opening or closing booked balance (`OPBD` / `CLBD`), an entry without a booking
 * date or in another currency than the account, or balances that do not add up
 * (opening + booked entries ≠ closing: the file is cut or damaged).
 *
 * The entry reference (`Ntry/AcctSvcrRef`) is the dedup key of a movement. An entry without one —
 * or the second entry of a statement repeating one — gets none and is told apart by its
 * fingerprint: booking date, value date, side, amount, bank transaction code, text and its
 * rank among identical entries of the statement.
 */
final class Camt053Reader
{
    /**
     * @return list<StatementData> in file order, at least one
     * @throws BankRefusedException NOT_XML | NOT_CAMT053 | INCOMPLETE
     */
    public static function read(string $xml): array
    {
        try {
            $message = CamtParser::parse($xml);
        } catch (CamtFormatException $e) {
            if ($e->reason === CamtFormatException::NOT_XML) {
                throw new BankRefusedException(BankRefusedException::NOT_XML, $e->getMessage(), $e);
            }
            throw self::notCamt053($e);
        }
        if ($message->kind !== CamtMessage::KIND_STATEMENT || $message->reports === []) {
            throw self::notCamt053();
        }
        if ($message->messageId === '') {
            throw new BankRefusedException(BankRefusedException::NOT_CAMT053, 'Der Kontoauszug trägt keine Meldungs-ID (GrpHdr/MsgId).');
        }

        return array_map(static fn(CamtReport $report) => self::statement($message, $report), $message->reports);
    }

    private static function statement(CamtMessage $message, CamtReport $report): StatementData
    {
        $opening = $report->balance(CamtBalance::OPENING_BOOKED);
        $closing = $report->balance(CamtBalance::CLOSING_BOOKED);
        if ($opening === null || $closing === null || $opening->date === null || $closing->date === null) {
            throw new BankRefusedException(BankRefusedException::INCOMPLETE, 'Der Kontoauszug trägt keinen Anfangs- oder Schlusssaldo mit Datum (OPBD / CLBD) — ohne beide wird er nicht eingelesen.');
        }
        $currency = $report->currency !== '' ? $report->currency : $opening->currency;
        if ($currency === '' || $opening->currency !== $currency || $closing->currency !== $currency) {
            throw new BankRefusedException(BankRefusedException::INCOMPLETE, 'Der Kontoauszug nennt die Währung des Kontos nicht eindeutig (Acct/Ccy und die Salden).');
        }

        $movements = [];
        $seenRefs  = [];
        $ranks     = [];
        $skipped   = 0;
        $balance   = Money::fromDecimal($opening->amount, $currency);
        foreach ($report->entries as $entry) {
            if ($entry->status !== CamtEntry::STATUS_BOOKED) {
                $skipped++;
                continue;
            }
            $position = count($movements) + 1;
            if ($entry->bookingDate === null) {
                throw new BankRefusedException(BankRefusedException::INCOMPLETE, "Buchung {$position} des Kontoauszugs trägt kein Buchungsdatum (BookgDt).");
            }
            if ($entry->currency !== $currency) {
                throw new BankRefusedException(BankRefusedException::INCOMPLETE, "Buchung {$position} des Kontoauszugs lautet auf {$entry->currency}, das Konto auf {$currency}.");
            }
            $amount  = Money::fromDecimal($entry->amount, $currency);
            $balance = $entry->isCredit ? $balance->add($amount) : $balance->subtract($amount);

            $single  = count($entry->transactions) === 1 ? $entry->transactions[0] : null;
            $party   = null;
            $iban    = '';
            if ($single !== null) {
                $main  = $entry->isCredit ? $single->debtor : $single->creditor;
                $party = self::named($entry->isCredit ? $single->ultimateDebtor : $single->ultimateCreditor) ?? $main;
                $iban  = $main === null ? '' : $main->iban;
            }
            $foreign = $single !== null && $single->instructedAmount !== null && $single->instructedCurrency !== '' && $single->instructedCurrency !== $currency;
            $text    = trim(($single === null ? '' : $single->remittance) . "\n" . $entry->additionalInfo);

            // The reference is the dedup key; a second entry repeating one in the same statement gets none.
            $ref = $entry->accountServicerRef;
            if ($ref === '' || mb_strlen($ref) > 35 || isset($seenRefs[$ref])) {
                $ref = null;
            } else {
                $seenRefs[$ref] = true;
            }
            $base = implode('|', [
                $entry->bookingDate->format('Y-m-d'),
                $entry->valueDate?->format('Y-m-d') ?? '',
                $entry->isCredit ? 'C' : 'D',
                $entry->amount,
                $entry->bankTxCode,
                $text,
            ]);
            $ranks[$base] = ($ranks[$base] ?? 0) + 1;

            $movements[] = new MovementData(
                $position,
                $ref,
                hash('sha256', $base . '|' . $ranks[$base]),
                $entry->isCredit,
                $entry->isReversal,
                $entry->bookingDate,
                $entry->valueDate,
                $entry->amount,
                $currency,
                $foreign ? $single->instructedAmount : null,
                $foreign ? $single->instructedCurrency : null,
                $foreign ? self::rate($single->exchangeRate) : null,
                $entry->bankTxCode,
                $party === null ? '' : $party->name,
                $iban,
                $single === null ? '' : $single->referenceType,
                $single === null ? '' : $single->reference,
                $text,
                max(1, count($entry->transactions), (int) $entry->batchCount),
            );
        }
        if (!$balance->equals(Money::fromDecimal($closing->amount, $currency))) {
            throw new BankRefusedException(
                BankRefusedException::INCOMPLETE,
                'Der Kontoauszug geht nicht auf: Anfangssaldo plus Buchungen ergibt ' . $balance->toDecimal() . ', der Schlusssaldo lautet ' . $closing->amount
                . ' — die Datei ist unvollständig oder beschädigt. Bitte neu aus dem E-Banking laden.'
            );
        }

        return new StatementData(
            $message->messageId,
            $message->createdOn ?? new \DateTimeImmutable(),
            $report->id,
            $report->electronicSequence,
            $report->legalSequence,
            ($report->from ?? $opening->date)->setTime(0, 0),
            ($report->to ?? $closing->date)->setTime(0, 0),
            $report->iban,
            $currency,
            $opening->amount,
            $opening->date,
            $closing->amount,
            $closing->date,
            $movements,
            $skipped,
        );
    }

    private static function named(?CamtParty $party): ?CamtParty
    {
        return $party !== null && $party->name !== '' ? $party : null;
    }

    /** The bank's rate as a plain decimal with at most eight decimals; null for anything else. */
    private static function rate(string $raw): ?string
    {
        if (!preg_match('/^(\d{1,10})(?:\.(\d+))?$/', $raw, $m)) {
            return null;
        }
        $fraction = rtrim(substr($m[2] ?? '', 0, 8), '0');
        $rate     = (ltrim($m[1], '0') ?: '0') . ($fraction !== '' ? '.' . $fraction : '');

        return $rate === '0' ? null : $rate;
    }

    private static function notCamt053(?\Throwable $previous = null): BankRefusedException
    {
        return new BankRefusedException(BankRefusedException::NOT_CAMT053, 'Die Datei ist kein Kontoauszug camt.053 (Document/BkToCstmrStmt mit GrpHdr und Stmt erwartet).', $previous);
    }
}
