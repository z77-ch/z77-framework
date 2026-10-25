<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * Reads the Anthropic API key from a file outside public/ and outside the repo.
 *
 * The path comes from the project's configuration; there is deliberately no default
 * in code. Error messages name the path, never the key, and the key never shows up
 * in var_dump()/print_r() output.
 */
final class KeyStore
{
    private ?string $key = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @throws \RuntimeException when no path is configured or the file is missing, unreadable or empty */
    public function get(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if ($this->path === '') {
            throw new \RuntimeException('KiBot: no API key file configured (paths.keyFile).');
        }
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException('KiBot: API key file not found or not readable: ' . $this->path);
        }
        $key = trim((string) file_get_contents($this->path));
        if ($key === '') {
            throw new \RuntimeException('KiBot: API key file is empty: ' . $this->path);
        }
        return $this->key = $key;
    }

    public function isAvailable(): bool
    {
        try {
            $this->get();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'key' => $this->key === null ? null : '***'];
    }
}
