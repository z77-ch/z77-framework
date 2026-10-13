<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Module\Debtor\Entities\DunningRun;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Invoicing\DocumentNumber;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\DunningLevelRepository;
use Z77\Module\Debtor\Repositories\DunningNoticeRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
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
 *     `due date + next level's days after due ≤ as-of` AND — when the
 *     invoice already carries a notice — `last notice's date + next
 *     level's days after previous ≤ as-of` (owner 2026-10-13: a level is
 *     never sent a few minutes after the one before); the due list says per
 *     invoice what the next notice would be and since when;
 *   - the notice DATE of a run (and the as-of day of the due list) is not
 *     in the future (today is allowed) and not before the date of the
 *     latest run (owner 2026-10-13: no backdating — a run dated 23.09.
 *     created on 13.10. made the next level look due at once);
 *   - a run names the invoices to dun and the notice date; each is checked
 *     again under the row lock (still due at that date, same next level) —
 *     a stale selection refuses the whole run, nothing issued;
 *   - a level with a fee issues a fee document in the same unit of work:
 *     one lump-sum line without VAT on the mandator's dunning-fee account
 *     (`DebtorAccounts` `dunningFee`), the invoice's payment target, the
 *     debtor's terms, finalized at once (posted: receivable / fee account),
 *     `source_type` `dunning`, `source_ref` `{invoice number}:{level code}`;
 *     the notice links it. The fee shares the one document series, so the
 *     QR reference stays unique (the notice's bill is over open + fee under
 *     the INVOICE's reference; a credit above the invoice's open amount is
 *     placed on the debtor's open fees by the CAMT booking).
 *
 * Lock order of the run (debtor.md «Lock order», review 2026-10-13 H4):
 * `document.YYMM` of the run date (only when the ladder carries a fee) →
 * the debtor profiles of the invoices, SHARED, by contact id → the invoice
 * rows, EXCLUSIVE, by id → THEN every read that judges (levels, last
 * notices, open amounts) → THEN the notices, the fee documents and their
 * postings (`journal-entry.*`). Nothing is read before the last lock: under
 * REPEATABLE READ the first plain read fixes what the unit of work sees.
 */
final class DunningService
{
    private readonly string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor === null ? Actor::current() : Actor::normalize($actor);
    }

    /**
     * What is due as of $asOf, oldest due-since first.
     *
     * @return list<array{invoice: Invoice, open: Money, current: ?DunningLevel, next: DunningLevel, dueSince: \DateTimeImmutable}>
     * @throws DunningRefusedException NO_LEVELS | DATE (in the future, or before the latest run)
     */
    public function dueList(\DateTimeImmutable $asOf): array
    {
        $ladder = $this->ladder();
        if ($ladder === []) {
            throw new DunningRefusedException(DunningRefusedException::NO_LEVELS, 'Keine aktive Mahnstufe — unter Stammdaten › Aufträge › Mahnstufen anlegen.');
        }
        $this->assertNoticeDate($asOf, 'Der Stichtag');
        $opens = $this->invoices()->openAmountsOfFinalInvoices();
        if ($opens === []) {
            return [];
        }
        $last = $this->notices()->lastNoticePerInvoice();
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);
        $due      = [];
        foreach ($this->invoices()->findBy(['id' => array_keys($opens)]) as $invoice) {
            $item = $this->dueItem($invoice, Money::fromDecimal($opens[(int) $invoice->getId()], $invoice->getCurrency()), $last[(int) $invoice->getId()] ?? null, $ladder, $asOf, $profiles);
            if ($item !== null) {
                $due[] = $item;
            }
        }
        usort($due, static fn(array $a, array $b) => [$a['dueSince'], $a['invoice']->getNumber()] <=> [$b['dueSince'], $b['invoice']->getNumber()]);

        return $due;
    }

    /**
     * The run: a notice per invoice at its next level, the fee documents
     * issued and finalized, all in ONE unit of work, in the lock order of
     * the class docblock.
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
        $this->assertNoticeDate($date, 'Das Mahndatum');   // early and friendly; judged again under the locks
        $ladder = $this->ladder();
        if ($ladder === []) {
            throw new DunningRefusedException(DunningRefusedException::NO_LEVELS, 'Keine aktive Mahnstufe — unter Stammdaten › Aufträge › Mahnstufen anlegen.');
        }
        sort($ids);

        // The parties, read BEFORE the unit of work: their profiles are locked before the invoice rows (rule «profile
        // row → invoice rows», the order of InvoicingService::invoice() and DebtorProfileService::delete()), so the
        // contacts must be known first. A document's party never changes (reinvoice() refuses another one).
        $contactOf = [];
        foreach ($this->invoices()->findBy(['id' => $ids]) as $invoice) {
            $contactOf[(int) $invoice->getId()] = (int) $invoice->getContact()->getId();
        }
        foreach ($ids as $id) {
            if (!isset($contactOf[$id])) {
                throw new DunningRefusedException(DunningRefusedException::NOT_FOUND, "Dokument {$id} gibt es nicht.");
            }
        }
        $contactIds = array_values(array_unique($contactOf));
        sort($contactIds);

        $accounts   = new DebtorAccounts($this->em);
        $feeAccount = null;
        $invoicing  = new InvoicingService($this->em, $this->actor);
        $now        = new \DateTimeImmutable();
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);

        // A fee document gets its number from the ONE document series when it is booked (owner 2026-10-10: the number
        // is drawn by finalize(), the booking print — here inside this unit of work), from the month's range of the run
        // date — the same range a booking print of other documents of that month locks FIRST, before their rows. So
        // this run locks the range FIRST as well (DOCTRINE-NR-002): `create()` inside the unit of work holds the existing
        // row exclusively without consuming a number; outside, ahead of it, it makes sure the row exists and is committed
        // (DOCTRINE-NR-003). finalize() of the fee, joining this unit of work, finds the range already held. Without a
        // fee on the ladder nothing is drawn and nothing is locked.
        $feeRange = array_filter($ladder, static fn(DunningLevel $l) => $l->getFee() > 0) !== [] ? DocumentNumber::range($date) : null;
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        if ($feeRange !== null) {
            $ranges->create($feeRange);
        }

        return $this->em->getTransaction(DunningRun::class)->run(function () use ($ids, $contactOf, $contactIds, $date, $ladder, $accounts, &$feeAccount, $invoicing, $now, $profiles, $ranges, $feeRange): DunningRun {
            // 1. The month range of the fee documents.
            if ($feeRange !== null) {
                $ranges->create($feeRange);
            }
            // 2. The debtor profiles, SHARED (the fee's invoice() takes the same lock again — a no-op then); a missing
            //    profile is judged below (no profile, nothing due).
            foreach ($contactIds as $contactId) {
                $profiles->lockForContact($contactId, exclusive: false);
            }
            // 3. Every invoice row, EXCLUSIVE, by id — locking reads only, nothing read yet.
            if (count($this->invoices()->lockIds($ids)) !== count($ids)) {
                throw new DunningRefusedException(DunningRefusedException::NOT_FOUND, 'Ein ausgewähltes Dokument gibt es nicht mehr — Liste neu laden.');
            }

            // 4. Now read and judge everything — the first plain read of the unit of work sees what every writer that
            //    held these rows committed (notices of a concurrent run, payments).
            $this->assertNoticeDate($date, 'Das Mahndatum');
            $last = $this->notices()->lastNoticePerInvoice($ids);
            $plan = [];
            foreach ($ids as $id) {
                $invoice = $this->invoices()->lockForUpdate($id);   // held since step 3 — the row with its lines
                if ($invoice === null || (int) $invoice->getContact()->getId() !== $contactOf[$id]) {
                    throw new DunningRefusedException(DunningRefusedException::NOT_FOUND, "Dokument {$id} gibt es nicht (mehr) — Liste neu laden.");
                }
                $item = $this->dueItem($invoice, $invoicing->openAmount($invoice), $last[$id] ?? null, $ladder, $date, $profiles);
                if ($item === null) {
                    throw new DunningRefusedException(DunningRefusedException::NOT_DUE, $invoice->documentName() . ' ist am ' . $date->format('d.m.Y') . ' nicht (mehr) fällig für eine Mahnung — Liste neu laden.');
                }
                $plan[] = $item;
            }

            // 5. Issue: the notices, the fee documents booked (number, posting → journal-entry.*).
            $run = new DunningRun($date, $this->actor, $now);
            foreach ($plan as $i => $item) {
                $invoice = $item['invoice'];
                $level   = $item['next'];
                $notice  = new DunningNotice($run, $i + 1, $invoice, $level, $item['open']);
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
     * The notice date (or the due list's as-of day) is neither in the
     * future nor before the latest run (owner 2026-10-13). Compared by day.
     * Not serialised against a run committed at the same moment on OTHER
     * invoices — the rule keeps a run from being backdated by hand, it is
     * not a lock.
     *
     * @throws DunningRefusedException DATE
     */
    private function assertNoticeDate(\DateTimeImmutable $date, string $what): void
    {
        $day = $date->format('Y-m-d');
        if ($day > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new DunningRefusedException(DunningRefusedException::DATE, $what . ' liegt in der Zukunft.');
        }
        $latest = $this->notices()->latestRunDate();
        if ($latest !== null && $day < $latest->format('Y-m-d')) {
            throw new DunningRefusedException(
                DunningRefusedException::DATE,
                $what . ' ' . $date->format('d.m.Y') . ' liegt vor dem letzten Mahnlauf vom ' . $latest->format('d.m.Y') . ' — ein Mahnlauf wird nicht zurückdatiert.'
            );
        }
    }

    /**
     * The due item of one invoice as of $asOf, or null when it is not due:
     * no open amount, a blocked or inactive debtor, past the last step, the
     * next step's days after due not yet reached, or — after a notice — its
     * days after that last notice not yet reached.
     *
     * @param array{level: int, date: \DateTimeImmutable}|null $last the invoice's last notice
     * @param list<DunningLevel> $ladder by level ascending
     * @return array{invoice: Invoice, open: Money, current: ?DunningLevel, next: DunningLevel, dueSince: \DateTimeImmutable}|null
     */
    private function dueItem(Invoice $invoice, Money $open, ?array $last, array $ladder, \DateTimeImmutable $asOf, DebtorProfileRepository $profiles): ?array
    {
        if (!$open->isPositive() || !$invoice->isFinal() || !$invoice->kind()->isPayable() || $invoice->kind()->value !== 'invoice') {
            return null;
        }
        $profile = $profiles->findByContact($invoice->getContact());
        if ($profile === null || !$profile->isActive() || $profile->hasDunningBlock()) {
            return null;
        }
        $highest = $last['level'] ?? 0;
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
        if ($last !== null) {
            $afterPrevious = $last['date']->modify('+' . $next->getDaysAfterPrevious() . ' days');
            if ($afterPrevious > $dueSince) {
                $dueSince = $afterPrevious;
            }
        }
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
