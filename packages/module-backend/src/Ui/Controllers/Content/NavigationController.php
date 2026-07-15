<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\AbstractTreeEntityController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\Navigation,
    Z77\Shared\Repositories\NavigationRepository,
    Z77\Shared\Validators\NavigationValidator,
    Z77\Shared\Tree\TreeNode,
    Z77\Shared\Tree\TreeService
;

/**
 * Manages the navigation ELEMENT (the {@see Navigation} tree). Render-slots + view
 * areas are config (ModuleManager `navSlots`, ADR-022), not an editable entity — the
 * list view groups the tree by the config slots.
 *
 * The generic tree move lives in {@see AbstractTreeEntityController}; this class
 * supplies only the navigation-specific {@see applyMovePolicy()}.
 */
class NavigationController extends AbstractTreeEntityController
{
    private function repo(): NavigationRepository
    {
        return $this->em()->getRepository(Navigation::class);
    }

    private ?TreeService $navTreeSvc = null;

    /** Tree algorithms for the navigation forest (roots grouped by render-slot slug). */
    private function navTree(): TreeService
    {
        return $this->navTreeSvc ??= new TreeService(fn(Navigation $n) => $n->getSlot());
    }

    // ── AbstractTreeEntityController hooks (the move endpoint lives in the base) ──
    protected function treeRepo(): RepositoryInterface { return $this->repo(); }
    protected function treeService(): TreeService { return $this->navTree(); }

    protected function listAction(): HtmlResponse
    {
        $entries = $this->repo()->findAll();

        $mm      = DI::getModuleManager();
        $navTree = $this->navTree();
        // Each slot's tree-roots → nested display view-models (nodeTree), so the list
        // template is a pure renderer: no service calls, no ref resolution, no url/route
        // composition in the view (the same node model feeds the edit-fetch update).
        $entriesForSlot = fn(string $slot): array => array_map(
            fn(Navigation $e) => $this->nodeTree($e, $entries),
            $navTree->sort(array_filter($entries, fn(Navigation $e) => $e->getSlot() === $slot))
        );

        // Hierarchical grouping mirrors the config: view area (module) → render-slot
        // → tree-roots carrying that slot slug. Structure is config (ADR-022), not data.
        $areas    = [];
        $allSlugs = [];
        foreach ($mm->getViewAreaKeys() as $envKey) {
            $slots = [];
            $total = 0;
            foreach ($mm->getNavSlots($envKey) as $slug => $label) {
                $slotEntries = $entriesForSlot($slug);
                $total      += count($slotEntries);
                $slots[]     = ['slug' => $slug, 'label' => $label, 'entries' => $slotEntries];
                $allSlugs[]  = $slug;
            }
            $areas[] = ['key' => $envKey, 'label' => $mm->getViewAreaLabel($envKey), 'slots' => $slots, 'total' => $total];
        }

        // Display-roots not shown under any render-slot: tree-roots (parentId null)
        // whose slot is NOT a registered render-slot — true orphans (empty slot) AND
        // strays carrying an unknown/removed slug (legacy data). Surfaced so they stay
        // editable/deletable; children render nested under their parent.
        $ungrouped = array_map(
            fn(Navigation $e) => $this->nodeTree($e, $entries),
            $navTree->sort(array_filter(
                $entries,
                fn(Navigation $e) => $e->getParentId() === null && !in_array($e->getSlot(), $allSlugs, true)
            ))
        );

        $response = $this->html([
            'areas'     => $areas,
            'ungrouped' => $ungrouped,
        ]);

        $this->layoutManager->addJs('navigation/list', self::NAMESPACE);

        return $response;
    }

