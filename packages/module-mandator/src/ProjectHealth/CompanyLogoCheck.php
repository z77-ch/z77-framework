<?php

namespace Z77\Module\Mandator\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Files\LocalFiles;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 11 («Ersteinrichtung», owner 2026-11-19): the documents or the mails go out without
 * the company logo — «Logo» (the letterhead of every PDF) or «Logo (E-Mail)» (the top of the HTML
 * mails) is empty, or names a file this server cannot find (`LocalFiles::path()`, the reader
 * the letterhead and `EmailLogo` use). No mandator at all is step 2's line, not this one.
 *
 * One query (the record) and at most two file lookups.
 */
final class CompanyLogoCheck extends SetupCheck
{
    private string $which = '';

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
        return ['backend', 'finance', 'mandator', 'edit'];
    }

    public function label(): string
    {
        return 'Firmenlogo für ' . $this->which . ' hinterlegen';
    }

    public function isOpen(): bool
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        if ($mandator === null) {
            return false;
        }
        $this->which = match (true) {
            self::missing($mandator->getLogoPath())      => 'Belege',
            // The «Logo (E-Mail)» is also the login screens' logo (owner 2026-11-20, `brandLogo`).
            self::missing($mandator->getEmailLogoPath()) => 'E-Mails und Anmeldung',
            default                                      => '',
        };

        return $this->which !== '';
    }

    private static function missing(string $stored): bool
    {
        return trim($stored) === '' || LocalFiles::path(trim($stored)) === null;
    }
}
