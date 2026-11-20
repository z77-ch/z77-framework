<?php

namespace Z77\Module\Article\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Article\Entities\Product;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 61 («Ersteinrichtung», owner 2026-11-20): the mandator IS liable to VAT and an ACTIVE
 * article has no tax code. A company that is not liable may leave the code empty
 * (`ArticleValidator`, `CurrentMandator::liableToVat()`); once it switches to liable, such an
 * article reaches an invoice and the booking print refuses «Position n: der MWST-Code fehlt»
 * (debtor's `InvoicingService`). This step names it BEFORE that. Silent while not liable — the
 * same answer the validator and debtor's `chargesVat()` read. One `findOneBy()` after the flag.
 */
final class ArticleTaxCodeCheck extends SetupCheck
{
    public function id(): string
    {
        return 'article.tax-code';
    }

    public function order(): int
    {
        return 61;
    }

    public function target(): array
    {
        return ['backend', 'order', 'article', 'list'];
    }

    public function label(): string
    {
        return 'Artikel ohne MWST-Code';
    }

    public function isOpen(): bool
    {
        $em = DI::getUnifiedEntityManager();
        if (!(new CurrentMandator($em))->liableToVat()) {
            return false;
        }

        return $em->getRepository(Product::class)->findOneBy(['active' => true, 'taxCode' => '']) !== null;
    }
}
