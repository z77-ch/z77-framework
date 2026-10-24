<?php

namespace Z77\Module\Mailing\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see OutboxMail}: the screen's search (SQL, paged)
 * and — the deliberate deviation of this repository — the STATE TRANSITIONS.
 *
 * Every transition is ONE conditional UPDATE on the DBAL connection of the
 * same EntityManager (`… WHERE id = ? AND state = ?`), and its answer is
 * whether a row changed. The row's state is the lock: the job claims a mail
 * (`queued → sending`) only while it is still queued and due, the operator
 * cancels one only while it is queued — a cancel and a send racing for the
 * same row cannot both win, without a lock table and without Doctrine's
 * optimistic version column (nothing here edits the CONTENT, which is what a
 * version would protect). A failed statement marks an open unit of work
 * rollback-only before it rethrows (ADR-039 decision 10).
 *
 * The entity is written once (the INSERT through the unified EntityManager in
 * `OutboxService`); after a transition, read it again with {@see fresh()} — a
 * copy loaded before would still show the old state.
 */
class OutboxMailRepository extends DoctrineRepository
{
    private const TABLE = 'mail_outbox';

    private const DATETIME = 'Y-m-d H:i:s';

    // ── the screen ───────────────────────────────────────────────────────

    /**
     * One page of the outbox list: the rows matching every criterion, in the
     * search's order. Values bound; the ORDER BY from a fixed map. Doctrine-only.
     *
     * @return list<OutboxMail>
     */
    public function search(OutboxSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            'recipient' => "m.recipient_email {$dir}, m.id {$dir}",
            'subject'   => "m.subject {$dir}, m.id {$dir}",
            'source'    => "m.source {$dir}, m.id {$dir}",
            default     => "m.created_at {$dir}, m.id {$dir}",
        };
        $ids = array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT m.id FROM ' . self::TABLE . " m{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        ));

