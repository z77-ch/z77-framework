<?php

namespace Z77\Module\Order\Validators;

use Z77\Module\Debtor\Validators\ValidatesMasterDataRow;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates an {@see OrderStatus} for the backend and for any other writer (plan §4.2, ADR-043
 * decision 2 with the addendum 2026-10-14).
 *
 * Code and label: the shared rule of file-based master data (`ValidatesMasterDataRow` of
 * module-debtor, which order requires — one rule for every master-data row, Rule 9): code
 * `[a-z][a-z0-9-]{1,15}`, unique; label required, at most 80. Level 1–{@see MAX_LEVEL}, NOT unique
 * (two «erledigt» share a level — a lateral move).
 *
 * The invariants, as field errors on the flag that breaks them:
 *
 *   - `final ⇒ ¬invoiceable ∧ ¬invoiceBooked`;
 *   - `consumesStock ⇒ ¬reservesStock`;
 *   - `offer ⇒ ¬reservesStock ∧ ¬consumesStock ∧ ¬invoiceable`;
 *   - `invoiceBooked ⇒ ¬invoiceable`;
 *   - `invoiceBooked` and `deliveryNoteTarget` each on at most ONE ACTIVE row — a flag by which
 *     code SELECTS a status is unique per installation;
 *   - the ACTIVE `invoiceBooked` row's level lies ABOVE every active `invoiceable` row's level —
 *     the booking print must be a forward move.
 *
 * The cross-row rules look at the OTHER rows as they are stored; the row itself counts when it is
 * active (a new row is). Without a repository they are skipped (format-only — the form's first
 * render). {@see crossRowProblem()} is the same check for the activation switch, which runs no
 * field validator (the debtor model of `setActive()`).
 */
class OrderStatusValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    public const MAX_LEVEL = 999;

    public function __construct(
        OrderStatus $status,
        private ?FileRepository $repo = null,
    ) {
        parent::__construct($status);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    protected function executeValidation(?array $only = null): void
    {
        parent::executeValidation($only);
        if ($only === null) {
            $this->validateFlags();
        }
    }

    /** A refusal found outside the field run (the activation switch) — mapped onto its field. */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validateLevel(int $level): void
    {
        if ($level < 1 || $level > self::MAX_LEVEL) {
            $this->addFieldError('level', 'Stufe: 1 bis ' . self::MAX_LEVEL . '.');
        }
    }

    /** The invariants between the flags of this row, then across the rows. */
    private function validateFlags(): void
    {
        /** @var OrderStatus $status */
        $status = $this->entity;
        foreach (self::flagProblems($status) as $field => $message) {
            if (!$this->hasFieldError($field)) {
                $this->addFieldError($field, $message);
            }
        }
        if ($this->repo === null || !$status->isActive()) {
            return;
        }
        $problem = self::crossRowProblem($status, $this->repo->findAll());
        if ($problem !== null && !$this->hasFieldError($problem[0])) {
            $this->addFieldError($problem[0], $problem[1]);
        }
    }

    /**
     * The invariants WITHIN one row. Pure — the harness checks them offline.
     *
     * @return array<string, string> field → German message
     */
    public static function flagProblems(OrderStatus $s): array
    {
        $problems = [];
        if ($s->isFinal() && ($s->isInvoiceable() || $s->isInvoiceBooked())) {
            $problems['final'] = 'Ein abschliessender Status ist weder «fakturierbar» noch «gebucht» — ein erledigter Auftrag wird nicht mehr verbucht.';
        }
        if ($s->isConsumesStock() && $s->isReservesStock()) {
            $problems['reserves_stock'] = 'Ein Status reserviert ODER verbraucht Bestand, nicht beides — mit dem Verbrauch wird die Reservierung frei.';
        }
        if ($s->isOffer() && ($s->isReservesStock() || $s->isConsumesStock() || $s->isInvoiceable())) {
            $problems['offer'] = 'Eine Offerte reserviert und verbraucht keinen Bestand und ist nicht fakturierbar.';
        }
        if ($s->isInvoiceBooked() && $s->isInvoiceable()) {
            $problems['invoice_booked'] = '«Gebucht» und «fakturierbar» schliessen sich aus — der Buchungsdruck setzt den gebuchten Status, danach ist nichts mehr zu fakturieren.';
        }

        return $problems;
    }

    /**
     * The invariants ACROSS the rows for $s as an ACTIVE row among $rows (the stored rows; the
     * stored copy of $s itself is skipped by id). Pure — the harness and the activation switch use it.
     *
     * @param list<OrderStatus> $rows
     * @return array{0: string, 1: string}|null field and German message, or null
     */
    public static function crossRowProblem(OrderStatus $s, array $rows): ?array
    {
        $others = array_values(array_filter(
            $rows,
            static fn(OrderStatus $o) => $o->isActive() && ($s->getId() === null || $o->getId() !== $s->getId())
        ));
        foreach ($others as $o) {
            if ($s->isInvoiceBooked() && $o->isInvoiceBooked()) {
                return ['invoice_booked', 'Nur ein aktiver Status ist «gebucht» — «' . $o->getLabel() . '» ist es schon. Dort zuerst ausschalten oder ihn deaktivieren.'];
            }
            if ($s->isDeliveryNoteTarget() && $o->isDeliveryNoteTarget()) {
                return ['delivery_note_target', 'Nur ein aktiver Status ist «Ziel des Lieferscheins» — «' . $o->getLabel() . '» ist es schon. Dort zuerst ausschalten oder ihn deaktivieren.'];
            }
        }
        foreach ($others as $o) {
            if ($s->isInvoiceBooked() && $o->isInvoiceable() && $s->getLevel() <= $o->getLevel()) {
                return ['level', 'Der gebuchte Status braucht eine höhere Stufe als jeder fakturierbare — «' . $o->getLabel() . '» hat Stufe ' . $o->getLevel() . '. Der Buchungsdruck ist ein Schritt vorwärts.'];
            }
            if ($s->isInvoiceable() && $o->isInvoiceBooked() && $o->getLevel() <= $s->getLevel()) {
                return ['level', 'Ein fakturierbarer Status braucht eine tiefere Stufe als der gebuchte «' . $o->getLabel() . '» (Stufe ' . $o->getLevel() . ') — der Buchungsdruck ist ein Schritt vorwärts.'];
            }
        }

        return null;
    }
}
