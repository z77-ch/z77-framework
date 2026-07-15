<?php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController,
    Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Shared\Services\ContentService
;

class IndexController extends AbstractFrontendController
{
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

    protected function contactAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Contact']);
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
