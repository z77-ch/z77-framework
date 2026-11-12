<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptJournalPosting;
use Z77\Module\Receipt\Entities\ReceiptState;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * «JOURNAL VERBUCHEN» of the receipt journal (owner decisions E1 and E11, 2026-11-12; plan T4) —
 * the PATTERN of debtor's Faktura-Journal (ADR-054 decisions 5–7, `JournalPostingService`), not
 * its classes:
 *
 *   - {@see preview()} — the RUN up to a CUT-OFF date (E11; default {@see defaultCutOff()}, the
 *     last day of the previous month): every captured receipt dated on or before it, as the
 *     `{id, version}` list `post()` takes; what blocks the run (incomplete, not buildable, no fiscal
 *     year, a closed or VAT-settled period) with its German reason; the entries summed per account.
 *     The same {@see ReceiptPostingBuilder::build()} as the posting;
 *   - {@see post()} — the run the confirmation showed, in ONE unit of work, all or nothing.
 *
 * The run is ALWAYS every captured receipt up to the cut-off (E11, like O7 of the Faktura-Journal):
 * one that is incomplete BLOCKS the whole run — nothing is posted until the owner completes,
 * re-dates or deletes it. Receipts dated after the cut-off stay in the journal untouched; the phone
 * keeps capturing while the owner posts last month.
 *
 * Lock order: receipt rows EXCLUSIVE by id (ascending) → reads → `journal-entry.*` (financial's
 * `post()` per receipt). No number range of its own — the journal numbers are the ledger's.
 * Year close and locked periods are the ledger's rules: its refusal (`PostingRefusedException`)
 * rolls the whole posting back and comes out as {@see ReceiptRefusedException::LEDGER} naming the
 * receipt; the open-work checks (`Close\UnpostedReceiptsCheck`) keep a year from closing while a
 * receipt dated in it waits here.
 */
