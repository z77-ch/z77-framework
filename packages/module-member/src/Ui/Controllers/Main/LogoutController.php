<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\LoginFlow,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * B8 logout: ends the current member session and returns to the login page.
 * («Alle Geräte abmelden» is the profile's device-key action, stage C.)
 *
 * POST with the session's token only: on GET any page could sign a member out with an image tag.
 */
class LogoutController extends AbstractMemberController
{
    #[HttpMethod('POST'), Csrf]
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
