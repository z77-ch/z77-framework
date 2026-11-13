<?php

namespace Z77\Module\Debtor\Mail;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Module\Debtor\Pdf\DunningPdf;
use Z77\Module\Debtor\Services\DunningService;
use Z77\Module\Mailing\Attachments\AttachmentProvider;
use Z77\Shared\Mail\Attachment;

/**
 * The outbox attachment `dunning-pdf:<notice id>` (owner 2026-11-13): the dunning notice as PDF,
 * rendered at SEND time from the notice row, the invoice's snapshot, the level's current text and
 * the mandator — exactly what «PDF» on the screen renders ({@see DunningPdf::of()}); nothing is
 * stored, the mail holds only the reference. Registered in this module's config under
 * `mailAttachments`; only ever loaded where module-mailing is installed.
 *
 *   - {@see accepts()} at QUEUE time: the id names an existing notice;
 *   - {@see resolve()} at SEND time: the PDF; a notice gone since throws — a retry of that mail
 *     (the outbox's contract), the cause in `last_error`.
 */
final class DunningPdfAttachment implements AttachmentProvider
{
    public const TYPE = 'dunning-pdf';

    private const PATTERN = '/^[1-9]\d{0,9}$/';

    public function accepts(string $id): bool
    {
        return $this->notice($id) !== null;
    }

    public function resolve(string $id): Attachment
    {
        $notice = $this->notice($id);
        if ($notice === null) {
            throw new \RuntimeException("Dunning notice {$id} is missing — no PDF to attach.");
        }
        $em    = DI::getUnifiedEntityManager();
        $level = (new DunningService($em, 'mail-outbox'))->levelOf($notice);   // reads only; no session at send time

        return new Attachment(DunningPdf::fileName($notice, $level), 'application/pdf', DunningPdf::of($notice, $level, $em)->output());
    }

    private function notice(string $id): ?DunningNotice
    {
        return preg_match(self::PATTERN, $id) === 1 ? DI::getUnifiedEntityManager()->getRepository(DunningNotice::class)->find((int) $id) : null;
    }
}
