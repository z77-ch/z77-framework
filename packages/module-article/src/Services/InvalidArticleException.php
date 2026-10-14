<?php

namespace Z77\Module\Article\Services;

use Z77\Module\Article\Validators\ArticleValidator;

/**
 * A save refused by its rules — the validator carries the field errors in German, the draft what
 * was typed, so the dialog re-renders both. Nothing was written.
 */
final class InvalidArticleException extends ArticleException
{
    public function __construct(
        public readonly ArticleValidator $validator,
        public readonly ArticleDraft $draft,
    ) {
        parent::__construct('Article refused by its validator');
    }
}
