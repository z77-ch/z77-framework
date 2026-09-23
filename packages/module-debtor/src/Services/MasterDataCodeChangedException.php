<?php

namespace Z77\Module\Debtor\Services;

/**
 * A write tried to change the `code` of an existing file-based master-data
 * row — payment terms, payment target or dunning level. The code is the key
 * database rows and issued documents carry (ADR-043 decision 19), so it is
 * immutable once created: for another meaning, a new row.
 *
 * Carries what was stored and what was submitted, so the screen can say both;
 * the entity's name only shapes the developer message.
 */
final class MasterDataCodeChangedException extends DebtorException
{
    public function __construct(
        string $entity,
        public readonly string $storedCode,
        public readonly string $submittedCode,
    ) {
        parent::__construct("The code of an existing {$entity} is immutable: stored '{$storedCode}', submitted '{$submittedCode}'");
    }
}
