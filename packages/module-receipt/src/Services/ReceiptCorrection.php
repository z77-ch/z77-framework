<?php

namespace Z77\Module\Receipt\Services;

/**
 * What the owner sets on a captured receipt in the journal — the raw values as a form or an
 * import has them; {@see ReceiptJournalService::update()} checks each one with
 * {@see ReceiptFields} (the same rules as the capture). An empty account or tax code = not set
 * (the receipt stays incomplete); `withoutVat` is the explicit «ohne MWST» and excludes a code.
 * The photo, the template and who captured it are not correctable.
 */
final class ReceiptCorrection
{
    public function __construct(
        public readonly string $date,
        public readonly string $amount,
        public readonly string $text = '',
        public readonly string $debitAccount = '',
        public readonly string $creditAccount = '',
        public readonly string $taxCode = '',
        public readonly bool $withoutVat = false,
    ) {}
}
