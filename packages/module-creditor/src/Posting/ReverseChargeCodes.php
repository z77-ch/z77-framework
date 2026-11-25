<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Which tax codes are REVERSE CHARGE (VAT return stage S6): module-vat's codes of category
 * `reverse-charge` — BM, BI, BM2, BI2 and an own code of that category, active or not (a stored line
 * keeps a code deactivated since). Read once per instance from module-vat's file data. The
 * creditor's one answer to «is this line reverse charge?» — the run, the window, the save, the
 * VAT return's open point. No date rule here: the reverse-charge DATE is financial's rule, asked by
 * {@see DirectPosting} alone.
 */
final class ReverseChargeCodes
{
    /** @var list<string>|null */
    private ?array $codes = null;

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** @return list<string> the reverse-charge codes */
    public function codes(): array
    {
        return $this->codes ??= array_values(array_map(
            static fn(TaxCode $code): string => $code->getCode(),
            array_filter($this->em->getRepository(TaxCode::class)->findAll(), static fn(TaxCode $code): bool => $code->category() === TaxCategory::ReverseCharge)
        ));
    }

    public function isReverseCharge(?string $taxCode): bool
    {
        return $taxCode !== null && in_array($taxCode, $this->codes(), true);
    }

    /**
     * @param list<CreditorInvoiceLine> $lines
     * @return list<CreditorInvoiceLine> the lines carrying a reverse-charge code, in their order
     */
    public function linesOf(array $lines): array
    {
        return array_values(array_filter($lines, fn(CreditorInvoiceLine $l): bool => $this->isReverseCharge($l->getTaxCode())));
    }
}
