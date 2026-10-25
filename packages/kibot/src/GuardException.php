<?php

declare(strict_types=1);

namespace Z77\KiBot;

/** A request Guard rejects. $key names the user-facing text in Config 'messages'. */
final class GuardException extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct('KiBot guard: ' . $key);
    }
}
