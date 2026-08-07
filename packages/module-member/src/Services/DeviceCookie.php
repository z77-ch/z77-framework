<?php

namespace Z77\Module\Member\Services;

/**
 * The carrier of the «angemeldet bleiben» device key (B8 stage C): one cookie,
 * hardened like the session cookie (HttpOnly, SameSite=Lax, Secure on HTTPS)
 * but long-lived — the key itself is what expires, rolling, at the account.
 *
 * The kernel has no cookie abstraction, so this is the one place that touches
 * setcookie()/$_COOKIE. Writes update $_COOKIE too: the same request must see
 * what it just handed out (and the harness runs without headers at all).
 */
final class DeviceCookie
{
    public const NAME = 'z77_member_device';

    public function read(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function write(string $value, int $ttlSeconds, ?int $now = null): void
    {
        $_COOKIE[self::NAME] = $value;
        $this->send($value, ($now ?? time()) + $ttlSeconds);
    }

    public function clear(): void
    {
        unset($_COOKIE[self::NAME]);
        $this->send('', 1);
    }

    private function send(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        setcookie(self::NAME, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
