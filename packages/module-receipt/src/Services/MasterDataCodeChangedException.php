<?php

namespace Z77\Module\Receipt\Services;

/**
 * A write tried to change the `code` of an existing receipt template or payment method. Receipts
 * carry the code (ADR-043 decision 19), so it is immutable once created: for another meaning, a
 * new row.
 */
final class MasterDataCodeChangedException extends ReceiptException
{
    public function __construct(string $entity, public readonly string $storedCode, public readonly string $submittedCode)
    {
        parent::__construct("The code of an existing {$entity} is immutable: stored '{$storedCode}', submitted '{$submittedCode}'");
    }
}
