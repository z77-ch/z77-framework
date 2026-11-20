<?php

namespace Z77\Module\Backend\Remember;

use Z77\Core\DI;
use Z77\Module\Backend\TwoFactor\BackendTwoFactor;
use Z77\Module\Backend\Ui\Controllers\System\LoginController;
use Z77\Shared\Auth\AuthBridgeInterface;
use Z77\Shared\Auth\DeviceCookie;
use Z77\Shared\Auth\LoginThrottle;
use Z77\Shared\Auth\SessionPolicy;
use Z77\Shared\Entities\BackendUser;

/**
 * «Auf diesem Gerät angemeldet bleiben» of the backend login (login.md LOGIN-REMEMBER-001): a
 * request without a backend identity that carries the device cookie gets the identity back —
 * no password, and by default no code (`rememberSkipsSecondFactor`: the device passed the code
 * when it was remembered).
 *
 * Runs as an auth bridge (`backendConfig` → `authBridges`), which AccessGuard calls on every
 * session-bound request BEFORE it reads the current user — so a remembered device never sees the
 * login page, on whatever URL the browser starts. Without the cookie it costs one array lookup.
 *
 * ⚠️ Everything that can sign a person in without a password is in this one method. It
 * - accepts nothing when the installation switched the feature off (`rememberDevice`),
 * - goes through the login throttle first (a locked-out address restores nothing, and a cookie
 *   that fits no device counts as a failed attempt of that address),
 * - redeems the cookie through {@see RememberedDevices::redeem()} (rotation, reuse detection),
 * - reads the user again and compares the session stamp: a password, role or 2FA change since
 *   the device was remembered ends it,
 * - and signs in only through AuthService::login(), which regenerates the session id.
 *
 * It never throws: it runs in front of every page, the public ones too. A failure is logged
 * (never the cookie value) and the request goes on as a guest.
 */
final class DeviceBridge implements AuthBridgeInterface
{
    /** The throttle's name for a device attempt. No form can submit it: usernames are trimmed text. */
    private const THROTTLE_NAME = "\x01device-token";

    public function sync(): void
    {
        $cookie = self::cookie();
        $value  = $cookie->read();
        if ($value === null) {
            return;
        }

        try {
            $this->restore($cookie, $value);
        } catch (\Throwable $e) {
            error_log('DeviceBridge: remembered device not restored — ' . $e->getMessage());
        }
    }

    /** The backend's device cookie. Path `/`: the alias `/login` and the frontend's admin overlay are outside `/backend`. */
    public static function cookie(): DeviceCookie
    {
        return new DeviceCookie(RememberedDevices::COOKIE);
    }

    /** Is the bridge wired? A project whose own `backendConfig` lacks `authBridges` must not offer the checkbox. */
    public static function isRegistered(): bool
    {
        $bridges = DI::getModuleManager()->getModuleConfig('backend')?->get('authBridges', []) ?? [];

        return in_array(self::class, $bridges, true);
    }

    private function restore(DeviceCookie $cookie, string $value): void
    {
        $policy = SessionPolicy::fromConfig();
        if (!$policy->rememberDevice) {
            $cookie->clear();
            return;
        }

        $auth    = DI::getAuthService();
        $current = $auth->getCurrentUser();
        if ($current->isBackendRealm() && $current->isLoggedIn()) {
            return;
        }

        $session = DI::getSessionManager();
        if (self::codeIsOutstanding($session->get(LoginController::PENDING_KEY))) {
            return; // this device was recognised a moment ago and the code page is waiting
        }

        $ip       = DI::getRequest()->getClientIp();
        $throttle = LoginThrottle::fromConfig();
        if (!$throttle->tryAttempt(self::THROTTLE_NAME, $ip)) {
            return; // over a limit: nothing is compared, the cookie stays for later
        }

        $devices = new RememberedDevices();
        $result  = $devices->redeem($value);

        if ($result->outcome === DeviceRedeem::REUSED) {
            $cookie->clear();
            $this->endEverythingOf($result->userId);
            return; // stays counted as a failure of this address
        }
        if (!$result->accepted()) {
            $cookie->clear();
            return; // stays counted
        }

        // From here on the device proved itself: not a failed attempt, whatever follows.
        $throttle->succeeded(self::THROTTLE_NAME, $ip);

        $user = DI::getUnifiedEntityManager()->getRepository(BackendUser::class)->find($result->userId);
        if (!$user instanceof BackendUser || $user->getAuthEpoch() !== $result->authEpoch) {
            $devices->forget($result->cookieValue ?? $value);
            $cookie->clear();
            return;
        }

        if ($result->cookieValue !== null) {
            $cookie->write($result->cookieValue, max(1, $result->expiresAt - time()));
        }

        $session->remove(BackendTwoFactor::ENROLMENT_KEY);

        if ($user->hasTotp() && !$policy->rememberSkipsSecondFactor) {
            // The device stands for the password only. No identity: the login page sends a
            // marker with `device` on to the code page, which finishes the login as always.
            $session->regenerate();
            $session->set(LoginController::PENDING_KEY, [
                'id'         => $user->getId(),
                'at'         => time(),
                'auth_epoch' => $user->getAuthEpoch(),
                'remember'   => false,
                'device'     => true,
            ]);
            return;
        }

        $auth->restoredFromDevice($user, true)->login();
    }

    private static function codeIsOutstanding(mixed $pending): bool
    {
        return is_array($pending) && time() - (int) ($pending['at'] ?? 0) < LoginController::PENDING_SECONDS;
    }

    /**
     * A copy of a device token was used: either the thief or the owner holds a running session
     * from it, and nothing tells which. The user's stamp is raised — ALL his sessions and ALL his
     * remembered devices end, and he signs in with the password (and the code).
     */
    private function endEverythingOf(int $userId): void
    {
        error_log('DeviceBridge: a replaced device token was presented again — sessions and remembered devices of user id ' . $userId . ' ended');

        $em   = DI::getUnifiedEntityManager();
        $user = $em->getRepository(BackendUser::class)->find($userId);
        if (!$user instanceof BackendUser) {
            return;
        }
        $user->bumpAuthEpoch();
        $em->persist($user);
        $em->flush();
        (new RememberedDevices())->revokeAllFor($userId);
    }
}
