<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Accounting\AccountingGateway;
use Z77\Module\Debtor\Accounting\AccountingGateways;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceJournalPosting;
use Z77\Module\Debtor\Invoicing\PostingBuilder;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Core\DI;
use Z77\Shared\Money\Money;

/**
 * The FAKTURA-JOURNAL and «Journal verbuchen» (ADR-054 decisions 5–7, step 3 of the build plan,
 * owner 2026-10-23). The journal is a QUERY, not a table: the documents in state `issued` —
 * numbered, printed (their PDF archived), with the customer, NOT posted. Nothing of the posting is
 * stored before the posting («keine Buchungssätze»): what WILL be posted is computed on demand by
 * the one {@see PostingBuilder} from each document's snapshot — the confirmation's preview and the
 * posting itself use the same call.
 *
 *   - {@see pending()} / {@see totals()} / {@see blockers()} — what the screen shows;
 *   - {@see preview()} — the journal entries `post()` would book, summed per account / VAT
 *     category (the confirmation page and the journal list print);
 *   - {@see post()} — «Journal verbuchen»: ALWAYS the whole journal (owner decision O7), ONE unit
 *     of work, all or nothing.
 *
 * Lock order (debtor.md «Lock order of the module»): invoice rows EXCLUSIVE by id → reads →
 * `journal-entry.*` (financial's `post()` per document). No number range — the numbers were drawn
 * by «Fakturieren».
 */
final class JournalPostingService
{
    private readonly string $actor;
    private ?AccountingGateway $gateway;
    /** @var list<InvoicePostedListener>|null */
    private ?array $listeners;

    /**
     * @param string|null            $actor   the author's name; null = the session identity ({@see Actor::current()})
     * @param AccountingGateway|null $gateway null = the one `debtorConfig → accountingGateway` names, built at first use
     * @param list<InvoicePostedListener>|null $listeners null = every module's `invoicePostedListeners` ({@see InvoicePostedListeners}), collected at first use
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null, ?AccountingGateway $gateway = null, ?array $listeners = null)
    {
        $this->actor     = $actor !== null ? Actor::normalize($actor) : Actor::current();
        $this->gateway   = $gateway;
        $this->listeners = $listeners;
    }

    /** @return list<Invoice> the open journal in number order, lines loaded */
    public function pending(): array
    {
        return $this->invoices()->journalDocuments();
    }

    /**
     * The journal's totals — signed (a credit note negative), per VAT code and in all
     * ({@see InvoiceRepository::journalTotals()}); $postingId names a posted batch instead of the
     * open journal.
     *
     * @return array{count: int, net: string, tax: string, gross: string, oldest: ?string, newest: ?string, blocked: int, vat: list<array{code: string, rate: int, label: string, base: string, tax: string}>}
     */
    public function totals(?int $postingId = null): array
    {
        return $this->invoices()->journalTotals($postingId);
    }

    /**
     * What keeps «Journal verbuchen» from running: a document flagged by its source as out of
     * step with it (`reissue_needed`, ADR-054 decision 9 — the source re-invoices it first).
     *
     * @param list<Invoice> $documents the open journal ({@see pending()})
     * @return list<array{id: int, name: string, reason: string}>
     */
    public function blockers(array $documents): array
    {
        $blocked = [];
        foreach ($documents as $document) {
            if ($document->getReissueNeeded() !== null) {
                $blocked[] = ['id' => (int) $document->getId(), 'name' => $document->documentName(), 'reason' => $document->getReissueNeeded()];
            }
        }

        return $blocked;
    }

    /**
     * The journal entries {@see post()} would book for $documents — the same
     * `PostingBuilder::build()` per document — summed per account (revenue, receivable, rounding)
     * and per VAT category, debit and credit, in account order; plus the number of entries. A
     * document that posts nothing (a zero document) adds nothing.
     *
     * @param list<Invoice> $documents
     * @return array{entries: int, rows: list<array{account: string, debit: Money, credit: Money}>}
     * @throws AccountNotConfiguredException the receivable account is not set on the mandator
     */
    public function preview(array $documents): array
    {
        $receivable = (new DebtorAccounts($this->em))->postableNumber('receivable');
        $rows    = [];
        $entries = 0;
        foreach ($documents as $document) {
            $request = PostingBuilder::build($document, $receivable);
            if ($request === null) {
                continue;
            }
            $entries++;
            foreach ($request->lines as $line) {
                $key = $line->account ?? ('MWST ' . $line->vatCategory);
                $rows[$key] ??= ['account' => $key, 'debit' => Money::zero($line->debit->currency), 'credit' => Money::zero($line->debit->currency)];
                $rows[$key]['debit']  = $rows[$key]['debit']->add($line->debit);
                $rows[$key]['credit'] = $rows[$key]['credit']->add($line->credit);
            }
        }
        ksort($rows, SORT_STRING);

        return ['entries' => $entries, 'rows' => array_values($rows)];
    }

