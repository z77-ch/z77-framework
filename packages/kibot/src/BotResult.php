<?php

declare(strict_types=1);

namespace Z77\KiBot;

/** What one question produced. $error is the user-facing text when something failed. */
final class BotResult
{
    /**
     * @param list<string> $topics topic ids whose sources went into the answer
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $topics = [],
        public readonly ?string $error = null,
        public readonly ?string $errorKey = null,
        public readonly ?string $stopReason = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
