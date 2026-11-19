<?php

namespace Z77\Module\Mandator\Dashboard;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;

/**
 * mandator's entries on the backend dashboard (ADR-052), registered under
 * `dashboard` in `mandatorConfig` — todo → Mandant:
 *
 *   - liable to VAT without a UID (the VAT number is the UID + «MWST»; a
 *     document with VAT must name it).
 *
 * «Mandant nicht erfasst» moved to the project checks (2026-11-19): the setup
 * step `mandator.accounts` says «Mandant erfassen» in its place in the setup
 * order (`ProjectHealth\MandatorAccountsCheck`) — so it is shown once.
 *
 * The IBAN is not the mandator's (it lives on debtor's payment target, owner
 * 2026-09-23) — debtor reports a missing one. An unreadable record
 * (`MandatorUnavailableException`) throws: the dashboard shows the module as
 * «nicht verfügbar».
 */
final class MandatorDashboard implements DashboardContributor
{
    private const TARGET = ['backend', 'finance', 'mandator', 'edit'];

    public function entries(AuthUser $user): iterable
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        if ($mandator !== null && $mandator->isLiableToVat() && trim($mandator->getUid()) === '') {
            return [DashboardEntry::pending(Severity::Todo, 'Mandant MWST-pflichtig ohne UID', self::TARGET)];
        }

        return [];
    }
}
