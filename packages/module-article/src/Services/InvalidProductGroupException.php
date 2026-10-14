<?php

namespace Z77\Module\Article\Services;

use Z77\Module\Article\Validators\ProductGroupValidator;

/** A product-group save refused by its validator — the window shows the field errors. */
final class InvalidProductGroupException extends ArticleException
{
    public function __construct(
        public readonly ProductGroupValidator $validator,
        public readonly ProductGroupDraft $draft,
    ) {
        parent::__construct('Product group refused by its validator');
    }
}
