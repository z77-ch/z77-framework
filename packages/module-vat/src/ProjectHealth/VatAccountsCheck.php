<?php

namespace Z77\Module\Vat\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 4 («Ersteinrichtung», owner 2026-11-19): an ACTIVE tax code of a category that posts
 * VAT (standard / reduced / special → «Geschuldete MWST», input-material / input-other → the two
 * «Vorsteuer» accounts, the three input-tax corrections → «Vorsteuer Investitionen, übriger
 * Betriebsaufwand») whose VAT account is not usable — empty on the mandator record, or not a
 * postable, active account of the chart. A code without such an account cannot be posted with; the
 * fix is the mandator's account tab, the line points at «MWST-Codes» where the codes are seen.
 *
 * module-vat does not require the bookkeeping (VAT-NAV-001 stance): the accounts are asked of
 * financial's `LedgerService::vatAccountFor()` / `accountExists()` BY NAME, and only when financial
 * is a registered module — without it the check says nothing (no chart to compare). A leftover
 * `vatAccounts` key or an unreadable mandator makes `vatAccountFor()` throw: the bridge shows
 * «nicht verfügbar». Reads: the codes (file master data, a few dozen rows), then at most three accounts.
 *
 * Silent while the mandator is NOT liable to VAT (MANDATOR-VAT-001, owner 2026-11-20): its
 * documents carry no VAT and post to no VAT account, so a missing one blocks nothing. The answer is
 * module-mandator's `CurrentMandator::liableToVat()` (none saved = liable) — asked BY NAME, like
 * financial: module-vat does not require module-mandator; without it the company counts as liable.
 *
 * The optional constructor arguments are the harness seams: `fn(string $category): bool` — true
 * when the category's VAT account is usable (the default asks financial); `fn(): bool` — the
 * mandator is liable (the default asks module-mandator).
 */
final class VatAccountsCheck extends SetupCheck
{
    /**
     * Categories that post VAT to ONE account of the bookkeeping — the three input-tax corrections
     * post to «Vorsteuer Investitionen, übriger Betriebsaufwand» (ADR-041 addendum 2026-11-25,
     * point 3). Zero and exempt need none; reverse charge posts to the owed and an input account,
     * asked through the codes of those categories.
     */
    private const POSTING_CATEGORIES = ['standard', 'reduced', 'special', 'input-material', 'input-other', 'input-subsequent', 'input-correction', 'input-reduction'];

    /** @var (\Closure(string): bool)|null */
    private readonly ?\Closure $accountUsable;

    /** @var (\Closure(): bool)|null */
    private readonly ?\Closure $liableToVat;

    public function __construct(?\Closure $accountUsable = null, ?\Closure $liableToVat = null)
    {
        $this->accountUsable = $accountUsable;
        $this->liableToVat   = $liableToVat;
    }

    public function id(): string
    {
        return 'vat.accounts';
    }

    public function order(): int
    {
        return 40;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'tax-code', 'list'];
    }

    public function label(): string
    {
        return 'MWST-Codes: Konto fehlt im Kontenplan';
    }

    public function isOpen(): bool
    {
        $categories = [];
        foreach (DI::getUnifiedEntityManager()->getRepository(TaxCode::class)->findAll() as $code) {
            if ($code->isActive() && in_array($code->getCategory(), self::POSTING_CATEGORIES, true)) {
                $categories[$code->getCategory()] = true;
            }
        }
        if ($categories === [] || !($this->liableToVat ?? self::mandatorLiable(...))()) {
            return false;
        }
        foreach (array_keys($categories) as $category) {
            if (!($this->accountUsable ?? $this->ledgerAccountUsable(...))($category)) {
                return true;
            }
        }

        return false;
    }

    /** module-mandator's answer (none saved / not readable = liable); without the module installed: liable. */
    private static function mandatorLiable(): bool
    {
        $class = 'Z77\Module\Mandator\Services\CurrentMandator';
        if (!class_exists($class)) {
            return true;
        }

        return (new $class(DI::getUnifiedEntityManager()))->liableToVat();
    }

    /** True when financial is not registered here (nothing to compare against) or the category's account is a postable, active chart account. */
    private function ledgerAccountUsable(string $category): bool
    {
        $service = 'Z77\Module\Financial\Services\LedgerService';
        if (!class_exists($service) || DI::getModuleManager()->getModuleConfig('financial') === null) {
            return true;
        }
        $ledger = new $service(DI::getUnifiedEntityManager());
        $number = $ledger->vatAccountFor($category);

        return $number !== null && $ledger->accountExists($number);
    }
}
