<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Module\Member\Ui\Form\RegisterFormDefinition,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler
;

/**
 * B7 registration page (public-form standard: definition + handler own the
 * whole cascade — CSRF, bot checks, validation, session rate limit, PRG).
 *
 * The onValid callback hands the validated form to RegistrationFlow: per-
 * address throttle, then account + confirmation mail OR the existing-account
 * mail. Its true/false feeds straight back into the handler contract, so the
 * neutral answer (danke page) covers new and existing addresses alike — the
 * page cannot be used to probe which addresses have accounts.
 */
class RegisterController extends AbstractMemberController
{
    use PublicFormCheckTrait;

    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $this->layoutManager->addJs('public-form', 'Z77\\Module\\Frontend', 'footer', true);

        $form = PublicFormHandler::create(new RegisterFormDefinition());

        $onValid = function ($valid): bool {
            return $this->flow()->register(
                (string)$valid->get('email'),
                (string)$valid->get('company') ?: null,
                (string)$valid->get('first_name') ?: null,
                (string)$valid->get('last_name') ?: null,
            );
        };

        if ($form->process($onValid)) {
            return $this->redirect('/member/main/register/danke');
        }

        return $this->html(['pageTitle' => 'Registrieren'] + $form->viewContext());
    }

    /** PRG target — the confirmation is a page, not a session flag. */
    protected function dankeAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Registrierung erhalten']);
    }

    /** Per-field blur validation (public-form standard). */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new RegisterFormDefinition());
    }
}