    /**
     * Display view-model for one navigation node: name + the composed url/route cells
     * + ref resolution, pre-escaped where it becomes HTML (`urlDisplay`). SINGLE source
     * for both the list-tree render AND the edit-fetch in-place node update — the two
     * MUST show the same thing (that duplication is exactly what this removes).
     *
     * @return array{id:?int, name:string, urlDisplay:string, route:string, isRef:bool, active:bool}
     */
    private function nodeDisplay(Navigation $node): array
    {
        $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $refId = $node->getRef();

        if ($refId !== null) {
            $target = $this->repo()->find($refId);
            if ($target !== null) {
                $urlDisplay = '<span class="be-tree__ref-label">Verweis →</span> #'
                    . $e($target->getId()) . ' · ' . $e($target->getName())
                    . '<span class="be-tree__canonical"> (' . $e($target->getUrl()) . ')</span>';
            } else {
                $urlDisplay = '<span class="be-tree__ref-label">Verweis → #'
                    . $e($refId) . ' (Ziel fehlt)</span>';
            }
            $route = '';
        } else {
            $urlDisplay = $e($node->getUrl());
            $route      = $node->getModule() . '/' . $node->getGroup() . '/' . $node->getController() . '/' . $node->getAction();
        }

        return [
            'id'         => $node->getId(),
            'name'       => $node->getName(),
            'urlDisplay' => $urlDisplay,
            'route'      => $route,
            'isRef'      => $refId !== null,
            'active'     => $node->isActive(),
        ];
    }

    /**
     * Recursively maps a navigation subtree to nested display arrays (children sorted
     * like the tree). Adds `hasChildren` + `children` on top of {@see nodeDisplay}.
     *
     * @param Navigation[] $all  the full entry set (children are filtered from it)
     * @return array<string, mixed>
     */
    private function nodeTree(Navigation $node, array $all): array
    {
        $children = array_map(
            fn(Navigation $c) => $this->nodeTree($c, $all),
            $this->navTree()->children($all, $node->getId())
        );
        return $this->nodeDisplay($node) + [
            'hasChildren' => $children !== [],
            'children'    => $children,
        ];
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        $parentId   = (int)DI::getRequest()->getGetParameter('parent');
        $parent     = $parentId > 0 ? $this->repo()->find($parentId) : null;
        $presetSlot = (string)DI::getRequest()->getGetParameter('slot');

        // Sibling-add from a tree-root carries ?slot (no Navigation parent) so the new
        // root lands in the same render-slot. The slot is known from the clicked node —
        // lock it (hidden field) instead of a selector.
        $nav        = new Navigation();
        $lockedSlot = null;
        if ($parent === null && $presetSlot !== '') {
            $nav->setSlot($presetSlot);
            $lockedSlot = $presetSlot;
        }
        return $this->edit($nav, $parent, $lockedSlot);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id  = (int)DI::getRequest()->getGetParameter('id');
        $nav = $id ? $this->repo()->find($id) : null;

        if ($nav === null) {
            return $this->fetchError('Entry not found');
        }

        return $this->edit($nav);
    }

