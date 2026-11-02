<?php

namespace Z77\Module\Backend\TwoFactor;

use Z77\Core\DI;
use Z77\Core\Session\SessionManager;
use Z77\Module\Backend\PasswordReset\PasswordResetTokens;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Alert\AlertService;
use Z77\Shared\Alert\EmailAlertChannel;
use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Mail\EmailMessage;

/**
 * The second factor of the backend login (login.md BACKEND-2FA-001): enrolment, the code check,
 * switching off, the administrator's reset. The door's own key file and guard directory: nothing
 * here touches a member's 2FA.
 *
 * ⚠️ Every change runs inside one lock per user ({@see locked()}), on the user read fresh under it:
 * without it, parallel guesses would all pass the guard before the first failure is counted, and
 * one code could pass twice. Other writers of the user's row cannot undo what happens here: the
 * file store writes only the columns a save changed (persistence-file.md DATA-MERGE-001).
 *
 * A pending enrolment waits in the session, as ciphertext, not in the store, and every open of the
 * dialog makes a new secret: what a thief read with a stolen cookie is gone at the owner's next
 * open, at the latest at his login or logout, and after {@see ENROLMENT_SECONDS}. A thief opening
 * the dialog AFTER the owner's last open replaces the secret instead of learning it: the owner's
 * code is then refused and he opens it again (login.md «second factor»).
 */
final class BackendTwoFactor
{
    public const ENROLMENT_KEY         = 'auth.totp_enrolment';
    public const ENROLMENT_SECONDS     = 600;
    private const TEMPLATE_NAMESPACE   = 'Z77\\Module\\Backend';
    private const ALERT_SOURCE         = 'backend-2fa:code-lock';

    /** @param \Closure(EmailMessage): bool $send the mail stack; false = not sent */
    public function __construct(
        private UnifiedEntityManager $em,
        private TotpVault $vault,
        private TotpGuard $guard,
        private string $guardDir,
        private SessionManager $session,
        private \Closure $send,
        private ?AlertService $alerts = null,
    ) {
    }

    public static function create(): self
    {
        $base     = rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/');
        $guardDir = $base . '/var/lib/throttle/backend-totp-guard';
        $alertTo  = trim((string) DI::getConfigManager()->getBaseConfig('config/auth', throwError: false)->get('codeLockAlertEmail', ''));

        return new self(
            DI::getUnifiedEntityManager(),
            new TotpVault($base . '/data/framework/auth/totp.key'),
            new TotpGuard($guardDir),
            $guardDir,
            DI::getSessionManager(),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            // Its state beside the key file, not under var/lib/alert: an alert about a dead var/lib
            // cannot keep its memory there (alert.md).
            $alertTo !== '' ? new AlertService($base . '/data/framework/auth/alert', [new EmailAlertChannel($alertTo)]) : null,
        );
    }

    /**
     * A new secret for this open of the dialog, replacing any earlier one of this session. Not
     * stored yet.
     *
     * @throws \RuntimeException when the key file is damaged
     */
    public function enrolmentSecret(BackendUser $user, ?int $now = null): string
    {
        $secret = Totp::generateSecret();
        $this->session->set(self::ENROLMENT_KEY, [
            'user_id' => $user->getId(),
            'at'      => $now ?? time(),
            'secret'  => $this->vault->encrypt($secret),
        ]);

        return $secret;
    }

    /**
     * The confirming code. Only now does anything reach the store, and the code is spent with it.
     * False = wrong code, or nothing pending in this session, or the dialog is too old; 2FA stays
     * as it was. Switching on ends the user's other sessions: the caller keeps its own with
     * AuthService::renewSessionStamp().
     */
    public function confirmEnrolment(BackendUser $user, string $code, ?int $now = null): bool
    {
        $now ??= time();
        $pending = $this->session->get(self::ENROLMENT_KEY);
        if (!is_array($pending) || ($pending['user_id'] ?? null) !== $user->getId()) {
            return false;
        }
        if ($now - (int) ($pending['at'] ?? 0) >= self::ENROLMENT_SECONDS) {
            $this->session->remove(self::ENROLMENT_KEY);
            return false;
        }
        $ciphertext = (string) ($pending['secret'] ?? '');
        $secret     = $this->vault->decrypt($ciphertext);
        $step       = $secret !== null ? Totp::acceptedStep($secret, $code, $now) : null;
        if ($step === null) {
            return false;
        }

        $done = $this->locked($user, function (BackendUser $fresh) use ($ciphertext, $now, $step): bool {
            if ($fresh->hasTotp()) {
                return false; // another session of this user got there first
            }
            $fresh->enableTotp($ciphertext, date(DATE_ATOM, $now), $step);
            $fresh->bumpAuthEpoch();
            $this->save($fresh);
            return true;
        });
        if ($done === true) {
            $this->session->remove(self::ENROLMENT_KEY);
            $this->guard->reset((string) $user->getId());
            error_log('BackendTwoFactor: 2FA switched on for user id ' . $user->getId());
            $this->notify($user, '2FA wurde eingerichtet', 'emails/twoFactorEnabled');
        }

        return $done === true;
    }

