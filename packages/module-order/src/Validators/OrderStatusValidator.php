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
 * Across the rows there is no refusal any more (owner 2026-10-16): setting the start switch or an
 * action default TAKES it from the row that held it — the master-data save does that
 * ({@see \Z77\Module\Order\Services\OrderStatuses::takeovers()}). The repository is for the code's
 * uniqueness; without one that check is skipped (format-only — the form's first render).
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
            $this->validateSwitches();
        }
    }

    public function validatePosition(int $position): void
    {
        if ($position < 0 || $position > self::MAX_POSITION) {
            $this->addFieldError('position', 'Sortierung: 0 bis ' . self::MAX_POSITION . '.');
        }
    }

    /**
     * «Wird gesetzt beim Fakturieren» and «Wird gesetzt beim Verbuchen» (owner 2026-10-24) are two
     * statuses: on ONE row the posting would move no order and the two steps could not be told apart.
     * One posting status for all is the save's takeover, not a refusal (owner 2026-10-16).
     */
    private function validateSwitches(): void
    {
        /** @var OrderStatus $status */
        $status = $this->entity;
        if ($status->isPhaseDefault() && $status->isSetOnPosting() && !$this->hasFieldError('set_on_posting')) {
            $this->addFieldError('set_on_posting', '«Wird gesetzt beim Verbuchen» gehört zu einem anderen Status als «Wird gesetzt beim Fakturieren» — sonst wechselt das Verbuchen keinen Auftrag.');
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
}
