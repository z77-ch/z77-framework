<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\Navigation,
    Z77\Shared\Entities\NavigationAlias,
    Z77\Shared\Repositories\NavigationRepository,
    Z77\Shared\Repositories\NavigationAliasRepository,
    Z77\Shared\Validators\NavigationAliasValidator
;

/**
 * Manages {@see NavigationAlias} rows — the canonical entry URLs bound to a
 * navigation entry (ADR-015). Plain CRUD (not a tree): no move/reorder.
 * URL: /backend/content/navigation-alias/{action}. Entity-CSRF scope `navigationAlias`.
 */
class NavigationAliasController extends BackendAbstractController
{
    private function repo(): NavigationAliasRepository
    {
        return $this->em()->getRepository(NavigationAlias::class);
    }

    private function navRepo(): NavigationRepository
    {
        return $this->em()->getRepository(Navigation::class);
    }

    /** Routable navigation entries (the valid alias targets) for the select. @return Navigation[] */
    private function navOptions(): array
    {
        return array_values(array_filter(
            $this->navRepo()->findAll(),
            fn(Navigation $n) => $n->getRef() === null && $n->getCanonicalPath() !== ''
        ));
    }

    protected function listAction(): HtmlResponse
    {
        $navById = [];
        foreach ($this->navRepo()->findAll() as $n) {
            $navById[$n->getId()] = $n;
        }

        $response = $this->html([
            'rows' => array_map(fn(NavigationAlias $a) => $this->aliasDisplay($a, $navById), $this->repo()->findAll()),
        ]);
        return $response;
    }

    /**
     * Display view-model for one alias row. SINGLE source for the list render AND the
     * in-place answer of a save (`update-fields`) — the two must show the same thing.
     *
     * @param array<int, Navigation>|null $navById  null → looked up for this one alias
     * @return array{id:?int, path:string, navLabel:string, flags:string, active:bool}
     */
    private function aliasDisplay(NavigationAlias $alias, ?array $navById = null): array
    {
        $nav = $navById !== null
            ? ($navById[$alias->getNavigationId()] ?? null)
            : $this->navRepo()->find($alias->getNavigationId());

        // Pre-escaped: it becomes HTML in the list cell and in the `update-fields` answer.
        $flags = ($alias->isCanonical() ? '<span class="be-tree__ref-label">canonical</span>' : '')
               . ($alias->acceptsSlugs() ? '<span class="be-tree__ref-label">/…</span>' : '');

        return [
            'id'       => $alias->getId(),
            'path'     => $alias->getPath(),
            'navLabel' => '→ ' . ($nav ? $nav->getName() . ' (' . $nav->getCanonicalPath() . ')' : '#' . $alias->getNavigationId() . ' — fehlt'),
            'flags'    => $flags,
            'active'   => $alias->isActive(),
        ];
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->edit(new NavigationAlias());
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id    = (int)DI::getRequest()->getGetParameter('id');
        $alias = $id ? $this->repo()->find($id) : null;
        if ($alias === null) {
            return $this->fetchError('Alias nicht gefunden');
        }
        return $this->edit($alias);
    }

