<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
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
            'aliases' => $this->repo()->findAll(),
            'navById' => $navById,
        ]);
        return $response;
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

                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    $isNew
                        ? 'Alias «' . $alias->getPath() . '» angelegt'
                        : 'Alias «' . $alias->getPath() . '» gespeichert'
                );
                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $alias->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('reload');
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