    /**
     * Checks a code and, when right, spends it. A wrong code, a spent one and an unreadable secret
     * all count towards the lock; while locked even the right code is refused. An account without
     * 2FA has no code to pass: the caller asks {@see BackendUser::hasTotp()} first.
     */
    public function check(BackendUser $user, string $code, ?int $now = null): TotpCheck
    {
        return $this->spend($user, $code, $now ?? time(), static function (BackendUser $fresh, int $step): void {
            $fresh->recordTotpStep($step);
        });
    }

    /** Switching off: a valid code, nothing less. A stolen session must not drop the factor. */
    public function disable(BackendUser $user, string $code, ?int $now = null): TotpCheck
    {
        $result = $this->spend($user, $code, $now ?? time(), static function (BackendUser $fresh): void {
            $fresh->clearTotp();
        });
        if ($result === TotpCheck::Passed) {
            error_log('BackendTwoFactor: 2FA switched off for user id ' . $user->getId());
            $this->notify($user, '2FA wurde entfernt', 'emails/twoFactorDisabled');
        }

        return $result;
    }

    /**
     * The lost device (login.md «lost device»): the actor's rights are the controller's. Ends an
     * open reset link first (it was issued while the code protected it, and must not outlive the
     * code): if that fails nothing has changed and the answer is false. Then clears the factor and
     * the guard, ends all the target's sessions, tells the target, writes the log line. False also
     * means the user is gone.
     */
    public function resetByAdmin(BackendUser $target, int $actorId): bool
    {
        $id = (int) $target->getId();
        try {
            (new PasswordResetTokens())->revokeFor($id);
        } catch (\RuntimeException $e) {
            error_log('BackendTwoFactor: 2FA of user id ' . $id . ' not reset, open link not revoked — ' . $e->getMessage());
            return false;
        }

        $done = $this->locked($target, function (BackendUser $fresh): BackendUser {
            $fresh->clearTotp();
            $fresh->bumpAuthEpoch();
            $this->save($fresh);
            return $fresh;
        });
        if (!$done instanceof BackendUser) {
            return false;
        }

        $this->guard->reset((string) $id);
        error_log('BackendTwoFactor: 2FA of user id ' . $id . ' reset by user id ' . $actorId);
        $this->notify($done, '2FA wurde zurückgesetzt', 'emails/twoFactorReset');

        return true;
    }

    /** A deleted user's counter must not fall to the next user with his id (ids are max+1). */
    public function forgetAccount(int $userId): void
    {
        $this->guard->reset((string) $userId);
    }

