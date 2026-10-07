<?php

namespace Z77\Module\Debtor\Payments;

use Z77\Module\Debtor\Services\BankImportRefusedException;
use Z77\Module\Debtor\Services\Iban;

/**
 * Reads a CAMT.054 file — the ISO 20022 «Bank to Customer Debit Credit
 * Notification» as Swiss banks deliver it (camt.054.001.04 and .08, the
 * SIX «Swiss Payment Standards» implementation) — into {@see CamtFile} /
 * {@see CamtEntry}: pure data, nothing resolved (plan §6.4). The reader
 * knows the shape and nothing else; what a transaction MEANS for the
 * receivables side is `BankImportService`'s.
 *
 * The shape it reads:
 *
 *     Document/BkToCstmrDbtCdtNtfctn
 *       GrpHdr/MsgId, GrpHdr/CreDtTm
 *       Ntfctn (one per account)
 *         Acct/Id/IBAN
 *         Ntry (one per booking; CdtDbtInd CRDT | DBIT, RvslInd)
 *           ValDt/Dt, BookgDt/Dt, Amt @Ccy
 *           NtryDtls/TxDtls (one per transaction of a batch booking; a single
 *             one for a plain credit)
 *             Refs/AcctSvcrRef | TxId | EndToEndId
 *             Amt @Ccy (the transaction's share of a batch; the entry's amount otherwise)
 *             RltdPties/Dbtr/Nm, RltdPties/Dbtr/PstlAdr/TwnNm (UltmtDbtr first when present)
 *             RmtInf/Strd/CdtrRefInf/Tp/CdOrPrtry/Prtry (QRR | SCOR) + Ref
 *             RmtInf/Ustrd (the unstructured message, several joined)
 *
 * Every entry is returned — debits and reversals included, flagged — so the
 * import can say why it set one aside instead of silently dropping it. The
 * XML is parsed without network access and without external entities
 * (`LIBXML_NONET`, no entity loading); a file that is not a camt.054 or
 * lacks the header is refused with a German reason.
 */
final class CamtReader
{
    /** @throws BankImportRefusedException NOT_XML | NOT_CAMT054 */
    public static function read(string $xml): CamtFile
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($root === false) {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_XML, 'Die Datei ist kein lesbares XML.');
        }
        $ntfctn = $root->BkToCstmrDbtCdtNtfctn;
        if ($root->getName() !== 'Document' || !isset($ntfctn->GrpHdr)) {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_CAMT054, 'Die Datei ist keine camt.054-Meldung (Document/BkToCstmrDbtCdtNtfctn mit GrpHdr erwartet).');
        }
        $messageId = trim((string) $ntfctn->GrpHdr->MsgId);
        if ($messageId === '') {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_CAMT054, 'Die camt.054-Meldung trägt keine Meldungs-ID (GrpHdr/MsgId).');
        }
        $createdOn = self::dateTime((string) $ntfctn->GrpHdr->CreDtTm) ?? new \DateTimeImmutable();

        $iban    = '';
        $entries = [];
        $pos     = 0;
        foreach ($ntfctn->Ntfctn as $notification) {
            if ($iban === '') {
                $iban = Iban::normalize((string) $notification->Acct->Id->IBAN);
            }
            foreach ($notification->Ntry as $entry) {
                $isCredit   = strtoupper(trim((string) $entry->CdtDbtInd)) === 'CRDT';
                $isReversal = in_array(strtolower(trim((string) $entry->RvslInd)), ['true', '1'], true);
                $valueDate  = self::date((string) $entry->ValDt->Dt) ?? self::date((string) $entry->BookgDt->Dt) ?? new \DateTimeImmutable('today');
                $bookingDt  = self::date((string) $entry->BookgDt->Dt);
                $entryAmt   = $entry->Amt;
                $details    = isset($entry->NtryDtls) ? iterator_to_array($entry->NtryDtls->TxDtls, false) : [];
                if ($details === []) {
                    $details = [null];
                }
                foreach ($details as $detail) {
                    $pos++;
                    $amt      = $detail !== null && isset($detail->Amt) ? $detail->Amt : $entryAmt;
                    $amount   = self::decimal((string) $amt);
                    $currency = strtoupper(trim((string) ($amt['Ccy'] ?? ''))) ?: strtoupper(trim((string) ($entryAmt['Ccy'] ?? '')));
                    $refs     = $detail?->Refs;
                    $txRef    = trim((string) ($refs->AcctSvcrRef ?? '')) ?: trim((string) ($refs->TxId ?? '')) ?: trim((string) ($refs->EndToEndId ?? '')) ?: ($messageId . '#' . $pos);
                    [$type, $reference, $remittance] = self::remittance($detail);
                    $debtor = $detail?->RltdPties?->UltmtDbtr ?? $detail?->RltdPties?->Dbtr;
                    $entries[] = new CamtEntry(
                        $txRef,
                        $valueDate,
                        $bookingDt,
                        $amount,
                        $currency !== '' ? $currency : 'CHF',
                        $isCredit,
                        $isReversal,
                        $type,
                        $reference,
                        $remittance,
                        trim((string) ($debtor?->Nm ?? '')),
                        trim((string) ($debtor?->PstlAdr?->TwnNm ?? '')),
                    );
                }
            }
        }

        return new CamtFile($messageId, $createdOn, $iban, $entries);
    }

    /** @return array{0: string, 1: string, 2: string} reference type (QRR | SCOR | NON | ''), reference, unstructured message */
    private static function remittance(?\SimpleXMLElement $detail): array
    {
        if ($detail === null || !isset($detail->RmtInf)) {
            return ['', '', ''];
        }
        $rmt        = $detail->RmtInf;
        $unstructured = [];
        foreach ($rmt->Ustrd as $u) {
            $unstructured[] = trim((string) $u);
        }
        $type      = '';
        $reference = '';
        foreach ($rmt->Strd as $strd) {
            $info = $strd->CdtrRefInf;
            if (!isset($info)) {
                continue;
            }
            $reference = preg_replace('/\s+/', '', (string) $info->Ref) ?? '';
            $type      = strtoupper(trim((string) ($info->Tp->CdOrPrtry->Prtry ?? $info->Tp->CdOrPrtry->Cd ?? '')));
            foreach ($strd->AddtlRmtInf as $a) {
                $unstructured[] = trim((string) $a);
            }
            break;
        }
        if ($reference !== '' && $type === '') {
            $type = preg_match('/^\d{27}$/', $reference) ? 'QRR' : ($type === '' && str_starts_with($reference, 'RF') ? 'SCOR' : 'NON');
        }
        if ($reference === '') {
            $type = 'NON';
        }

        return [$type, $reference, implode(' ', array_filter($unstructured, static fn(string $s) => $s !== ''))];
    }

    /** The file's amount as a two-decimal string — string work only, no float (ADR-042): the fraction is cut or padded. */
    private static function decimal(string $raw): string
    {
        $raw = trim($raw);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $raw, $m)) {
            return '0.00';
        }
        $fraction = substr(str_pad($m[3] ?? '', 2, '0'), 0, 2);
        $value    = ltrim($m[2], '0') . '.' . $fraction;
        if (str_starts_with($value, '.')) {
            $value = '0' . $value;
        }

        return ($m[1] === '-' && $value !== '0.00') ? '-' . $value : $value;
    }

    private static function date(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));

        return $date === false ? null : $date;
    }

    private static function dateTime(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
