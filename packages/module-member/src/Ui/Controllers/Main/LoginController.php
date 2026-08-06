<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\LoginFlow,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Module\Member\Ui\Form\LoginFormDefinition,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler
;

/**
 * B8 login pages: request the magic link (public-form standard — the handler
 * owns CSRF/bot/validation/rate limit; the flow adds the per-address throttle
 * and the anti-oracle mail choice) and redeem it. A signed-in visitor is sent
 * straight to the profile.
 */
class LoginController extends AbstractMemberController
{
    use PublicFormCheckTrait;

    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        if (MemberAuth::create()->current() !== null) {
            return $this->redirect('/member/main/profile');
        }

        $this->layoutManager->addJs('public-form', 'Z77\\Module\\Frontend', 'footer', true);

        $form = PublicFormHandler::create(new LoginFormDefinition());

        $onValid = fn($valid): bool => $this->loginFlow()->request((string)$valid->get('email'));

        if ($form->process($onValid)) {
            return $this->redirect('/member/main/login/danke');
        }

        return $this->html(['pageTitle' => 'Anmelden'] + $form->viewContext());
    }

    /** PRG target — neutral by spec: «falls ein Konto besteht, ist eine Mail unterwegs». */
    protected function dankeAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Anmeldung angefordert']);
    }

    /** The link click: session or back to the request page with a hint. */
    protected function redeemAction(): RedirectResponse
    {
        $outcome = $this->loginFlow()->redeem(
            (string)DI::getRequest()->getGetParameter('token')
        );

        if ($outcome === LoginFlow::SESSION) {
            return $this->redirect('/member/main/profile');
        }

        $this->messageService->pushFlashAfterRedirect(
            'error',
            'Dieser Anmelde-Link ist nicht mehr gültig — fordern Sie einen neuen an.'
        );

        return $this->redirect('/member/main/login');
    }

    /** Per-field blur validation (public-form standard). */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new LoginFormDefinition());
    }

    private function loginFlow(): LoginFlow
    {
        return LoginFlow::create(
            $this->absoluteUrl('/member/main/login/redeem'),
            $this->absoluteUrl('/member/main/register'),
            $this->absoluteUrl('/member/main/confirm'),
        );
    }
}
