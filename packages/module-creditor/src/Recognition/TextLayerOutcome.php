<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * The result of the text-layer step ({@see TextLayerRecognizer}): one of four states, the
 * payment read (only when recognised), the reason in German otherwise, and the layout text
 * (kept for later steps — e.g. the UID of the letterhead, the AI). Plain data.
 */
final class TextLayerOutcome
{
    /** The payment part was found and read (check the payment's warnings and confidence). */
    public const RECOGNIZED  = 'recognized';
    /** Text read, but no QR payment part in it — a plain invoice, a foreign one. */
    public const NOT_FOUND   = 'not_found';
    /** The PDF has no usable text (a scan, protected, broken, too large). */
    public const NO_TEXT     = 'no_text';
    /** The host cannot read text layers (no pdftotext, processes disabled). */
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $state,
        public readonly ?RecognizedPayment $payment,
        public readonly ?string $reason,
        public readonly ?string $text,
    ) {}

    public static function recognized(RecognizedPayment $payment, string $text): self
    {
        return new self(self::RECOGNIZED, $payment, null, $text);
    }

    public static function notFound(string $text): self
    {
        return new self(self::NOT_FOUND, null, 'Kein QR-Zahlteil im Text des PDF gefunden', $text);
    }

    public static function noText(string $reason): self
    {
        return new self(self::NO_TEXT, null, $reason, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, null, $reason, null);
    }

    public function isRecognized(): bool
    {
        return $this->state === self::RECOGNIZED;
    }
}
