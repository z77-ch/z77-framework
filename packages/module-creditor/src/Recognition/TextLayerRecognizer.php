<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * Step 1 of the recognition (creditor plan §4): the PDF's bytes → its text layer
 * (`-layout` and `-raw`, {@see PdfText}) → the QR payment part ({@see SwissQrBillText}).
 * Pure of entities: the recognition service of the invoice decides what it fills in.
 *
 * Never throws for a host or a file it cannot handle — the outcome says why
 * ({@see TextLayerOutcome}): «unavailable» without pdftotext, «no_text» for a scan.
 */
final class TextLayerRecognizer
{
    private readonly PdfText $pdfText;

    public function __construct(
        private readonly RecognitionTools $tools,
        ?PdfText $pdfText = null,
    ) {
        $this->pdfText = $pdfText ?? new PdfText($tools);
    }

    public function recognize(string $pdfBytes): TextLayerOutcome
    {
        if (!$this->tools->canReadText()) {
            return TextLayerOutcome::unavailable((string) $this->tools->reason);
        }
        $texts  = [];
        $reason = null;
        foreach ($this->pdfText->readModes($pdfBytes, [PdfText::MODE_LAYOUT, PdfText::MODE_RAW]) as $result) {
            if ($result->hasText()) {
                $texts[] = (string) $result->text;
            } else {
                $reason ??= $result->reason;
            }
        }
        if ($texts === []) {
            return TextLayerOutcome::noText((string) $reason);
        }
        $payment = SwissQrBillText::parseBest(...$texts);

        return $payment === null ? TextLayerOutcome::notFound($texts[0]) : TextLayerOutcome::recognized($payment, $texts[0]);
    }
}
