<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\DI,
    Z77\Module\Backend\PasswordReset\PasswordRecovery,
    Z77\Module\Backend\PasswordReset\PasswordResetTokens,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Auth\LoginThrottle,
    Z77\Shared\Auth\PasswordPolicy,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Libraries\Convention\Naming,
    Z77\Shared\Validators\BackendUserValidator
;

class LoginController extends BackendAbstractController
{
    private const THROTTLED_MESSAGE = 'Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.';
    private const NEUTRAL_ANSWER    = 'Falls das Konto existiert, ist eine E-Mail unterwegs.';
    private const SESSION_EXPIRED   = 'Sitzung abgelaufen, bitte erneut versuchen.';

    protected function loginAction(): HtmlResponse|RedirectResponse
    {
        if (DI::getRequest()->isPost()) {
            return $this->handlePost();
        }

        return $this->renderForm(['error' => null, 'username' => '']);
    }

    protected function logoutAction(): RedirectResponse
    {
        DI::getAuthService()->logout();

        $this->messageService->pushFlashAfterRedirect('info', 'Du wurdest abgemeldet');
        return $this->redirect('/login');
    }

    /**
     * «Passwort vergessen» (login.md LOGIN-RESET-001): one answer for everything past the CSRF
     * check. PasswordRecovery::request() returns nothing, so there is no outcome to give away.
     */
    protected function forgotAction(): HtmlResponse
    {
        $context = ['error' => null, 'notice' => null, 'identifier' => ''];

        if (DI::getRequest()->isPost()) {
            $identifier = trim($this->postString('identifier'));

            if (!$this->csrfIsValid()) {
                return $this->html($this->baseContext(['error' => self::SESSION_EXPIRED, 'identifier' => $identifier] + $context));
            }

            PasswordRecovery::create()->request($identifier, DI::getRequest()->getClientIp());

            return $this->html($this->baseContext(['notice' => self::NEUTRAL_ANSWER] + $context));
        }

        return $this->html($this->baseContext($context));
    }

    /**
     * The form behind the mail's link. GET looks the token up without redeeming it, because mail
     * scanners follow links; only a successful POST consumes it, see {@see handleResetPost()}.
     */
    protected function resetAction(): HtmlResponse|RedirectResponse
    {
        // The token is in this page's address: it must not leave in a Referer header.
        header('Referrer-Policy: no-referrer');

        $request = DI::getRequest();
        $token   = $request->isPost() ? $request->getPostParameter('token') : $request->getGetParameter('token');
        $token   = is_string($token) ? $token : '';
        $tokens  = new PasswordResetTokens();

        $userId = $tokens->peek($token);
        $user   = $userId !== null ? $this->em()->getRepository(BackendUser::class)->find($userId) : null;
        if (!$user instanceof BackendUser) {
            return $this->html($this->baseContext(['mode' => 'invalid']));
        }

        return $request->isPost()
            ? $this->handleResetPost($token, $tokens, $user)
            : $this->renderResetForm($token);
    }

