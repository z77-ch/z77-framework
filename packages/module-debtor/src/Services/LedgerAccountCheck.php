<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «May a posting go to account n?» — asked of module-financial WHEN THAT
 * MODULE IS THERE, and answered with «cannot tell» when it is not.
 *
 * debtor only `suggest`s `z77/module-financial` (ADR-040 decision 5, plan
 * §2): an installation may keep its books elsewhere, and then nothing in
 * this module may fatal because a financial class is missing. So the
 * dependency is resolved by NAME, not by a `use` — {@see LEDGER_SERVICE} —
 * and every answer is three-valued:
 *
 *   - `true`  — the account exists, is postable and is active;
 *   - `false` — financial is installed and refuses the number;
 *   - `null`  — financial is not usable here; the caller may not treat this
 *     as a failure. A soft check, exactly as it says on the tin: the account
 *     numbers in `debtorConfig` and on a {@see \Z77\Module\Debtor\Entities\PaymentTarget}
 *     are then simply unverified strings until part 2 posts with them.
 *
 * **«Installed» means TWO things** (review 2026-09-22), and the second one
 * is what actually decides: the class must autoload, AND `financial` must be
 * a REGISTERED module (`ModuleManager::getModuleConfig('financial')`). A
 * package that sits in `vendor/` but is not in the project's
 * `moduleManager` config has no config, no announced entities and no booted
 * Doctrine metadata for `Account` — asking it would not answer «no», it
 * would fatal, and with it every debtor list. Registration is also what a
 * project actually switches when it decides to keep its books elsewhere.
 *
 * The check is the READ half of what becomes `AccountingGateway` in part 2
 * (plan §6.6) — same soft boundary, same one class in debtor that knows
 * financial's name. It never writes and never opens a unit of work; it wraps
 * `LedgerService::accountExists()` (plan §5.4, «for configuration
 * validation»), whose second production caller this is.
 */
final class LedgerAccountCheck
{
    /** The class this module asks — named as a string so its absence is a `false`, not a fatal. */
    public const LEDGER_SERVICE = 'Z77\\Module\\Financial\\Services\\LedgerService';

    /** The module that must be registered for the class to be usable at all. */
    public const LEDGER_MODULE = 'financial';

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** Whether the bookkeeping this check asks is installed AND registered as a module. */
    public function available(): bool
    {
        return class_exists(self::LEDGER_SERVICE)
            && DI::getModuleManager()->getModuleConfig(self::LEDGER_MODULE) !== null;
    }

    /**
     * True / false / null — see the class docblock. A plain read, no lock:
     * `post()` checks again under its own rules when part 2 books.
     */
    public function isPostable(string $number): ?bool
    {
        $number = trim($number);
        if ($number === '' || !$this->available()) {
            return null;
        }

        $ledger = new (self::LEDGER_SERVICE)($this->em);

        return (bool) $ledger->accountExists($number);
    }
}