    private function edit(NavigationAlias $alias): HtmlResponse|FetchResponse
    {
        $isNew     = $alias->getId() === null;
        $validator = new NavigationAliasValidator($alias, $this->repo(), $this->navRepo());

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'navigationAlias', $alias->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $alias->mapFromArray(BodyCleaner::cleanFor(NavigationAlias::class, $body));

            if ($validator->isValid()) {
                $this->em()->persist($alias);
                $this->em()->flush();

                if ($isNew) {
                    // One new row: appended to the (unsorted, storage-order) list through the
                    // same `_row` partial the list renders; the empty notice goes.
                    $this->messageService->pushFlash('success', 'Alias «' . $alias->getPath() . '» angelegt');
                    return $this->fetch()
                        ->setStatus('success')
                        ->setData(['id' => $alias->getId()])
                        ->insertRow('navigationAlias', (new TemplateRenderer(self::NAMESPACE))
                            ->partial('Content/NavigationAliasController/_row', ['row' => $this->aliasDisplay($alias)]))
                        ->addCommand('remove-element', ['target' => '[data-entity-empty="navigationAlias"]'])
                        ->addCommand('close-modal');
                }

                // One row changed: update its cells in place (ADR-047 addendum 2026-10-10).
                $display = $this->aliasDisplay($alias);
                $target  = FetchResponse::rowTarget('navigationAlias', $alias->getId());

                $this->messageService->pushFlash('success', 'Alias «' . $alias->getPath() . '» gespeichert');
                return $this->fetch()
                    ->setStatus('success')
                    ->setData([
                        'id'        => $alias->getId(),
                        'path'      => $display['path'],
                        'nav_label' => $display['navLabel'],
                        'flags'     => $display['flags'],
                    ])
                    ->addCommand('update-fields', [
                        'target' => $target,
                        'fields' => ['path' => 'text', 'nav_label' => 'text', 'flags' => 'html'],
                    ])
                    ->addCommand('set-class', [
                        'target' => $target,
                        'class'  => 'be-tree__node--inactive',
                        'on'     => !$alias->isActive(),
                    ])
                    ->addCommand('close-modal');
            }
        }

        $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('navigationAlias', $alias->getId()) : '';

        $response = $this->html([
            'alias'      => $alias,
            'navOptions' => $this->navOptions(),
            'entityCsrf' => $entityCsrf,
            'validator'  => $validator,
        ]);
        $this->layoutManager->addPartials('edit', 'Content/NavigationAliasController', self::NAMESPACE);
        return $response;
    }

    protected function confirmDeleteAction(): HtmlResponse
    {
        $id    = (int)DI::getRequest()->getGetParameter('id');
        $alias = $id ? $this->repo()->find($id) : null;

        $entityCsrf = $alias ? DI::getCsrfService()->generateEntityToken('navigationAlias', $id) : '';

        $response = $this->html(['alias' => $alias, 'entityCsrf' => $entityCsrf]);
        $this->layoutManager->addPartials('confirmDelete', 'Content/NavigationAliasController', self::NAMESPACE);
        return $response;
    }

    /** Per-row action hub (the list row's ⋮): edit + delete. Mirrors the DMS drive actions hub. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $id    = (int)DI::getRequest()->getGetParameter('id');
        $alias = $id ? $this->repo()->find($id) : null;
        if ($alias === null) {
            return $this->fetchError('Alias nicht gefunden');
        }

        $response = $this->html(['entry' => $alias]);
        $this->layoutManager->addPartials('actions', 'Content/NavigationAliasController', self::NAMESPACE);
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
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'navigationAlias', $id)) {
            return $this->fetchError('Invalid token');
        }

        $alias = $this->repo()->find($id);
        if ($alias === null) {
            return $this->fetchError('Alias nicht gefunden');
        }

        $this->em()->remove($alias);

        $this->messageService->pushFlash('success', 'Alias «' . $alias->getPath() . '» gelöscht');
        return $this->fetch()
            ->setStatus('success')
            ->removeRow('navigationAlias', $id)
            ->addCommand('close-modal');
    }

    /** Inline active toggle from the list view (global CSRF, no entity token — non-destructive). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id    = (int)DI::getRequest()->getGetParameter('id');
        $alias = $id ? $this->repo()->find($id) : null;
        if ($alias === null) {
            return $this->fetchError('Alias nicht gefunden');
        }

        $alias->setActive(!$alias->isActive());
        $this->em()->persist($alias);
        $this->em()->flush();

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('set-class', [
                'target' => '[data-alias-id="' . $id . '"]',
                'class'  => 'be-tree__node--inactive',
                'on'     => !$alias->isActive(),
            ]);
    }
}