        return $this->inOrder($ids);
    }

    /** How many rows match — the pager and the badge. Doctrine-only. */
    public function countSearch(OutboxSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " m{$where}", $params, $types);
    }

    /** @return array<string, int> state => rows, every state present (0 when none) */
    public function countByState(): array
    {
        $counts = array_fill_keys(OutboxMail::STATES, 0);
        foreach ($this->connection()->fetchAllKeyValue('SELECT state, COUNT(*) FROM ' . self::TABLE . ' GROUP BY state') as $state => $n) {
            $counts[(string) $state] = (int) $n;
        }

        return $counts;
    }

    /** Mails still waiting (queued or being sent) — the job's «anything left?». */
    public function countWaiting(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE state IN (?)',
            [[OutboxMail::STATE_QUEUED, OutboxMail::STATE_SENDING]],
            [ArrayParameterType::STRING]
        );
    }

    /** Queued mails created before `$before` — the dashboard's «waits too long». */
    public function countQueuedSince(\DateTimeImmutable $before): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE state = ? AND created_at < ?',
            [OutboxMail::STATE_QUEUED, $before->format(self::DATETIME)]
        );
    }

    /** The earliest `next_attempt_at` of a queued mail, null when none waits. */
    public function nextAttemptAt(): ?\DateTimeImmutable
    {
        $at = $this->connection()->fetchOne(
            'SELECT MIN(next_attempt_at) FROM ' . self::TABLE . ' WHERE state = ?',
            [OutboxMail::STATE_QUEUED]
        );

        return is_string($at) && $at !== '' ? new \DateTimeImmutable($at) : null;
    }

    /**
     * What went (or waits to go) out for each SOURCE — the read side of `source`
     * (step 3 of the mail plan): a document module asks «was invoice 42 mailed, to
     * whom, when?» without knowing the entity. Plain rows, oldest first per source;
     * a source without mails is absent. One query (indexed by `source`).
     *
     * @param list<string> $sources e.g. ['invoice:42', 'invoice:43']
     * @return array<string, list<array{id: int, to: string, state: string, created_at: \DateTimeImmutable,
     *                                   sent_at: ?\DateTimeImmutable, batch_id: string, created_by: string,
     *                                   sender: string, sender_email: string, attachments: list<string>}>> `sender` /
     *                                   `sender_email` = the sender identity the mail went out as ('' without one,
     *                                   2026-10-23); `attachments` = its attachment references (2026-10-24)
     */
    public function deliveriesOf(array $sources): array
    {
        $sources = array_values(array_unique(array_filter(array_map('strval', $sources), static fn(string $s) => $s !== '')));
        if ($sources === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, source, recipient_email, state, created_at, sent_at, batch_id, created_by, sender_name, sender_email, attachments FROM ' . self::TABLE
            . ' WHERE source IN (?) ORDER BY id ASC',
            [$sources],
            [ArrayParameterType::STRING]
        );
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row['source']][] = [
                'id'         => (int) $row['id'],
                'to'         => (string) $row['recipient_email'],
                'state'      => (string) $row['state'],
                'created_at' => new \DateTimeImmutable((string) $row['created_at']),
                'sent_at'    => $row['sent_at'] !== null ? new \DateTimeImmutable((string) $row['sent_at']) : null,
                'batch_id'   => (string) $row['batch_id'],
                'created_by' => (string) $row['created_by'],
                'sender'     => (string) $row['sender_name'],
                'sender_email' => (string) $row['sender_email'],
                // The attachment references the mail was queued with (`type:id`) — a document module tells
                // by them WHICH version went out (debtor: `invoice-pdf:<id>@<revision>`, ADR-054 decision 21).
                'attachments'  => array_values(array_filter(is_array($list = json_decode((string) $row['attachments'], true)) ? $list : [], 'is_string')),
            ];
        }

        return $bySource;
    }

    // ── the job ──────────────────────────────────────────────────────────

    /**
     * Ids of queued mails due at `$now`, the longest-waiting first.
     *
     * @return list<int>
     */
    public function dueIds(\DateTimeImmutable $now, int $limit): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT id FROM ' . self::TABLE . ' WHERE state = ? AND next_attempt_at <= ? ORDER BY next_attempt_at ASC, id ASC LIMIT ?',
            [OutboxMail::STATE_QUEUED, $now->format(self::DATETIME), max(1, $limit)],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        ));
    }

    /** `queued → sending`, only while still queued and due. */
    public function claim(int $id, \DateTimeImmutable $now): bool
    {
        return $this->transition(
            'state = ?', [OutboxMail::STATE_SENDING],
            'id = ? AND state = ? AND next_attempt_at <= ?', [$id, OutboxMail::STATE_QUEUED, $now->format(self::DATETIME)]
        );
    }

    /** `sending → sent`: one attempt more, the error cleared, out of the queue. */
    public function markSent(int $id, \DateTimeImmutable $at): bool
    {
        return $this->transition(
            "state = ?, attempts = attempts + 1, last_error = '', sent_at = ?, next_attempt_at = NULL", [OutboxMail::STATE_SENT, $at->format(self::DATETIME)],
            'id = ? AND state = ?', [$id, OutboxMail::STATE_SENDING]
        );
    }

    /** `sending → queued` after a failure: one attempt more, the error, the next try. */
    public function markRetry(int $id, string $error, \DateTimeImmutable $nextAttemptAt): bool
    {
        return $this->transition(
            'state = ?, attempts = attempts + 1, last_error = ?, next_attempt_at = ?', [OutboxMail::STATE_QUEUED, self::clip($error), $nextAttemptAt->format(self::DATETIME)],
            'id = ? AND state = ?', [$id, OutboxMail::STATE_SENDING]
        );
    }

    /** `sending → failed`: the last attempt failed, or the mail can never be sent (an invalid address). */
    public function markFailed(int $id, string $error): bool
    {
        return $this->transition(
            'state = ?, attempts = attempts + 1, last_error = ?, next_attempt_at = NULL', [OutboxMail::STATE_FAILED, self::clip($error)],
            'id = ? AND state = ?', [$id, OutboxMail::STATE_SENDING]
        );
    }

    /**
     * Every `sending` row → `failed`, with the reason. Called by the job at the
     * start of a slice, while it holds the job lock: no other sender exists, so
     * a row still `sending` belongs to a process that died between claiming and
     * recording — whether the mail went out is UNKNOWN, and sending it again
     * unasked could deliver it twice. The operator decides («Erneut senden»).
     *
     * @return int how many rows
     */
    public function failInterrupted(string $error): int
    {
        try {
            return (int) $this->connection()->executeStatement(
                'UPDATE ' . self::TABLE . ' SET state = ?, attempts = attempts + 1, last_error = ?, next_attempt_at = NULL WHERE state = ?',
                [OutboxMail::STATE_FAILED, self::clip($error), OutboxMail::STATE_SENDING]
            );
        } catch (\Throwable $e) {
            $this->markTransactionRollbackOnly();
            throw $e;
        }
    }

    // ── the operator ─────────────────────────────────────────────────────

    /** `queued → cancelled`, only while queued (a mail being sent cannot be called back). */
    public function cancel(int $id): bool
    {
        return $this->transition(
            'state = ?, next_attempt_at = NULL', [OutboxMail::STATE_CANCELLED],
            'id = ? AND state = ?', [$id, OutboxMail::STATE_QUEUED]
        );
    }

    /**
     * `failed → queued`, due at once, with a full new round of attempts
     * (`attempts` back to 0 — «Erneut senden» means «try again as if new»; the
     * last error stays visible until the next attempt replaces it).
     */
    public function requeue(int $id, \DateTimeImmutable $now): bool
    {
        return $this->transition(
            'state = ?, attempts = 0, next_attempt_at = ?', [OutboxMail::STATE_QUEUED, $now->format(self::DATETIME)],
            'id = ? AND state = ?', [$id, OutboxMail::STATE_FAILED]
        );
    }

    /** The row as the database has it NOW — after a transition a copy in memory is stale. */
    public function fresh(int $id): ?OutboxMail
    {
        $mail = $this->find($id);
        if ($mail instanceof OutboxMail) {
            $this->em()->refresh($mail);
        }

        return $mail instanceof OutboxMail ? $mail : null;
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $setParams
     * @param list<mixed> $whereParams
     */
    private function transition(string $set, array $setParams, string $where, array $whereParams): bool
    {
        try {
            return $this->connection()->executeStatement(
                'UPDATE ' . self::TABLE . " SET {$set} WHERE {$where}",
                array_merge($setParams, $whereParams)
            ) === 1;
        } catch (\Throwable $e) {
            $this->markTransactionRollbackOnly();
            throw $e;
        }
    }

    /**
     * @param list<int> $ids
     * @return list<OutboxMail>
     */
    private function inOrder(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $mail) {
            $byId[$mail->getId()] = $mail;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * The WHERE clause of {@see search()} / {@see countSearch()}: the view's
     * states, the day range half-open on `created_at`, LIKE for the texts, the batch exact.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<mixed>}
     */
    private function searchWhere(OutboxSearch $search): array
    {
        $parts  = [];
        $params = [];
        $types  = [];
        if (($states = $search->states()) !== []) {
            $parts[]  = 'm.state IN (?)';
            $params[] = $states;
            $types[]  = ArrayParameterType::STRING;
        }
        if ($search->from !== null) {
            $parts[]  = 'm.created_at >= ?';
            $params[] = $search->from . ' 00:00:00';
            $types[]  = ParameterType::STRING;
        }
        if ($search->to !== null) {
            $parts[]  = 'm.created_at < ?';
            $params[] = (new \DateTimeImmutable($search->to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            $types[]  = ParameterType::STRING;
        }
        if ($search->recipient !== null) {
            $parts[]  = '(m.recipient_email LIKE ? OR m.recipient_name LIKE ?)';
            $like     = '%' . addcslashes($search->recipient, '%_\\') . '%';
            array_push($params, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }
        if ($search->batch !== '') {
            $parts[]  = 'm.batch_id = ?';   // idx_mail_outbox_batch
            $params[] = $search->batch;
            $types[]  = ParameterType::STRING;
        }
        foreach (['subject' => $search->subject, 'source' => $search->source] as $column => $value) {
            if ($value !== null) {
                $parts[]  = "m.{$column} LIKE ?";
                $params[] = '%' . addcslashes($value, '%_\\') . '%';
                $types[]  = ParameterType::STRING;
            }
        }

        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params, $types];
    }

    private static function clip(string $error): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');

        return mb_strlen($error) > OutboxMail::ERROR_LENGTH ? mb_substr($error, 0, OutboxMail::ERROR_LENGTH - 1) . '…' : $error;
    }
}
