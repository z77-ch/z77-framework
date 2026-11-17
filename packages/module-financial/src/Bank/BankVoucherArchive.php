<?php

namespace Z77\Module\Financial\Bank;

use Z77\Shared\Files\WriteOnceStore;

/**
 * The ARCHIVE of the files attached to a direct posting of a bank movement — «Geschäftlich buchen
 * …» with a tax code (ADR-061 decision 15, stage C2): the voucher of a ledger entry is a booking
 * record and is kept unaltered for the retention period, next to the statement files:
 *
 *   data/archive/financial/bank-voucher/<YYYY>/<YYYYMMDD>-<bank account code>-<movement id>-<sha256, 16 hex>.<pdf|jpg|png>
 *
 * `<YYYY>` / `<YYYYMMDD>` the movement's booking date — the date of the entry it is the voucher
 * of. `bank_posting` names path, SHA-256 and the original file name.
 *
 * WHY HERE and not in the DMS (ADR-060 decision 7; the open point of ADR-061's consequences):
 * financial depends on no document module, and a voucher needs none of what a document store
 * offers (folders, sharing, versions, delete) — it needs to be written once, never changed and
 * found again from its entry. That is the shared {@see WriteOnceStore} (kernel shared, Rule 9:
 * the same block as the camt.053 and camt.054 archives): written and verified before it is moved
 * into place, never overwritten, no delete path except {@see discardWritten()} for a posting
 * whose unit of work rolled back.
 */
final class BankVoucherArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/financial/bank-voucher';

    /** What a voucher may be: the sniffed media type → the file extension it is stored under. */
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    private readonly WriteOnceStore $store;

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $this->store = new WriteOnceStore($basePath);
    }

    /** The media type of $bytes when it is one a voucher may be ({@see TYPES}), else null — sniffed, never the client's word. */
    public static function typeOf(string $bytes): ?string
    {
        $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return isset(self::TYPES[$type]) ? $type : null;
    }

    /**
     * Stores the voucher and answers its path relative to the installation.
     *
     * @throws \RuntimeException the file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $bytes, string $sha256, string $type, \DateTimeImmutable $bookingDate, string $bankAccountCode, int $movementId): string
    {
        $relative = self::DIR . '/' . $bookingDate->format('Y') . '/' . $bookingDate->format('Ymd') . '-'
            . preg_replace('/[^a-z0-9-]/', '', strtolower($bankAccountCode)) . '-' . $movementId . '-' . substr($sha256, 0, 16) . '.' . (self::TYPES[$type] ?? 'bin');
        $this->store->store($relative, $bytes, $sha256);

        return $relative;
    }

    /** The archived voucher, its checksum verified; null when the file is missing or altered. */
    public function bytes(string $relative, string $sha256): ?string
    {
        return $this->store->bytes($relative, $sha256);
    }

    /** Removes the files this instance wrote — for a caller whose unit of work rolled back after {@see store()}. */
    public function discardWritten(): void
    {
        $this->store->discardWritten();
    }
}