    private function edit(Navigation $nav, ?Navigation $parent = null, ?string $lockedSlot = null): HtmlResponse|FetchResponse
    {
        $isNew      = $nav->getId() === null;
        $navSlots   = DI::getModuleManager()->getAllNavSlots();   // slug => label
        $knownSlots = array_keys($navSlots);
        $validator  = new NavigationValidator($nav, $this->repo(), $knownSlots);

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'navigation', $nav->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $postedParentId = $isNew ? (int)($body['parent_id'] ?? 0) : 0;
            $postedParent   = $postedParentId > 0 ? $this->repo()->find($postedParentId) : null;

            $originalParentId = $nav->getParentId();
            $originalSortKey  = $nav->getSortKey();

            $nav->mapFromArray(BodyCleaner::cleanFor(Navigation::class, $body));

            // parentId/sortKey are server-controlled — never trust the body.
            // New child: parent comes from the validated ?parent target. Existing
            // entry: preserve the stored values (reparent/reorder go via moveAction).
            $nav->setParentId($isNew ? $postedParent?->getId() : $originalParentId);
            if (!$isNew) {
                $nav->setSortKey($originalSortKey);
            }

            if ($validator->isValid()) {
                if ($isNew) {
                    $nav->setSortKey($this->navTree()->nextSortKey($this->repo()->findAll(), $nav));
                }
                $this->em()->persist($nav);
                $this->em()->flush();

                if ($isNew) {
                    $this->messageService->pushFlashAfterRedirect('success', 'Eintrag «' . $nav->getName() . '» angelegt');
                    return $this->fetch()
                        ->setStatus('success')
                        ->setData(['id' => $nav->getId()])
                        ->addCommand('close-modal')
                        ->addCommand('reload');
                }

                // Same node display model as the list tree (nodeDisplay) — the in-place
                // update must render the url/route cells identically to a full reload.
                $display = $this->nodeDisplay($nav);
                $target  = '[data-nav-id="' . $nav->getId() . '"]';

                $this->messageService->pushFlash('success', 'Eintrag «' . $nav->getName() . '» gespeichert');
                return $this->fetch()
                    ->setStatus('success')
                    ->setData(array_merge($nav->mapToArray(), [
                        'url_display' => $display['urlDisplay'],
                        'route'       => $display['route'],
                    ]))
                    ->addCommand('update-fields', [
                        'target' => $target,
                        'fields' => ['name' => 'text', 'url_display' => 'html', 'route' => 'text'],
                    ])
                    ->addCommand('set-class', [
                        'target' => $target,
                        'class'  => 'be-tree__node--inactive',
                        'on'     => !$nav->isActive(),
                    ])
                    ->addCommand('close-modal')
                    ->addCommand('scroll-to',   ['target' => $target]);
            }
            // validation failed — fall through to form re-render
        }

        $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('navigation', $nav->getId()) : '';

        $allEntries  = $this->repo()->findAll();
        $refTargets  = array_values(array_filter(
            $allEntries,
            fn(Navigation $e) => $e->getId() !== $nav->getId()
                && $e->getRef() === null
                && $e->getCanonicalUrl() !== ''
        ));

