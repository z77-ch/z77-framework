<?php

namespace Z77\Module\Article\Services;

/**
 * A delete refused because a row elsewhere references a variant of the article (an order line —
 * module-order's foreign key on `article_variant`, which article does not name). The answer is
 * to deactivate it: a referenced article is deactivated, never deleted (plan §4.1). Nothing was
 * deleted.
 */
final class ArticleInUseException extends ArticleException
{
    public function __construct(public readonly int $productId, ?\Throwable $previous = null)
    {
        parent::__construct("Article {$productId} is referenced and cannot be deleted — deactivate it instead", 0, $previous);
    }
}
