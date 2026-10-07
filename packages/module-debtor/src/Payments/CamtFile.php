<?php

namespace Z77\Module\Debtor\Payments;

/**
 * A CAMT.054 file as {@see CamtReader} read it: the message header and
 * its transactions, nothing resolved. The IBAN is normalized (upper-case,
 * no spaces).
 */
final class CamtFile
{
    /** @param list<CamtEntry> $entries in file order */
    public function __construct(
        public readonly string $messageId,
        public readonly \DateTimeImmutable $createdOn,
        public readonly string $iban,
        public readonly array $entries,
    ) {}
}
