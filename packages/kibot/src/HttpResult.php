<?php

declare(strict_types=1);

namespace Z77\KiBot;

/** Outcome of one transport call. status 0 = no HTTP response (connection error). */
final class HttpResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $error = '',
    ) {
    }
}
