<?php

namespace Z77\Module\Api\Ui;

use Z77\Shared\Qr\QrCode;

/**
 * What the pairing window shows the phone: the address of the app shell's pairing page with
 * the code, and that address as a QR code (SVG, server-side — the kernel's bacon-qr-code facade).
 *
 * The URL shape is fixed by the app shell (api.md API-008): `<origin>/app/pair?code=<code>`.
 */
final class ApiDevicePairing
{
    /** Where the phone pairs: `<origin>/app/pair?code=ABCDEFGHJK` (the ten symbols, no dash). */
    public static function appUrl(string $origin, string $plainCode): string
    {
        return rtrim($origin, '/') . '/app/pair?code=' . rawurlencode($plainCode);
    }

    /** «kunde.ch/app» — what the owner types on the phone when the camera is no option. */
    public static function shortAppUrl(string $origin): string
    {
        return (string) preg_replace('#^https?://#', '', rtrim($origin, '/')) . '/app';
    }

    /**
     * The installation's canonical origin (`canonicalBaseUrl`, SEC-005: URLs handed out are
     * never taken from the Host header). Without one — a development box — the address the
     * backend is used on: the person who sees the QR typed it himself.
     *
     * @param object $request the kernel Request (or a double with getBaseUrl / isHttps / getHost)
     */
    public static function origin(object $request): string
    {
        try {
            return rtrim((string) $request->getBaseUrl(), '/');
        } catch (\RuntimeException) {
            $host = (string) $request->getHost();

            return ($request->isHttps() ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
        }
    }

    /**
     * The QR as inline SVG markup, without the XML declaration. Generated from the URL alone
     * (no user text) — safe to print raw.
     */
    public static function qrSvg(string $url, int $sizePx = 184): string
    {
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', QrCode::svg($url, $sizePx));
    }
}
