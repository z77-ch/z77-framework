<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankStatement;
use Z77\Shared\Files\WriteOnceStore;

/**
 * The ARCHIVE of imported camt.053 files (ADR-060 decision 7, owner decision 6 of 2026-11-15):
 * the file a statement was read from is a booking record and is kept unaltered — under the
 * installation's `data/`, the pattern of debtor's invoice archive:
 *
 *   data/archive/financial/bank/<YYYY>/<YYYYMMDD>-<bank account code>-<sha256, 16 hex>.xml
 *
 * `<YYYY>` / `<YYYYMMDD>` the closing date of the (last) statement in the file — a statement
 * spanning two years lies in the later one, from which the ten years count. Non-public (`data/`
 * is never served), in the release's shared `data` store and in the «Daten» backup. Not in the
 * DMS: financial does not depend on it, and a bank statement must not show up in a Drive.
 *
 * The writing rules are the shared {@see WriteOnceStore}'s (kernel shared — lifted from this
 * class on 2026-11-17, and this class reads and writes through it since stage C2): written under
 * a temporary name, read back and verified, then moved into place; a path that holds other bytes
 * is refused, one that already holds the same bytes is accepted. There is NO delete and NO
 * overwrite path — the one exception is {@see discardWritten()}, for the import whose unit of
 * work rolled back after the file was written, so that no file lies there that no statement row
 * names.
 *
 * Reading ({@see bytes()}) checks the SHA-256 against the statement row; a missing or altered
 * file is refused with a German sentence — never silently accepted.
 */
final class BankStatementArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/financial/bank';

    private readonly WriteOnceStore $store;

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $this->store = new WriteOnceStore($basePath);
    }

    /** The path a file of $sha256 closing on $closingDate for $bankAccountCode is archived under, relative to the installation. */
    public static function pathFor(string $sha256, \DateTimeImmutable $closingDate, string $bankAccountCode): string
    {
        return self::DIR . '/' . $closingDate->format('Y') . '/'
            . $closingDate->format('Ymd') . '-' . preg_replace('/[^a-z0-9-]/', '', $bankAccountCode) . '-' . substr($sha256, 0, 16) . '.xml';
    }

    /**
     * Stores $bytes and answers the path relative to the installation.
     *
     * @throws \RuntimeException the file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $bytes, string $sha256, \DateTimeImmutable $closingDate, string $bankAccountCode): string
    {
        $relative = self::pathFor($sha256, $closingDate, $bankAccountCode);
        $this->store->store($relative, $bytes, $sha256);

        return $relative;
    }

    /**
     * The archived file of $statement, its checksum verified.
     *
     * @throws BankRefusedException ARCHIVE — the file is missing or altered
     */
    public function bytes(BankStatement $statement): string
    {
        $path  = $statement->getArchivePath();
        $bytes = $this->store->bytes($path, $statement->getFileSha256());
        if ($bytes === null) {
            $missing = !$this->store->exists($path);
            error_log('financial bank archive: statement ' . $statement->getId() . ': ' . ($missing ? 'missing file ' : 'SHA-256 mismatch for ') . $path);
            throw new BankRefusedException(
                BankRefusedException::ARCHIVE,
                'Die archivierte Datei des Kontoauszugs ' . ($missing ? 'fehlt' : 'ist verändert (Prüfsumme stimmt nicht)') . ' — Datei '
                . $path . ' aus der Datensicherung zurückholen; die Ursache steht im Fehlerprotokoll.'
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
