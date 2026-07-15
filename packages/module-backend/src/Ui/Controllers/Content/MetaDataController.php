<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\MetaData,
    Z77\Shared\Entities\Navigation,
    Z77\Shared\Repositories\MetaDataRepository,
    Z77\Shared\Repositories\NavigationRepository,
    Z77\Shared\Validators\MetaDataValidator
;

/**
 * Backend editor for per-page SEO {@see MetaData}. The list is "by navigation
 * point": every routable navigation entry is shown with the status of its
 * metadata (present / missing) for the active editing language. Identity is
 * (navigation_id, language) — immutable on edit (like {@see ContentController}'s
 * (slug, language)); the int `id` keys edit/delete + entity CSRF.
 *
 * URL: /backend/content/meta-data/{action}.
 *
 * Multi-language follows the shared editing-language mode (CONTENT-LANG-001, see
 * docs/topics/content.md): the list is scoped to the session-sticky editing
 * language ({@see BackendAbstractController::contentEditLanguage()}), `?language=`
 * is only the switch trigger, and a new record inherits the mode language. The
 * `?env=` filter (public environment) is orthogonal and preserved across switches.
 */
class MetaDataController extends BackendAbstractController
{
    private function repo(): MetaDataRepository
    {
        return $this->em()->getRepository(MetaData::class);
    }

    private function navRepo(): NavigationRepository
    {
        return $this->em()->getRepository(Navigation::class);
    }

    protected function listAction(): HtmlResponse
    {
        // Editing-language mode (CONTENT-LANG-001) — shared with ContentController:
        // `?language=` only switches, the active language is session-sticky. The
        // per-page metadata status below is read for this one language.
        $switch = (string)DI::getRequest()->getGetParameter('language');
        if ($switch !== '') {
            $this->setContentEditLanguage($switch);
        }
        $lang = $this->contentEditLanguage();
        // SEO metadata only applies to PUBLIC view areas (modules whose config
        // carries `'public' => true`). The admin backend is a view area but not
        // public → excluded. A view area's name === its module key (invariant), so a
        // page belongs to the view area named after its module. View areas + their
        // labels are config now (ModuleManager, ADR-022), not NavigationGroup rows.
        $mm         = DI::getModuleManager();
        $publicKeys = $mm->getPublicViewAreaKeys();

        // Optional ?env filter: restrict to one public environment (the filter bar
        // links here). Unknown / empty → all public environments.
        $envFilter = (string)DI::getRequest()->getGetParameter('env');
        if ($envFilter !== '' && !in_array($envFilter, $publicKeys, true)) {
            $envFilter = '';
        }

        // Routable navigation entries only — a metadata record describes a page,
        // not a container/opener (empty canonical URL) or a UI-only ref.
        $pages = array_filter(
            $this->navRepo()->findAll(),
            fn(Navigation $n) => $n->getRef() === null && $n->getCanonicalUrl() !== ''
        );

        // One section per public view area (config order), each carrying its pages
        // (sorted by name) + metadata status. The filter bar lists every public view
        // area (independent of the active filter).
        $environments = [];
        $groups       = [];
        foreach ($publicKeys as $name) {
            $environments[] = ['key' => $name, 'label' => $mm->getViewAreaLabel($name)];

            if ($envFilter !== '' && $name !== $envFilter) continue;     // active filter

            $envPages = array_values(array_filter($pages, fn(Navigation $n) => $n->getModule() === $name));
            usort($envPages, fn(Navigation $a, Navigation $b) => strcasecmp($a->getName(), $b->getName()));

            $rows = [];
            foreach ($envPages as $page) {
                $rows[] = [
                    'page' => $page,
                    'meta' => $this->repo()->findByNavigationAndLanguage($page->getId(), $lang),
                ];
            }
            $groups[] = ['key' => $name, 'label' => $mm->getViewAreaLabel($name), 'rows' => $rows];
        }

        $response = $this->html([
            'groups'        => $groups,
            'environments'  => $environments,
            'envFilter'     => $envFilter,
            'editLanguage'  => $lang,
            'editLanguages' => DI::getI18n()->getLanguages(),
        ]);
        return $response;
    }

