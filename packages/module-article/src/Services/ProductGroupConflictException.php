<?php

namespace Z77\Module\Article\Services;

/** The group is gone or its version moved on since the form was rendered — reload. */
final class ProductGroupConflictException extends ArticleException
{
    public function __construct(public readonly int $groupId, public readonly int $expectedVersion)
    {
        parent::__construct("Product group {$groupId} was changed or removed in the meantime (expected version {$expectedVersion}) — reload it");
    }
}
