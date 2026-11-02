<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\DI,
    Z77\Module\Backend\PasswordReset\PasswordRecovery,
    Z77\Module\Backend\PasswordReset\PasswordResetTokens,
    Z77\Module\Backend\TwoFactor\BackendTwoFactor,
    Z77\Module\Backend\TwoFactor\TotpCheck,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Auth\PasswordPolicy,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Libraries\Convention\Naming,
    Z77\Shared\Services\AuthService,
    Z77\Shared\Validators\BackendUserValidator
;

class LoginController extends BackendAbstractController
{
    private const THROTTLED_MESSAGE = 'Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.';
    private const NEUTRAL_ANSWER    = 'Falls das Konto existiert, ist eine E-Mail unterwegs.';
    private const SESSION_EXPIRED   = 'Sitzung abgelaufen, bitte erneut versuchen.';
    private const CODE_PAGE_GONE    = 'Die Anmeldung ist abgelaufen, bitte melde dich erneut an.';

    /** The password was right, the code is outstanding: the only trace of such a login. */
    private const PENDING_KEY     = 'auth.second_factor_pending';
    private const PENDING_SECONDS = 300;

    private string $secondFactorRefusal = '';

    protected function loginAction(): HtmlResponse|RedirectResponse
    {
        if (DI::getRequest()->isPost()) {
            return $this->handlePost();
        }

        return $this->renderForm(['error' => null, 'username' => '']);
    }

    /**
     * The second step of a login with 2FA (login.md BACKEND-2FA-001). Nothing here trusts the first
     * step beyond the marker: the user is read again, and until the code passed there is no
     * identity in this session.
     */
    protected function codeAction(): HtmlResponse|RedirectResponse
    {
        $user = $this->pendingUser();
        if ($user === null) {
            $this->messageService->pushFlashAfterRedirect('info', self::CODE_PAGE_GONE);
            return $this->redirect('/login');
        }

        if (!DI::getRequest()->isPost()) {
            return $this->html($this->baseContext(['error' => null]));
        }

        if (!$this->csrfIsValid()) {
            return $this->html($this->baseContext(['error' => self::SESSION_EXPIRED]));
        }

        $check = BackendTwoFactor::create()->check($user, $this->postString('code'));

        return $check === TotpCheck::Passed
            ? $this->finishSecondFactor($user)
            : $this->html($this->baseContext(['error' => $check->refusal()]));
    }

    private function finishSecondFactor(BackendUser $user): RedirectResponse
    {
        DI::getSessionManager()->remove(self::PENDING_KEY);
        // Only now: until the code passed, the attempt counts as a failure of this address.
        $this->loginThrottle()->succeeded($user->getUsername(), DI::getRequest()->getClientIp());

        return $this->completeLogin(DI::getAuthService()->passedSecondFactor($user), $user);
    }

    /**
     * The user behind a live marker, read again: gone, 2FA switched off in the meantime (the code
     * page would have nothing to ask), a raised stamp or an expired marker all end it. There is no
     * «disabled user» state yet; a password change or an admin reset raises the stamp.
     */
    private function pendingUser(): ?BackendUser
    {
        $sm      = DI::getSessionManager();
        $pending = $sm->get(self::PENDING_KEY);
        if (!is_array($pending)) {
            return null;
        }

        $user = time() - (int) ($pending['at'] ?? 0) < self::PENDING_SECONDS
            ? $this->em()->getRepository(BackendUser::class)->find((int) ($pending['id'] ?? 0))
            : null;
        if (!$user instanceof BackendUser || !$user->hasTotp() || (int) ($pending['auth_epoch'] ?? -1) !== $user->getAuthEpoch()) {
            $sm->remove(self::PENDING_KEY);
            return null;
        }

        return $user;
    }

