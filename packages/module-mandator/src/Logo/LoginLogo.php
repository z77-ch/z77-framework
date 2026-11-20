<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Mail\InlineImage;
use Z77\Shared\Site\BrandLogoSource;

/**
 * The installation's brand logo — the backend login screens (owner 2026-11-20, LOGIN-LOGO-001),
 * registered under `brandLogo`; kernel `Site\BrandLogo` inlines what it answers (at most 96 KB).
 *
 * Which file ({@see LogoFiles}): the login variant the logo window stored in the DMS
 * (`login_logo_document_id`, 2× its display size, density 2), else the old «Logo (E-Mail)» path
 * (`email_logo_path`) — the login showed that file since LOGIN-LOGO-001. Alt = the mandator's name.
 * Nothing → null, the mark shows its wordmark.
 */
final class LoginLogo implements BrandLogoSource
{
    public function logo(): ?InlineImage
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        $file     = $mandator !== null ? LogoFiles::of($mandator, LogoUses::LOGIN) : null;

        return $file !== null ? InlineImage::fromFile($file['path'], $mandator->getName(), $file['density']) : null;
    }
}
