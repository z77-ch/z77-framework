<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Module\Member\Ui\Form\ResendFormDefinition,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler
;

/**
 * B7 resend page: request a fresh confirmation link. The flow issues a new
 * token (devaluing the old one) for a registered account, sends the
 * existing-account mail for a confirmed/active one, and does nothing for an
 * unknown address — the danke page is the same in every case (anti-oracle).
 */
class ResendController extends AbstractMemberController
{
    use PublicFormCheckTrait;

    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $this->layoutManager->addJs('public-form', 'Z77\\Module\\Frontend', 'footer', true);

        $form = PublicFormHandler::create(new ResendFormDefinition());

        $onValid = fn($valid): bool => $this->flow()->resend((string)$valid->get('email'));

        if ($form->process($onValid)) {
            return $this->redirect('/member/main/resend/danke');
        }

        return $this->html(['pageTitle' => 'Link erneut anfordern'] + $form->viewContext());
    }

    /** PRG target. */
    protected function dankeAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Link angefordert']);
    }

    /** Per-field blur validation (public-form standard). */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new ResendFormDefinition());
    }
}
