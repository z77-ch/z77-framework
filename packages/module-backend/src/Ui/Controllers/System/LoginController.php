<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Entities\LoginUser,
    Z77\Shared\Libraries\Convention\Naming
;

class LoginController extends BackendAbstractController
{
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

    private function handlePost(): HtmlResponse|RedirectResponse
    {
        $request  = DI::getRequest();
        $token    = trim($request->getPostParameter('csrf_token') ?? '');
        $username = trim($request->getPostParameter('username') ?? '');
        $password = $request->getPostParameter('password') ?? '';

        if (!DI::getCsrfService()->validate($token)) {
            return $this->renderForm(['error' => 'Sitzung abgelaufen, bitte erneut versuchen.', 'username' => $username]);
        }

        if ($username === '' || $password === '') {
            return $this->renderForm(['error' => 'Benutzername und Passwort sind erforderlich.', 'username' => $username]);
        }

        $user = DI::getUnifiedEntityManager()->getRepository(LoginUser::class)->findByUsername($username);
        $auth = $user !== null ? DI::getAuthService()->canLogin($user, $password) : null;

        if ($auth === null) {
            return $this->renderForm(['error' => 'Ungültige Anmeldedaten.', 'username' => $username]);
        }

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
}
