<?php

namespace Z77\Module\Article\Services;

/**
 * The article was changed or deleted since the form was rendered (its version moved on) — the
 * screen answers «neu laden». Nothing was written.
 */
final class ArticleConflictException extends ArticleException
{
    public function __construct(public readonly int $productId, public readonly int $expectedVersion)
    {
        parent::__construct("Article {$productId} was changed or removed in the meantime (expected version {$expectedVersion}) — reload it");
    }
}