    /**
     * Per-row action hub (the list row's ⋮). Present → edit + delete (by meta id);
     * absent → create (by navigation_id). Language comes from the session mode (the
     * list is scoped to it). Mirrors the DMS drive actions hub.
     */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $navId = (int)DI::getRequest()->getGetParameter('navigation_id');
        $page  = $navId ? $this->navRepo()->find($navId) : null;
        if ($page === null) {
            return $this->fetchError('Seite nicht gefunden');
        }

        $response = $this->html([
            'page' => $page,
            'meta' => $this->repo()->findByNavigationAndLanguage($navId, $this->contentEditLanguage()),
        ]);
        $this->layoutManager->addPartials('actions', 'Content/MetaDataController', self::NAMESPACE);
        return $response;
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        $navigationId = (int)DI::getRequest()->getGetParameter('navigation_id');

        // A new record inherits the active editing language (the mode is the single
        // source — see ContentController). To author another language, switch mode.
        $meta = new MetaData();
        if ($navigationId > 0) {
            $meta->setNavigationId($navigationId);
        }
        $meta->setLanguage($this->contentEditLanguage());

        return $this->edit($meta, true);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $meta = $id ? $this->repo()->find($id) : null;

        if ($meta === null) {
            return $this->fetchError('Metadaten nicht gefunden');
        }

        return $this->edit($meta, false);
    }

    private function edit(MetaData $meta, bool $isNew): HtmlResponse|FetchResponse
    {
        $rawLd = '';

        if (DI::getRequest()->isPost()) {
            $body  = DI::getRequest()->getJsonBody();
            $rawLd = is_string($body['application_ld'] ?? null) ? $body['application_ld'] : '';

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'metadata', $meta->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            // identity captured before hydration (navigation_id + language are
            // immutable on edit — changing them would orphan / duplicate a record)
            $origNavId = $meta->getNavigationId();
            $origLang  = $meta->getLanguage();

            $meta->mapFromArray(BodyCleaner::cleanFor(MetaData::class, $body));

            if (!$isNew) {
                $meta->setNavigationId($origNavId);
                $meta->setLanguage($origLang);
            } else {
                // language follows the editing mode, never the (read-only) form field
                $meta->setLanguage($this->contentEditLanguage());
            }
        }

        $validator = new MetaDataValidator($meta, $this->repo(), $this->navRepo(), $isNew, $rawLd);

        if (DI::getRequest()->isPost() && $validator->isValid()) {
            $this->em()->persist($meta);
            $this->em()->flush();

            $page = $this->navRepo()->find($meta->getNavigationId());
            $name = $page?->getName() ?? ('#' . $meta->getNavigationId());

            $this->messageService->pushFlashAfterRedirect(
                'success',
                $isNew
                    ? 'Metadaten für «' . $name . '» angelegt'
                    : 'Metadaten für «' . $name . '» gespeichert'
            );
            return $this->fetch()
                ->setStatus('success')
                ->addCommand('close-modal')
                ->addCommand('reload');
        }

        $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('metadata', $meta->getId()) : '';

        // The page this metadata belongs to — for the form header / locked identity.
        $page = $meta->getNavigationId() ? $this->navRepo()->find($meta->getNavigationId()) : null;

        $response = $this->html([
            'meta'       => $meta,
            'page'       => $page,
            'isNew'      => $isNew,
            'entityCsrf' => $entityCsrf,
            'validator'  => $validator,
            'rawLd'      => $rawLd,
        ]);
        $this->layoutManager->addPartials('edit', 'Content/MetaDataController', self::NAMESPACE);
        return $response;
    }

    protected function confirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $meta = $id ? $this->repo()->find($id) : null;

        if ($meta === null) {
            return $this->fetchError('Metadaten nicht gefunden');
        }

        $page       = $this->navRepo()->find($meta->getNavigationId());
        $entityCsrf = DI::getCsrfService()->generateEntityToken('metadata', $id);

        $response = $this->html(['meta' => $meta, 'page' => $page, 'entityCsrf' => $entityCsrf]);
        $this->layoutManager->addPartials('confirmDelete', 'Content/MetaDataController', self::NAMESPACE);
        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = !empty($body['id']) ? (int)$body['id'] : null;

        if (!$id) {
            return $this->fetchError('Missing id');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'metadata', $id)) {
            return $this->fetchError('Invalid token');
        }

        $meta = $this->repo()->find($id);
        if ($meta === null) {
            return $this->fetchError('Metadaten nicht gefunden');
        }

        $this->em()->remove($meta);
        $this->em()->flush();

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('reload');
    }
}
