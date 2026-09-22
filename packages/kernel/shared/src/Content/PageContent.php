<?php

namespace Z77\Shared\Content;

use Z77\Core\Config\AuthRole;
use Z77\Core\DI;
use Z77\Core\Http\RequestMode;
use Z77\Shared\Services\ContentService;

/**
 * The content document of a page, for the current request — the entry point a
 * frontend controller (or a service it calls) uses for designed pages
 * (ADR-044). It adds the things every such page needs and that are easy
 * to forget when ContentService::view() is called directly:
 *
 *   - the request language (with ContentService's default-language fallback);
 *   - the content preview: `?preview=<key>` renders the variant set
 *     (ContentPreview::key(), ADR-044 addendum) — called directly, the service
 *     ignores previews, silently;
 *   - a loud failure: a page whose document is missing or inactive throws. A
 *     designed page without its text is a deploy error (the documents go to
 *     the shared data/content/ BEFORE the release), and an exception says so
 *     where an empty page would hide it;
 *   - the page editor (ADR-045 §4): for a user with at least `editor` on a
 *     full page (not a fetch fragment) the view marks its slots —
 *     ContentView::editAttribute() then returns the marker, for everyone
 *     else ''. Decided here, per request, from the logged-in user; nothing is
 *     remembered.
 *
 * ContentService stays request-free; this class is where the request is read.
 * Each call builds its own ContentService (ADR-012: consumer-built, not DI).
 */
final class PageContent
{
    private function __construct() {}

    /**
     * @throws \RuntimeException when the document is missing or inactive in the
     *         request language and in the default language
     */
    public static function view(string $slug): ContentView
    {
        $language = DI::getRequest()->getLanguage();
        $preview  = ContentPreview::key();
        $view     = ContentService::create()->view($slug, $language, $preview);
        if ($view === null) {
            throw new \RuntimeException("Content document '{$slug}' ({$language}) is missing or inactive in data/content/.");
        }

        return self::editorOnPage()
            ? $view->forEditor(ContentExtensions::assemble()->blueprint($slug), $preview)
            : $view;
    }

    /** A full-page request of a user with at least `editor` (ADR-045 §4). */
    private static function editorOnPage(): bool
    {
        if (DI::getRequest()->getMode() !== RequestMode::Page) {
            return false;
        }
        $user = DI::getAuthService()->getCurrentUser();

        return $user !== null && $user->hasAtLeast(AuthRole::EDITOR);
    }
}
