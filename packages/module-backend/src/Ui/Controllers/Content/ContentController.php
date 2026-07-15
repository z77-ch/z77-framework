<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Content\BlockRegistry,
    Z77\Shared\Entities\Content,
    Z77\Shared\Repositories\ContentRepository,
    Z77\Shared\Validators\ContentValidator
;

/**
 * Backend editor for {@see Content} documents (slug-addressed, document storage).
 * Identity is (slug, language) — there is no int id, so edit/delete/CSRF key on
 * "<slug>.<language>". v1 scaffold: metadata (title, active) + a JSON blocks
 * textarea; the per-type visual block editor is the next step.
 * URL: /backend/content/content/{action}.
 */
class ContentController extends BackendAbstractController
{
    private function repo(): ContentRepository
    {
        return $this->em()->getRepository(Content::class);
    }

    private function csrfKey(Content $content): string
    {
        return $content->getSlug() . '.' . $content->getLanguage();
    }

    protected function listAction(): HtmlResponse
    {
        // `?language=` is only the switch trigger; the active editing language is
        // session-sticky (see docs/topics/content.md). The list is then scoped to
        // that one language so de/fr documents never mix in the same view.
        $switch = (string)DI::getRequest()->getGetParameter('language');
        if ($switch !== '') {
            $this->setContentEditLanguage($switch);
        }
        $language = $this->contentEditLanguage();

        $contents = array_values(array_filter(
            $this->repo()->findAll(),
            fn(Content $c) => $c->getLanguage() === $language
        ));
        usort($contents, fn(Content $a, Content $b) => $a->getSlug() <=> $b->getSlug());

        $response = $this->html([
            'contents'      => $contents,
            'editLanguage'  => $language,
            'editLanguages' => DI::getI18n()->getLanguages(),
        ]);
        // content/editor styles the modal block editor; modal CSS must be present
        // on the full page that opens it (a fetch-loaded modal cannot pull its own
        // stylesheet). List/tree + button styles now live in base.css (always loaded).
        $this->layoutManager->addCss('content/editor', self::NAMESPACE);
        // Header band (hc1 = add action, hc2 = language switcher) is auto-loaded by convention from
        // list.hc1.tpl.php / list.hc2.tpl.php — see BackendAbstractController::loadHeaderSlots().
        return $response;
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        // A new document inherits the active editing language (the mode is the
        // single source — no per-document language picker, so content cannot be
        // entered under the wrong language). Switch the mode to add another.
        $content = new Content();
        $content->setLanguage($this->contentEditLanguage());

        return $this->edit($content, true);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $slug = (string)DI::getRequest()->getGetParameter('slug');
        $lang = (string)DI::getRequest()->getGetParameter('language');

        $content = ($slug !== '' && $lang !== '') ? $this->repo()->findBySlug($slug, $lang) : null;
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        return $this->edit($content, false);
    }

    private function edit(Content $content, bool $isNew): HtmlResponse|FetchResponse
    {
        $registry   = BlockRegistry::assemble();
        $knownTypes = $registry->types();
        $origKey    = $this->csrfKey($content); // identity captured before hydration
        $rawBlocks  = '';

        if (DI::getRequest()->isPost()) {
            $body      = DI::getRequest()->getJsonBody();
            $rawBlocks = is_string($body['blocks'] ?? null) ? $body['blocks'] : '';

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'content', $origKey)) {
                    return $this->fetchError('Invalid token');
                }
            }

            $content->mapFromArray(BodyCleaner::cleanFor(Content::class, $body));

            // slug + language are the identity — immutable on edit (renaming would
            // orphan the old file). Force them back from the loaded record so a
            // crafted body cannot rename.
            if (!$isNew) {
                [$slug, $lang] = explode('.', $origKey, 2) + ['', ''];
                $content->setSlug($slug);
                $content->setLanguage($lang);
            } else {
                // A new document's language is the editing mode, not the (read-only)
                // form field — a crafted body cannot place it under another language.
                $content->setLanguage($this->contentEditLanguage());
            }
        }

        $validator = new ContentValidator($content, $knownTypes, $this->repo(), $isNew, $rawBlocks);

        if (DI::getRequest()->isPost() && $validator->isValid()) {
            $this->em()->persist($content);
            $this->em()->flush();

            $this->messageService->pushFlashAfterRedirect(
                'success',
                $isNew
                    ? 'Inhalt «' . $content->getSlug() . '» angelegt'
                    : 'Inhalt «' . $content->getSlug() . '» gespeichert'
            );
            return $this->fetch()
                ->setStatus('success')
                ->addCommand('close-modal')
                ->addCommand('reload');
        }

        $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('content', $origKey) : '';

        $response = $this->html([
            'content'    => $content,
            'isNew'      => $isNew,
            'knownTypes' => $knownTypes,
            'schemas'    => $registry->schemas(),
            'entityCsrf' => $entityCsrf,
            'validator'  => $validator,
            'rawBlocks'  => $rawBlocks,
        ]);
        $this->layoutManager->addPartials('edit', 'Content/ContentController', self::NAMESPACE);
        $response->addCommand('load-script', [
            'src'   => $this->layoutManager->resolveJsPath('content/editor', self::NAMESPACE),
            'init'  => 'content-editor',
            'scope' => '[data-z77-popup-body]',
        ]);
        return $response;
    }

    protected function confirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $slug = (string)DI::getRequest()->getGetParameter('slug');
        $lang = (string)DI::getRequest()->getGetParameter('language');

        $content = ($slug !== '' && $lang !== '') ? $this->repo()->findBySlug($slug, $lang) : null;
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        $entityCsrf = DI::getCsrfService()->generateEntityToken('content', $this->csrfKey($content));

        $response = $this->html(['content' => $content, 'entityCsrf' => $entityCsrf]);
        $this->layoutManager->addPartials('confirmDelete', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    /** Per-row action hub (the list row's ⋮): edit + delete. slug+language keyed. Mirrors the DMS drive actions hub. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $slug    = (string)DI::getRequest()->getGetParameter('slug');
        $lang    = (string)DI::getRequest()->getGetParameter('language');
        $content = ($slug !== '' && $lang !== '') ? $this->repo()->findBySlug($slug, $lang) : null;
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        $response = $this->html(['entry' => $content]);
        $this->layoutManager->addPartials('actions', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $slug = (string)($body['slug'] ?? '');
        $lang = (string)($body['language'] ?? '');

        if ($slug === '' || $lang === '') {
            return $this->fetchError('Missing slug/language');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'content', $slug . '.' . $lang)) {
            return $this->fetchError('Invalid token');
        }

        $content = $this->repo()->findBySlug($slug, $lang);
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        $this->em()->remove($content);

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('reload');
    }

    /** Inline active toggle from the list view (global CSRF, no entity token — non-destructive). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $slug = (string)DI::getRequest()->getGetParameter('slug');
        $lang = (string)DI::getRequest()->getGetParameter('language');

        $content = ($slug !== '' && $lang !== '') ? $this->repo()->findBySlug($slug, $lang) : null;
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        $content->setActive(!$content->isActive());
        $this->em()->persist($content);
        $this->em()->flush();

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('set-class', [
                'target' => '[data-content-slug="' . $content->getSlug() . '"][data-content-lang="' . $content->getLanguage() . '"]',
                'class'  => 'be-tree__node--inactive',
                'on'     => !$content->isActive(),
            ]);
    }
}
