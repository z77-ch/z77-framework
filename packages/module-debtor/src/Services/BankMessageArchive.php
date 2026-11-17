<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\BankMessage;
use Z77\Shared\Files\WriteOnceStore;

/**
 * The ARCHIVE of imported camt.054 files (ADR-061 decision 12, stage C3): the file a
 * {@see BankMessage} was read from is a booking record and is kept unaltered for the retention
 * period — the scheme of financial's camt.053 archive, under debtor's archive root:
 *
 *   data/archive/debtor/bank/<YYYY>/<YYYYMMDD>-<payment target code>-<sha256, 16 hex>.xml
 *
 * `<YYYY>` / `<YYYYMMDD>` the message's creation date (`GrpHdr/CreDtTm`) — never earlier than an
 * entry it reports, so a notification spanning New Year lies in the later year, from which the
 * ten years count. The code is the payment target the IBAN resolved to. The file of a ZIP is the
 * XML inside it (the ZIP is only the bank's wrapper). `bank_message.archive_path` and
 * `file_sha256` name it.
 *
 * The writing rules are the shared {@see WriteOnceStore}'s: written and verified before it is
 * moved into place, never overwritten, no delete path except {@see discardWritten()} for an import
 * whose unit of work rolled back. Not to be confused with {@see BankImportArchive}, which READS
 * the XML files out of a bank's ZIP.
 */
final class BankMessageArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/debtor/bank';

    private readonly WriteOnceStore $store;

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $this->store = new WriteOnceStore($basePath);
    }

    /** The path a file of $sha256 created on $createdOn for $paymentTargetCode is archived under, relative to the installation. */
    public static function pathFor(string $sha256, \DateTimeImmutable $createdOn, string $paymentTargetCode): string
    {
        return self::DIR . '/' . $createdOn->format('Y') . '/'
            . $createdOn->format('Ymd') . '-' . preg_replace('/[^a-z0-9-]/', '', strtolower($paymentTargetCode)) . '-' . substr($sha256, 0, 16) . '.xml';
    }

    /**
     * Stores the file and answers its path relative to the installation.
     *
     * @throws \RuntimeException the file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $bytes, string $sha256, \DateTimeImmutable $createdOn, string $paymentTargetCode): string
    {
        $relative = self::pathFor($sha256, $createdOn, $paymentTargetCode);
        $this->store->store($relative, $bytes, $sha256);

        return $relative;
    }

    /**
     * The archived file of $message, its checksum verified.
     *
     * @throws BankImportRefusedException ARCHIVE — the message has no archived file (imported before
     *                                    2026-11-17), or the file is missing or altered
     */
    public function bytes(BankMessage $message): string
    {
        $path = $message->getArchivePath();
        if ($path === null || $message->getFileSha256() === null) {
            throw new BankImportRefusedException(BankImportRefusedException::ARCHIVE, 'Zur Meldung «' . $message->getMessageId() . '» ist keine Datei archiviert — sie wurde vor dem 17.11.2026 eingelesen.');
        }
        $bytes = $this->store->bytes($path, $message->getFileSha256());
        if ($bytes === null) {
            $missing = !$this->store->exists($path);
            error_log('debtor bank archive: message ' . $message->getId() . ': ' . ($missing ? 'missing file ' : 'SHA-256 mismatch for ') . $path);
            throw new BankImportRefusedException(
                BankImportRefusedException::ARCHIVE,
                'Die archivierte Datei der Meldung «' . $message->getMessageId() . '» ' . ($missing ? 'fehlt' : 'ist verändert (Prüfsumme stimmt nicht)')
                . ' — Datei ' . $path . ' aus der Datensicherung zurückholen; die Ursache steht im Fehlerprotokoll.'
            );
        }

        return $bytes;
    }

    /** Removes the files this instance wrote — for a caller whose unit of work rolled back after {@see store()}. */
    public function discardWritten(): void
    {
        $this->store->discardWritten();
    }
}
