<?php

namespace Z77\Module\Mandator\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Mandator\Logo\LogoFiles;
use Z77\Module\Mandator\Logo\LogoUses;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 11 («Ersteinrichtung», slot 110): the company logo is missing for a use the
 * installation shows — the PDF letterhead, the mails, the login, the website (owner 2026-11-20, the
 * logo set-up window; `LogoUses::served()` — the website only with module-frontend, a use the
 * project renders with its own design does not count). A use counts as set when the window stored
 * a document for it ({@see LogoFiles::present()}; the login also with the e-mail's). The old path
 * fields no longer count (owner 2026-11-20): a project that had only paths sees this step once after
 * the deploy and uploads through the window.
 *
 * The line opens the logo WINDOW (`opens()`, `data-window-open`), not the mandator page. It stays
 * silent for good after «Später / kein Logo» (`logo_step_dismissed`) until a logo is saved through
 * the window again. No mandator at all is step 2's line, not this one.
 *
 * One query (the record); a stored document id counts without reading it.
 */
final class CompanyLogoCheck extends SetupCheck
{
    /** @var list<string> */
    private array $missing = [];

    public function id(): string
    {
        return 'mandator.logo';
    }

    public function order(): int
    {
        return 110;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'mandator', 'logo'];
    }

    public function opens(): string
    {
        return DashboardEntry::OPEN_WINDOW;
    }

    public function label(): string
    {
        $served = LogoUses::served();
        if (count($this->missing) === count($served)) {
            return 'Firmenlogo hinterlegen';
        }
        return 'Firmenlogo für ' . LogoUses::words($this->missing, LogoUses::STEP_WORDS) . ' hinterlegen';
    }

    public function isOpen(): bool
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        if ($mandator === null || $mandator->isLogoStepDismissed()) {
            return false;
        }
        $this->missing = array_values(array_filter(
            LogoUses::served(),
            static fn(string $use): bool => !LogoFiles::present($mandator, $use)
        ));

        return $this->missing !== [];
    }
}
