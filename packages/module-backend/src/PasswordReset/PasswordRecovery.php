<?php

namespace Z77\Module\Backend\PasswordReset;

use Z77\Core\DI;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Mail\EmailMessage;
use Z77\Shared\Repositories\BackendUserRepository;
use Z77\Shared\Throttle\FileThrottle;

/**
 * The two mails of «Passwort vergessen» and everything that decides whether the first one goes
 * out (login.md LOGIN-RESET-001). {@see request()} returns nothing on purpose: the controller has
 * no outcome it could show, so the answer cannot tell an existing account from an unknown one.
 *
 * ⚠️ The link's origin comes from `Request::getBaseUrl()` (`canonicalBaseUrl`, ADR-030), never from
 * the Host header: a forged Host would put the attacker's domain into a genuine mail and hand him
 * the token (SEC-005). An installation without the value sends no mail; the cause is in the log.
 */
final class PasswordRecovery
{
    private const REQUESTS_PER_ACCOUNT = 3;
    private const REQUESTS_PER_IP      = 10;
    private const WINDOW_SECONDS       = 3600;

    private const RESET_PATH         = '/backend/system/login/reset';
    private const TEMPLATE_NAMESPACE = 'Z77\\Module\\Backend';

    /**
     * @param \Closure(EmailMessage): bool $send    the mail stack; false = not sent
     * @param \Closure(): string           $baseUrl the installation's origin; may throw when unset
     */
    public function __construct(
        private BackendUserRepository $users,
        private PasswordResetTokens $tokens,
        private FileThrottle $throttle,
        private \Closure $send,
        private \Closure $baseUrl,
    ) {
    }

    public static function create(): self
    {
        return new self(
            DI::getUnifiedEntityManager()->getRepository(BackendUser::class),
            new PasswordResetTokens(),
            // Disposable, shared across releases: same reasoning as MemberThrottle::defaultDir().
            new FileThrottle(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/password-reset'),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            static fn(): string => DI::getRequest()->getBaseUrl(),
        );
    }


    /**
     * A «Passwort vergessen» request. Sends the link when the identifier names an account with a
     * recovery address and no limit is exceeded; in every other case nothing happens. Never
     * throws: whatever goes wrong goes to the log, the visitor's answer stays the same.
     */
    public function request(string $identifier, ?string $clientIp, ?int $now = null): void
    {
        $now ??= time();

        try {
            $this->tokens->purge($now);

            $ip = FileThrottle::normalizeIp((string) $clientIp);
            if ($ip !== null && !$this->throttle->allow('ip:' . $ip, self::REQUESTS_PER_IP, self::WINDOW_SECONDS, $now)) {
                return;
            }

            $user = $this->users->findByRecoveryIdentifier($identifier);
            if ($user === null || $user->getEmail() === '') {
                return;
            }

            // Per account, not per spelling: username and address share one counter.
            if (!$this->throttle->allow('account:' . $user->getId(), self::REQUESTS_PER_ACCOUNT, self::WINDOW_SECONDS, $now)) {
                return;
            }

            // Origin first: without one no token is issued for a mail that cannot be built.
            $origin = ($this->baseUrl)();
            $plain  = $this->tokens->issue((int) $user->getId(), $now);

            $sent = ($this->send)((new EmailMessage())
                ->to($user->getEmail())
                ->subject('Passwort zurücksetzen')
                ->template('emails/passwordReset', self::TEMPLATE_NAMESPACE, [
                    'greetingName' => $this->greetingName($user),
                    'resetUrl'     => $origin . self::RESET_PATH . '?token=' . $plain,
                    'validMinutes' => intdiv(PasswordResetTokens::TTL_SECONDS, 60),
                ]));

            if (!$sent) {
                error_log('PasswordRecovery: reset mail not sent for user id ' . $user->getId());
            }
        } catch (\Throwable $e) {
            error_log('PasswordRecovery: request failed — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * «Dein Passwort wurde geändert»: tells the owner of the mailbox when it was not him. Carries
     * no link. Never throws: the password is already changed, a failed notice must not undo the
     * answer.
     */
    public function notifyPasswordChanged(BackendUser $user): void
    {
        if ($user->getEmail() === '') {
            return;
        }

        try {
            $sent = ($this->send)((new EmailMessage())
                ->to($user->getEmail())
                ->subject('Dein Passwort wurde geändert')
                ->template('emails/passwordChanged', self::TEMPLATE_NAMESPACE, [
                    'greetingName' => $this->greetingName($user),
                ]));

            if (!$sent) {
                error_log('PasswordRecovery: change notice not sent for user id ' . $user->getId());
            }
        } catch (\Throwable $e) {
            error_log('PasswordRecovery: change notice failed — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    private function greetingName(BackendUser $user): string
    {
        return $user->getName() !== '' ? $user->getName() : $user->getUsername();
    }
}
