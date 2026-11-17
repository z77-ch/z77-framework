<?php

namespace Z77\Tests\Runner;

/**
 * One harness run by `tests/run.php`: its own process, stdout + stderr into ONE log file (the same
 * handle for both, so the order is kept), read back while it runs.
 *
 * Why a file and not a pipe: on Windows `stream_select()` does not work on process pipes and a
 * full pipe blocks the child; a file never blocks and is the log at the same time.
 *
 * Timing without touching the harness: every complete line gets the time it was READ (the runner
 * polls a few times a second). A line in the harnesses' section-header shape — column 0, one to
 * three capitals, optional digits, a dot, a space (`M. Database …`, `SM. Backend …`) — starts a
 * section; `D. … a sub-case` continues the current one. Lines before the first header are the
 * section `(start)` (autoload, set-up).
 *
 * Watchdog: no new output for `idle` seconds, or more than `limit` seconds in all, and the process
 * TREE is killed (`taskkill /F /T` on Windows — a harness may run a PHP server or a browser) and
 * the run is reported `hung` with its last lines.
 */
final class HarnessProcess
{
    public const SECTION_HEADER = '/^([A-Z]{1,3}[0-9]{0,2}[a-z]?)\. (?!…)\S/u';

    /** @var resource|null */
    private $proc = null;

    /** @var resource|null */
    private $writer = null;

    /** @var resource|null */
    private $reader = null;

    private int $offset = 0;
    private string $partial = '';
    private int $pid = 0;
    private float $lastOutput = 0.0;
    private ?int $exitCode = null;
    private ?string $hungReason = null;
    private ?string $skipReason = null;
    public float $started = 0.0;
    public ?float $ended = null;

    /** @var list<array{0: float, 1: string}> time read, text */
    private array $lines = [];

    /** @var list<array{label: string, start: float, end: ?float}> */
    private array $sections = [];

    /** @param list<string>|null $command null = cannot run here (see skip()) */
    public function __construct(
        public readonly string $name,
        public readonly string $file,
        public readonly string $logFile,
        private ?array $command,
        private string $cwd,
    ) {
    }

    public function start(float $now): void
    {
        $this->started = $this->lastOutput = $now;
        $this->writer  = fopen($this->logFile, 'wb');
        $this->proc    = proc_open($this->command, [0 => ['pipe', 'r'], 1 => $this->writer, 2 => $this->writer], $pipes, $this->cwd, null, ['bypass_shell' => true]);
        if (!is_resource($this->proc)) {
            $this->skip('could not start: ' . implode(' ', $this->command));
            return;
        }
        fclose($pipes[0]);
        $this->pid      = (int) proc_get_status($this->proc)['pid'];
        $this->reader   = fopen($this->logFile, 'rb');
        $this->sections = [['label' => '(start)', 'start' => $now, 'end' => null]];
    }

    /** Not run at all — a tool it needs is missing (node, a browser); said in the table. */
    public function skip(string $reason): void
    {
        $this->skipReason = $reason;
        $this->ended      = $this->ended ?? $this->started;
        if (!is_file($this->logFile)) {
            file_put_contents($this->logFile, "SKIPPED by the runner: {$reason}\n");
        }
    }

    public function isRunning(): bool
    {
        return $this->proc !== null && $this->ended === null;
    }

    /** Reads new output, ends or kills the process; true while it still runs. */
    public function poll(float $now, int $idleSeconds, int $limitSeconds): bool
    {
        if (!$this->isRunning()) {
            return false;
        }
        $this->read($now);
        $status = proc_get_status($this->proc);
        if (!$status['running']) {
            // proc_get_status() reports the exit code once, at the first call that sees the end.
            $this->exitCode = (int) $status['exitcode'];
            $this->finish($now);

            return false;
        }
        if ($now - $this->lastOutput > $idleSeconds) {
            $this->kill($now, sprintf('no output for %d s (watchdog)', $idleSeconds));

            return false;
        }
        if ($now - $this->started > $limitSeconds) {
            $this->kill($now, sprintf('over the total limit of %d s (watchdog)', $limitSeconds));

            return false;
        }

        return true;
    }

