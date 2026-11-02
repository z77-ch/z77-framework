<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;
use Z77\Shared\Site\InstallationLabel;

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

    /**
     * The name an authenticator app shows for this account's code: memberConfig `totpIssuer`, else
     * the installation's name or host. Only a label: a secret that exists keeps working whatever it
     * says, and the app shows the name it was given at setup.
     */
    public static function issuer(): string
    {
        $configured = trim((string) DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
            ->get('totpIssuer', ''));
        if ($configured !== '') {
            return $configured;
        }
        $label = InstallationLabel::current();

        return $label !== '' ? $label : 'z77';
    }

    public static function guard(): TotpGuard
    {
        return new TotpGuard(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/totp-guard');
    }
}
