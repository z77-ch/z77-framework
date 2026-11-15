<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * The text layer of a PDF through `pdftotext` (creditor plan §4 step 1).
 *
 * Input is the PDF's BYTES, never a name or path from outside: they are written to a fresh
 * temp file (`tempnam()`, 0600) that is removed in `finally`; the original file name is never
 * looked at. Each mode runs `pdftotext <mode> -enc UTF-8 <temp> -` through {@see ToolRunner}
 * (argument array, no shell, timeout, output cap):
 *
 * - {@see MODE_LAYOUT} `-layout` — the physical layout: receipt and payment part side by side
 *   in columns, which {@see SwissQrBillText} splits by the headings' positions;
 * - {@see MODE_RAW} `-raw` — content stream order: for most generators the blocks one below
 *   the other, a second view that rescues a layout whose columns ran together.
 *
 * A PDF without a text layer (a scan) comes back as a failure «kein Textlayer» — the AI step
 * (stage 3, opt-in) or the owner takes it from there. Nothing throws.
 */
final class PdfText
{
    public const MODE_LAYOUT = 'layout';
    public const MODE_RAW    = 'raw';

    private const OPTIONS = [self::MODE_LAYOUT => '-layout', self::MODE_RAW => '-raw'];

    public function __construct(
        private readonly RecognitionTools $tools,
        private readonly float $timeoutSeconds = 20.0,
        private readonly int $maxTextBytes = 2_097_152,
        private readonly int $maxPdfBytes = 20_971_520,
    ) {}

    public function read(string $pdfBytes, string $mode = self::MODE_LAYOUT): PdfTextResult
    {
        return $this->readModes($pdfBytes, [$mode])[$mode];
    }

    /**
     * Several modes from ONE temp file.
     *
     * @param list<string> $modes
     * @return array<string, PdfTextResult> keyed by mode, in the order asked
     */
    public function readModes(string $pdfBytes, array $modes = [self::MODE_LAYOUT, self::MODE_RAW]): array
    {
        foreach ($modes as $mode) {
            if (!isset(self::OPTIONS[$mode])) {
                throw new \InvalidArgumentException("Unknown pdftotext mode '{$mode}'");
            }
        }
        $refusal = $this->refusal($pdfBytes);
        if ($refusal !== null) {
            return array_combine($modes, array_map(static fn(string $m) => PdfTextResult::failed($m, $refusal), $modes));
        }

        $file = tempnam(sys_get_temp_dir(), 'z77pdf');
        try {
            if ($file === false || file_put_contents($file, $pdfBytes) !== strlen($pdfBytes)) {
                $reason = 'Temporäre Datei konnte nicht geschrieben werden';

                return array_combine($modes, array_map(static fn(string $m) => PdfTextResult::failed($m, $reason), $modes));
            }
            $results = [];
            foreach ($modes as $mode) {
                $results[$mode] = $this->extract($file, $mode);
            }

            return $results;
        } finally {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
    }

    private function refusal(string $pdfBytes): ?string
    {
        if (!$this->tools->canReadText()) {
            return (string) $this->tools->reason;
        }
        if (strlen($pdfBytes) > $this->maxPdfBytes) {
            return 'PDF ist grösser als ' . intdiv($this->maxPdfBytes, 1_048_576) . ' MB';
        }
        // The header may sit anywhere in the first 1024 bytes (PDF 32000-1, Annex H).
        if (!str_contains(substr($pdfBytes, 0, 1024), '%PDF-')) {
            return 'Datei ist kein PDF';
        }

        return null;
    }

    private function extract(string $file, string $mode): PdfTextResult
    {
        $run = ToolRunner::run(
            [$this->tools->pdftotext, self::OPTIONS[$mode], '-enc', 'UTF-8', $file, '-'],
            $this->timeoutSeconds,
            $this->maxTextBytes,
        );
        if ($run->startError !== null) {
            return PdfTextResult::failed($mode, $run->startError);
        }
        if ($run->timedOut) {
            return PdfTextResult::failed($mode, 'Texterkennung nach ' . (int) $this->timeoutSeconds . ' s abgebrochen');
        }
        if ($run->tooLarge) {
            return PdfTextResult::failed($mode, 'Text des PDF ist grösser als ' . intdiv($this->maxTextBytes, 1024) . ' KB');
        }
        if ($run->exitCode !== 0) {
            return PdfTextResult::failed($mode, match ($run->exitCode) {
                1       => 'PDF nicht lesbar (beschädigt oder mit Passwort geschützt)',
                3       => 'PDF verbietet das Auslesen des Texts',
                default => 'pdftotext fehlgeschlagen (Code ' . $run->exitCode . ')',
            });
        }

        $text = $run->stdout;
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim(str_replace("\f", '', $text)) === '') {
            return PdfTextResult::failed($mode, 'Kein Textlayer im PDF (vermutlich ein Scan)');
        }

        return PdfTextResult::text($mode, $text);
    }
}
