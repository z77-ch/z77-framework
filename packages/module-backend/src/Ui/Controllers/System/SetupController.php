<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\DI,
    Z77\Core\Config\AuthRole,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Auth\PasswordPolicy,
    Z77\Shared\Entities\LoginUser,
    Z77\Shared\Repositories\LoginUserRepository,
    Z77\Shared\Validators\LoginUserValidator
;

/**
 * First-run admin setup for NON-INTERACTIVE installs. When the installer cannot
 * prompt (CI / FTP / hosting panel) it does not create an admin — it writes a
 * one-time `SETUP_TOKEN` under `data/` instead (see {@see \Z77\Core\Installer\Install}).
 * This controller turns that token — proof of filesystem/server access — into the
 * admin account, defeating the public "first-in-first-win" race on an open-source
 * app where the codebase (and any shipped credential) would be known.
 *
 * Hard gates, checked on every request in order:
 *   1. a user already exists      → setup is permanently locked.
 *   2. no `SETUP_TOKEN` on disk    → nothing to gate against → unavailable.
 *   3. submitted token must equal the on-disk token (constant-time compare).
 * On success the admin is persisted, the token is deleted (single-use), and the
 * visitor is redirected to `/login`.
 *
 * Reachable without auth — registered with {@see AuthRole::GUEST} in
 * `backendConfig`. No friendly URL alias by design: a one-shot bootstrap route
 * should not live in the user-editable runtime navigation. Canonical URL:
 * `/backend/system/setup/setup`.
 *
 * @see docs/topics/security.md
 */
class SetupController extends BackendAbstractController
{
    private const TOKEN_PATH = '/data/framework/auth/SETUP_TOKEN';

    protected function setupAction(): HtmlResponse|RedirectResponse
    {
        // Gate 1: already provisioned → lock forever (and clean up any stale token).
        if ($this->repo()->findAll() !== []) {
            $this->discardToken();
            return $this->html(['mode' => 'locked']);
        }

        // Gate 2: no token → setup cannot be offered.
        $expected = $this->readToken();
        if ($expected === null) {
            return $this->html(['mode' => 'unavailable']);
        }

        if (DI::getRequest()->isPost()) {
            return $this->handlePost($expected);
        }

        return $this->renderForm();
    }

    private function handlePost(string $expectedToken): HtmlResponse|RedirectResponse
    {
        $request  = DI::getRequest();
        $csrf     = trim($request->getPostParameter('csrf_token') ?? '');
        $token    = trim($request->getPostParameter('setup_token') ?? '');
        $username = trim($request->getPostParameter('username') ?? '');
        $password = $request->getPostParameter('password') ?? '';

        if (!DI::getCsrfService()->validate($csrf)) {
            return $this->renderForm('Sitzung abgelaufen, bitte erneut versuchen.', $username);
        }

        // Gate 3: token must match the on-disk value (constant-time).
        if ($token === '' || !hash_equals($expectedToken, $token)) {
            return $this->renderForm('Ungültiges Setup-Token.', $username);
        }

        // Build the admin. Roles are fixed server-side (never from the body);
        // the password is policy-evaluated — under the configured tier a weak one
        // is flagged for the every-login nag (and hard-blocked under veryStrong,
        // enforced by the validator).
        $tier = $this->passwordTier();
        $user = new LoginUser(BodyCleaner::cleanFor(LoginUser::class, ['username' => $username]));
        $user->setRoles([AuthRole::SUPER_USER]); // first account = the governor (ADR-021)
        $user->setSortKey(0);
        $user->setPasswordWeak(PasswordPolicy::isWeak($password, [$username], $tier));

        $validator = new LoginUserValidator($user, $this->repo(), $password, true, null, $tier);
        if (!$validator->isValid()) {
            return $this->renderForm(null, $username, $validator);
        }

        $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
        $this->em()->persist($user);
        $this->em()->flush();

        // Single-use: burn the token so /setup locks immediately afterwards.
        $this->discardToken();

        $this->messageService->pushFlashAfterRedirect('success', 'Administrator-Konto erstellt. Bitte melde dich an.');
        if ($user->isPasswordWeak()) {
            $this->messageService->pushMessageAfterRedirect(
                'error',
                'Das gewählte Passwort ist unsicher. Du wirst bei jedem Login daran erinnert.'
            );
        }

        return $this->redirect('/login');
    }

    private function renderForm(?string $error = null, string $username = 'admin', ?LoginUserValidator $validator = null): HtmlResponse
    {
        $response = $this->html([
            'mode'              => 'form',
            'error'             => $error,
            'username'          => $username,
            'csrfToken'         => DI::getCsrfService()->getToken(),
            'validator'         => $validator,
            'passwordMinLength' => $this->passwordTier()->minLength(),
        ]);
        // Live password-strength meter (hint only; the server policy decides on save).
        // Reused from user management; on this full-page render it self-inits on DOM ready.
        $this->layoutManager->addJs('password-meter', self::NAMESPACE);
        // Show-password toggle on the password field (self-inits on DOM ready).
        $this->layoutManager->addJs('password-toggle', self::NAMESPACE);
        return $response;
    }

    private function repo(): LoginUserRepository
    {
        return $this->em()->getRepository(LoginUser::class);
    }

    /** Absolute path of the one-time setup token (filesystem-only, never under public/). */
    private function tokenPath(): string
    {
        return ABS_BASE_PATH . self::TOKEN_PATH;
    }

    /** On-disk token, or null when the file is missing/empty (setup not available). */
    private function readToken(): ?string
    {
        $path = $this->tokenPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = trim((string) file_get_contents($path));
        return $raw === '' ? null : $raw;
    }

    private function discardToken(): void
    {
        $path = $this->tokenPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
