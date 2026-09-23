<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Repositories\PaymentTermsRepository;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates {@see PaymentTerms} for the backend and for any other writer
 * (`DebtorMasterData` runs it too, so an import is bound to the same rules).
 *
 * Code: `[a-z][a-z0-9-]{1,15}`, unique (the entity lower-cases; a
 * hand-edited file might not). Label required. `dueDays` 0–365 — 0 means
 * «zahlbar sofort».
 *
 * Discount tiers: at most {@see PaymentTerms::MAX_TIERS}, each with days 1 …
 * `dueDays` (a discount after the whole amount is due is meaningless) and a
 * percent above 0 and below 100 %; day counts must differ, and the EARLIER
 * tier must give the HIGHER percent — otherwise the later one would never
 * be chosen and the terms are a typo.
 *
 * Document text: keys must be languages of this installation
 * ({@see DocumentTextRule}, shared with {@see DunningLevelValidator}).
 *
 * Without a repository the uniqueness check is skipped (format-only).
 */
class PaymentTermsValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    /** Longest a due-day count may be — a year of credit is already extreme. */
    public const MAX_DUE_DAYS = 365;

    public function __construct(
        PaymentTerms $terms,
        private ?PaymentTermsRepository $repo = null,
    ) {
        parent::__construct($terms);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    public function validateDueDays(int $days): void
    {
        if ($days < 0 || $days > self::MAX_DUE_DAYS) {
            $this->addFieldError('due_days', 'Zahlungsfrist: 0 bis ' . self::MAX_DUE_DAYS . ' Tage (0 = zahlbar sofort).');
        }
    }

    /** @param list<array{days: int, percent: int}> $discounts */
    public function validateDiscounts(array $discounts): void
    {
        if (count($discounts) > PaymentTerms::MAX_TIERS) {
            $this->addFieldError('discounts', 'Höchstens ' . PaymentTerms::MAX_TIERS . ' Skonto-Stufen.');
            return;
        }

        $dueDays  = $this->entity->getDueDays();
        $seenDays = [];
        $previous = null;
        foreach ($discounts as $i => $tier) {
            $position = $i + 1;
            if ($tier['days'] < 1) {
                $this->addFieldError('discounts', 'Skonto-Stufe ' . $position . ': Tage müssen mindestens 1 sein.');
                return;
            }
            // No `> 0` exception for «zahlbar sofort»: every tier lies after
            // a due date of 0, so the terms would contradict themselves.
            if ($tier['days'] > $dueDays) {
                $this->addFieldError('discounts', 'Skonto-Stufe ' . $position . ': ' . $tier['days'] . ' Tage liegen nach der Zahlungsfrist von ' . $dueDays . ' Tagen.');
                return;
            }
            if ($tier['percent'] < 1 || $tier['percent'] >= 10000) {
                $this->addFieldError('discounts', 'Skonto-Stufe ' . $position . ': Prozent über 0 und unter 100.');
                return;
            }
            if (isset($seenDays[$tier['days']])) {
                $this->addFieldError('discounts', 'Zwei Skonto-Stufen mit ' . $tier['days'] . ' Tagen — die Tage müssen sich unterscheiden.');
                return;
            }
            if ($previous !== null && $tier['percent'] >= $previous) {
                $this->addFieldError('discounts', 'Die spätere Skonto-Stufe muss weniger Prozent geben als die frühere.');
                return;
            }
            $seenDays[$tier['days']] = true;
            $previous                = $tier['percent'];
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
