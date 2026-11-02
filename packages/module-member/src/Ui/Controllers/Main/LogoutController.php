<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\LoginFlow,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController
;

/**
 * B8 logout: ends the current member session and returns to the login page.
 * («Alle Geräte abmelden» is the profile's device-key action, stage C.)
 *
 * Signs out on a POST with the session's token only: on a GET any page could sign a member out with
 * an image tag. Everything else gets a small page with a fresh token and a button, never a silent
 * refusal (security.md SEC-GET-001): a stale tab whose session is gone must still be able to leave,
 * device key included, on a shared device above all. The page itself signs nobody out, and a
 * cross-site POST carries neither cookie (SameSite=Lax) to act on.
 */
class LogoutController extends AbstractMemberController
{
    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!$request->isPost() || !DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->html(['pageTitle' => 'Abmelden', 'stale' => $request->isPost()]);
        }

        LoginFlow::create(
            $this->absoluteUrl('/member/main/login/redeem'),
            $this->absoluteUrl('/member/main/register'),
            $this->absoluteUrl('/member/main/confirm'),
        )->logout();

        $this->messageService->pushFlashAfterRedirect('success', 'Sie sind abgemeldet.');

        return $this->redirect('/member/main/login');
    }
}
