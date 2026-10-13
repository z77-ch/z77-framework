<?php

namespace Z77\Module\Financial\Close;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * Financial's own close check (owner decision 2026-10-13, review 2026-10-13 §2: «collective
 * account 1100 ≠ open items, nothing prevents or reports it»): at the year close, every
 * registered {@see SubledgerReconciliationInterface} says what its collective account should
 * show at the year's last day, and this check compares it with the account's balance in the
 * journal. Registered in `financialConfig` under `openWorkChecks` → `period-close`; financial
 * still knows no module — the modules register their reconciliations
 * ({@see SubledgerReconciliations}). Nothing registered → nothing to say.
 *
 * Findings, all WARNINGS (decision 2026-10-13, to confirm by the owner): the closer must
 * confirm them explicitly and the protocol keeps them (`confirmed_warnings`) — a reported,
 * recorded difference, not a block, because until P5 brings the opening entry the journal of a
 * later year carries no opening balance (FIN-REPORT-001) and every such year would be blocked
 * by the carried balance alone; the hard guard is the account flag
 * «nur durch Module bebuchbar» (`Account::$modulePostingOnly`), this is the second line:
 *
 *   - the balance differs: both figures, the difference, and the MANUAL entries on the account
 *     with their net — the usual cause, now that the flag refuses new ones;
 *   - the account is not in the chart (or a group): the module names an account nobody posts to;
 *   - the account is not flagged «nur durch Module bebuchbar»: a manual entry could still land
 *     on it — the flag and the module's account setting disagree.
 *
 * Parameters as the scope documents them (`FiscalYearCloseService`): `fiscalYear` (the code),
 * `from`, `to`. Reads in the caller's snapshot, writes nothing.
 */
final class SubledgerReconciliationCheck implements OpenWorkCheckInterface
{
    /** How many manual entry numbers a finding lists before it says «…». */
    public const LISTED_ENTRIES = 12;

    public function __construct(private readonly ?SubledgerReconciliations $reconciliations = null, private readonly ?UnifiedEntityManager $em = null) {}

    public function check(string $scope, array $parameters): iterable
    {
        $code = $parameters['fiscalYear'] ?? null;
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!is_string($code) || !$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            return;
        }
        $em    = $this->em ?? DI::getUnifiedEntityManager();
        $setup = $this->reconciliations ?? SubledgerReconciliations::fromModules(DI::getModuleManager());

        /** @var FiscalYearRepository $years */
        $years = $em->getRepository(FiscalYear::class);
        $year  = $years->findOneBy(['code' => $code]);
        if ($year === null) {
            return;
        }
        /** @var AccountRepository $accounts */
        $accounts = $em->getRepository(Account::class);
        /** @var JournalLineRepository $lines */
        $lines    = $em->getRepository(JournalLine::class);
        $currency = LedgerService::baseCurrency();
        $fromDay  = $from->format('Y-m-d');
        $toDay    = $to->format('Y-m-d');
        $at       = $to->format('d.m.Y');

        foreach ($setup->all() as $reconciliation) {
            foreach ($reconciliation->balances($code, $from, $to) as $expected) {
                if (!$expected instanceof SubledgerBalance) {
                    throw new \RuntimeException('❌ ' . get_class($reconciliation) . ' must yield ' . SubledgerBalance::class . ' objects, got ' . get_debug_type($expected) . '.');
                }
                $reference = 'account:' . $expected->account;
                $account   = $accounts->findOneBy(['number' => $expected->account]);
                if ($account === null || !$account->isPostable()) {
                    yield Finding::warning(
                        "Abgleich {$expected->label}: das Sammelkonto {$expected->account} gibt es im Kontenplan nicht als buchbares Konto — "
                        . 'die Kontoeinstellung des Moduls prüfen.',
                        $reference
                    );
                    continue;
                }
                if (!$account->isModulePostingOnly()) {
                    yield Finding::warning(
                        "Sammelkonto «{$account->label()}» ist nicht «nur durch Module bebuchbar» — manuelle Buchungen darauf bleiben möglich "
                        . "und verfälschen den Abgleich mit den {$expected->label}. Im Kontenplan einschalten.",
                        $reference
                    );
                }

                $totals = $lines->accountTotals((int) $account->getId(), (int) $year->getId(), $fromDay, $toDay);
                $ledger = Money::fromDecimal($totals['debit'], $currency)->subtract(Money::fromDecimal($totals['credit'], $currency));
                if ($ledger->equals($expected->balance)) {
                    continue;
                }
                $manual = $lines->manualLinesOnAccount((int) $account->getId(), (int) $year->getId(), $fromDay, $toDay, self::LISTED_ENTRIES);
                $why    = '';
                if ($manual['count'] > 0) {
                    $numbers = implode(', ', $manual['numbers']) . ($manual['count'] > count($manual['numbers']) ? ', …' : '');
                    $why     = " Manuelle Buchungen auf dem Konto: Nr. {$numbers} (zusammen " . AmountFormat::of(Money::fromDecimal($manual['net'], $currency)) . ').';
                }
                yield Finding::warning(
                    "Sammelkonto «{$account->label()}» stimmt nicht mit den {$expected->label} überein: Saldo im Journal per {$at} "
                    . AmountFormat::of($ledger) . ', laut Modul ' . AmountFormat::of($expected->balance)
                    . ', Differenz ' . AmountFormat::of($ledger->subtract($expected->balance)) . '.' . $why,
                    $reference
                );
            }
        }
    }
}
