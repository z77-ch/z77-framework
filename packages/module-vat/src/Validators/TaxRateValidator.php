<?php

namespace Z77\Module\Vat\Validators;

use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Repositories\TaxRateRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see TaxRate}.
 *
 * Code: must name an existing {@see \Z77\Module\Vat\Entities\TaxCode} (active
 * or not — a deactivated code may still need a historical row). Valid from: a
 * real `YYYY-MM-DD`. Rate: a non-negative integer in hundredths of a percent,
 * at most 100 % (`10000`).
 *
 * Validity ranges must not overlap: a row is valid until the next `validFrom`
 * of the same code, so two rows of one code sharing a `validFrom` would claim
 * the same day — refused.
 *
 * Backdating (owner 2026-09-21): with `$today` given, a `validFrom` before
 * today is refused — a rate that reaches into issued documents' service dates
 * would change what they should have computed — EXCEPT when it lies before
 * the code's earliest existing row: that is pure backfill of history (pre-2018
 * rates for a migration), which touches no day a current row covers. The
 * FIRST rate of a code that has no row at all may start at any valid date
 * (owner, 2026-09-21): there is no existing range it could reach into, and
 * an installation creating a code for older documents needs it to cover
 * them. An edit of a code's only row is not a first rate — the row exists,
 * so the backfill rule applies. Without `$today` (seed check, tests) the
 * rule is not applied. Without repositories only the format is checked.
 */
class TaxRateValidator extends EntityValidator
{
    /** 100 % — nothing above is a VAT rate. */
    public const MAX_RATE = 10000;

    public function __construct(
        TaxRate $taxRate,
        private ?TaxCodeRepository $codes = null,
        private ?TaxRateRepository $rates = null,
        private ?\DateTimeImmutable $today = null,
    ) {
        parent::__construct($taxRate);
    }

    public function validateCode(string $code): void
    {
        $this->validate('code', 'Code', $code)->notEmpty();

        if ($this->hasFieldError('code') || $this->codes === null) {
            return;
        }
        if ($this->codes->findByCode($code) === null) {
            $this->addFieldError('code', 'Code «' . $code . '» existiert nicht.');
        }
    }

    public function validateValidFrom(string $validFrom): void
    {
        $this->validate('valid_from', 'Gültig ab', $validFrom)->notEmpty();

        if (!$this->hasFieldError('valid_from') && !TaxRate::isValidDate($validFrom)) {
            $this->addFieldError('valid_from', 'Gültig ab muss ein Datum im Format JJJJ-MM-TT sein.');
        }

        if ($this->hasFieldError('valid_from') || $this->rates === null) {
            return;
        }

        $code   = $this->entity->getCode();
        $all    = $this->rates->findByCode($code);
        $others = array_values(array_filter(
            $all,
            fn(TaxRate $r) => $r->getId() !== $this->entity->getId()
        ));

        foreach ($others as $other) {
            if ($other->getValidFrom() === $validFrom) {
                $this->addFieldError('valid_from', 'Für «' . $code . '» gibt es bereits einen Satz gültig ab ' . $validFrom . '.');
                return;
            }
        }

        // Not backdated, or the first rate of a code without any row (`$all`,
        // not `$others`: the only row being edited is not a first rate).
        if ($this->today === null || $validFrom >= $this->today->format('Y-m-d') || $all === []) {
            return;
        }

        // Backdated. Allowed only as backfill before the earliest existing row.
        $earliest = $others === [] ? null : $others[array_key_last($others)]->getValidFrom();   // rows come newest first
        if ($earliest === null || $validFrom >= $earliest) {
            $this->addFieldError(
                'valid_from',
                'Gültig ab darf nicht in der Vergangenheit liegen — ausser vor dem ältesten bestehenden Satz'
                . ($earliest !== null ? ' (' . $earliest . ')' : '') . ', um Historie nachzutragen.'
            );
        }
    }

    public function validateRate(mixed $rate): void
    {
        if (!is_int($rate)) {
            $this->addFieldError('rate', 'Satz muss eine ganze Zahl in Hundertstelprozent sein.');
            return;
        }
        if ($rate < 0) {
            $this->addFieldError('rate', 'Satz darf nicht negativ sein.');
            return;
        }
        if ($rate > self::MAX_RATE) {
            $this->addFieldError('rate', 'Satz darf 100 % nicht übersteigen.');
        }
    }
}
