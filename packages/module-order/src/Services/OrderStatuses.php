<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;

/**
 * The status RULES over one snapshot of the status rows — pure, no database, no file (the harness
 * checks them offline, section T). Built from `OrderStatusRepository::allInOrder()`.
 *
 * Behaviour is the PHASE's (ADR-043 addendum 2026-10-15); a status is a label in one phase. **No
 * code path selects a status by its `code`** (harness N1): a step that needs «the» status asks for
 * the DEFAULT of a phase — {@see defaultOf()}: the active row marked `phaseDefault`, else the first
 * active row of the phase by position. The steps:
 *
 *   - a new order                 — {@see initial()}: THE start status (one for all, owner 2026-10-16);
 *   - the order confirmation      — the default of `confirmed`;
 *   - the delivery-note print     — the default of `delivered`;
 *   - «Fakturieren»                — the default of `closed` («Fakturiert»);
 *   - «Journal verbuchen» (debtor)  — {@see postingTarget()}: the row flagged `setOnPosting` («Gebucht»,
 *                                    owner 2026-10-24 — two statuses after invoicing, both `closed`).
 *
 * An action moves an order only FORWARD into its phase ({@see actionTarget()}): an order already in
 * that phase or past it keeps its status (a reprint).
 *
 * Transitions by hand ({@see refusal()}): forward or lateral (a phase at or after the current one)
 * always and backward too, while the order has NO invoice; never to the default of `closed` («Fakturieren»'s
 * alone — no «fakturiert» without an invoice) nor to the posting status («Journal verbuchen»'s alone); with an
 * invoice NOTHING by hand
 * (owner 2026-10-20: the invoice is final, a correction is a credit note on it — «Erledigt» is for
 * an order that is never invoiced); only ACTIVE statuses are targets — an order keeps a deactivated one.
 */
final class OrderStatuses
{
    public const SAME            = 'same-status';
    public const INACTIVE_TARGET = 'inactive-target';
    public const BOOKED_TARGET   = 'booked-by-print-only';
    public const POSTED_TARGET   = 'set-by-posting-only';
    public const INVOICED        = 'invoiced';

    /** @var list<OrderStatus> along the ladder: phase, position, id */
    private readonly array $rows;

    /** @param list<OrderStatus> $rows every status row, active or not */
    public function __construct(array $rows)
    {
        usort($rows, [OrderStatus::class, 'compare']);
        $this->rows = array_values($rows);
    }

    /** @return list<OrderStatus> */
    public function all(): array
    {
        return $this->rows;
    }

    /** The row carrying $code — an order's own status or a target a user picked — or null. */
    public function byCode(string $code): ?OrderStatus
    {
        $code = OrderStatus::normalizeCode($code);
        foreach ($this->rows as $row) {
            if ($row->getCode() === $code) {
                return $row;
            }
        }

        return null;
    }

    /** The status an action sets when it moves an order into $phase — null = the phase has no active status. */
    public function defaultOf(OrderPhase $phase): ?OrderStatus
    {
        $first = null;
        foreach ($this->rows as $row) {
            if (!$row->isActive() || $row->getPhase() !== $phase) {
                continue;
            }
            if ($row->isPhaseDefault()) {
                return $row;
            }
            $first ??= $row;
        }

        return $first;
    }

