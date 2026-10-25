<?php

declare(strict_types=1);

namespace Z77\KiBot;

/** SessionStore over an array held by reference — $_SESSION in the standalone endpoint, a plain array in tests. */
final class ArraySession implements SessionStore
{
    private array $data;

    public function __construct(array &$data)
    {
        $this->data = &$data;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
