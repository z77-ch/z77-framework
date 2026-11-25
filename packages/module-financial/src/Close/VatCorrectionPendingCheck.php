<?php

namespace Z77\Module\Financial\Close;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\VatReturn\VatCorrectionTransit;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;

/**
 * Financial's close check for the transit account of the year-end VAT correction (ADR-042 addendum
 * 3, point 4 — the mandator's `vat-correction-pending`, KMU 2209 «MWST-Korrektur, noch nicht
 * abgerechnet»). Registered in `financialConfig` under `openWorkChecks` → `period-close`;
 * parameters as the scope documents them (`FiscalYearCloseService`): `fiscalYear`, `from`, `to`.
 *
 * A WARNING, never blocking — confirmed with the one tick «Warnungen gelesen» and so recorded in the
 * close protocol (`FiscalYearCloseLog::confirmed_warnings`): «Konto 2209 MWST-Korrektur, noch nicht
 * abgerechnet: Saldo {Betrag} — die Korrekturbuchung dazu steht noch aus.» (number and name from the
 * chart account the mandator names; the amount with the account statement's sign, debit − credit).
 *
 * WHAT is open (derived from the owner's sentence, not the bare balance): the balance at the end of
 * the fiscal year (`to`) PLUS the movements on the account dated after it, the year openings left
 * out ({@see VatCorrectionTransit::openAfter()}). The pattern books the amount on the year's last
 * day and the correction entry always LATER (the year's last VAT period is posted — that is why the
 * pattern exists), so the balance at the year end alone is never 0.00 and would claim «steht noch
 * aus» also when the correction is already booked; with the later movements a booked correction
 * silences the warning and a later year opening does not hide an amount still open.
 *
 * No key named, the mandator unreadable, the account not in the chart, or 0.00 open: no finding.
 * Reads in the caller's snapshot, writes nothing.
 */
final class VatCorrectionPendingCheck implements OpenWorkCheckInterface
{
    /** For a harness; the registry instantiates the check without any argument. */
    public function __construct(private readonly ?UnifiedEntityManager $em = null) {}

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        $em      = $this->em ?? DI::getUnifiedEntityManager();
        $transit = new VatCorrectionTransit($em);
        $number  = $transit->account();
        if ($number === null) {
            return;
        }
        $open = $transit->openAfter($to);
        if ($open->isZero()) {
            return;
        }
        $account = $em->getRepository(Account::class)->findOneBy(['number' => $number]);
        yield Finding::warning(
            'Konto ' . ($account instanceof Account ? $account->label() : $number) . ': Saldo ' . AmountFormat::of($open) . ' — die Korrekturbuchung dazu steht noch aus.',
            'account:' . $number
        );
    }
}