        $response = $this->html([
            'entry'       => $nav,
            'parent'      => $parent,
            'lockedSlot'  => $lockedSlot,
            'entityCsrf'  => $entityCsrf,
            'navSlots'    => $navSlots,
            'refTargets'  => $refTargets,
            'validator'   => $validator,
        ]);
        $this->layoutManager->addPartials('edit', 'Content/NavigationController', self::NAMESPACE);
        $response->addCommand('load-script', [
            'src'   => $this->layoutManager->resolveJsPath('navigation/edit', self::NAMESPACE),
            'init'  => 'navigation-edit',
            'scope' => '[data-z77-popup-body]',
        ]);
        return $response;
    }

    protected function confirmDeleteAction(): HtmlResponse
    {
        $id  = (int)DI::getRequest()->getGetParameter('id');
        $nav = $id ? $this->repo()->find($id) : null;

        $entityCsrf = $nav ? DI::getCsrfService()->generateEntityToken('navigation', $id) : '';

        $response = $this->html(['entry' => $nav, 'entityCsrf' => $entityCsrf]);
        $this->layoutManager->addPartials('confirmDelete', 'Content/NavigationController', self::NAMESPACE);
        return $response;
    }

    /**
     * Per-row action hub (the list row's ⋮). Renders a modal that launches the
     * row's specific modals (add-sibling / edit / delete). The active toggle stays
     * inline in the row. Mirrors the DMS drive actions hub.
     */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $id  = (int)DI::getRequest()->getGetParameter('id');
        $nav = $id ? $this->repo()->find($id) : null;
        if ($nav === null) {
            return $this->fetchError('Entry not found');
        }

        // Sibling-add target — same rule as the list row: a child reuses its parent,
        // a tree-root reuses its render-slot slug.
        $siblingUrl = $nav->getParentId() !== null
            ? '/backend/content/navigation/add?parent=' . $nav->getParentId()
            : '/backend/content/navigation/add?slot=' . rawurlencode($nav->getSlot());

        $response = $this->html(['entry' => $nav, 'siblingUrl' => $siblingUrl]);
        $this->layoutManager->addPartials('actions', 'Content/NavigationController', self::NAMESPACE);
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

        $entityCsrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($entityCsrf, 'navigation', $id)) {
            return $this->fetchError('Invalid token');
        }

        $nav = $this->repo()->find($id);
        if ($nav === null) {
            return $this->fetchError('Entry not found');
        }

        $this->em()->remove($nav);
        $this->em()->flush();

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('remove-element', ['target' => '[data-nav-id="' . $id . '"]'])
            ->addCommand('close-modal');
    }

    /** Inline active toggle from the list view (global CSRF, no entity token — non-destructive). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id  = (int)DI::getRequest()->getGetParameter('id');
        $nav = $id ? $this->repo()->find($id) : null;
        if ($nav === null) {
            return $this->fetchError('Entry not found');
        }

        $nav->setActive(!$nav->isActive());
        $this->em()->persist($nav);
        $this->em()->flush();

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('set-class', [
                'target' => '[data-nav-id="' . $id . '"]',
                'class'  => 'be-tree__node--inactive',
                'on'     => !$nav->isActive(),
            ]);
    }

    #[Fetch, HttpMethod('POST')]
    protected function checkFieldAction(): FetchResponse
    {
        $body  = DI::getRequest()->getJsonBody();
        $field = (string)($body['field'] ?? '');
        if ($field === '') {
            return $this->fetch();
        }

        $cleaned = BodyCleaner::cleanFor(Navigation::class, [$field => $body['value'] ?? '']);

        $entity = new Navigation();
        $entity->mapFromArray($cleaned);

        $validator = new NavigationValidator($entity);
        $validator->isValid([$field]);

        $response = $this->fetch();
        if ($validator->hasFieldError($field)) {
            $response
                ->setStatus('error')
                ->setField($field, false, $validator->getFieldError($field));
        }
        return $response;
    }

    /**
     * Navigation move policy (the splice/renumber mechanics live in
     * {@see AbstractTreeEntityController::moveAction}). Rules:
     *  - ref entries cannot be parents;
     *  - cross-slot moves are rejected — a tree-root keeps its slot, a child
     *    carries none (XOR-constraint); walk to the tree-root slot on both
     *    sides and compare;
     *  - on reparent the moved node loses its slot; dropped to top-level it
     *    inherits its former tree-root's slot (a top-level node MUST have one).
     *
     * @param array<int, TreeNode> $index
     */
    protected function applyMovePolicy(TreeNode $node, ?TreeNode $newParent, array $index): ?string
    {
        /** @var Navigation $node */
        if ($newParent !== null && $newParent->getRef() !== null) {
            return 'Verweis-Einträge können keine Kinder aufnehmen.';
        }

        $tree        = $this->navTree();
        $oldParentId = $node->getParentId();

        // slot scope: walk to the tree-root slot on both sides; reject cross-slot.
        $entrySlot      = $oldParentId === null ? $node->getSlot() : '';
        $targetRootSlot = $newParent === null ? $entrySlot : $tree->rootOf($index, $newParent)->getSlot();
        $sourceRootSlot = $oldParentId === null ? $entrySlot : $tree->rootOf($index, $index[$oldParentId] ?? $node)->getSlot();

        if ($sourceRootSlot !== '' && $targetRootSlot !== '' && $sourceRootSlot !== $targetRootSlot) {
            return 'Cross-Slot-Move ist nicht erlaubt (' . $sourceRootSlot . ' → ' . $targetRootSlot . ').';
        }

        if ($newParent !== null) {
            $node->setParentId($newParent->getId());
            $node->setSlot(''); // a child never carries a slot (XOR-constraint)
        } else {
            $node->setParentId(null);
            if ($node->getSlot() === '') {
                if ($sourceRootSlot === '') {
                    return 'Top-Level-Eintrag braucht einen Slot.';
                }
                $node->setSlot($sourceRootSlot); // inherit from former tree-root
            }
        }

        return null;
    }
}
