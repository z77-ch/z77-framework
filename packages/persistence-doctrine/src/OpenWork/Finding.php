<?php

namespace Z77\Persistence\Doctrine\OpenWork;

/**
 * One thing a check found open: how it weighs, a sentence for the person
 * who asked, and an opaque reference the reporting module understands
 * (`invoice:2026-0012`, `order:4711`) — the registry never interprets it.
 */
final class Finding
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
        public readonly string $reference = ''
    ) {
        if (trim($message) === '') {
            throw new \InvalidArgumentException('An open-work finding needs a message.');
        }
    }

    public static function blocking(string $message, string $reference = ''): self
    {
        return new self(Severity::Blocking, $message, $reference);
    }

    public static function warning(string $message, string $reference = ''): self
    {
        return new self(Severity::Warning, $message, $reference);
    }
}
