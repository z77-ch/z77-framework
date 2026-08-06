<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController
;

/**
 * B8 profile — the session-guarded page. Stage A shows the account and its
 * state (a 'confirmed' account signs in but sees only this page + «wartet
 * auf Freischaltung», B7 decision 4); 2FA setup and the device list join in
 * the next stages. The guard is MemberAuth, not the framework ACL — member
 * sessions are the customer login, the admin login stays untouched.
 */
class ProfileController extends AbstractMemberController
{
    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        return $this->html([
            'pageTitle' => 'Profil',
            'account'   => $account,
        ]);
    }
}