    /**
     * «JOURNAL VERBUCHEN» (ADR-054 decision 6): every document of the open Faktura-Journal posted
     * into the ledger in ONE unit of work, all or nothing — `issued` → `final` with who / when and
     * the protocol row {@see InvoiceJournalPosting} each document names.
     *
     *   1. Before the unit of work: the receivable account (refused early when not set) and the
     *      accounting port.
     *   2. Inside: every document $seen names locked EXCLUSIVE in id order and re-read; each must
     *      still be in the journal at the VERSION the confirmation showed; no OTHER document may
     *      have entered the journal since (the owner posts the whole journal, O7 — what was not
     *      seen is not posted: `changed`). Then the blockers judged under the lock (`blocked`).
     *   3. The protocol row (count, signed gross total, first and last number), then per document
     *      in NUMBER order: `PostingBuilder` → `AccountingGateway::post()` (posting date = the
     *      INVOICE date, idempotency key `{kind}:{id}:final` — unchanged) → `Invoice::post()`.
     *   4. Every {@see InvoicePostedListener} with the posted ids — in the same unit of work (owner
     *      2026-10-24: module-order moves the orders to «Gebucht»); a throw rolls the posting back.
     *
     * A refusal of the bookkeeping (a closed or VAT-settled period, no fiscal year, an account
     * that no longer takes postings) rolls back the WHOLE posting — nothing is half-posted; the
     * message names the document.
     *
     * @param list<array{id: int, version: int}> $seen the journal as the confirmation showed it
     * @throws JournalRefusedException EMPTY | BLOCKED | CHANGED
     * @throws AccountNotConfiguredException
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException
     */
    public function post(array $seen): InvoiceJournalPosting
    {
        $versions = [];
        foreach ($seen as $i => $pair) {
            if (!is_array($pair) || !isset($pair['id'], $pair['version'])) {
                throw new \InvalidArgumentException("post() takes a list of ['id' => int, 'version' => int] pairs — item {$i} is not one");
            }
            $versions[(int) $pair['id']] = (int) $pair['version'];
        }
        if ($versions === []) {
            throw new JournalRefusedException(JournalRefusedException::EMPTY, 'Das Faktura-Journal ist leer — nichts zu verbuchen.');
        }
        $receivable = (new DebtorAccounts($this->em))->postableNumber('receivable');
        $gateway    = $this->gateway();
        $listeners  = $this->listeners ??= InvoicePostedListeners::fromModules(DI::getModuleManager());
        $now        = new \DateTimeImmutable();

        return $this->em->getTransaction(Invoice::class)->run(function () use ($versions, $receivable, $gateway, $listeners, $now): InvoiceJournalPosting {
            // 2. The rows, EXCLUSIVE, in id order — then judged.
            $ids = array_keys($versions);
            sort($ids);
            $this->invoices()->lockIds($ids);   // every row first — locking reads only, so the reads below see what their writers committed
            $documents = [];
            foreach ($ids as $id) {
                $document = $this->invoices()->lockForUpdate($id);
                if ($document === null || !$document->isInJournal() || $document->getVersion() !== $versions[$id]) {
                    throw new JournalRefusedException(JournalRefusedException::CHANGED, ($document !== null ? $document->documentName() : 'Ein Dokument des Journals') . ' wurde seit der Bestätigung geändert, verbucht oder ist nicht mehr im Faktura-Journal — nichts wurde verbucht. Das Journal neu laden und nochmals bestätigen.');
                }
                $documents[] = $document;
            }
            $current = array_column($this->invoices()->journalVersions(), 'id');
            sort($current);
            if ($current !== $ids) {
                throw new JournalRefusedException(JournalRefusedException::CHANGED, 'Seit der Bestätigung ist ein Dokument ins Faktura-Journal gekommen — nichts wurde verbucht. Das Journal neu laden und nochmals bestätigen (verbucht wird immer das ganze Journal).');
            }
            $blocked = $this->blockers($documents);
            if ($blocked !== []) {
                throw new JournalRefusedException(JournalRefusedException::BLOCKED, 'Verbuchen gesperrt — ' . (count($blocked) === 1 ? '1 Dokument muss' : count($blocked) . ' Dokumente müssen') . ' zuerst neu fakturiert werden: '
                    . implode('; ', array_map(static fn(array $b) => $b['name'] . ' (' . $b['reason'] . ')', $blocked)) . '. Nichts wurde verbucht.');
            }

            // 3. The protocol, then the postings in number order.
            usort($documents, static fn(Invoice $a, Invoice $b) => [$a->getNumber(), $a->getId()] <=> [$b->getNumber(), $b->getId()]);
            $currency = $documents[0]->getCurrency();
            $gross    = Money::zero($currency);
            foreach ($documents as $document) {
                $gross = $gross->add($document->getGrossTotal()->multiply($document->kind()->sign()));
            }
            $numbers  = array_map(static fn(Invoice $d) => (int) $d->getNumber(), $documents);
            $protocol = new InvoiceJournalPosting($this->actor, $now, count($documents), $gross, min($numbers), max($numbers));
            $this->em->persist($protocol);
            foreach ($documents as $document) {
                $request = PostingBuilder::build($document, $receivable);
                $document->post($request === null ? null : $gateway->post($request), $protocol, $this->actor, $now);
                $this->em->persist($document);
            }
            $this->em->flush();

            // 4. The listening modules, inside the unit of work — all or nothing.
            $posted = array_map(static fn(Invoice $d) => (int) $d->getId(), $documents);
            foreach ($listeners as $listener) {
                $listener->invoicesPosted($posted, $this->em, $this->actor);
            }
            if ($listeners !== []) {
                $this->em->flush();
            }

            return $protocol;
        });
    }

    /**
     * The posting protocols, newest first (or oldest first) — the tab «Verbuchte Journale».
     *
     * @return list<InvoiceJournalPosting>
     */
    public function protocols(int $offset, int $limit, bool $newestFirst = true): array
    {
        return $this->em->getRepository(InvoiceJournalPosting::class)->page($offset, $limit, $newestFirst);
    }

    public function countProtocols(): int
    {
        return $this->em->getRepository(InvoiceJournalPosting::class)->countAll();
    }

    public function protocol(int $id): ?InvoiceJournalPosting
    {
        return $id > 0 ? $this->em->getRepository(InvoiceJournalPosting::class)->find($id) : null;
    }

    private function gateway(): AccountingGateway
    {
        return $this->gateway ??= AccountingGateways::fromConfig($this->em, $this->actor);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }
}
