<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * What one external tool run left behind ({@see ToolRunner::run()}): the exit code, both output
 * streams (each capped), and why it did not finish normally — a start failure, the timeout, or
 * output above the cap. Plain data.
 */
final class ToolRun
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut = false,
        public readonly bool $tooLarge = false,
        public readonly ?string $startError = null,
    ) {}

    public static function notStarted(string $reason): self
    {
        return new self(null, '', '', false, false, $reason);
    }

    /** Ran to its end with exit code 0, within time and size. */
    public function succeeded(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut && !$this->tooLarge && $this->startError === null;
    }
}
