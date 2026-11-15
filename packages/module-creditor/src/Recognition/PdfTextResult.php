<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * The text layer of a PDF in one extraction mode ({@see PdfText}): the text (UTF-8, LF line
 * ends, pages separated by form feed) or null with the reason in German. Plain data.
 */
final class PdfTextResult
{
    private function __construct(
        public readonly string $mode,
        public readonly ?string $text,
        public readonly ?string $reason,
    ) {}

    public static function text(string $mode, string $text): self
    {
        return new self($mode, $text, null);
    }

    public static function failed(string $mode, string $reason): self
    {
        return new self($mode, null, $reason);
    }

    public function hasText(): bool
    {
        return $this->text !== null;
    }
}
