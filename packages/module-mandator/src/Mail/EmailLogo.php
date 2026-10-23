<?php

namespace Z77\Module\Mandator\Mail;

use Z77\Shared\Files\LocalFiles;
use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Mail\InlineImage;
use Z77\Shared\Mail\InlineImageSource;

/**
 * The inline image `logo` of the HTML mails (owner decision 2026-10-23): the mandator's
 * «Logo (E-Mail)» (`email_logo_path`), alt = the mandator's name. Registered in this module's
 * config under `mailInlineImages`; kernel `InlineImages` asks it at SEND time.
 *
 * Degrades to «no logo» (null) rather than failing — the mail then goes out without it: no
 * record, no path stored, the file missing or unreadable, not a PNG / JPEG / GIF
 * ({@see InlineImage::fromFile()}), or no project root known. An unreadable record
 * (`MandatorUnavailableException`) is the caller's to log (`InlineImages` catches and logs
 * every throw).
 */
final class EmailLogo implements InlineImageSource
{
    public function image(): ?InlineImage
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        $path     = $mandator !== null ? trim($mandator->getEmailLogoPath()) : '';
        // A project file or a Drive `/media` path (kernel LocalFiles, 2026-10-23).
        $file = $path !== '' ? LocalFiles::path($path) : null;

        return $file !== null ? InlineImage::fromFile($file, $mandator->getName()) : null;
    }
}
