<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Module\Debtor\Entities\DunningRun;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\DunningLevelRepository;
use Z77\Module\Debtor\Repositories\DunningNoticeRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The dunning (plan §6.5, P4 part 3): the DUE LIST as of a day, the RUN
 * that issues the notices — and, where the level carries a fee, the FEE
 * DOCUMENT (an `Invoice` of kind `fee`, owner 2026-10-06: «eigene
 * Belegart») — and the history per invoice. The notice itself is a PDF
 * rendered on request (`Pdf/DunningPdf`), nothing stored.
 *
 * The rules:
 *
 *   - the ladder is the ACTIVE {@see DunningLevel}s by `level`; an
 *     invoice's current level is the highest notice it carries; the NEXT
 *     level is the first step above it — nothing past the last step;
 *   - due = a FINAL invoice (kind invoice) with an open amount > 0, whose
 *     debtor profile is active and carries no dunning block, and
 *     `due date + next level's days ≤ as-of`; the due list says per
 *     invoice what the next notice would be;
 *   - a run names the invoices to dun and the notice DATE; each is
 *     checked again under the row lock (still due at that date, same next
 *     level) — a stale selection refuses the whole run, nothing issued;
 *   - a level with a fee issues a fee document in the same unit of work:
 *     one lump-sum line without VAT on the mandator's dunning-fee account
 *     (`DebtorAccounts` `dunningFee`), the invoice's payment target, the
 *     debtor's terms, finalized at once (posted: receivable / fee account),
 *     `source_type` `dunning`, `source_ref` `{invoice number}:{level code}`;
 *     the notice links it. The fee shares the invoice number range, so the
 *     QR reference stays unique (the notice's bill is over open + fee under
 *     the INVOICE's reference; a credit above the invoice's open amount is
 *     placed on the debtor's open fees by the CAMT booking).
 */
final class DunningService
{
    private readonly string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor === null ? Actor::current() : Actor::normalize($actor);
    }

    /**
     * What is due as of $asOf, oldest due date first.
     *
     * @return list<array{invoice: Invoice, open: Money, current: ?DunningLevel, next: DunningLevel, dueSince: \DateTimeImmutable}>
     * @throws DunningRefusedException NO_LEVELS
     */
    public function dueList(\DateTimeImmutable $asOf): array
    {
        $ladder = $this->ladder();
        if ($ladder === []) {
            throw new DunningRefusedException(DunningRefusedException::NO_LEVELS, 'Keine aktive Mahnstufe — unter Stammdaten › Aufträge › Mahnstufen anlegen.');
        }
        $opens   = $this->invoices()->openAmountsOfFinalInvoices();
        if ($opens === []) {
            return [];
        }
        $highest = $this->notices()->highestLevelPerInvoice();
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);
        $due      = [];
        foreach ($this->invoices()->findBy(['id' => array_keys($opens)]) as $invoice) {
            $item = $this->dueItem($invoice, Money::fromDecimal($opens[(int) $invoice->getId()], $invoice->getCurrency()), $highest[(int) $invoice->getId()] ?? 0, $ladder, $asOf, $profiles);
            if ($item !== null) {
                $due[] = $item;
            }
        }
        usort($due, static fn(array $a, array $b) => [$a['dueSince'], $a['invoice']->getNumber()] <=> [$b['dueSince'], $b['invoice']->getNumber()]);

        return $due;
    }

    /**
     * The run: a notice per invoice at its next level, the fee documents
     * issued and finalized, all in ONE unit of work.
     *
     * @param list<int> $invoiceIds
     * @throws DunningRefusedException NOTHING | DATE | NOT_FOUND | NOT_DUE | NO_LEVELS
     * @throws InvoiceRefusedException | \Z77\Module\Debtor\Accounting\AccountingRefusedException the fee document was refused — nothing issued
     */
    public function run(\DateTimeImmutable $date, array $invoiceIds): DunningRun
    {
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($ids === []) {
            throw new DunningRefusedException(DunningRefusedException::NOTHING, 'Keine Rechnung ausgewählt.');
        }
        if ($date > new \DateTimeImmutable('today')) {
            throw new DunningRefusedException(DunningRefusedException::DATE, 'Das Mahndatum liegt in der Zukunft.');
        }
        $ladder = $this->ladder();
        if ($ladder === []) {
            throw new DunningRefusedException(DunningRefusedException::NO_LEVELS, 'Keine aktive Mahnstufe — unter Stammdaten › Aufträge › Mahnstufen anlegen.');
        }
        $accounts   = new DebtorAccounts($this->em);
        $feeAccount = null;
        $invoicing  = new InvoicingService($this->em, $this->actor);
        $now        = new \DateTimeImmutable();
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);
        sort($ids);

        return $this->em->getTransaction(DunningRun::class)->run(function () use ($ids, $date, $ladder, $accounts, &$feeAccount, $invoicing, $now, $profiles): DunningRun {
            $run     = new DunningRun($date, $this->actor, $now);
            $highest = $this->notices()->highestLevelPerInvoice();
            $position = 0;
            foreach ($ids as $id) {
                // The row locked, the open amount and the level judged again under the lock.
                $invoice = $this->invoices()->lockForUpdate($id);
                if ($invoice === null) {
                    throw new DunningRefusedException(DunningRefusedException::NOT_FOUND, "Dokument {$id} gibt es nicht.");
                }
                $open = $invoicing->openAmount($invoice);
                $item = $this->dueItem($invoice, $open, $highest[$id] ?? 0, $ladder, $date, $profiles);
                if ($item === null) {
                    throw new DunningRefusedException(DunningRefusedException::NOT_DUE, $invoice->documentName() . ' ist am ' . $date->format('d.m.Y') . ' nicht (mehr) fällig für eine Mahnung — Liste neu laden.');
                }
                $level  = $item['next'];
                $notice = new DunningNotice($run, ++$position, $invoice, $level, $open);
                $run->add($notice);

                if ($level->getFee() > 0) {
                    $feeAccount ??= $accounts->postableNumber('dunningFee');
                    $fee = $invoicing->invoice(InvoiceDraft::fee(
                        (int) $invoice->getContact()->getId(),
                        $date,
                        $invoice->getCurrency(),
                        $level->fee($invoice->getCurrency()),
                        'Mahngebühr ' . $level->getLabel() . ' zu ' . $invoice->documentName(),
                        $feeAccount,
                        $invoice->getPayment()->getTargetCode() !== '' ? $invoice->getPayment()->getTargetCode() : null,
                        'dunning',
                        $invoice->getNumber() . ':' . $level->getCode(),
                    ));
                    // invoice() joined THIS unit of work and did not flush — the row must exist before finalize() locks it.
                    $this->em->flush();
                    $invoicing->finalize([['id' => (int) $fee->getId(), 'version' => $fee->getVersion()]]);
                    $notice->withFee($fee);
                }
            }
            $this->em->persist($run);

            return $run;
        });
    }

    /** @return list<DunningNotice> oldest first */
    public function noticesOf(Invoice $invoice): array
    {
        return $this->notices()->forInvoice($invoice);
    }

    public function notice(int $noticeId): ?DunningNotice
    {
        return $this->em->getRepository(DunningNotice::class)->find($noticeId);
    }

    /** @return list<DunningRun> newest first */
    public function runs(int $limit = 20): array
    {
        return $this->notices()->runsNewestFirst($limit);
    }

    /** The level a notice was issued at — by code; null when the ladder no longer carries it. */
    public function levelOf(DunningNotice $notice): ?DunningLevel
    {
        return $this->levels()->findByCode($notice->getLevelCode());
    }

    // ── the rules ───────────────────────────────────────────────────────

    /**
     * The due item of one invoice as of $asOf, or null when it is not due:
     * no open amount, a blocked or inactive debtor, past the last step, or
     * the next step's days not yet reached.
     *
     * @param list<DunningLevel> $ladder by level ascending
     * @return array{invoice: Invoice, open: Money, current: ?DunningLevel, next: DunningLevel, dueSince: \DateTimeImmutable}|null
     */
    private function dueItem(Invoice $invoice, Money $open, int $highest, array $ladder, \DateTimeImmutable $asOf, DebtorProfileRepository $profiles): ?array
    {
        if (!$open->isPositive() || !$invoice->isFinal() || !$invoice->kind()->isPayable() || $invoice->kind()->value !== 'invoice') {
            return null;
        }
        $profile = $profiles->findByContact($invoice->getContact());
        if ($profile === null || !$profile->isActive() || $profile->hasDunningBlock()) {
            return null;
        }
        $current = null;
        $next    = null;
        foreach ($ladder as $level) {
            if ($level->getLevel() <= $highest) {
                $current = $level;
                continue;
            }
            $next = $level;
            break;
        }
        if ($next === null) {
            return null;
        }
        $dueSince = $invoice->getDueDate()->modify('+' . $next->getDaysAfterDue() . ' days');
        if ($dueSince > $asOf) {
            return null;
        }

        return ['invoice' => $invoice, 'open' => $open, 'current' => $current, 'next' => $next, 'dueSince' => $dueSince];
    }

    /** @return list<DunningLevel> the active levels by level ascending */
    private function ladder(): array
    {
        $levels = array_values(array_filter($this->levels()->allInOrder(), static fn(DunningLevel $l) => $l->isActive()));
        usort($levels, static fn(DunningLevel $a, DunningLevel $b) => $a->getLevel() <=> $b->getLevel());

        return $levels;
    }

    private function levels(): DunningLevelRepository
    {
        return $this->em->getRepository(DunningLevel::class);
    }

    private function notices(): DunningNoticeRepository
    {
        return $this->em->getRepository(DunningNotice::class);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }
}
