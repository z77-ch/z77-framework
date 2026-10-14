<?php

namespace Z77\Module\Article\Services;

/**
 * A group that still carries sub-groups or articles is not deleted (owner 2026-10-14: «delete only
 * when empty and unreferenced») — the answer is to move them first, or to deactivate the group.
 */
final class ProductGroupInUseException extends ArticleException
{
    public function __construct(
        public readonly int $groupId,
        public readonly int $children,
        public readonly int $products,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Product group {$groupId} still carries {$children} sub-group(s) and {$products} article(s) — move them or deactivate the group", 0, $previous);
    }
}
