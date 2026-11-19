<?php

namespace Z77\Module\Mandator\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Mandator\Services\MandatorAccounts;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 2 («Ersteinrichtung», owner 2026-11-19): the mandator is not saved yet — «Mandant
 * erfassen» (was the dashboard line «Mandant nicht erfasst», moved here from `MandatorDashboard`)
 * — or one of its accounts with a KMU start value (`MandatorAccounts::DEFAULTS`: debtor, VAT,
 * year opening) is empty or not a postable, active account of the chart — «Mandant: Konten
 * zuweisen». `private` / `bank-transfer` have no start value and are asked only when a mixed bank
 * account needs them (financial's `financial.bank-mixed`).
 *
 * Without module-financial registered the numbers cannot be checked (`LedgerAccountCheck`
 * answers null) — then only an EMPTY field counts. An unreadable record
 * (`MandatorUnavailableException`) throws: the bridge shows «nicht verfügbar».
 *
 * Two queries: the record, and the chart rows of its numbers (`LedgerAccountCheck::notPostable()`).
 */
final class MandatorAccountsCheck extends SetupCheck
{
    private bool $missing = false;

    public function id(): string
    {
        return 'mandator.accounts';
    }

    public function order(): int
    {
        return 20;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'mandator', 'edit'];
    }

    public function label(): string
    {
        return $this->missing ? 'Mandant erfassen' : 'Mandant: Konten zuweisen';
    }

    public function isOpen(): bool
    {
        $em       = DI::getUnifiedEntityManager();
        $mandator = (new CurrentMandator($em))->find();
        $this->missing = $mandator === null;
        if ($mandator === null) {
            return true;
        }
        $numbers = array_map(static fn(string $key) => $mandator->account($key), array_keys(MandatorAccounts::DEFAULTS));
        if (in_array('', $numbers, true)) {
            return true;
        }

        return ((new LedgerAccountCheck($em))->notPostable($numbers) ?? []) !== [];
    }
}
