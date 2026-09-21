<?php

namespace Z77\Module\Vat\Services;

use Z77\Module\Vat\Calculation\ResolvedRate;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Repositories\TaxRateRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Answers the one read question of this module: «rate of tax code X on date D»
 * (ADR-041 decisions 2 and 4). The date is the SERVICE date of the document —
 * legally what decides the rate, and what wdv already did.
 *
 * The answer is a {@see ResolvedRate}: code, label, category and the rate that
 * applied, as a value the caller can snapshot into its document. A deactivated
 * code still resolves — history has to. There is deliberately no «try»
 * variant: a missing rate is an error the user must see, never a 0 %.
 */
final class VatRates
{
    public function __construct(
        private readonly TaxCodeRepository $codes,
        private readonly TaxRateRepository $rates,
    ) {}

    public static function from(UnifiedEntityManager $em): self
    {
        return new self($em->getRepository(TaxCode::class), $em->getRepository(TaxRate::class));
    }

    /**
     * @throws UnknownTaxCodeException the code does not exist
     * @throws NoRateException        the code has no rate in effect on $serviceDate
     */
    public function resolve(string $code, \DateTimeImmutable $serviceDate): ResolvedRate
    {
        $taxCode = $this->codes->findByCode($code);
        if ($taxCode === null) {
            throw new UnknownTaxCodeException(TaxCode::normalizeCode($code));
        }

        $rate = $this->rates->findEffective($taxCode->getCode(), $serviceDate);
        if ($rate === null) {
            throw new NoRateException($taxCode->getCode(), $serviceDate);
        }

        return ResolvedRate::fromEntities($taxCode, $rate);
    }
}
