<?php

namespace Z77\Module\Debtor\Services;

use Z77\Shared\Payment\Camt\CamtArchive;
use Z77\Shared\Payment\Camt\CamtFormatException;

/**
 * The XML files inside a ZIP a bank hands out (owner 2026-11-13: a Swiss bank delivers its camt.054
 * as `<BANK>_camt054_<timestamp>.zip`) — debtor's door to the shared {@see CamtArchive}, which
 * owns the reading and its limits since the bank account service (owner decision 12, 2026-11-15:
 * financial's camt.053 import reads the same archives). This class only turns the shared refusal
 * into debtor's {@see BankImportRefusedException}, so the import screen keeps ONE exception type.
 */
final class BankImportArchive
{
    public const MAX_ENTRIES     = CamtArchive::MAX_ENTRIES;
    public const MAX_ENTRY_BYTES = CamtArchive::MAX_ENTRY_BYTES;
    public const MAX_TOTAL_BYTES = CamtArchive::MAX_TOTAL_BYTES;

    /**
     * @return list<array{name: string, xml: string}> in archive order
     * @throws BankImportRefusedException NOT_XML — not a readable ZIP, no XML inside, over a limit
     */
    public static function xmlFiles(string $zipBytes): array
    {
        try {
            return CamtArchive::xmlFiles($zipBytes);
        } catch (CamtFormatException $e) {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_XML, $e->getMessage(), $e);
        }
    }
}