    /**
     * The status «Journal verbuchen» sets (owner 2026-10-24): the ACTIVE row flagged `setOnPosting`
     * — null = none (an installation whose file predates the switch and was not upgraded yet, or
     * the owner switched it off: the posting then moves no order). Never the default of `closed`
     * itself — a row carrying both would make the posting a no-op that looks like one.
     */
    public function postingTarget(): ?OrderStatus
    {
        $closed = $this->defaultOf(OrderPhase::Closed);
        foreach ($this->rows as $row) {
            if ($row->isActive() && $row->isSetOnPosting() && $row !== $closed) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A new order's status: THE start status (owner 2026-10-16 — one for all, «Eröffnet» or
     * «Termin»); without an active one the first active status of `new`; null = none there either.
     */
    public function initial(): ?OrderStatus
    {
        foreach ($this->rows as $row) {
            if ($row->isActive() && $row->isStart()) {
                return $row;
            }
        }

        return $this->defaultOf(OrderPhase::New);
    }

    /**
     * The rows that LOSE a switch when $s is saved active with its switches (owner 2026-10-16:
     * «setze ich ihn, wird der Flag bei den anderen gelöscht»): the start status is one for all,
     * the action default one per phase. Pure — the master-data save clears them, the harness checks
     * it; the stored copy of $s itself is skipped by id.
     *
     * The posting switch (`setOnPosting`, owner 2026-10-24) is one for all as well.
     *
     * @param list<OrderStatus> $rows
     * @return array{start: list<OrderStatus>, default: list<OrderStatus>, posting: list<OrderStatus>}
     */
    public static function takeovers(OrderStatus $s, array $rows): array
    {
        $out = ['start' => [], 'default' => [], 'posting' => []];
        if (!$s->isActive()) {
            return $out;
        }
        foreach ($rows as $o) {
            if ($s->getId() !== null && $o->getId() === $s->getId()) {
                continue;
            }
            if ($s->isStart() && $o->isStart()) {
                $out['start'][] = $o;
            }
            if ($s->isPhaseDefault() && $o->isPhaseDefault() && $o->getPhase() === $s->getPhase()) {
                $out['default'][] = $o;
            }
            if ($s->isSetOnPosting() && $o->isSetOnPosting()) {
                $out['posting'][] = $o;
            }
        }

        return $out;
    }

    /**
     * The statuses a NEW order may start in when the create form picks one: every active one of a
     * phase that is not locked (owner 2026-10-15: «bei der Erfassung kann ich wählen, ausser gebucht
     * und höher») — so a service order starts as «Terminauftrag» at once.
     *
     * @return list<OrderStatus>
     */
    public function startable(): array
    {
        return array_values(array_filter($this->rows, static fn(OrderStatus $s) => $s->isActive() && $s->getPhase()->isStartable()));
    }

    /**
     * The status an ACTION moving into $phase sets for an order in $current: the default of $phase
     * when the order stands BEFORE it on the ladder; null when it is already in or past it (a
     * reprint moves nothing) or when $phase has no active status.
     */
    public function actionTarget(OrderStatus $current, OrderPhase $phase): ?OrderStatus
    {
        return $current->getPhase()->rank() < $phase->rank() ? $this->defaultOf($phase) : null;
    }

    /**
     * Why an order in $from may NOT move to $to by a status change — null = allowed.
     *
     * @return array{0: string, 1: string}|null the reason constant and the German message
     */
    public function refusal(OrderStatus $from, OrderStatus $to, bool $hasInvoice): ?array
    {
        if ($to->getCode() === $from->getCode()) {
            return [self::SAME, 'Der Auftrag ist bereits im Status «' . $to->getLabel() . '».'];
        }
        if (!$to->isActive()) {
            return [self::INACTIVE_TARGET, 'Der Status «' . $to->getLabel() . '» ist deaktiviert und wird nicht mehr vergeben.'];
        }
        if ($to->getCode() === $this->defaultOf(OrderPhase::Closed)?->getCode()) {
            return [self::BOOKED_TARGET, '«' . $to->getLabel() . '» setzt nur das Fakturieren — es erstellt die Rechnung und setzt den Status in einem Schritt.'];
        }
        if ($to->isSetOnPosting()) {
            return [self::POSTED_TARGET, '«' . $to->getLabel() . '» setzt nur «Journal verbuchen» — wenn die Rechnung des Auftrags in die Buchhaltung übernommen wird.'];
        }
        if ($hasInvoice) {
            return [self::INVOICED, 'Zum Auftrag ist eine Rechnung fakturiert — der Status bleibt.'];
        }

        return null;
    }

    /**
     * The targets a status change offers an order in $from (the confirm list of the screen): every
     * row {@see refusal()} allows, along the ladder.
     *
     * @return list<OrderStatus>
     */
    public function targets(OrderStatus $from, bool $hasInvoice): array
    {
        return array_values(array_filter($this->rows, fn(OrderStatus $to) => $this->refusal($from, $to, $hasInvoice) === null));
    }
}
