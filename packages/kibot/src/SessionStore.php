<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * Where Guard and the endpoint keep per-visitor state (CSRF token, rate counter,
 * topics of the last answer). A framework adapter implements it over its own session.
 */
interface SessionStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;
}
