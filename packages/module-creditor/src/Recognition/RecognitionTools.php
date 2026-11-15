<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * Which recognition tools this host offers (creditor plan §4): can PHP start a process at all
 * (`proc_open` and friends not in `disable_functions`), and does `pdftotext` answer — its
 * version and flavour (poppler on cyon; xpdf answers too, with the same options).
 *
 * «Degrade, never fail» (owner answer §11): a missing tool is not an exception but a
 * {@see $reason} in German; the intake row keeps its PDF and is completed by hand.
 *
 * The binary is found by RUNNING it (`pdftotext -v`), not by looking at the file system: a bare
 * name is searched in PATH by the operating system, and an `open_basedir` that hides
 * `/usr/bin` cannot make a present tool look absent. The configured value is a binary path or
 * name (`creditorConfig['tools']['pdftotext']`, default {@see DEFAULT_PDFTOTEXT}) — never a
 * command line: it is the first element of an argument array, no shell sees it.
 *
 * {@see probe()} answers once per process per binary (the plan's «probes once per run»);
 * {@see detect()} probes afresh.
 */
final class RecognitionTools
{
    public const DEFAULT_PDFTOTEXT = 'pdftotext';

    private const PROBE_TIMEOUT_SECONDS = 5.0;

    /** @var array<string, self> */
    private static array $probed = [];

    private function __construct(
        public readonly string $pdftotext,
        public readonly ?string $version,
        public readonly ?string $flavour,     // 'poppler' | 'xpdf' | null (unknown build)
        public readonly ?string $reason,      // null = text layer readable
    ) {}

    /** The probe of $pdftotext, cached for this process. */
    public static function probe(?string $pdftotext = null): self
    {
        $binary = self::binaryOf($pdftotext);

        return self::$probed[$binary] ??= self::detect($binary);
    }

    /** A fresh probe, nothing cached. */
    public static function detect(?string $pdftotext = null): self
    {
        $binary = self::binaryOf($pdftotext);
        if ($binary === '' || str_contains($binary, "\0")) {
            return new self($binary, null, null, 'Texterkennung auf diesem Server nicht verfügbar: kein gültiger Pfad zu pdftotext eingestellt');
        }
        $blocked = ToolRunner::unavailableReason();
        if ($blocked !== null) {
            return new self($binary, null, null, 'Texterkennung auf diesem Server nicht verfügbar: ' . $blocked);
        }

        $run    = ToolRunner::run([$binary, '-v'], self::PROBE_TIMEOUT_SECONDS, 4096);
        $answer = $run->stdout . "\n" . $run->stderr;
        if ($run->startError !== null || !preg_match('/pdftotext\s+version\s+([0-9][0-9A-Za-z.\-]*)/i', $answer, $m)) {
            return new self($binary, null, null, 'Texterkennung auf diesem Server nicht verfügbar: pdftotext nicht gefunden');
        }
        $flavour = match (true) {
            stripos($answer, 'poppler') !== false                                             => 'poppler',
            stripos($answer, 'Glyph & Cog') !== false || stripos($answer, 'xpdf') !== false => 'xpdf',
            default                                                                           => null,
        };

        return new self($binary, $m[1], $flavour, null);
    }

    public function canReadText(): bool
    {
        return $this->reason === null;
    }

    private static function binaryOf(?string $pdftotext): string
    {
        return trim($pdftotext ?? self::DEFAULT_PDFTOTEXT);
    }
}
