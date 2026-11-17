<?php

namespace Z77\Module\Debtor\Payments;

use Z77\Module\Debtor\Services\BankImportRefusedException;
use Z77\Shared\Payment\Camt\CamtFormatException;
use Z77\Shared\Payment\Camt\CamtMessage;
use Z77\Shared\Payment\Camt\CamtParser;
use Z77\Shared\Payment\Camt\CamtParty;

/**
 * Reads a CAMT.054 file — the ISO 20022 «Bank to Customer Debit Credit
 * Notification» as Swiss banks deliver it (camt.054.001.04 and .08, the
 * SIX «Swiss Payment Standards» implementation) — into {@see CamtFile} /
 * {@see CamtEntry}: pure data, nothing resolved (plan §6.4). The reader
 * knows the shape and nothing else; what a transaction MEANS for the
 * receivables side is `BankImportService`'s.
 *
 * The XML itself is read by the shared {@see CamtParser} (kernel shared,
 * `Z77\Shared\Payment\Camt` — owner decision 12 of the bank account service,
 * 2026-11-15: financial's camt.053 reader uses the same parser, so a fix to
 * the standard's shape is made once). This class is debtor's VIEW of a
 * notification — one row per transaction:
 *
 *   - every `TxDtls` of every `Ntry` of every `Ntfctn` becomes a
 *     {@see CamtEntry}, in file order; an entry without `TxDtls` becomes one
 *     row with the entry's amount;
 *   - the row's reference is `Refs/AcctSvcrRef`, else `TxId`, else
 *     `EndToEndId`, else `{MsgId}#{position}`;
 *   - the amount is the transaction's share (`TxDtls/Amt`), the entry's
 *     amount otherwise;
 *   - the payer is the ultimate debtor when it carries a NAME, else the
 *     debtor (2026-10-13; .08 nests both under …/Pty — the parser reads them
 *     there);
 *   - the value date falls back to the booking date, then to today.
 *
 * Every entry is returned — debits and reversals included, flagged — so the
 * import can say why it set one aside instead of silently dropping it. A
 * file that is not a camt.054 or lacks the header is refused with a German
 * reason.
 */
final class CamtReader
{
    /** @throws BankImportRefusedException NOT_XML | NOT_CAMT054 */
    public static function read(string $xml): CamtFile
    {
        try {
            $message = CamtParser::parse($xml);
        } catch (CamtFormatException $e) {
            if ($e->reason === CamtFormatException::NOT_XML) {
                throw new BankImportRefusedException(BankImportRefusedException::NOT_XML, $e->getMessage(), $e);
            }
            throw self::notCamt054($e);
        }
        if ($message->kind !== CamtMessage::KIND_NOTIFICATION) {
            throw self::notCamt054();
        }
        if ($message->messageId === '') {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_CAMT054, 'Die camt.054-Meldung trägt keine Meldungs-ID (GrpHdr/MsgId).');
        }

        $iban    = '';
        $entries = [];
        $pos     = 0;
        foreach ($message->reports as $notification) {
            if ($iban === '') {
                $iban = $notification->iban;
            }
            foreach ($notification->entries as $entry) {
                $valueDate = $entry->valueDate ?? $entry->bookingDate ?? new \DateTimeImmutable('today');
                foreach ($entry->transactions === [] ? [null] : $entry->transactions as $detail) {
                    $pos++;
                    $txRef = '';
                    if ($detail !== null) {
                        $txRef = $detail->accountServicerRef ?: $detail->txId ?: $detail->endToEndId;
                    }
                    $currency = ($detail?->amount !== null ? $detail->currency : '') ?: $entry->currency;
                    // The ultimate debtor when it carries a NAME, else the debtor (review 2026-10-13: an
                    // UltmtDbtr without a name hid Dbtr's).
                    $debtor = self::named($detail?->ultimateDebtor) ?? $detail?->debtor;
                    $entries[] = new CamtEntry(
                        $txRef !== '' ? $txRef : $message->messageId . '#' . $pos,
                        $valueDate,
                        $entry->bookingDate,
                        $detail?->amount ?? $entry->amount,
                        $currency !== '' ? $currency : 'CHF',
                        $entry->isCredit,
                        $entry->isReversal,
                        $detail === null ? '' : $detail->referenceType,
                        $detail === null ? '' : $detail->reference,
                        $detail === null ? '' : $detail->remittance,
                        $debtor === null ? '' : $debtor->name,
                        $debtor === null ? '' : $debtor->city,
                    );
                }
            }
        }

        return new CamtFile($message->messageId, $message->createdOn ?? new \DateTimeImmutable(), $iban, $entries);
    }

    private static function named(?CamtParty $party): ?CamtParty
    {
        return $party !== null && $party->name !== '' ? $party : null;
    }

    private static function notCamt054(?\Throwable $previous = null): BankImportRefusedException
    {
        return new BankImportRefusedException(BankImportRefusedException::NOT_CAMT054, 'Die Datei ist keine camt.054-Meldung (Document/BkToCstmrDbtCdtNtfctn mit GrpHdr erwartet).', $previous);
    }
}
