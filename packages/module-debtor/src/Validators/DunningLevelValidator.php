<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Repositories\DunningLevelRepository;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see DunningLevel} for the backend and for any other writer.
 *
 * Code: `[a-z][a-z0-9-]{1,15}`, unique. Label required.
 *
 * `level` 1–{@see MAX_LEVEL} and UNIQUE across the rows — it is the order a
 * dunning run walks, and two rows on one step would make that order a
 * coin toss. `daysAfterDue` 0–{@see MAX_DAYS}. `fee` 0 or more, at most
 * {@see MAX_FEE} minor units — a dunning fee is a fee, not an invoice; and
 * it carries NO VAT (plan §6.5), which is why this entity has no tax code
 * to validate.
 *
 * Document text: the shared per-language rule ({@see DocumentTextRule}).
 *
 * Without a repository the uniqueness checks are skipped (format-only).
 */
class DunningLevelValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    public const MAX_LEVEL = 9;
    public const MAX_DAYS  = 365;

    /** 1'000.00 in minor units — anything beyond is a typo, not a dunning fee. */
    public const MAX_FEE = 100000;

    public function __construct(
        DunningLevel $level,
        private ?DunningLevelRepository $repo = null,
    ) {
        parent::__construct($level);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    public function validateLevel(int $level): void
    {
        if ($level < 1 || $level > self::MAX_LEVEL) {
            $this->addFieldError('level', 'Stufe: 1 bis ' . self::MAX_LEVEL . '.');
            return;
        }
        if ($this->repo === null) {
            return;
        }

        $ownId = $this->entity->getId();
        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() !== $ownId && $other->getLevel() === $level) {
                $this->addFieldError('level', 'Stufe ' . $level . ' ist bereits mit «' . $other->getCode() . '» belegt.');
                return;
            }
        }
    }

    public function validateDaysAfterDue(int $days): void
    {
        if ($days < 0 || $days > self::MAX_DAYS) {
            $this->addFieldError('days_after_due', 'Tage nach Verfall: 0 bis ' . self::MAX_DAYS . '.');
        }
    }

    public function validateFee(int $fee): void
    {
        if ($fee < 0) {
            $this->addFieldError('fee', 'Die Mahngebühr kann nicht negativ sein.');
            return;
        }
        if ($fee > self::MAX_FEE) {
            // String work on the integer — no float anywhere near an amount (plan §3).
            $max = intdiv(self::MAX_FEE, 100) . '.' . str_pad((string) (self::MAX_FEE % 100), 2, '0', STR_PAD_LEFT);
            $this->addFieldError('fee', 'Die Mahngebühr ist höchstens ' . $max . '.');
        }
    }

    /** @param array<string, string> $texts */
    public function validateDocumentText(array $texts): void
    {
        $error = DocumentTextRule::check($texts);
        if ($error !== null) {
            $this->addFieldError(DocumentTextRule::FIELD, $error);
        }
    }
}
