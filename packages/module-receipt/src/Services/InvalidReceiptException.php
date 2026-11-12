<?php

namespace Z77\Module\Receipt\Services;

/**
 * A capture or a correction refused per FIELD — the German message per field the screen shows
 * under it and the API target returns as its field errors (Envelope v1). Keys: `date`, `amount`,
 * `text`, `template`, `payment_method`, `debit`, `credit`, `tax_code`, `photo`, `captured_by`, `device`,
 * `capture_key`. Nothing was written.
 */
final class InvalidReceiptException extends ReceiptException
{
    /** @param array<string, string> $errors field → German message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Receipt refused: ' . implode('; ', array_map(static fn(string $f, string $m) => "{$f}: {$m}", array_keys($errors), $errors)));
    }
}
