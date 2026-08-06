<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Entities\MemberAccount,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Services\Totp,
    Z77\Module\Member\Services\TotpSetup,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Shared\Qr\QrCode
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

    /**
     * 2FA setup (B8): GET shows the QR (server-rendered data-URI via the
     * kernel Qr facade) plus the manual-entry key; POST confirms with the
     * app code — only then is 2FA active. A reload re-shows the SAME pending
     * secret (TotpSetup::begin resumes).
     */
    protected function totpAction(): HtmlResponse|RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }
        if ($account->hasTotp()) {
            return $this->redirect('/member/main/profile');
        }

        $setup   = TotpSetup::create();
        $request = DI::getRequest();
        $error   = '';

        if ($request->isPost()) {
            if (DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
                && $setup->confirm($account, (string)$request->getPostParameter('code'))
            ) {
                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Zwei-Faktor-Schutz ist aktiv — ab jetzt fragt die Anmeldung nach dem App-Code.'
                );

                return $this->redirect('/member/main/profile');
            }
            $error = 'Der Code ist ungültig — bitte scannen Sie den QR-Code und versuchen Sie es erneut.';
        }

        $secret = $setup->begin($account);
        $uri    = Totp::otpauthUri('AXO3', $account->getEmail(), $secret);

        return $this->html([
            'pageTitle' => 'Zwei-Faktor-Schutz einrichten',
            'qrDataUri' => QrCode::pngDataUri($uri, 220),
            'secret'    => trim(chunk_split($secret, 4, ' ')),
            'error'     => $error,
        ]);
    }

    /** Removing 2FA demands a valid app code (spec) — POST only. */
    protected function totpRemoveAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if ($request->isPost()
            && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
            && TotpSetup::create()->remove($account, (string)$request->getPostParameter('code'))
        ) {
            $this->messageService->pushFlashAfterRedirect('success', 'Zwei-Faktor-Schutz entfernt.');
        } else {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                'Entfernen fehlgeschlagen — der App-Code ist erforderlich und muss gültig sein.'
            );
        }

        return $this->redirect('/member/main/profile');
    }
}
