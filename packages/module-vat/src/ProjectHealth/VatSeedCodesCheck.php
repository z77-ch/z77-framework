<?php

namespace Z77\Module\Vat\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Vat\Services\VatSeedUpgrade;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 41 «MWST-Codes: Code mit fremder Kategorie» (ADR-041 addendum 2026-11-25, point 5):
 * runs the upgrade of the seed codes ({@see VatSeedUpgrade} — the missing ones of UL, VE, VK, VN,
 * BM, BI, BM2, BI2 are created) and is OPEN while one of those names carries another category than
 * the seed's — an installation's own code under a name the form maps by (the input field of a
 * reverse-charge code is found by its name), which the upgrade never changes. The fix is by hand
 * in «MWST-Codes»: the foreign code's category set right.
 *
 * The optional constructor argument is the harness seam: `fn(): list<string>` — the foreign codes
 * (the default runs the upgrade on the installation's entity manager, today).
 */
final class VatSeedCodesCheck extends SetupCheck
{
    /** @var (\Closure(): list<string>)|null */
    private readonly ?\Closure $foreign;

    public function __construct(?\Closure $foreign = null)
    {
        $this->foreign = $foreign;
    }

    public function id(): string
    {
        return 'vat.seed-codes';
    }

    public function order(): int
    {
        return 41;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'tax-code', 'list'];
    }

    public function label(): string
    {
        return 'MWST-Codes: Code mit fremder Kategorie';
    }

    public function isOpen(): bool
    {
        return ($this->foreign ?? self::upgrade(...))() !== [];
    }

    /** @return list<string> */
    private static function upgrade(): array
    {
        return VatSeedUpgrade::apply(DI::getUnifiedEntityManager(), new \DateTimeImmutable('today'))['foreign'];
    }
}
