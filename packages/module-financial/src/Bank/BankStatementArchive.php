<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankStatement;

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
 * There is NO delete and NO overwrite path: {@see store()} refuses a path that holds other
 * bytes, and accepts one that already holds the same bytes. The one exception is
 * {@see discardWritten()} — for the import whose unit of work rolled back after the file was
 * written, so that no file lies there that no statement row names.
 *
 * Reading ({@see bytes()}) checks the SHA-256 against the statement row; a missing or altered
 * file is refused with a German sentence — never silently accepted.
 */
final class BankStatementArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/financial/bank';

    private readonly string $base;

    /** @var list<string> absolute paths written by this instance — removed by {@see discardWritten()} */
    private array $written = [];

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $base = $basePath ?? (defined('ABS_BASE_PATH') ? (string) constant('ABS_BASE_PATH') : '');
        if ($base === '') {
            throw new \LogicException('BankStatementArchive needs the installation root (ABS_BASE_PATH)');
        }
        $this->base = rtrim(str_replace('\\', '/', $base), '/');
    }

    /**
     * Stores $bytes and answers the path relative to the installation. The file is written under
     * a temporary name, read back, its SHA-256 compared with $sha256, and only then moved into
     * place.
     *
     * @throws \RuntimeException the file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $bytes, string $sha256, \DateTimeImmutable $closingDate, string $bankAccountCode): string
    {
        $relative = self::DIR . '/' . $closingDate->format('Y') . '/'
            . $closingDate->format('Ymd') . '-' . preg_replace('/[^a-z0-9-]/', '', $bankAccountCode) . '-' . substr($sha256, 0, 16) . '.xml';
        $absolute = $this->base . '/' . $relative;
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException("BankStatementArchive: the checksum given for {$relative} is not the checksum of the bytes");
        }
        if (is_file($absolute)) {
            if (!hash_equals($sha256, (string) @hash_file('sha256', $absolute))) {
                throw new \RuntimeException("BankStatementArchive: {$relative} exists with other content — an archived file is never overwritten");
            }

            return $relative;
        }
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("BankStatementArchive: cannot create the directory {$dir}");
        }
        $temp = $absolute . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !hash_equals($sha256, (string) @hash_file('sha256', $temp))) {
                throw new \RuntimeException("BankStatementArchive: cannot write {$relative}");
            }
            if (!@rename($temp, $absolute)) {
                throw new \RuntimeException("BankStatementArchive: cannot move {$relative} into place");
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        $this->written[] = $absolute;

        return $relative;
    }

    /**
     * The archived file of $statement, its checksum verified.
     *
     * @throws BankRefusedException ARCHIVE — the file is missing or altered
     */
    public function bytes(BankStatement $statement): string
    {
        $absolute = $this->base . '/' . $statement->getArchivePath();
        $bytes    = is_file($absolute) ? @file_get_contents($absolute) : false;
        if ($bytes === false || !hash_equals($statement->getFileSha256(), hash('sha256', $bytes))) {
            error_log('financial bank archive: statement ' . $statement->getId() . ': ' . ($bytes === false ? 'missing file ' : 'SHA-256 mismatch for ') . $absolute);
            throw new BankRefusedException(
                BankRefusedException::ARCHIVE,
                'Die archivierte Datei des Kontoauszugs ' . ($bytes === false ? 'fehlt' : 'ist verändert (Prüfsumme stimmt nicht)') . ' — Datei '
                . $statement->getArchivePath() . ' aus der Datensicherung zurückholen; die Ursache steht im Fehlerprotokoll.'
            );
        }

        return $bytes;
    }

    /** Removes the files this instance wrote — for a caller whose unit of work rolled back after {@see store()}. */
    public function discardWritten(): void
    {
        foreach ($this->written as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->written = [];
    }
}
