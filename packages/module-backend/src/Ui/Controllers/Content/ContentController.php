<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Persistence\Concurrency\EntityStateHash,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Content\BlockRegistry,
    Z77\Shared\Content\ContentExtensions,
    Z77\Shared\Content\ContentPreview,
    Z77\Shared\Entities\Content,
    Z77\Shared\Repositories\ContentRepository,
    Z77\Shared\Services\ContentVariantService,
    Z77\Shared\Validators\ContentValidator
;

/**
 * Backend editor for {@see Content} documents (slug-addressed, document storage).
 * Identity is (slug, language, variant) — there is no int id, so edit/delete/CSRF
 * key on "<slug>.<language>" for the live copy and "<slug>.<language>.<variant>"
 * for a variant (ADR-044 addendum; every request carries `variant`, '' = live).
 * Variants are created from a live row, previewed on the website with ?preview=
 * and published per set through {@see ContentVariantService}. Every live write
 * (save, add, active switch, publish, restore) goes through that service, which
 * keeps the previous live copy as a version `v-…` and stamps changedBy/changedAt
 * (ADR-045); a version is not edited, only restored or (ADMIN) deleted.
 * Access (backendConfig): EDITOR for all actions, ADMIN for confirmDelete/remove.
 * Metadata (title, active) + the visual block editor built
 * from each BlockRenderer::schema(). A slug with a blueprint (ADR-044) is edited
 * in blueprint mode: fixed slots, no block add/remove/reorder, enforced on save.
 * Saves are guarded by an optimistic lock (entity_hash).
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
        $key = $content->getSlug() . '.' . $content->getLanguage();

        return $content->isLive() ? $key : $key . '.' . $content->getVariant();
    }

    /** Username stamped on a live write (ADR-045). */
    private function username(): string
    {
        return DI::getAuthService()->getCurrentUser()->getUserName();
    }

    /** May the current user delete (a document, variant, version)? Same rule as the access config. */
    private function canDelete(): bool
    {
        $auth = DI::getAuthService();

        return $auth->canReach($auth->getCurrentUser(), 'backend', 'content', 'content', 'confirm-delete');
    }

    /** The document named by the GET parameters slug, language, variant ('' = live). */
    private function fromQuery(): ?Content
    {
        $slug    = (string)DI::getRequest()->getGetParameter('slug');
        $lang    = (string)DI::getRequest()->getGetParameter('language');
        $variant = ContentPreview::normalize((string)DI::getRequest()->getGetParameter('variant'));

        return ($slug !== '' && $lang !== '') ? $this->repo()->findBySlug($slug, $lang, $variant) : null;
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
        // By slug; per slug the live copy first, then its variants by key, then
        // its versions newest first (the key carries the archiving time).
        $rank = fn(Content $c): int => $c->isLive() ? 0 : ($c->isVersion() ? 2 : 1);
        usort($contents, function (Content $a, Content $b) use ($rank): int {
            $order = [$a->getSlug(), $rank($a)] <=> [$b->getSlug(), $rank($b)];
            if ($order !== 0) {
                return $order;
            }
            return $a->isVersion()
                ? strcmp($b->getVariant(), $a->getVariant())
                : strcmp($a->getVariant(), $b->getVariant());
        });

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
        // Always the live copy; variants come from «Variante anlegen».
        $content = new Content();
        $content->setLanguage($this->contentEditLanguage());
        $content->setVariant('');

        return $this->edit($content, true);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $content = $this->fromQuery();
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }
        // A version is history: it is restored, not changed.
        if ($content->isVersion()) {
            return $this->fetchError('Eine Version wird nicht bearbeitet — erst wiederherstellen.');
        }

        return $this->edit($content, false);
    }

    private function edit(Content $content, bool $isNew): HtmlResponse|FetchResponse
    {
        $registry     = BlockRegistry::assemble();
        $knownTypes   = $registry->types();
        $schemas      = $registry->schemas();
        $extensions   = ContentExtensions::assemble();
        $origKey      = $this->csrfKey($content); // identity captured before hydration
        $origSlug     = $content->getSlug();
        $origLang     = $content->getLanguage();
        $origVariant  = $content->getVariant();
        $origChangedBy = $content->getChangedBy();
        $origChangedAt = $content->getChangedAt();
        $storedBlocks = $content->getBlocks();     // orphans are always taken from here
        $rawBlocks    = '';
        $validator    = null;
        // The hash of the state the form was rendered from. GET: the loaded
        // document; after a failed POST the SUBMITTED hash is re-issued unchanged
        // (pattern NavigationController) — a conflict stays a conflict until reload.
        $entityHash   = '';

        if (DI::getRequest()->isPost()) {
            $body      = DI::getRequest()->getJsonBody();
            $rawBlocks = is_string($body['blocks'] ?? null) ? $body['blocks'] : '';

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'content', $origKey)) {
                    return $this->fetchError('Invalid token');
                }
            }

            // Built BEFORE mapFromArray: the lock compares the submitted hash with the
            // STORED state, which $content still carries at this point.
            $validator = new ContentValidator($content, $knownTypes, $this->repo(), $isNew, $rawBlocks, $schemas);
            if (!$isNew) {
                $entityHash = trim($body['entity_hash'] ?? '');
                $validator->guardStoredState($entityHash);
            }

            $content->mapFromArray(BodyCleaner::cleanFor(Content::class, $body));

            // slug + language + variant are the identity — immutable on edit
            // (renaming would orphan the old file). BodyCleaner passes `variant`
            // through like any other field, so all three are forced back from the
            // loaded record: a crafted body can neither rename a document nor move
            // it into another set.
            // changedBy/changedAt pass BodyCleaner too; they are the server's
            // (ADR-045) — back to the stored values, a live save stamps anew.
            $content->setChangedBy($origChangedBy);
            $content->setChangedAt($origChangedAt);
            if (!$isNew) {
                $content->setSlug($origSlug);
                $content->setLanguage($origLang);
                $content->setVariant($origVariant);
            } else {
                // A new document's language is the editing mode, not the (read-only)
                // form field — a crafted body cannot place it under another language.
                // And it is the live copy, whatever the body says.
                $content->setLanguage($this->contentEditLanguage());
                $content->setVariant('');
            }

            // Blueprint document: save exactly its slots, whatever the body says.
            $blueprint = $extensions->blueprint($content->getSlug());
            if ($blueprint !== null) {
                $content->setBlocks($blueprint->enforce($content->getBlocks(), $storedBlocks, $schemas));
            }
            $validator->useBlueprint($blueprint);
        }

        $validator ??= new ContentValidator($content, $knownTypes, $this->repo(), $isNew, $rawBlocks, $schemas);
        $blueprint   = $extensions->blueprint($content->getSlug());

        if (DI::getRequest()->isPost() && $validator->isValid()) {
            if ($content->isLive()) {
                // Archives the stored live copy as a version, stamps this one.
                ContentVariantService::create()->saveLive($content, $this->username());
            } else {
                // A variant save: no version, no stamp (ADR-045).
                $this->em()->persist($content);
                $this->em()->flush();
            }

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
        if (!$isNew && !DI::getRequest()->isPost()) {
            $entityHash = EntityStateHash::of($content);
        }

        $response = $this->html([
            'content'    => $content,
            'isNew'      => $isNew,
            'knownTypes' => $knownTypes,
            'schemas'    => $schemas,
            'blueprint'  => $blueprint,
            'actions'    => $extensions->actions(),
            'entityCsrf' => $entityCsrf,
            'entityHash' => $entityHash,
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
        $content = $this->fromQuery();
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        $entityCsrf = DI::getCsrfService()->generateEntityToken('content', $this->csrfKey($content));

        $response = $this->html(['content' => $content, 'entityCsrf' => $entityCsrf]);
        $this->layoutManager->addPartials('confirmDelete', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    /**
     * Per-row action hub (the list row's ⋮): edit + delete, plus «Variante anlegen»
     * on a live row and «Vorschau öffnen» / «Satz veröffentlichen» on a variant.
     * slug+language+variant keyed. Mirrors the DMS drive actions hub.
     */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $content = $this->fromQuery();
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        // The website start page in the document's language, with the set's key.
        // A backend request has no ?preview= of its own, so localizedUrl() adds
        // none; the key is added here, explicitly.
        $previewUrl = $content->isLive()
            ? ''
            : ContentPreview::carry(localizedUrl('/', $content->getLanguage()), $content->getVariant());

        $response = $this->html([
            'entry'      => $content,
            'previewUrl' => $previewUrl,
            'canDelete'  => $this->canDelete(),
        ]);
        $this->layoutManager->addPartials('actions', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body    = DI::getRequest()->getJsonBody();
        $slug    = (string)($body['slug'] ?? '');
        $lang    = (string)($body['language'] ?? '');
        $variant = ContentPreview::normalize((string)($body['variant'] ?? ''));

        if ($slug === '' || $lang === '') {
            return $this->fetchError('Missing slug/language');
        }

        $content = $this->repo()->findBySlug($slug, $lang, $variant);
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        // Checked against the key of the record found — the key confirmDeleteAction
        // bound the token to (slug.lang, or slug.lang.variant for a variant).
        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'content', $this->csrfKey($content))) {
            return $this->fetchError('Invalid token');
        }

        $this->em()->remove($content);

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('reload');
    }

    /**
     * Inline active toggle from the list view (global CSRF, no entity token —
     * non-destructive). On a live copy it is a live write like any save, so it
     * leaves a version (ADR-045: every live save); a version itself is not switched.
     */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $content = $this->fromQuery();
        if ($content === null) {
            return $this->fetchError('Inhalt nicht gefunden');
        }
        if ($content->isVersion()) {
            return $this->fetchError('Eine Version wird nicht geändert — erst wiederherstellen.');
        }

        $content->setActive(!$content->isActive());
        if ($content->isLive()) {
            ContentVariantService::create()->saveLive($content, $this->username());
        } else {
            $this->em()->persist($content);
            $this->em()->flush();
        }

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('set-class', [
                'target' => '[data-content-slug="' . $content->getSlug() . '"][data-content-lang="' . $content->getLanguage() . '"]'
                          . '[data-content-variant="' . $content->getVariant() . '"]',
                'class'  => 'be-tree__node--inactive',
                'on'     => !$content->isActive(),
            ]);
    }

    /**
     * «Variante anlegen» (live rows only): copies the live document into an
     * existing set or a new one. GET renders the modal, POST creates the copy.
     */
    protected function createVariantAction(): HtmlResponse|FetchResponse
    {
        $content = $this->fromQuery();
        if ($content === null || !$content->isLive()) {
            return $this->fetchError('Inhalt nicht gefunden');
        }

        // The sets this document can join: every existing set that does not hold it yet.
        $sets = array_values(array_filter(
            $this->repo()->variantKeys(),
            fn(string $key) => $this->repo()->findBySlug($content->getSlug(), $content->getLanguage(), $key) === null
        ));

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            $csrf = trim($body['entity_csrf'] ?? '');
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'content', $this->csrfKey($content))) {
                return $this->fetchError('Invalid token');
            }

            // '' = «Neuer Satz» (name + random tail). Otherwise it must be one of
            // the offered sets — a crafted key cannot skip the random tail.
            $set = (string)($body['set'] ?? '');
            if ($set === '') {
                $key = ContentPreview::newKey((string)($body['name'] ?? ''));
            } elseif (in_array($set, $sets, true)) {
                $key = $set;
            } else {
                return $this->fetchError('Satz nicht verfügbar');
            }

            try {
                ContentVariantService::create()->createVariant($content, $key);
            } catch (\DomainException $e) {
                return $this->fetchError($e->getMessage());
            }

            $this->messageService->pushFlashAfterRedirect(
                'success',
                'Variante «' . $key . '» von «' . $content->getSlug() . '» angelegt'
            );
            return $this->fetch()
                ->setStatus('success')
                ->addCommand('close-modal')
                ->addCommand('reload');
        }

        $response = $this->html([
            'content'    => $content,
            'sets'       => $sets,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('content', $this->csrfKey($content)),
        ]);
        $this->layoutManager->addPartials('createVariant', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    /** «Satz veröffentlichen»: confirm modal listing every document of the set, all languages. */
    protected function confirmPublishAction(): HtmlResponse|FetchResponse
    {
        $key       = ContentPreview::normalize((string)DI::getRequest()->getGetParameter('variant'));
        $documents = $this->repo()->findByVariant($key);
        if ($documents === []) {
            return $this->fetchError('Satz nicht gefunden');
        }
        usort($documents, fn(Content $a, Content $b) =>
            [$a->getSlug(), $a->getLanguage()] <=> [$b->getSlug(), $b->getLanguage()]);

        $response = $this->html([
            'setKey'     => $key,
            'documents'  => $documents,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('content-set', $key),
        ]);
        $this->layoutManager->addPartials('confirmPublish', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    /**
     * Publishes a set — destructive (the live copies are replaced), so the entity
     * token is bound to the set key. The previous live copies become versions
     * `v-…` (one key per run); see ContentVariantService::publish().
     */
    #[Fetch, HttpMethod('POST')]
    protected function publishAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $key  = ContentPreview::normalize((string)($body['variant'] ?? ''));
        if ($key === '') {
            return $this->fetchError('Missing variant');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'content-set', $key)) {
            return $this->fetchError('Invalid token');
        }

        try {
            $report = ContentVariantService::create()->publish($key, $this->username());
        } catch (\DomainException $e) {
            return $this->fetchError($e->getMessage());
        }

        $count = count($report['published']);
        $text  = 'Satz «' . $key . '» veröffentlicht (' . $count . ' Dokument' . ($count === 1 ? '' : 'e') . ')';
        if ($report['archive'] !== '') {
            $text .= ' — bisherige Fassungen als Version «' . $report['archive'] . '» gesichert';
        }
        $this->messageService->pushFlashAfterRedirect('success', $text);

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->addCommand('reload');
    }

    /** «Wiederherstellen» (version rows only): confirm modal. */
    protected function confirmRestoreAction(): HtmlResponse|FetchResponse
    {
        $version = $this->fromQuery();
        if ($version === null || !$version->isVersion()) {
            return $this->fetchError('Version nicht gefunden');
        }

        $response = $this->html([
            'version'    => $version,
            'live'       => $this->repo()->findBySlug($version->getSlug(), $version->getLanguage()),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('content', $this->csrfKey($version)),
        ]);
        $this->layoutManager->addPartials('confirmRestore', 'Content/ContentController', self::NAMESPACE);
        return $response;
    }

    /**
     * Makes a version the live copy again (EDITOR, like any live save). The
     * current live copy becomes a version first; see ContentVariantService::restore().
     */
    #[Fetch, HttpMethod('POST')]
    protected function restoreAction(): FetchResponse
    {
        $body    = DI::getRequest()->getJsonBody();
        $slug    = (string)($body['slug'] ?? '');
        $lang    = (string)($body['language'] ?? '');
        $variant = ContentPreview::normalize((string)($body['variant'] ?? ''));

        $version = ($slug !== '' && $lang !== '') ? $this->repo()->findBySlug($slug, $lang, $variant) : null;
        if ($version === null || !$version->isVersion()) {
            return $this->fetchError('Version nicht gefunden');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'content', $this->csrfKey($version))) {
            return $this->fetchError('Invalid token');
        }

        try {
            $archive = ContentVariantService::create()->restore($version, $this->username());
        } catch (\DomainException $e) {
            return $this->fetchError($e->getMessage());
        }

        $text = 'Version von «' . $version->getSlug() . '» wiederhergestellt';
        if ($archive !== '') {
            $text .= ' — bisherige Fassung als Version «' . $archive . '» gesichert';
        }
        $this->messageService->pushFlashAfterRedirect('success', $text);

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->addCommand('reload');
    }
}
