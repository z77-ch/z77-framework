<?php
namespace Z77\Module\Article\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Article\Entities\ProductGroup,
    Z77\Module\Article\Repositories\ProductGroupRepository,
    Z77\Module\Article\Services\InvalidProductGroupException,
    Z77\Module\Article\Services\ProductGroupConflictException,
    Z77\Module\Article\Services\ProductGroupDraft,
    Z77\Module\Article\Services\ProductGroupInUseException,
    Z77\Module\Article\Services\ProductGroupService,
    Z77\Module\Article\Validators\ProductGroupValidator,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Module\Vat\Repositories\TaxCodeRepository,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › Aufträge › Warengruppen (plan §4b A1, owner 2026-10-14: «der Baum reicht vorerst,
 * ich muss ja irgendwie Waren gruppieren können») — the product-group TREE of free depth. Mounted
 * by a thin host controller in the backend (ADR-018 pattern): `use ProductGroupControllerTrait` +
 * a one-line layout config delegating to {@see ProductGroupLayout::config()}; URL
 * `/backend/order/product-group/…`.
 *
 *   - `list` — the tree as the chart of accounts shows it (the shared hub-tree rows
 *     `.be-tree--hub be-tree--lead-switch`, one step of indent per level via `--node-depth`; no
 *     second tree UI): the «Aktiv» switch, ⋮ opens the group WINDOW, the name, the defaults it
 *     gives new articles, and in the trailing tools the article count (a link to the article
 *     list filtered by this group, sub-groups included) and «+ Untergruppe» (an icon — a helper
 *     action in a row, backend-screen.md §4). The tree is a fetch region: a save reloads it.
 *     Action cell: «+ Warengruppe» (backend-screen.md §2);
 *   - `add` / `edit` — the group WINDOW (ADR-047; a window because it carries help — ADR-048):
 *     name, parent group (indented tree options without the group's own subtree — choosing
 *     another parent MOVES the group with its articles and sub-groups), the default tax code
 *     and revenue account for NEW articles. `add?parent=<id>` starts under that group;
 *   - `toggle-active` — the inline switch;
 *   - `confirm-delete` / `delete` — «Löschen …» in the window's action row: only an EMPTY group
 *     (no sub-group, no article); otherwise the refusal says what is still in it.
 *
 * Every write goes through {@see ProductGroupService}; nothing managed is mutated here. No
 * JavaScript of its own (Rule 7). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait ProductGroupControllerTrait
{
    private const GROUP_NS = 'Z77\\Module\\Article';

    private const GROUP_CONFLICT_MESSAGE = 'Die Warengruppe wurde inzwischen geändert oder gelöscht — bitte neu laden und erneut erfassen.';

    /** URL root of THIS mount. */
    protected function groupListBase(): string
    {
        return ProductGroupLayout::BASE;
    }

    /** Where the article list of THIS installation lives — the count links there. */
    protected function groupArticleListUrl(): string
    {
        return ArticleLayout::BASE . '/list';
    }

    private function groupRepository(): ProductGroupRepository
    {
        return $this->em()->getRepository(ProductGroup::class);
    }

    private function groupService(): ProductGroupService
    {
        return new ProductGroupService($this->em());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $response = $this->html([
            'tree'        => $this->groupRepository()->tree(),
            'counts'      => $this->groupRepository()->productCounts(),
            'actionBase'  => $this->groupListBase(),
            'articleList' => $this->groupArticleListUrl(),
        ]);
        // The fragment owns its header slot: «+ Warengruppe» → the action cell. A fetch of the
        // region (after a save) wants the tree alone.
        if (!ListDefinition::isFetch(DI::getRequest())) {
            $this->layoutManager->addPartials('act', 'Backend/ProductGroupController', self::GROUP_NS, 'hc1');
        }

        return $response;
    }

    // ── add / edit (window) ───────────────────────────────────────────────

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->groupWindow(null);
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $group = $id ? $this->groupRepository()->find($id) : null;
        if ($group === null) {
            return $this->groupRefuse('Warengruppe nicht gefunden');
        }

        return $this->groupWindow($group);
    }

    /**
     * The group window (ADR-047): GET renders it, POST saves through the service. Fetched, the
     * save answers with commands — close the window, reload the tree region (a new or moved
     * group changes the order and the indent of more than its own row); a refusal re-renders the
     * window with the field errors. Without the script it is a page form, redirect to the list.
     */
    private function groupWindow(?ProductGroup $group): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $tree    = $this->groupRepository()->tree();
        $draft   = $group !== null
            ? new ProductGroupDraft($group->getName(), (int) $group->getParentId(), $group->getDefaultTaxCode(), $group->getDefaultRevenueAccount())
            : new ProductGroupDraft(parentId: $tree->find((int) $request->getGetParameter('parent')) !== null ? (int) $request->getGetParameter('parent') : 0);
        $validator = new ProductGroupValidator($draft);
        $version   = $group?->getVersion() ?? 0;

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if ($group !== null && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'product-group', $group->getId())) {
                return $this->groupRefuse(self::GROUP_CONFLICT_MESSAGE);
            }
            $draft   = ProductGroupDraft::fromArray($post);
            $version = (int) ($post['version'] ?? 0);
            try {
                $saved = $group === null
                    ? $this->groupService()->create($draft)
                    : $this->groupService()->update((int) $group->getId(), $version, $draft);

                return $this->groupSaved(($group === null ? 'Warengruppe angelegt: ' : 'Warengruppe gespeichert: ') . $saved->getName());
            } catch (ProductGroupConflictException) {
                return $this->groupRefuse(self::GROUP_CONFLICT_MESSAGE);
            } catch (InvalidProductGroupException $e) {
                $validator = $e->validator;
                $draft     = $e->draft;
            }
        }

        $ledger  = new LedgerAccountCheck($this->em());
        /** @var TaxCodeRepository $codes */
        $codes   = $this->em()->getRepository(TaxCode::class);
        $parent  = $draft->parentId > 0 ? $draft->parentId : null;
        $context = [
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'group'      => $group,
            'draft'      => $draft,
            'version'    => $version,
            'validator'  => $validator,
            'parents'    => $tree->selectable(keep: $parent, without: $group?->getId()),
            'inherited'  => $parent !== null ? $tree->defaultsFor($parent) : ['taxCode' => '', 'revenueAccount' => ''],
            'path'       => $group !== null ? $tree->path((int) $group->getId()) : '',
            'codes'      => $codes->selectable($group !== null && $group->getDefaultTaxCode() !== '' ? [$group->getDefaultTaxCode()] : []),
            'accounts'   => $ledger->documentAccounts(),
            'ledger'     => $ledger->available(),
            'entityCsrf' => $group !== null ? DI::getCsrfService()->generateEntityToken('product-group', $group->getId()) : '',
            'actionBase' => $this->groupListBase(),
        ];
        // Attached BEFORE html(): the base controller renders the attached help into the answer.
        $this->help->attach('Backend/ProductGroupController/edit.help', self::GROUP_NS, ['ledger' => $context['ledger']], 'Hilfe: Warengruppe');

        return $this->groupPage('edit', $context);
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the tree row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id = (int) DI::getRequest()->getGetParameter('id');
        try {
            $group = $this->groupService()->setActive($id, (bool) (DI::getRequest()->getJsonBody()['value'] ?? false));
        } catch (ProductGroupConflictException) {
            return $this->fetchError('Warengruppe nicht gefunden');
        }

        // The row's dimming and badge follow; the order and the indent do not change.
        $this->messageService->pushFlash('success', ($group->isActive() ? 'Aktiv: ' : 'Inaktiv: ') . $group->getName());
        $response = $this->fetch()->setStatus('success')->setData(['id' => $group->getId()]);
        $response->addCommand('refresh-region', ['name' => ProductGroupLayout::REGION]);

        return $response;
    }

    // ── delete (in the window) ───────────────────────────────────────────

    /** «Löschen …» of the group window — the confirmation, loaded into the same window. */
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $tree    = $this->groupRepository()->tree();
        $group   = $tree->find($id);
        if ($group === null) {
            return $this->groupRefuse('Warengruppe nicht gefunden');
        }

        return $this->groupPage('confirmDelete', [
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'group'      => $group,
            'path'       => $tree->path($id),
            'children'   => $tree->childCount($id),
            'products'   => $this->groupRepository()->productCounts()[$id] ?? 0,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('product-group', $id),
            'actionBase' => $this->groupListBase(),
        ]);
    }

    /** Deletes an EMPTY group. */
    #[Csrf]
    protected function deleteAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->getPostParameters();
        $id      = (int) $request->getGetParameter('id');
        if ($id <= 0 || !$request->isPost() || !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'product-group', $id)) {
            return $this->groupRefuse(self::GROUP_CONFLICT_MESSAGE);
        }
        try {
            $this->groupService()->delete($id, (int) ($post['version'] ?? 0));
        } catch (ProductGroupConflictException) {
            return $this->groupRefuse(self::GROUP_CONFLICT_MESSAGE);
        } catch (ProductGroupInUseException $e) {
            return $this->groupRefuse(self::groupInUseMessage($e));
        }

        return $this->groupSaved('Warengruppe gelöscht');
    }

    /** What a refused delete says — what is still in the group and what to do instead. */
    private static function groupInUseMessage(ProductGroupInUseException $e): string
    {
        $parts = [];
        if ($e->children > 0) {
            $parts[] = $e->children === 1 ? '1 Untergruppe' : $e->children . ' Untergruppen';
        }
        if ($e->products > 0) {
            $parts[] = $e->products === 1 ? '1 Artikel' : $e->products . ' Artikel';
        }
        $what = $parts === [] ? 'inzwischen etwas' : implode(' und ', $parts);

        return 'Die Warengruppe enthält ' . $what . ' und kann nicht gelöscht werden — zuerst verschieben oder die Gruppe mit dem Schalter «Aktiv» deaktivieren.';
    }

    // ── answers ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function groupPage(string $template, array $context): HtmlResponse
    {
        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/ProductGroupController', self::GROUP_NS);

        return $response;
    }

    /**
     * The answer to a successful write from the window (ADR-047): close the window, reload the
     * tree region. Without the script: flash and back to the list.
     */
    private function groupSaved(string $flash): FetchResponse|RedirectResponse
    {
        if (!ListDefinition::isFetch(DI::getRequest())) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->groupListBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success');
        $response->addCommand('close-window');
        $response->addCommand('refresh-region', ['name' => ProductGroupLayout::REGION]);

        return $response;
    }

    private function groupRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->groupListBase() . '/list', 303);
    }
}