    /** Kills the process tree (also used when the runner itself is interrupted). */
    public function kill(float $now, string $reason): void
    {
        if (!$this->isRunning()) {
            return;
        }
        $this->hungReason = $reason;
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . $this->pid . ' >NUL 2>NUL');
        } else {
            proc_terminate($this->proc, 9);
        }
        $deadline = microtime(true) + 10;
        while (proc_get_status($this->proc)['running'] && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->exitCode = null;
        $this->finish($now);
    }

    private function finish(float $now): void
    {
        $this->read($now);
        if ($this->partial !== '') {
            $this->addLine($now, rtrim($this->partial, "\r"));
            $this->partial = '';
        }
        $this->ended = $now;
        $last        = count($this->sections) - 1;
        if ($last >= 0) {
            $this->sections[$last]['end'] = $now;
        }
        proc_close($this->proc);
        foreach ([$this->reader, $this->writer] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function read(float $now): void
    {
        if (!is_resource($this->reader)) {
            return;
        }
        fseek($this->reader, $this->offset);
        $chunk = (string) stream_get_contents($this->reader);
        if ($chunk === '') {
            return;
        }
        $this->offset    += strlen($chunk);
        $this->lastOutput = $now;
        $parts            = explode("\n", $this->partial . $chunk);
        $this->partial    = (string) array_pop($parts);
        foreach ($parts as $text) {
            $this->addLine($now, rtrim($text, "\r"));
        }
    }

    private function addLine(float $now, string $text): void
    {
        $this->lines[] = [$now, $text];
        if (preg_match(self::SECTION_HEADER, $text)) {
            $last = count($this->sections) - 1;
            if ($last >= 0) {
                $this->sections[$last]['end'] = $now;
            }
            $this->sections[] = ['label' => $text, 'start' => $now, 'end' => null];
        }
    }

    /** The section running now (for the progress line). */
    public function currentSection(): string
    {
        return $this->sections === [] ? '' : $this->sections[count($this->sections) - 1]['label'];
    }

    public function lastOutputAt(): float
    {
        return $this->lastOutput;
    }

    /**
     * The harness's verdict, read from its output.
     *
     * Counts come from its summary line (`N passed, M failed`, `N ok, M failed`, `PASS — N checks`,
     * `FAIL — M of T checks`); a harness without one is counted by its check lines (`ok` / `OK` /
     * `FAIL` at the start, indented or not).
     *
     * State: `skipped` (not run, or exit 2 with no check — the harnesses' «a tool is missing»),
     * `hung` (killed by the watchdog), `red` (a failed check, a fatal error, an exit code ≠ 0, or no
     * check at all), `no-db` (green, but its database sections said SKIPPED), `ok`.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $texts    = array_map(fn(array $l) => $l[1], $this->lines);
        $passed   = null;
        $failed   = null;
        $counted  = [0, 0];
        $failures = [];
        $noDb     = false;
        foreach ($texts as $text) {
            if (preg_match('/^(\d+) (?:passed|ok), (\d+) failed/', $text, $m)) {
                [$passed, $failed] = [(int) $m[1], (int) $m[2]];
            } elseif (preg_match('/^PASS — (\d+) checks/u', $text, $m)) {
                [$passed, $failed] = [(int) $m[1], 0];
            } elseif (preg_match('/^FAIL — (\d+) of (\d+) checks/u', $text, $m)) {
                [$passed, $failed] = [(int) $m[2] - (int) $m[1], (int) $m[1]];
            } elseif (preg_match('/^\s*(ok|OK)\s/', $text)) {
                $counted[0]++;
            } elseif (preg_match('/^\s*FAIL\b/', $text)) {
                $counted[1]++;
                $failures[] = trim($text);
            } elseif (preg_match('/(Fatal error|Parse error|Uncaught |Stack trace:)/', $text)) {
                $failures[] = trim($text);
            }
            if (preg_match('/SKIPPED \(no z77test/', $text)) {
                $noDb = true;
            }
        }
        $passed ??= $counted[0];
        $failed ??= $counted[1];

        $reason = $this->skipReason ?? $this->hungReason;
        if ($this->skipReason !== null) {
            $state = 'skipped';
        } elseif ($this->hungReason !== null) {
            $state = 'hung';
        } elseif ($this->exitCode === 2 && $passed + $failed === 0) {
            $state  = 'skipped';
            $reason = $this->lastLines(1)[0] ?? 'exit 2';
        } elseif ($failed > 0 || $this->exitCode !== 0 || $failures !== [] || $passed === 0) {
            $state  = 'red';
            $reason = match (true) {
                $failed > 0          => null,
                $this->exitCode !== 0 => 'exit code ' . $this->exitCode,
                $failures !== []     => 'error output',
                default              => 'no check ran',
            };
        } else {
            $state = $noDb ? 'no-db' : 'ok';
        }

        $sections = [];
        foreach ($this->sections as $section) {
            $seconds = ($section['end'] ?? $this->ended ?? $section['start']) - $section['start'];
            $sections[] = ['label' => $section['label'], 'seconds' => round($seconds, 1)];
        }
        // A harness without headers has only «(start)»: no slowest section worth naming.
        $slowest = count($sections) > 1 ? array_reduce($sections, fn(?array $c, array $s) => $c === null || $s['seconds'] > $c['seconds'] ? $s : $c) : null;

        return [
            'name'     => $this->name,
            'file'     => $this->file,
            'state'    => $state,
            'reason'   => $reason,
            'exit'     => $this->exitCode,
            'passed'   => $passed,
            'failed'   => $failed,
            'seconds'  => round(($this->ended ?? $this->started) - $this->started, 1),
            'slowest'  => $slowest,
            'sections' => $sections,
            'failures' => $failures,
            'last'     => in_array($state, ['hung', 'red', 'skipped'], true) ? $this->lastLines(8) : [],
            'log'      => $this->logFile,
        ];
    }

    /** @return list<string> */
    private function lastLines(int $n): array
    {
        $texts = array_values(array_filter(array_map(fn(array $l) => $l[1], $this->lines), fn(string $t) => trim($t) !== ''));
        if ($texts === [] && is_file($this->logFile)) {
            $texts = array_values(array_filter(explode("\n", str_replace("\r", '', (string) file_get_contents($this->logFile))), fn(string $t) => trim($t) !== ''));
        }

        return array_slice($texts, -$n);
    }
}
