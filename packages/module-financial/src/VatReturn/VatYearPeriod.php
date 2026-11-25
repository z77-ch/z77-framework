<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\VatReturn;

/**
 * One settlement period of a tax year in the yearly overview — the list «Abrechnungen {Jahr}»:
 * the period, its LIVE return (null while it is not posted — the overview is then «vorläufig»)
 * and the due date of its return ({@see VatYearDeadlines::dueDate()}). Immutable.
 */
final class VatYearPeriod
{
    public function __construct(
        public readonly VatPeriod $period,
        public readonly ?VatReturn $return,
        public readonly \DateTimeImmutable $due,
    ) {}

    public function isPosted(): bool
    {
        return $this->return !== null;
    }
}
