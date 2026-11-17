<?php

namespace Z77\Module\Debtor\Jobs;

use Z77\Core\DI;
use Z77\Module\Debtor\Services\BankEntryLinkService;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * «Debitoren-Bankabgleich» (ADR-061 decision 12, stage C3b) — the camt.053 arrives AFTER the
 * camt.054: financial's statement import knows no owner (BANK-LINK-002) and never calls debtor,
 * so this pass links what the import of the camt.054 could not link yet. One slice:
 * `BankEntryLinkService::linkEntries()` — every camt.054 entry whose `AcctSvcrRef` stands on
 * exactly one free camt.053 credit with the same sum and count is linked (level 1). Nothing is
 * booked, nothing proposed: the proposals for old payments wait for the office in «Bankabgleich …».
 *
 * Without module-financial, or without a bank account reading statements, it reads nothing and
 * is done. Idle cost: three reads. Asks `hasTimeLeft()` between links and comes again when work
 * is left.
 *
 * Ships `defaultSchedule` `every:1m` (debtorConfig) like creditor's `creditor-intake`, which links
 * the creditor side in the same rhythm: it deletes nothing (jobs.md), and a statement read in the
 * backend shows its «Zuordnung» within a minute. The constructor argument is a harness seam; the
 * runner calls `new self()`.
 */
final class DebtorBankLinkJob implements Job
{
    public const KEY = 'debtor-bank-link';

    public function __construct(private ?BankEntryLinkService $links = null) {}

    public function run(JobContext $context): JobResult
    {
        if ($this->links === null && !BankEntryLinkService::installed()) {
            return JobResult::done('Finanzen nicht installiert — nichts abzugleichen');
        }
        $actor = trim($context->getActor()->getUserName());
        $actor = $actor !== '' ? $actor : 'cron:' . self::KEY;
        $pass  = ($this->links ?? new BankEntryLinkService(DI::getUnifiedEntityManager(), $actor))->linkEntries(null, static fn(): bool => $context->hasTimeLeft());
        foreach ($pass['notes'] as $line) {
            $context->log($line);
        }
        $note = $pass['linked'] === 1 ? '1 Zahlungseingang zugeordnet' : $pass['linked'] . ' Zahlungseingänge zugeordnet';

        return $pass['more'] ? JobResult::again(null, 0, $note) : JobResult::done($note);
    }
}