    /**
     * POST with the session's token only: a logout on GET lets any page sign the user out with an
     * image tag, and a prefetch does it by accident. A GET answers as every wrong method does.
     */
    #[HttpMethod('POST'), Csrf]
    protected function logoutAction(): RedirectResponse
    {
        DI::getAuthService()->logout();
        DI::getSessionManager()->remove(self::PENDING_KEY);
        DI::getSessionManager()->remove(BackendTwoFactor::ENROLMENT_KEY);

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
            : $this->renderResetForm($token, $user);
    }

    private function handleResetPost(string $token, PasswordResetTokens $tokens, BackendUser $user): HtmlResponse|RedirectResponse
    {
        if (!$this->csrfIsValid()) {
            return $this->renderResetForm($token, $user, self::SESSION_EXPIRED);
        }

        $password = $this->postString('password');
        if ($password !== $this->postString('password_repeat')) {
            return $this->renderResetForm($token, $user, 'Die beiden Passwörter stimmen nicht überein.');
        }

        // The same rules as the user form: the tier decides whether a weak one is refused.
        $tier      = $this->passwordTier();
        $validator = new BackendUserValidator($user, null, $password, true, $user->getId(), $tier);
        if (!$validator->isValid(['password'])) {
            return $this->renderResetForm($token, $user, null, $validator);
        }

        if (!$this->passesSecondFactor($user)) {
            return $this->renderResetForm($token, $user, $this->secondFactorRefusal);
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
     * The one place a second factor enters this flow: asked HERE, before anything changes, so the
     * recovery link cannot bypass it. A wrong code changes nothing, leaves the link usable and
     * counts towards the lock. Only an account with 2FA is asked; the form shows the field then.
     */
    private function passesSecondFactor(BackendUser $user): bool
    {
        if (!$user->hasTotp()) {
            return true;
        }
        $check = BackendTwoFactor::create()->check($user, $this->postString('code'));
        $this->secondFactorRefusal = $check->refusal();

        return $check === TotpCheck::Passed;
    }

    private function renderResetForm(string $token, BackendUser $user, ?string $error = null, ?BackendUserValidator $validator = null): HtmlResponse
    {
        $response = $this->html($this->baseContext([
            'mode'              => 'form',
            'error'             => $error,
            'token'             => $token,
            'validator'         => $validator,
            'passwordMinLength' => $this->passwordTier()->minLength(),
            'askCode'           => $user->hasTotp(),
        ]));

        // Live strength meter (hint only) and show-password toggle, both self-init on DOM ready.
        // ⚠️ After html(): only then do they load behind core.js, which creates _Z77.
        $this->layoutManager->addJs('password-meter', self::NAMESPACE);
        $this->layoutManager->addJs('password-toggle', self::NAMESPACE);

        return $response;
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

        // Password right, 2FA on: no identity yet. The reservation above stays, the code page
        // hands it back once the code passed. Whoever was signed in in this browser is signed out.
        if ($user->hasTotp()) {
            $auth->logout();
            DI::getSessionManager()->remove(BackendTwoFactor::ENROLMENT_KEY);
            DI::getSessionManager()->regenerate();
            DI::getSessionManager()->set(self::PENDING_KEY, ['id' => $user->getId(), 'at' => $now, 'auth_epoch' => $user->getAuthEpoch()]);

            return $this->redirect('/backend/system/login/code');
        }

        $throttle->succeeded($username, $ip, $now);

        return $this->completeLogin($auth, $user);
    }

    /** The identity, the greeting, the nag, the way on: the same for both ways through the login. */
    private function completeLogin(AuthService $auth, BackendUser $user): RedirectResponse
    {
        // A secret read with a cookie stolen earlier must not survive into this login.
        DI::getSessionManager()->remove(BackendTwoFactor::ENROLMENT_KEY);
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
        $response = $this->html($this->baseContext($extra));

        // Show-password toggle on the password field (self-inits on DOM ready). ⚠️ After html():
        // only then does it load behind core.js, which creates _Z77.
        $this->layoutManager->addJs('password-toggle', self::NAMESPACE);

        return $response;
    }

    private function baseContext(array $extra = []): array
    {
        return array_merge([
            'csrfToken' => DI::getCsrfService()->getToken(),
        ], $extra);
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
