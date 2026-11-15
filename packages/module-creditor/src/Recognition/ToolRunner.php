<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * Runs an external command line tool for the recognition (creditor plan §4) — the only place
 * in the module that starts a process.
 *
 * - The command is an ARGUMENT ARRAY handed to `proc_open()`: no shell, so no quoting and no
 *   injection whatever the arguments hold (file names are temp names anyway).
 * - stdout and stderr go to TEMP FILES, not pipes: a pipe cannot be polled with a timeout on
 *   Windows (`stream_select()` does not work on process pipes there), a file can — the loop
 *   polls `proc_get_status()`, watches the output size and stops the process at the timeout or
 *   above the cap. Both temp files are removed in `finally`.
 * - Nothing throws: a process that cannot start comes back as {@see ToolRun::notStarted()}.
 */
final class ToolRunner
{
    private const POLL_MICROSECONDS = 10_000;

    /** Process control functions this runner needs — any of them in `disable_functions` = no tools. */
    private const FUNCTIONS = ['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'];

    /** Null when processes can be started here, else the reason (German, shown on the row). */
    public static function unavailableReason(): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (self::FUNCTIONS as $function) {
            if (!function_exists($function) || in_array($function, $disabled, true)) {
                return "PHP-Funktion {$function}() ist auf diesem Server gesperrt";
            }
        }

        return null;
    }

    /**
     * @param list<string> $command the binary first, then its arguments
     */
    public static function run(array $command, float $timeoutSeconds, int $maxOutputBytes): ToolRun
    {
        $reason = self::unavailableReason();
        if ($reason !== null) {
            return ToolRun::notStarted($reason);
        }
        $out = tempnam(sys_get_temp_dir(), 'z77to');
        $err = tempnam(sys_get_temp_dir(), 'z77te');
        try {
            if ($out === false || $err === false) {
                return ToolRun::notStarted('Temporäre Datei konnte nicht angelegt werden');
            }

            return self::runInto($command, $out, $err, $timeoutSeconds, $maxOutputBytes);
        } finally {
            foreach ([$out, $err] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @param list<string> $command */
    private static function runInto(array $command, string $out, string $err, float $timeoutSeconds, int $maxOutputBytes): ToolRun
    {
        $spec    = [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']];
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $process = proc_open($command, $spec, $pipes);
        } catch (\Throwable $e) {
            $process = false;
            $warning = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            return ToolRun::notStarted('Programm konnte nicht gestartet werden' . ($warning !== null ? ' (' . $warning . ')' : ''));
        }
        fclose($pipes[0]);

        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $exitCode = null;
        $timedOut = false;
        $tooLarge = false;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Only the FIRST status call after the end carries the real exit code.
                $exitCode = (int) $status['exitcode'];
                break;
            }
            clearstatcache(true, $out);
            if ((int) filesize($out) > $maxOutputBytes) {
                $tooLarge = true;
                break;
            }
            if (hrtime(true) > $deadline) {
                $timedOut = true;
                break;
            }
            usleep(self::POLL_MICROSECONDS);
        }
        if ($exitCode === null) {
            proc_terminate($process, 9);
        }
        proc_close($process);
        clearstatcache(true, $out);
        // A process that wrote past the cap and ended between two polls is still too large — never a cut text.
        $tooLarge = $tooLarge || (int) filesize($out) > $maxOutputBytes;

        return new ToolRun(
            $exitCode,
            (string) file_get_contents($out, false, null, 0, $maxOutputBytes),
            (string) file_get_contents($err, false, null, 0, 4096),
            $timedOut,
            $tooLarge,
        );
    }
}
