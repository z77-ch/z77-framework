<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\LoginFlow,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController
;

/**
 * B8 logout: ends the current member session and returns to the login page.
 * («Alle Geräte abmelden» is the profile's device-key action, stage C.)
 */
class LogoutController extends AbstractMemberController
{
    protected function indexAction(): RedirectResponse
    {
        LoginFlow::create(
            $this->absoluteUrl('/member/main/login/redeem'),
            $this->absoluteUrl('/member/main/register'),
            $this->absoluteUrl('/member/main/confirm'),
        )->logout();

        $this->messageService->pushFlashAfterRedirect('success', 'Sie sind abgemeldet.');

        return $this->redirect('/member/main/login');
    }
}
