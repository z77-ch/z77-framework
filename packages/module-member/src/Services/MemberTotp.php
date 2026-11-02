<?php

namespace Z77\Module\Member\Services;

use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;

/**
 * Where the member door keeps its TOTP state: the kernel classes take their paths from their
 * owner. ⚠️ Neither path may change: the key file decrypts every member's stored secret, and the
 * guard directory holds the running locks.
 */
final class MemberTotp
{
    public static function vault(): TotpVault
    {
        return new TotpVault(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/data/framework/member/totp.key');
    }

    public static function guard(): TotpGuard
    {
        return new TotpGuard(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/totp-guard');
    }
}
