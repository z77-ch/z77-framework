<?php

namespace Z77\Module\Article\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Article\Entities\Product;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 6 («Ersteinrichtung», owner 2026-11-19): no ACTIVE article exists — an order line
 * names an article, so without one no order can be captured. Registered by module-article (the
 * owner of the data and of the target screen), not by module-order. One `findOneBy()`.
 */
final class ArticleCheck extends SetupCheck
{
    public function id(): string
    {
        return 'article.first';
    }

    public function order(): int
    {
        return 60;
    }

    public function target(): array
    {
        return ['backend', 'order', 'article', 'list'];
    }

    public function label(): string
    {
        return 'Artikel erfassen';
    }

    public function isOpen(): bool
    {
        return DI::getUnifiedEntityManager()->getRepository(Product::class)->findOneBy(['active' => true]) === null;
    }
}