    private function handleResetPost(string $token, PasswordResetTokens $tokens, BackendUser $user): HtmlResponse|RedirectResponse
    {
        if (!$this->csrfIsValid()) {
            return $this->renderResetForm($token, self::SESSION_EXPIRED);
        }

        $password = $this->postString('password');
        if ($password !== $this->postString('password_repeat')) {
            return $this->renderResetForm($token, 'Die beiden Passwörter stimmen nicht überein.');
        }

        // The same rules as the user form: the tier decides whether a weak one is refused.
        $tier      = $this->passwordTier();
        $validator = new BackendUserValidator($user, null, $password, true, $user->getId(), $tier);
        if (!$validator->isValid(['password'])) {
            return $this->renderResetForm($token, null, $validator);
        }

        if (!$this->passesSecondFactor($user)) {
            return $this->renderResetForm($token, 'Die Bestätigung ist fehlgeschlagen.');
        }

        // Hashed before the token is consumed: bcrypt must not run inside the store's lock, and a
        // failure here leaves the link usable.
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // From here on, exactly one request gets through. Read the user again: the password
        // hashing took long enough for an admin's save to land in between.
        if ($tokens->consume($token) === null) {
            return $this->html($this->baseContext(['mode' => 'invalid']));
        }
        $user = $this->em()->getRepository(BackendUser::class)->find((int) $user->getId());
        if (!$user instanceof BackendUser) {
            return $this->html($this->baseContext(['mode' => 'invalid']));
        }

        $user->setPasswordHash($hash);
        $user->setPasswordWeak(PasswordPolicy::isWeak($password, [$user->getUsername()], $tier));
        $user->bumpAuthEpoch();
        $this->em()->persist($user);
        $this->em()->flush();

        PasswordRecovery::create()->notifyPasswordChanged($user);
        $this->loginThrottle()->forgive($user->getUsername(), DI::getRequest()->getClientIp());

        // No automatic login: whoever reset the password signs in with it, like everyone else.
        $this->messageService->pushFlashAfterRedirect('success', 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.');

        return $this->redirect('/login');
    }

    /**
     * The one place a second factor enters this flow. Always true today: the backend has no 2FA
     * yet. Backend-2FA is the next step (security.md «pending»): it asks for the code HERE, before
     * anything changes; a wrong code changes nothing and counts towards a lockout, so the
     * recovery link cannot bypass the second factor.
     */
    private function passesSecondFactor(BackendUser $user): bool
    {
        return true;
    }

    private function renderResetForm(string $token, ?string $error = null, ?BackendUserValidator $validator = null): HtmlResponse
    {
        // Live strength meter (hint only) and show-password toggle, both self-init on DOM ready.
        $this->layoutManager->addJs('password-meter', self::NAMESPACE);
        $this->layoutManager->addJs('password-toggle', self::NAMESPACE);

        return $this->html($this->baseContext([
            'mode'              => 'form',
            'error'             => $error,
            'token'             => $token,
            'validator'         => $validator,
            'passwordMinLength' => $this->passwordTier()->minLength(),
        ]));
    }

    private function handlePost(): HtmlResponse|RedirectResponse
    {
        $request  = DI::getRequest();
        $username = trim($this->postString('username'));
        $password = $this->postString('password');

        if (!$this->csrfIsValid()) {
            return $this->renderForm(['error' => self::SESSION_EXPIRED, 'username' => $username]);
        }

        if ($username === '' || $password === '') {
            return $this->renderForm(['error' => 'Benutzername und Passwort sind erforderlich.', 'username' => $username]);
        }

        // SEC-004: over a limit, no password_verify happens at all. The attempt is counted here
        // and handed back on success, so parallel guesses cannot slip past the limit.
        $throttle = $this->loginThrottle();
        $ip       = $request->getClientIp();
        $now      = time();
        if (!$throttle->tryAttempt($username, $ip, $now)) {
            return $this->renderForm(['error' => self::THROTTLED_MESSAGE, 'username' => $username]);
        }

        $user = DI::getUnifiedEntityManager()->getRepository(BackendUser::class)->findByUsername($username);
        $auth = $user !== null ? DI::getAuthService()->canLogin($user, $password) : null;

        if ($auth === null) {
            return $this->renderForm(['error' => 'Ungültige Anmeldedaten.', 'username' => $username]);
        }

        $throttle->succeeded($username, $ip, $now);
        $auth->login();

        $this->messageService->pushFlashAfterRedirect('success', 'Hallo ' . $user->getUsername() . ', du bist angemeldet');

        // Weak-password nag: allowed but reminded on EVERY login (persistent
        // message channel — stays until the admin closes it). The policy never
        // blocks login; it keeps nudging. See PasswordPolicy / login.md.
        if ($user->isPasswordWeak()) {
            $this->messageService->pushMessageAfterRedirect(
                'error',
                'Dein Passwort ist unsicher. Bitte ändere es unter Stammdaten → Benutzer.'
            );
        }

        return $this->resolvePostLoginRedirect();
    }

    private function resolvePostLoginRedirect(): RedirectResponse
    {
        $sm     = DI::getSessionManager();
        $origin = $sm->get('access.origin', null);

        if ($origin) {
            $sm->remove('access.origin');
            $module     = $origin['module']        ?? '';
            $group      = $origin['group']         ?? '';
            $controller = Naming::toControllerUrlSegment($origin['controller'] ?? '');
            $action     = Naming::toActionUrlSegment($origin['action'] ?? '');

            if ($module !== '' && $group !== '' && $controller !== '' && $action !== '') {
                return $this->redirect("/{$module}/{$group}/{$controller}/{$action}");
            }
        }

        // No remembered origin — land on the current module's default landing page
        // (e.g. backend → /backend/system/dashboard/overview). Keeping the user inside
        // the module they logged in through ensures flash/message feedback is rendered.
        $mm         = DI::getModuleManager();
        $module     = DI::getControllerHandler()->getCurrentModule();
        $group      = $mm->getDefaultGroup($module);
        $controller = $mm->getGroupDefaultController($module, $group);
        $action     = $mm->getDefaultActionForController($module, $group, $controller) ?? '';

        return $this->redirect("/{$module}/{$group}/{$controller}/{$action}");
    }

    /**
     * Renders the login form. All form-render paths (initial GET + every POST
     * error re-render) funnel through here so the show-password toggle script is
     * registered exactly once, in one place. Success/logout return redirects and
     * never touch this.
     */
    private function renderForm(array $extra): HtmlResponse
    {
        // Show-password toggle on the password field (self-inits on DOM ready).
        $this->layoutManager->addJs('password-toggle', self::NAMESPACE);
        return $this->html($this->baseContext($extra));
    }

    private function baseContext(array $extra = []): array
    {
        return array_merge([
            'csrfToken' => DI::getCsrfService()->getToken(),
        ], $extra);
    }

    /**
     * The limits are installation policy (`config/client/auth.inc.php`); a seed-once file from
     * before SEC-004 has no such keys and gets the class's defaults.
     */
    private function loginThrottle(): LoginThrottle
    {
        $config = DI::getConfigManager()->getBaseConfig('config/auth', throwError: false);

        return new LoginThrottle(
            LoginThrottle::defaultDir(),
            max(1, (int) $config->get('loginFailuresPerUsernameIp', LoginThrottle::FAILURES_PER_USERNAME_IP)),
            max(1, (int) $config->get('loginFailuresPerIp', LoginThrottle::FAILURES_PER_IP)),
        );
    }

    /**
     * ⚠️ An EMPTY token is refused here, not left to CsrfService::validate(): a session that holds
     * no token yet compares '' with '' and would pass.
     */
    private function csrfIsValid(): bool
    {
        $token = trim($this->postString('csrf_token'));

        return $token !== '' && DI::getCsrfService()->validate($token);
    }

    /** A POST field as text; `field[]=…` (an array) reads as empty instead of a TypeError. */
    private function postString(string $name): string
    {
        $value = DI::getRequest()->getPostParameter($name);

        return is_string($value) ? $value : '';
    }
}
