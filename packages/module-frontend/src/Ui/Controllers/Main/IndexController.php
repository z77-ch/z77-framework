<?php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController,
    Z77\Module\Frontend\Ui\Form\ContactFormDefinition,
    Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler,
    Z77\Shared\Services\ContentService
;

class IndexController extends AbstractFrontendController
{
    use PublicFormCheckTrait;

    protected function homeAction(): HtmlResponse
    {
        $language    = DI::getRequest()->getLanguage();
        $contentHtml = ContentService::create()->render('home', $language);

        return $this->html(['pageTitle' => 'Home', 'contentHtml' => $contentHtml]);
    }

    protected function aboutAction(): HtmlResponse
    {
        // Bespoke mode: pass the Content ENTITY (gated via find()); the template
        // owns the markup and reads blocks via BlockView. Contrast homeAction,
        // which uses the stream renderer (render() → one HTML string).
        $content = ContentService::create()->find('about', DI::getRequest()->getLanguage());

        return $this->html(['pageTitle' => 'About', 'content' => $content]);
    }

    protected function servicesAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Services']);
    }

    /**
     * Reference implementation of a public form (public-form-bauplan.md): the
     * whole form lives in {@see ContactFormDefinition}; the framework handler
     * owns CSRF, bot checks, validation, rate limit, sending and the PRG
     * hand-off. What stays here is page content.
     *
     * Page cache is off for this action (frontendConfig) — the CSRF token and
     * the per-user form state must never be shared.
     */
    protected function contactAction(): HtmlResponse|RedirectResponse
    {
        $this->layoutManager->addJs('public-form', self::NAMESPACE, 'footer', true);

        $form = PublicFormHandler::create(new ContactFormDefinition());

        // true → a successful (or bot-faked) submit: redirect, never re-render.
        // No flash here — thanksAction IS the confirmation. A form that confirms
        // inline would push one on this line:
        //   $this->messageService->pushFlashAfterRedirect('success', t('form.flash.sent'));
        if ($form->process()) {
            return $this->redirect(localizedUrl('/thanks'));
        }

        return $this->html(['pageTitle' => 'Contact'] + $form->viewContext());
    }

    /**
     * The PRG target of contactAction — a page of its own, so "sent" is a URL
     * and not a session flag that makes the contact page render something else.
     * Reachable directly, which is fine: it states nothing but the thank-you.
     */
    protected function thanksAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Danke']);
    }

    /** Per-field blur validation for the contact form (see the trait). */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new ContactFormDefinition());
    }

    protected function legalAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Legal']);
    }

    protected function privacyAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Privacy']);
    }
}