final class ReceiptJournalPostingService
{
    private ?string $actor;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) at the posting */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /** The default cut-off (E11): the last day of the month before $today. */
    public static function defaultCutOff(?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        return ($today ?? new \DateTimeImmutable())->setTime(0, 0)->modify('first day of this month')->modify('-1 day');
    }

    /**
     * What {@see post()} would book for the run up to $cutOff.
     *
     * The VAT totals (`vat`, `withoutVat`) are read off the SAME built lines — the one line of a
     * receipt that carries the tax data — so the screen never computes VAT itself (receipt.md).
     *
     * @return array{cutOff: \DateTimeImmutable, seen: list<array{id: int, version: int}>, blocked: list<array{id: int, reason: string}>, postable: bool, entries: int, gross: Money, rows: list<array{account: string, debit: Money, credit: Money}>, vat: list<array{code: string, rate: int, base: Money, tax: Money}>, withoutVat: Money, blockedGross: Money}
     *         `seen` = every receipt of the run (what `post()` takes); `postable` = the run is not empty and nothing blocks it;
     *         `gross` = the postable receipts; `vat` = per code and rate the net base and the tax of the postable receipts, by code;
     *         `withoutVat` = the gross of the postable receipts without a tax line; `blockedGross` = the gross of the blocked ones
     */
    public function preview(?\DateTimeImmutable $cutOff = null): array
    {
        $cutOff ??= self::defaultCutOff();
        $builder  = new ReceiptPostingBuilder($this->em);
        $currency = ReceiptFields::currency();
        $seen     = [];
        $blocked  = [];
        $rows     = [];
        $vat      = [];
        $gross    = Money::zero($currency);
        $without  = Money::zero($currency);
        $blockedGross = Money::zero($currency);
        foreach ($this->receipts()->pending($cutOff) as $receipt) {
            $seen[] = ['id' => (int) $receipt->getId(), 'version' => $receipt->getVersion()];
            try {
                $request = $builder->build($receipt);
                $this->assertPeriodTakes($receipt, $request->hasTaxLine());
            } catch (ReceiptRefusedException $e) {
                $blocked[]    = ['id' => (int) $receipt->getId(), 'reason' => $e->getMessage()];
                $blockedGross = $blockedGross->add($receipt->getAmount());
                continue;
            }
            $gross   = $gross->add($receipt->getAmount());
            $taxLine = null;
            foreach ($request->lines as $line) {
                $rows[$line->account] ??= ['account' => $line->account, 'debit' => Money::zero($currency), 'credit' => Money::zero($currency)];
                $rows[$line->account]['debit']  = $rows[$line->account]['debit']->add($line->debit);
                $rows[$line->account]['credit'] = $rows[$line->account]['credit']->add($line->credit);
                if ($line->taxCode !== null) {
                    $taxLine = $line;
                }
            }
            if ($taxLine === null) {
                $without = $without->add($receipt->getAmount());
                continue;
            }
            $key = $taxLine->taxCode . '|' . $taxLine->taxRate;
            $vat[$key] ??= ['code' => (string) $taxLine->taxCode, 'rate' => (int) $taxLine->taxRate, 'base' => Money::zero($currency), 'tax' => Money::zero($currency)];
            $vat[$key]['base'] = $vat[$key]['base']->add($taxLine->taxBase);
            $vat[$key]['tax']  = $vat[$key]['tax']->add($taxLine->taxAmount);
        }
        ksort($rows, SORT_STRING);
        ksort($vat, SORT_STRING);

        return [
            'cutOff'       => $cutOff,
            'seen'         => $seen,
            'blocked'      => $blocked,
            'postable'     => $seen !== [] && $blocked === [],
            'entries'      => count($seen) - count($blocked),
            'gross'        => $gross,
            'rows'         => array_values($rows),
            'vat'          => array_values($vat),
            'withoutVat'   => $without,
            'blockedGross' => $blockedGross,
        ];
    }

    /**
     * The protocols of «Journal verbuchen» — the tab «Verbuchte Journale».
     *
     * @return list<ReceiptJournalPosting>
     */
    public function protocols(int $offset, int $limit, bool $newestFirst = true): array
    {
        return $this->em->getRepository(ReceiptJournalPosting::class)->page($offset, $limit, $newestFirst);
    }

    public function countProtocols(): int
    {
        return $this->em->getRepository(ReceiptJournalPosting::class)->countAll();
    }

    /**
     * Post the run up to $cutOff — the confirmation's `seen` list — into the ledger, ONE unit of
     * work, all or nothing:
     *
     *   1. every named row locked EXCLUSIVE in id order; each must still be `captured` at the version
     *      the confirmation showed, and the run must be exactly the captured receipts dated on or
     *      before $cutOff now — one added, re-dated or removed since is `changed`, nothing posted;
     *   2. a receipt of the run that is incomplete refuses the WHOLE run (`incomplete`, E11);
     *   3. the protocol row, then per receipt in date / id order: the posting
     *      ({@see ReceiptPostingBuilder::build()}) → `LedgerService::post()` (date = the receipt date,
     *      source `receipt`, ref = id, key `receipt:{id}`) → `Receipt::post()` with the entry, the
     *      protocol, who and when.
     *
     * @param list<array{id: int, version: int}> $seen
     * @throws ReceiptRefusedException EMPTY | CHANGED | INCOMPLETE | NOT_POSTABLE | LEDGER
     * @throws \LogicException nobody is logged in and no actor was named
     */
    public function post(\DateTimeImmutable $cutOff, array $seen): ReceiptJournalPosting
    {
        $versions = [];
        foreach ($seen as $i => $pair) {
            if (!is_array($pair) || !isset($pair['id'], $pair['version'])) {
                throw new \InvalidArgumentException("post() takes a list of ['id' => int, 'version' => int] pairs — item {$i} is not one");
            }
            $versions[(int) $pair['id']] = (int) $pair['version'];
        }
        if ($versions === []) {
            throw new ReceiptRefusedException(ReceiptRefusedException::EMPTY, 'Bis zum Stichtag ' . $cutOff->format('d.m.Y') . ' ist kein Beleg im Belegjournal — nichts verbucht.');
        }
        $actor = $this->actor ??= Actor::current();
        $now   = new \DateTimeImmutable();

        return $this->em->getTransaction(JournalEntry::class)->run(function () use ($cutOff, $versions, $actor, $now): ReceiptJournalPosting {
            // 1. The rows, EXCLUSIVE, in id order — judged; the run compared; then read fresh.
            $ids    = array_keys($versions);
            sort($ids);
            $locked = $this->receipts()->lockIds($ids);
            foreach ($ids as $id) {
                $row = $locked[$id] ?? null;
                if ($row === null || $row['state'] !== ReceiptState::Captured->value || $row['version'] !== $versions[$id]) {
                    throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Beleg ' . $id . ' wurde seit der Bestätigung geändert, verbucht oder gelöscht — nichts wurde verbucht. Das Belegjournal neu laden und nochmals bestätigen.');
                }
            }
            if ($this->receipts()->capturedIdsUntil($cutOff) !== $ids) {
                throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Seit der Bestätigung hat sich das Belegjournal bis zum Stichtag ' . $cutOff->format('d.m.Y') . ' geändert (ein Beleg dazugekommen oder umdatiert) — nichts wurde verbucht. Neu laden und nochmals bestätigen.');
            }
            $receipts = array_map(fn(int $id): Receipt => $this->receipts()->refreshed($id), $ids);
            usort($receipts, static fn(Receipt $a, Receipt $b) => [$a->getReceiptDate(), $a->getId()] <=> [$b->getReceiptDate(), $b->getId()]);

            // 2. An incomplete receipt blocks the whole run (E11).
            $incomplete = array_values(array_filter($receipts, static fn(Receipt $r) => !$r->isComplete()));
            if ($incomplete !== []) {
                throw new ReceiptRefusedException(ReceiptRefusedException::INCOMPLETE, 'Verbuchen gesperrt — '
                    . implode('; ', array_map(static fn(Receipt $r) => ReceiptPostingBuilder::name($r) . ' (es fehlt: ' . implode(', ', $r->missing()) . ')', $incomplete))
                    . '. Vervollständigen, umdatieren oder löschen — nichts wurde verbucht.');
            }

            // 3. The protocol, then the postings.
            $gross = Money::zero(ReceiptFields::currency());
            foreach ($receipts as $receipt) {
                $gross = $gross->add($receipt->getAmount());
            }
            $protocol = new ReceiptJournalPosting($actor, $now, count($receipts), $gross);
            $this->em->persist($protocol);

            $builder = new ReceiptPostingBuilder($this->em);
            $ledger  = new LedgerService($this->em, $actor);
            foreach ($receipts as $receipt) {
                $request = $builder->build($receipt);
                try {
                    $ref = $ledger->post($request);
                } catch (PostingRefusedException $e) {
                    throw new ReceiptRefusedException(ReceiptRefusedException::LEDGER, ReceiptPostingBuilder::name($receipt) . ': ' . self::ledgerMessage($e) . ' — nichts wurde verbucht.', $e);
                }
                $receipt->post($ref->fiscalYear . '/' . $ref->number, $protocol, $actor, $now);
                $this->em->persist($receipt);
            }

            return $protocol;
        });
    }

    /**
     * The ledger's period rules asked BEFORE the posting, for the preview only — a lock-free read;
     * `post()` leaves the judgement to `LedgerService::post()` under its locks.
     *
     * @throws ReceiptRefusedException LEDGER
     */
    private function assertPeriodTakes(Receipt $receipt, bool $withTaxLine): void
    {
        $date = $receipt->getReceiptDate();
        $year = $this->em->getRepository(FiscalYear::class)->findByDate($date);
        $state = $year?->periodOn($date)?->getState();
        $reason = match (true) {
            $state === null                                              => 'kein Geschäftsjahr für den ' . $date->format('d.m.Y') . ' — zuerst das Geschäftsjahr eröffnen',
            $state === PeriodState::Closed->value                        => 'die Periode ist abgeschlossen — das Belegdatum in eine offene Periode legen',
            $state === PeriodState::VatSettled->value && $withTaxLine    => 'die MWST dieser Periode ist abgerechnet — eine Buchung mit MWST geht dort nicht mehr',
            default                                                      => null,
        };
        if ($reason !== null) {
            throw new ReceiptRefusedException(ReceiptRefusedException::LEDGER, ReceiptPostingBuilder::name($receipt) . ': ' . $reason . '.');
        }
    }

    /** The ledger's refusal in German, naming the next step — its own message is for the developer. */
    private static function ledgerMessage(PostingRefusedException $e): string
    {
        return match ($e->reason) {
            PostingRefusedException::NO_FISCAL_YEAR, PostingRefusedException::NO_PERIOD => 'kein Geschäftsjahr für dieses Datum — zuerst das Geschäftsjahr eröffnen',
            PostingRefusedException::PERIOD_CLOSED      => 'die Periode ist abgeschlossen — das Belegdatum in eine offene Periode legen',
            PostingRefusedException::PERIOD_VAT_SETTLED => 'die MWST dieser Periode ist abgerechnet — eine Buchung mit MWST geht dort nicht mehr',
            PostingRefusedException::ACCOUNT_UNKNOWN, PostingRefusedException::ACCOUNT_NOT_POSTABLE, PostingRefusedException::ACCOUNT_INACTIVE
                                                        => 'die Buchhaltung nimmt das Konto ' . ($e->account ?? '') . ' nicht (unbekannt, Gruppe oder inaktiv)',
            PostingRefusedException::IDEMPOTENCY_CONFLICT => 'unter diesem Beleg steht im Journal schon eine andere Buchung',
            default                                     => $e->getMessage(),
        };
    }

    private function receipts(): ReceiptRepository
    {
        return $this->em->getRepository(Receipt::class);
    }
}
