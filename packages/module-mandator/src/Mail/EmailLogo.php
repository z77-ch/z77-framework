<?php

namespace Z77\Module\Mandator\Mail;

use Z77\Core\DI;
use Z77\Module\Mandator\Logo\LogoFiles;
use Z77\Module\Mandator\Logo\LogoUses;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Mail\InlineImage;
use Z77\Shared\Mail\InlineImageSource;

/**
 * The inline image `logo` of the HTML mails (owner decision 2026-10-23): the mandator's e-mail
 * logo, alt = the mandator's name. Registered in this module's config under `mailInlineImages`;
 * kernel `InlineImages` asks it at SEND time.
 *
 * Which file (2026-11-20, the logo set-up): the e-mail variant the logo window stored in the DMS
 * (`email_logo_document_id`, 2× its display size — declared as density 2, so the mail shows it at
 * half its pixels), else the old «Logo (E-Mail)» path (`email_logo_path`, the display size) —
 * {@see LogoFiles}.
 *
 * Degrades to «no logo» (null) rather than failing — the mail then goes out without it: no
 * record, nothing stored, the file missing or unreadable, not a PNG / JPEG / GIF
 * ({@see InlineImage::fromFile()}). An unreadable record (`MandatorUnavailableException`) is the
 * caller's to log (`InlineImages` catches and logs every throw).
 *
 * The login screens' logo is no longer this class (it was on 2026-11-20 for a few hours):
 * `Logo\LoginLogo` answers `brandLogo` from the login variant.
 */
final class EmailLogo implements InlineImageSource
{
    public function image(): ?InlineImage
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        $file     = $mandator !== null ? LogoFiles::of($mandator, LogoUses::EMAIL) : null;

        return $file !== null ? InlineImage::fromFile($file['path'], $mandator->getName(), $file['density']) : null;
    }
}
