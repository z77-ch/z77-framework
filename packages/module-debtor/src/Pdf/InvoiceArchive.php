<?php

namespace Z77\Module\Debtor\Pdf;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceRevision;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Pdf\PdfConcat;

/**
 * The PDF ARCHIVE of issued document versions (ADR-054 decision 25, owner decision O13 of
 * 2026-10-23): the PDF of every issued version is rendered ONCE — at «Fakturieren» (revision 1;
 * a re-invoice under the same number, step 4, writes revision 2, 3, …) — and stored as a FILE;
 * every later PDF of that version (the single PDF, «Alle als ein PDF», the ZIP, the mail
 * attachment `invoice-pdf`) is THAT file, never a re-render.
 *
 * Where (owner decision 2026-10-23, confirmed): under the installation's `data/` —
 *
 *   data/archive/debtor/<YYYY>/<number>-r<revision>-<stamp>.pdf
 *
 * `<YYYY>` the year of the invoice date. Non-public (`data/` is never served), in the release's
 * shared `data` store (`.releases/target.json` → `shared`) and in the «Daten» backup (the whole
 * `data/` tree). The module stays independent of module-dms (one writer, no seam).
 * `invoice_revision` records the version: `file_path` (relative to the installation),
 * `file_sha256` (of the stored bytes), `file_name` (the name it is offered under).
 *
 * Reading ({@see pdf()}):
 *
 *   - a DRAFT renders live — its preview (no number, no stamp, nothing archived);
 *   - a numbered document WITH a revision row delivers the archived file of its CURRENT revision,
 *     its SHA-256 checked against the row; a missing or altered file is an
 *     {@see InvoiceArchiveException} with a German sentence (logged) — never a silent re-render;
 *   - a numbered document WITHOUT a revision row — posted before the Faktura-Journal (before
 *     2026-10-23, no stamp) — renders live from its snapshot, as it always did.
 *
 * Writing ({@see archive()}) happens INSIDE the unit of work that issues the version, after the
 * number and the stamp are set and before the commit: the revision row only commits together
 * with its file. A unit of work that rolls back after the file was written leaves an orphan file
 * that no row names ({@see discardWritten()} removes it when the caller can tell).
 */
final class InvoiceArchive
{
    /** The archive root, relative to the installation. */
    public const DIR = 'data/archive/debtor';

    private readonly string $base;

    /** @var list<string> absolute paths written by this instance — removed by {@see discardWritten()} */
    private array $written = [];

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $basePath = null)
    {
        $base = $basePath ?? (defined('ABS_BASE_PATH') ? (string) constant('ABS_BASE_PATH') : '');
        if ($base === '') {
            throw new \LogicException('InvoiceArchive needs the installation root (ABS_BASE_PATH)');
        }
        $this->base = rtrim(str_replace('\\', '/', $base), '/');
    }

    /**
     * Render the issued, stamped $document ONCE, store the file and return its revision row
     * (NOT persisted — the caller persists it in its unit of work).
     *
     * @throws \RuntimeException the file could not be written — the caller's unit of work rolls back
     */
    public function archive(Invoice $document, string $by, \DateTimeImmutable $at, ?string $reason = null): InvoiceRevision
    {
        if ($document->isDraft() || !$document->isNumbered() || $document->getDocumentStamp() === null) {
            throw new \LogicException('Only an issued, stamped document is archived — ' . $document->documentName() . ' is not');
        }
        $bytes    = InvoicePdf::of($document, $this->em)->output();
        $relative = self::DIR . '/' . $document->getInvoiceDate()->format('Y') . '/'
            . $document->getNumber() . '-r' . $document->getRevision() . '-' . $document->getDocumentStamp() . '.pdf';
        $absolute = $this->base . '/' . $relative;
        $dir      = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("InvoiceArchive: cannot create the directory {$dir}");
        }
        if (@file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException("InvoiceArchive: cannot write {$absolute}");
        }
        $this->written[] = $absolute;

        return new InvoiceRevision($document, $document->getRevision(), (string) $document->getDocumentStamp(), $relative, hash('sha256', $bytes), InvoicePdf::fileName($document), $reason, $by, $at);
    }

    /**
     * The PDF bytes of $document as it is delivered — see the class comment.
     *
     * @throws InvoiceArchiveException the archived file of an issued version is missing or altered
     */
    public function pdf(Invoice $document): string
    {
        $revision = $document->isDraft() ? null : $this->revisionOf($document);
        if ($revision === null) {
            return InvoicePdf::of($document, $this->em)->output();   // a preview, or a document posted before the journal
        }
        $absolute = $this->base . '/' . $revision->getFilePath();
        $bytes    = is_file($absolute) ? @file_get_contents($absolute) : false;
        if ($bytes === false) {
            throw $this->failure($document, $revision, 'fehlt', "missing file {$absolute}");
        }
        if (!hash_equals($revision->getFileSha256(), hash('sha256', $bytes))) {
            throw $this->failure($document, $revision, 'ist verändert (Prüfsumme stimmt nicht)', "SHA-256 mismatch for {$absolute}");
        }

        return $bytes;
    }

    /**
     * Several documents in ONE file, in the order given — each its delivered PDF ({@see pdf()}),
     * joined page for page ({@see PdfConcat}), the archived bytes untouched.
     *
     * @param list<Invoice> $documents with their lines loaded, at least one
     * @throws InvoiceArchiveException
     */
    public function combined(array $documents): string
    {
        if ($documents === []) {
            throw new \InvalidArgumentException('A combined PDF needs at least one document');
        }
        if (count($documents) === 1) {
            return $this->pdf($documents[0]);
        }
        $numbers = array_filter(array_map(static fn(Invoice $d) => $d->getNumber(), $documents));

        return PdfConcat::of(array_map(fn(Invoice $d) => $this->pdf($d), $documents), $numbers === [] ? 'Dokumente' : 'Dokumente ' . min($numbers) . ' bis ' . max($numbers));
    }

    /** The revision row of $document's CURRENT revision; null when none was archived (a draft, a document posted before the journal). */
    public function revisionOf(Invoice $document): ?InvoiceRevision
    {
        if ($document->getId() === null) {
            return null;
        }

        return $this->em->getRepository(InvoiceRevision::class)->findOneBy(['invoice' => $document->getId(), 'revision' => $document->getRevision()]);
    }

    /** Removes the files this instance wrote — for a caller whose unit of work rolled back after {@see archive()}. */
    public function discardWritten(): void
    {
        foreach ($this->written as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->written = [];
    }

    private function failure(Invoice $document, InvoiceRevision $revision, string $what, string $log): InvoiceArchiveException
    {
        error_log('debtor invoice archive: ' . $document->documentName() . ' revision ' . $revision->getRevision() . ': ' . $log);

        return new InvoiceArchiveException(
            'Das archivierte PDF von ' . $document->documentName() . ' (Version ' . $revision->getRevision() . ', ID ' . $revision->getDocumentStamp() . ') '
            . $what . ' — es wird nicht neu erzeugt. Datei ' . $revision->getFilePath() . ' aus der Datensicherung zurückholen; die Ursache steht im Fehlerprotokoll.'
        );
    }
}
