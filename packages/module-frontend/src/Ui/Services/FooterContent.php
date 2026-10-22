<?php

namespace Z77\Module\Frontend\Ui\Services;

use Z77\Shared\Content\PageContent;

/**
 * Footer texts — the shared content document `footer` (data/content/footer.<lang>.json,
 * blueprint in frontendConfig), edited in the backend (Webseiten › Inhalte) and
 * on the page.
 *
 * Called by partials/footer itself, not passed by the actions: the footer is a
 * layout element rendered on every page, also on pages of controllers that know
 * nothing about it — the same way it reads the navigation. A project with its
 * own footer partial never calls it. Without the document the footer renders
 * without these texts (PageContent::find(), no exception).
 */
final class FooterContent
{
    private function __construct() {}

    /** @return array{tagline:string,copyright:string,edit:string} */
    public static function data(): array
    {
        $view = PageContent::find('footer');
        if ($view === null) {
            return ['tagline' => '', 'copyright' => '', 'edit' => ''];
        }
        $footer = $view->keyed('footer');

        return [
            'tagline'   => trim((string)$footer->get('tagline')),
            'copyright' => trim((string)$footer->get('copyright')),
            'edit'      => $view->editAttribute('footer'),
        ];
    }
}
