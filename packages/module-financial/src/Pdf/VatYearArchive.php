<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Shared\Files\WriteOnceStore;

/**
 * The ARCHIVE of the stored yearly overviews of the VAT return («Ablegen …», owner S7-1 option b and
 * B-5 of 2026-11-25: an unchangeable PDF, every storing stays) — the pattern of
 * {@see VatReturnArchive}, in financial's write-once file archive, not the DMS:
 *
 *   data/archive/financial/vat-year/<tax year>/
 *       mwst-jahresuebersicht-<tax year>-<YYYYmmdd-HHiiss of the storing>-<sha256, 16 hex>.pdf
 *
 * Under the installation's `data/` — never served, in the release's SHARED `data` store and in the
 * «Daten» backup. The checksum in the name keeps an orphan of a killed unit of work from blocking
 * the next attempt. The writing rules are the shared {@see WriteOnceStore}'s: written under a
 * temporary name, read back and verified, moved into place; never overwritten; no delete path except
 * {@see discardWritten()} for the caller whose unit of work rolled back.
 */
final class VatYearArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/financial/vat-year';

    private readonly WriteOnceStore $store;

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $this->store = new WriteOnceStore($basePath);
    }

    /** «mwst-jahresuebersicht-2026-20270215-113200.pdf» — the name the stored PDF is offered under (kebab-case, file names follow the layer). */
    public static function fileName(int $taxYear, \DateTimeImmutable $storedAt): string
    {
        return 'mwst-jahresuebersicht-' . $taxYear . '-' . $storedAt->format('Ymd-His') . '.pdf';
    }

    /** The path a stored overview of $sha256 is archived under, relative to the installation. */
    public static function pathFor(int $taxYear, \DateTimeImmutable $storedAt, string $sha256): string
    {
        return self::DIR . '/' . $taxYear . '/' . substr(self::fileName($taxYear, $storedAt), 0, -4) . '-' . substr($sha256, 0, 16) . '.pdf';
    }

    /**
     * Stores $bytes and answers the path relative to the installation.
     *
     * @throws \RuntimeException the file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $bytes, string $sha256, int $taxYear, \DateTimeImmutable $storedAt): string
    {
        $relative = self::pathFor($taxYear, $storedAt, $sha256);
        $this->store->store($relative, $bytes, $sha256);

        return $relative;
    }

    /** The archived PDF when its SHA-256 still matches the row's; null when the file is missing or altered. */
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
