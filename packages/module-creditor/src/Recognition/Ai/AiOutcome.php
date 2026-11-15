<?php

namespace Z77\Module\Creditor\Recognition\Ai;

/**
 * The result of the AI step ({@see AiInvoiceReader}): one of three states, the data read (only
 * when recognised), a German reason otherwise. Plain data; never an exception — whatever went
 * wrong, the invoice stays a row with its PDF and is completed by hand (owner answer §11.9).
 *
 *   - RECOGNIZED: the answer was read; check {@see AiInvoiceData::$warnings} for dropped values;
 *   - SKIPPED:    nothing was sent — switched off, no key, too large, not a PDF, daily cap;
 *   - FAILED:     a call was made (or attempted) and gave nothing usable — refusal, garbage,
 *                 HTTP error, timeout. {@see $reason} is always {@see MANUAL}; {@see $detail}
 *                 says why.
 */
final class AiOutcome
{
    public const RECOGNIZED = 'recognized';
    public const SKIPPED    = 'skipped';
    public const FAILED     = 'failed';

    /** The row's note after a failed call. */
    public const MANUAL = 'KI-Erkennung fehlgeschlagen — von Hand erfassen';

    private function __construct(
        public readonly string $state,
        public readonly ?AiInvoiceData $data,
        public readonly ?string $reason,
        public readonly ?string $detail,
    ) {}

    public static function recognized(AiInvoiceData $data): self
    {
        return new self(self::RECOGNIZED, $data, null, null);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, null, $reason, null);
    }

    public static function failed(string $detail): self
    {
        return new self(self::FAILED, null, self::MANUAL, $detail);
    }

    public function isRecognized(): bool
    {
        return $this->state === self::RECOGNIZED;
    }
}
