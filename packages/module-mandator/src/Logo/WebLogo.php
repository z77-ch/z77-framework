<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Site\SiteLogo;
use Z77\Shared\Site\SiteLogoSource;

/**
 * The public website's logo (2026-11-20, the logo set-up window «Website»), registered under
 * `siteLogo`; module-frontend's default header and footer show it through `partials/brandMark`
 * with `siteLogo` (kernel `Site\SiteLogo`).
 *
 * The answer is the `/media` address of the website variant (`web_logo_document_id`) in the
 * public partition «Website-Logo» — linked, so the browser caches it; the `?v=` token changes with
 * the bytes. Only while the DMS delivers it openly (`ModuleDrive::publicUrl()`): set to «geschützt»
 * in the Drive, in the trash, or module-dms gone → null, the wordmark. There is no path fallback —
 * the website had no mandator logo before. Display size = half the stored pixels (density 2).
 */
final class WebLogo implements SiteLogoSource
{
    public function siteLogo(): ?SiteLogo
    {
        if (!LogoDrive::available()) {
            return null;
        }
        $mandator   = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        $documentId = $mandator?->logoDocument(LogoUses::WEB);
        $url        = $documentId !== null ? (new LogoDrive())->publicUrl($documentId) : null;

        return $url !== null ? new SiteLogo($url, $mandator->getName()) : null;
    }
}
