<?php

namespace Z77\Module\Order\Validators;

use Z77\Module\Debtor\Validators\ValidatesMasterDataRow;
use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates an {@see OrderStatus} for the backend and for any other writer (ADR-043 decision 2 with
 * the addendum 2026-10-15).
 *
 * Code and label: the shared rule of file-based master data (`ValidatesMasterDataRow` of
 * module-debtor, which order requires — one rule for every master-data row, Rule 9): code
 * `[a-z][a-z0-9-]{1,15}`, unique; label required, at most 80. The phase one of {@see OrderPhase};
 * the position 0–{@see MAX_POSITION}, NOT unique (equal = by id).
 *
 * Across the rows: at most ONE ACTIVE default per phase — the status an action sets must be
 * unambiguous. The cross-row rule looks at the OTHER rows as they are stored; the row itself counts
 * when it is active (a new row is). Without a repository it is skipped (format-only — the form's
 * first render). {@see crossRowProblem()} is the same check for the activation switch, which runs
 * no field validator (the debtor model of `setActive()`).
 *
 * There are no behaviour flags any more, so no invariant between them: the phase carries the
 * behaviour, and a phase cannot contradict itself.
 */
class OrderStatusValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    public const MAX_POSITION = 999;

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
            $this->validatePhaseValue();
            $this->validateAcrossRows();
        }
    }

    /** A refusal found outside the field run (the activation switch) — mapped onto its field. */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validatePosition(int $position): void
    {
        if ($position < 0 || $position > self::MAX_POSITION) {
            $this->addFieldError('position', 'Sortierung: 0 bis ' . self::MAX_POSITION . '.');
        }
    }

    /** The stored value, not the getter — `getPhase()` reads an unknown value as `new`. */
    private function validatePhaseValue(): void
    {
        /** @var OrderStatus $status */
        $status = $this->entity;
        if (OrderPhase::tryFrom($status->getPhaseValue()) === null && !$this->hasFieldError('phase')) {
            $this->addFieldError('phase', 'Phase wählen.');
        }
    }

    private function validateAcrossRows(): void
    {
        /** @var OrderStatus $status */
        $status = $this->entity;
        if ($this->repo === null || !$status->isActive()) {
            return;
        }
        $problem = self::crossRowProblem($status, $this->repo->findAll());
        if ($problem !== null && !$this->hasFieldError($problem[0])) {
            $this->addFieldError($problem[0], $problem[1]);
        }
    }

    /**
     * The rule ACROSS the rows for $s as an ACTIVE row among $rows (the stored rows; the stored copy
     * of $s itself is skipped by id): one active default per phase. Pure — the harness and the
     * activation switch use it.
     *
     * @param list<OrderStatus> $rows
     * @return array{0: string, 1: string}|null field and German message, or null
     */
    public static function crossRowProblem(OrderStatus $s, array $rows): ?array
    {
        if (!$s->isPhaseDefault()) {
            return null;
        }
        foreach ($rows as $o) {
            if ($o->isActive() && $o->isPhaseDefault() && $o->getPhase() === $s->getPhase()
                && ($s->getId() === null || $o->getId() !== $s->getId())) {
                return ['phase_default', '«' . $o->getLabel() . '» ist schon so markiert («' . ($s->getPhase()->defaultSwitchLabel() ?? $s->getPhase()->label()) . '») — pro Phase nur einer. Dort zuerst ausschalten oder ihn deaktivieren.'];
            }
        }

        return null;
    }
}
