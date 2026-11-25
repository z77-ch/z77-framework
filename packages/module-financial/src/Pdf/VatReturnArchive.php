<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Shared\Files\WriteOnceStore;

/**
 * The ARCHIVE of the posted VAT returns' vouchers (ADR-041 addendum 2026-11-24, point 14: «on
 * posting, the PDF with the breakdown per field and account is archived with the settlement
 * entry: the compilation for a return is an accounting voucher»; owner decision of 2026-11-24 — a
 * write-once file archive, not the DMS; financial.md «MWST buchen», the voucher):
 *
 *   data/archive/financial/vat-return/<YYYY of the period's last day>/
 *       mwst-abrechnung-<from>-<to>-r<revision>-<sha256, 16 hex>.pdf
 *
 * Under the installation's `data/`, the pattern of {@see \Z77\Module\Financial\Bank\BankStatementArchive}:
 * never served, in the release's SHARED `data` store (`.releases/RULES.md` rule 5 — `data` is a
 * shared name, so a deploy never replaces it) and in the «Daten» backup. The checksum in the
 * name: a voucher written by a unit of work that was then killed before its commit (no
 * {@see discardWritten()} ran) stays an orphan file instead of blocking the next attempt of the
 * same revision for ever — the PDF of every attempt differs (it prints the time).
 *
 * The writing rules are the shared {@see WriteOnceStore}'s: written under a temporary name, read
 * back and verified, then moved into place; never overwritten, no delete path except
 * {@see discardWritten()} for the caller whose unit of work rolled back. A lifted return keeps
 * its file byte for byte (whether it gets a mark is an open owner question — financial.md «lifting
 * a posted return»); the re-created return writes
 * its own (`-r2`).
 */
final class VatReturnArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/financial/vat-return';

    private readonly WriteOnceStore $store;

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $this->store = new WriteOnceStore($basePath);
    }

    /** «mwst-abrechnung-2026-07-01-2026-09-30-r1.pdf» — the name the voucher is offered under (kebab-case, file names follow the layer). */
    public static function fileName(VatPeriod $period, int $revision): string
    {
        return 'mwst-abrechnung-' . $period->from->format('Y-m-d') . '-' . $period->to->format('Y-m-d') . '-r' . $revision . '.pdf';
    }

    /** The path a voucher of $sha256 is archived under, relative to the installation. */
    public static function pathFor(VatPeriod $period, int $revision, string $sha256): string
    {
        return self::DIR . '/' . $period->to->format('Y') . '/' . substr(self::fileName($period, $revision), 0, -4) . '-' . substr($sha256, 0, 16) . '.pdf';
    }

    /**
     * Stores $bytes and answers the path relative to the installation.
     *
     * @throws \RuntimeException the file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $bytes, string $sha256, VatPeriod $period, int $revision): string
    {
        $relative = self::pathFor($period, $revision, $sha256);
        $this->store->store($relative, $bytes, $sha256);

        return $relative;
    }

    /** The archived voucher when its SHA-256 still matches the row's; null when the file is missing or altered. */
    public function bytes(string $path, string $sha256): ?string
    {
        return $this->store->bytes($path, $sha256);
    }

    /** Removes the files this instance wrote — for a caller whose unit of work rolled back after {@see store()}. */
    public function discardWritten(): void
    {
        $this->store->discardWritten();
    }
}
