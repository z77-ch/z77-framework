<?php

namespace Z77\Module\Member\Services;

/**
 * The carrier of the «angemeldet bleiben» device key (B8 stage C): one cookie,
 * hardened like the session cookie (HttpOnly, SameSite=Lax, Secure on HTTPS)
 * but long-lived — the key itself is what expires, rolling, at the account.
 *
 * The member door's name on the kernel's carrier ({@see \Z77\Shared\Auth\DeviceCookie}, shared
 * with the backend's «angemeldet bleiben» since 2026-11-20): the cookie mechanics live there,
 * this class only says WHICH cookie.
 */
final class DeviceCookie
{
    public const NAME = 'z77_member_device';

    private \Z77\Shared\Auth\DeviceCookie $cookie;

    public function __construct()
    {
        $this->cookie = new \Z77\Shared\Auth\DeviceCookie(self::NAME);
    }

    public function read(): ?string
    {
        return $this->cookie->read();
    }

    public function write(string $value, int $ttlSeconds, ?int $now = null): void
    {
        $this->cookie->write($value, $ttlSeconds, $now);
    }

    public function clear(): void
    {
        $this->cookie->clear();
    }
}