    /** A notice to the recovery address: deliberately without a link. Never throws: the change is done. */
    private function notify(BackendUser $user, string $subject, string $template): void
    {
        if ($user->getEmail() === '') {
            return;
        }

        try {
            $sent = ($this->send)((new EmailMessage())
                ->to($user->getEmail())
                ->subject($subject)
                ->template($template, self::TEMPLATE_NAMESPACE, [
                    'greetingName' => $user->getName() !== '' ? $user->getName() : $user->getUsername(),
                ]));

            if (!$sent) {
                error_log('BackendTwoFactor: notice «' . $subject . '» not sent for user id ' . $user->getId());
            }
        } catch (\Throwable $e) {
            error_log('BackendTwoFactor: notice «' . $subject . '» failed — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Can a code be judged right now? For the enrolment, where a right code needs no counter yet:
     * switching on with a dead guard would lock the user out at his next login.
     */
    public function canCheckCodes(BackendUser $user): bool
    {
        if ($this->guard->canCount((string) $user->getId())) {
            return true;
        }
        $this->unavailable((int) $user->getId(), 'write-counter');

        return false;
    }

    /**
     * The one way a code is judged: under the lock, against the guard, then $onPass and a save.
     * ⚠️ Fails closed: without a counter there is no limit on guesses, so every code is refused, the
     * right one too. An account without 2FA never comes here. The disk is asked FIRST: a right code
     * clears the counter and would never find out that a wrong one could not be counted.
     */
    private function spend(BackendUser $user, string $code, int $now, callable $onPass): TotpCheck
    {
        $id = (int) $user->getId();
        if (!$this->guard->canCount((string) $id)) {
            return $this->unavailable($id, 'write-counter');
        }

        $result = $this->locked($user, function (BackendUser $fresh) use ($code, $now, $onPass, $id): TotpCheck {
            $account = (string) $id;
            if ($this->guard->isLocked($account, $now)) {
                return TotpCheck::Locked;
            }

            $step = $this->acceptedStep($fresh, $code, $now);
            if ($step === null) {
                if (!$this->guard->recordFailure($account, $now)) {
                    return $this->unavailable($id, 'record-failure');
                }
                return $this->guard->isLocked($account, $now) ? TotpCheck::Locked : TotpCheck::Wrong;
            }

            if (!$this->guard->reset($account)) {
                return $this->unavailable($id, 'clear-counter');
            }
            $onPass($fresh, $step);
            $this->save($fresh);

            return TotpCheck::Passed;
        }, fn(): TotpCheck => $this->unavailable($id, 'lock')) ?? TotpCheck::Wrong;

        if ($result !== TotpCheck::Unavailable) {
            $this->alert(static fn(AlertService $alert) => $alert->success(self::ALERT_SOURCE));
        }

        return $result;
    }

    /**
     * Every refusal for a dead guard: one log line with the step and the directory (login.md «code
     * refused»), never a code or a secret; and the alert.
     */
    private function unavailable(int $userId, string $step): TotpCheck
    {
        error_log('BackendTwoFactor: code refused, counters unavailable — step=' . $step . ' dir=' . $this->guardDir . ' user id ' . $userId);
        $this->alert(fn(AlertService $alert) => $alert->failure(self::ALERT_SOURCE, $step, ['dir' => $this->guardDir]));

        return TotpCheck::Unavailable;
    }

    /** Whatever the alert does, the login's answer stays. No channel declared: nothing happens. */
    private function alert(callable $report): void
    {
        if ($this->alerts === null) {
            return;
        }
        try {
            @$report($this->alerts);
        } catch (\Throwable $e) {
            error_log('BackendTwoFactor: alert not delivered — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** The step of a code that is right and not spent; a secret the vault cannot read fails closed. */
    private function acceptedStep(BackendUser $user, string $code, int $now): ?int
    {
        if (!$user->hasTotp()) {
            return null;
        }
        $secret = $this->vault->decrypt($user->getTotpSecret());
        if ($secret === null) {
            error_log('BackendTwoFactor: stored secret unreadable for user id ' . $user->getId());
            return null;
        }

        return Totp::acceptedStep($secret, $code, $now, $user->getTotpLastStep());
    }

    /**
     * Runs $work with the user read fresh from the store, under this user's lock. Null = the user
     * is gone. No lock: $whenNoLock answers, the code check refuses that way. Without it the work
     * runs unserialized with a log line: the enrolment and the administrator's reset judge no
     * guess, and the reset is the way out of a dead guard.
     */
    private function locked(BackendUser $user, callable $work, ?callable $whenNoLock = null): mixed
    {
        $handle = $this->acquire((int) $user->getId());
        if ($handle === null) {
            if ($whenNoLock !== null) {
                return $whenNoLock();
            }
            error_log('BackendTwoFactor: lock unavailable, change not serialized for user id ' . $user->getId() . ' dir=' . $this->guardDir);
        }

        try {
            $fresh = $this->em->getRepository(BackendUser::class)->find((int) $user->getId());

            return $fresh instanceof BackendUser ? $work($fresh) : null;
        } finally {
            if ($handle !== null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** @return resource|null */
    private function acquire(int $userId)
    {
        if (!is_dir($this->guardDir)) {
            @mkdir($this->guardDir, 0755, true);
        }
        $handle = @fopen($this->guardDir . '/' . hash('sha256', (string) $userId) . '.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function save(BackendUser $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }
}
