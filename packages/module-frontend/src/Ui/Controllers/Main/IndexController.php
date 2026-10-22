<?php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController,
    Z77\Module\Frontend\Ui\Form\ContactFormDefinition,
    Z77\Module\Frontend\Ui\Services\PageSections,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Shared\Content\ContentView,
    Z77\Shared\Content\PageContent,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler
;

/**
 * The frontend starter pages (ADR-044 addendum 2026-10-22).
 *
 * Every page has ONE content document per language (data/content/<slug>.<lang>.json,
 * seeded on the first install, then the customer's); its structure is the
 * blueprint of the same slug in frontendConfig. An action reads its document,
 * passes each slot through PageSections (values + the page-editor marker) and
 * hands the result to its template, which only assembles section partials —
 * the templates hold no text.
 *
 * Deleting a page: its documents (backend Webseiten › Inhalte) and its menu
 * entry. The action then answers 404 (page()). Updates never bring it back:
 * starter content is seeded on the first install only (installer.md).
 */
class IndexController extends AbstractFrontendController
{
    use PublicFormCheckTrait;

    /** Home: hero, intro text, cards, call to action. */
    protected function homeAction(): HtmlResponse
    {
        $page = $this->page('home');

        return $this->html([
            'pageTitle' => $page->title(),
            'hero'      => PageSections::hero($page, 'hero'),
            'intro'     => PageSections::textSection($page, 'intro'),
            'cards'     => PageSections::cards($page, 'cards'),
            'cta'       => PageSections::cta($page, 'cta'),
        ]);
    }

    /** About: hero, text, image and text. */
    protected function aboutAction(): HtmlResponse
    {
        $page = $this->page('about');

        return $this->html([
            'pageTitle' => $page->title(),
            'hero'      => PageSections::hero($page, 'hero'),
            'text'      => PageSections::textSection($page, 'text'),
            'image'     => PageSections::imageText($page, 'image'),
        ]);
    }

    /** Services: hero, cards. */
    protected function servicesAction(): HtmlResponse
    {
        $page = $this->page('services');

        return $this->html([
            'pageTitle' => $page->title(),
            'hero'      => PageSections::hero($page, 'hero'),
            'cards'     => PageSections::cards($page, 'cards'),
        ]);
    }

    /**
     * Contact: intro text, the text above the form, and the reference public
     * form (public-form-bauplan.md): the whole form lives in
     * {@see ContactFormDefinition}; the framework handler owns CSRF, bot checks,
     * validation, rate limit, sending and the PRG hand-off. The texts around it
     * are the `contact` document.
     *
     * Page cache is off for this action (frontendConfig) — the CSRF token and
     * the per-user form state must never be shared.
     */
    protected function contactAction(): HtmlResponse|RedirectResponse
    {
        $page = $this->page('contact');

        $this->layoutManager->addJs('public-form', self::NAMESPACE, 'footer', true);

        $form = PublicFormHandler::create(new ContactFormDefinition());

        // true → a successful (or bot-faked) submit: redirect, never re-render.
        // No flash here — thanksAction IS the confirmation. A form that confirms
        // inline would push one on this line:
        //   $this->messageService->pushFlashAfterRedirect('success', t('form.flash.sent'));
        if ($form->process()) {
            return $this->redirect(localizedUrl('/thanks'));
        }

        return $this->html([
            'pageTitle' => $page->title(),
            'intro'     => PageSections::textSection($page, 'intro'),
            'formText'  => PageSections::form($page, 'form'),
        ] + $form->viewContext());
    }

    /**
     * The PRG target of contactAction — a page of its own, so "sent" is a URL
     * and not a session flag that makes the contact page render something else.
     * Reachable directly, which is fine: it states nothing but the thank-you.
     */
    protected function thanksAction(): HtmlResponse
    {
        $page = $this->page('thanks');

        return $this->html([
            'pageTitle' => $page->title(),
            'hero'      => PageSections::hero($page, 'hero'),
        ]);
    }

    /** Per-field blur validation for the contact form (see the trait). */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new ContactFormDefinition());
    }

    /** Impressum: hero + the usual headings; an empty section is left out. */
    protected function legalAction(): HtmlResponse
    {
        $page = $this->page('legal');

        return $this->html([
            'pageTitle' => $page->title(),
            'hero'      => PageSections::hero($page, 'hero'),
            'sections'  => PageSections::textSections($page, [
                'address', 'representatives', 'register', 'vat', 'disclaimer', 'links', 'copyright',
            ]),
        ]);
    }

    /** Datenschutzerklärung: hero + the usual headings; an empty section is left out. */
    protected function privacyAction(): HtmlResponse
    {
        $page = $this->page('privacy');

        return $this->html([
            'pageTitle' => $page->title(),
            'hero'      => PageSections::hero($page, 'hero'),
            'sections'  => PageSections::textSections($page, [
                'controller', 'visit', 'cookies', 'contact', 'thirdParty', 'abroad', 'retention', 'rights', 'changes',
            ]),
        ]);
    }

    /**
     * The page's content document — 404 when it is missing or inactive: a
     * starter page is deleted by deleting its documents, and an old link or a
     * search engine then gets "not found", not an error.
     */
    private function page(string $slug): ContentView
    {
        return PageContent::find($slug)
            ?? throw new NotFoundException("Page '{$slug}' has no active content document.");
    }
}
