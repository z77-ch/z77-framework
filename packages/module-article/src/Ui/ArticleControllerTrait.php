<?php
namespace Z77\Module\Article\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Article\Entities\Price,
    Z77\Module\Article\Entities\Product,
    Z77\Module\Article\Entities\ProductGroup,
    Z77\Module\Article\Entities\Variant,
    Z77\Module\Article\Repositories\PriceRepository,
    Z77\Module\Article\Repositories\ProductGroupRepository,
    Z77\Module\Article\Repositories\ProductRepository,
    Z77\Module\Article\Repositories\VariantRepository,
    Z77\Module\Article\Services\ArticleConflictException,
    Z77\Module\Article\Services\ArticleDraft,
    Z77\Module\Article\Services\ArticleInUseException,
    Z77\Module\Article\Services\ArticleService,
    Z77\Module\Article\Services\InvalidArticleException,
    Z77\Module\Article\Services\ProductGroupTree,
    Z77\Module\Article\Validators\ArticleValidator,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Module\Vat\Repositories\TaxCodeRepository,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat
;

/**
 * Stammdaten › Aufträge › Artikel (plan §9.5, P6 slim) — the article master as ONE flat article
 * per row: the product and its hidden DEFAULT variant (A3 in the schema, owner Q2 2026-10-14),
 * the price valid today from the «gültig ab» history (owner Q3). Mounted by a thin host
 * controller in the backend (ADR-018 pattern): `use ArticleControllerTrait` + a one-line layout
 * config delegating to {@see ArticleLayout::config()}; URL `/backend/order/article/…`.
 *
 *   - `list` — a STANDARD LIST ({@see ArticleListing}, listing.md): the state icon opens the
 *     article WINDOW, then Nr., Bezeichnung, Warengruppe (the path), Einheit, Preis, MWST, Konto,
 *     Bestand and the «Aktiv» switch; the list is a fetch region. Action cell: «+ Artikel»
 *     (backend-screen.md §2, the most frequent action of the entry) — with the chosen group, so
 *     the new article starts in it with its defaults. The product-group TREE stands at the top of
 *     the rail (`groupSelect`, section `railSelect` — the shared CSS-only picker of the fiscal
 *     year): a group shows its articles INCLUDING its sub-groups (owner 2026-10-14);
 *   - `add` / `edit` — the article WINDOW (ADR-047; a window and not a modal popup because it
 *     carries help — ADR-048, the help window cannot be used beside a modal): one form, one
 *     save — number, name, the product group (indented tree options; a NEW article opened with
 *     `?group=` starts there, tax code and revenue account prefilled from the group's defaults),
 *     unit, price + «gültig ab» + net / gross, tax code (the shared picker
 *     of module-vat), revenue account (module-mandator's datalist of the accounts «auf Belegen
 *     wählbar»), «führt Bestand»; below the form the price history, read-only. The save answers
 *     with commands (close the window, reload the list region it came from; an edit also
 *     replaces its row — FETCH-ROW-001); without the script it is a page form;
 *   - `toggle-active` — the inline switch (product and variants together);
 *   - `confirm-delete` / `delete` — «Löschen …» in the window's action row, the confirmation in
 *     the same window: only an article nothing references (the database decides — module-order's
 *     line has a foreign key on the variant; article does not name order); a referenced one is
 *     refused with «deaktivieren». There is no ⋮ hub: the row has ONE action besides the switch
 *     (open), and the state icon is it.
 *
 * Every write goes through {@see ArticleService} with the logged-in user as actor; nothing
 * managed is mutated here. No JavaScript of its own (Rule 7). The using class MUST provide (via
 * its host base): `html()`, `fetch()`, `fetchError()`, `redirect()`, `em()`, `$layoutManager`,
 * `$messageService`, `$help`.
 */
trait ArticleControllerTrait
{
    private const ARTICLE_NS = 'Z77\\Module\\Article';

    private const ARTICLE_CONFLICT_MESSAGE = 'Der Artikel wurde inzwischen geändert oder gelöscht — bitte neu laden und erneut erfassen.';

    /** URL root of THIS mount — every link, row button and modal form is built from it. */
    protected function articleListBase(): string
    {
        return ArticleLayout::BASE;
    }

    private function articleProducts(): ProductRepository
    {
        return $this->em()->getRepository(Product::class);
    }

    private function articleVariants(): VariantRepository
    {
        return $this->em()->getRepository(Variant::class);
    }

    private function articleGroups(): ProductGroupRepository
    {
        return $this->em()->getRepository(ProductGroup::class);
    }

    private function articlePrices(): PriceRepository
    {
        return $this->em()->getRepository(Price::class);
    }

    /** The one write path, acting as the logged-in user (the screen is ADMIN — someone is logged in). */
    private function articleService(): ArticleService
    {
        return new ArticleService($this->em(), DI::getAuthService()->getCurrentUser()->getUserName());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $tree       = $this->articleGroups()->tree();
        $definition = ArticleListing::definition($tree->ids());
        $state      = $definition->readRequest($request);
        $search     = ArticleListing::search($state, $tree);
        $total      = $this->articleProducts()->countSearch($search);
        $paging     = $state->paging($total);
        $products   = $total === 0 ? [] : $this->articleProducts()->search($search, $paging->offset(), $paging->pageSize);

        $response = $this->html([
            'products'   => $products,
            'rows'       => $this->articleRows($products, $tree),
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'tree'       => $tree,
            'group'      => ArticleListing::groupOf($state),
            'actionBase' => $this->articleListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»): «+ Artikel» is the
        // entry's most frequent action → the action cell (backend-screen.md §2). A FETCH of the
        // region (sort, page, search) wants the list alone.
        if (!ListDefinition::isFetch($request)) {
            $this->layoutManager->addPartials('act', 'Backend/ArticleController', self::ARTICLE_NS, 'hc1');
            // The product-group tree at the top of the rail — the filter of this list (owner
            // 2026-10-14: «the tree in the rail»); the shared CSS-only picker, links only.
            $this->layoutManager->addPartials('groupSelect', 'Backend/ArticleController', self::ARTICLE_NS, 'railSelect');
        }

        return $response;
    }

    // ── add / edit (window) ───────────────────────────────────────────────

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->articleWindow(null);
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $product = $id ? $this->articleProducts()->find($id) : null;
        if ($product === null) {
            return $this->articleRefuse('Artikel nicht gefunden');
        }

        return $this->articleWindow($product);
    }

    /**
     * The article window (ADR-047): GET renders it, POST saves through the service. Fetched, the
     * save answers with commands — close the window, reload the list region it was opened from
     * (a new article's place in the sort is the list's; an edited row is replaced as well);
     * a refusal re-renders the window with the field errors and what was typed. Without the
     * script it is a page: a plain form (`#[Csrf]`), redirect to the list.
     */
    private function articleWindow(?Product $product): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request   = DI::getRequest();
        $tree      = $this->articleGroups()->tree();
        $variant   = $product !== null ? $this->articleVariants()->defaultOf($product) : null;
        $draft     = $this->articleDraftOf($product, $variant, $tree);
        $validator = new ArticleValidator($draft);
        $version   = $product?->getVersion() ?? 0;

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if ($product !== null && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'article', $product->getId())) {
                return $this->articleRefuse(self::ARTICLE_CONFLICT_MESSAGE);
            }

            $draft   = ArticleDraft::fromArray($post);
            $version = (int) ($post['version'] ?? 0);
            try {
                $saved = $product === null
                    ? $this->articleService()->create($draft)
                    : $this->articleService()->update((int) $product->getId(), $version, $draft);

                return $this->articleSaved($saved, ($product === null ? 'Artikel angelegt: ' : 'Artikel gespeichert: ') . $draft->number . ' — ' . $saved->getName(), $product === null);
            } catch (ArticleConflictException) {
                return $this->articleRefuse(self::ARTICLE_CONFLICT_MESSAGE);
            } catch (InvalidArticleException $e) {
                $validator = $e->validator;
                $draft     = $e->draft;
            }
            // fall through — re-render the window with the errors and what was typed
        }

        $ledger  = new LedgerAccountCheck($this->em());
        $context = [
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'product'     => $product,
            'variant'     => $variant,
            'draft'       => $draft,
            'version'     => $version,
            'validator'   => $validator,
            'codes'       => $this->articleTaxCodes()->selectable($product !== null ? [$product->getTaxCode()] : []),
            'groups'      => $tree->selectable(keep: $product !== null ? (int) $product->getProductGroup()->getId() : null),
            'accounts'    => $ledger->documentAccounts(),
            'ledger'      => $ledger->available(),
            'history'     => $variant !== null ? $this->articlePrices()->historyOf($variant) : [],
            'fmt'         => static fn($money) => AmountFormat::of($money),
            'entityCsrf'  => $product !== null ? DI::getCsrfService()->generateEntityToken('article', $product->getId()) : '',
            'actionBase'  => $this->articleListBase(),
        ];
        // Attached BEFORE html(): the base controller renders the attached help into the answer.
        $this->help->attach('Backend/ArticleController/edit.help', self::ARTICLE_NS, ['ledger' => $context['ledger']], 'Hilfe: Artikel');

        return $this->articlePage('edit', $context);
    }

    /**
     * What the window shows before a POST: a new article starts net, valid from today, in the
     * group the list was filtered by (`?group=` — «+ Artikel» carries it), else in the only group
     * there is; its tax code and revenue account are that group's DEFAULTS (inherited from the
     * nearest group above that sets one — owner 2026-10-14, a server-side prefill, no script).
     * An existing one shows its values and the price VALID TODAY (else the next one that starts
     * later, with its own day) — saving it unchanged writes no price row (A4).
     */
    private function articleDraftOf(?Product $product, ?Variant $variant, ProductGroupTree $tree): ArticleDraft
    {
        $today = new \DateTimeImmutable('today');
        if ($product === null || $variant === null) {
            $asked  = (int) DI::getRequest()->getGetParameter('group');
            $active = $tree->selectable();
            $group  = $tree->find($asked)?->isActive() ? $asked : (count($active) === 1 ? (int) $active[0]['group']->getId() : 0);
            $preset = $group > 0 ? $tree->defaultsFor($group) : ['taxCode' => '', 'revenueAccount' => ''];

            return new ArticleDraft(
                priceMode:      'net',
                validFrom:      $today->format('Y-m-d'),
                taxCode:        $preset['taxCode'],
                revenueAccount: $preset['revenueAccount'],
                productGroup:   $group,
            );
        }
        $price = $this->articlePrices()->shownAt([$variant], $today)[$variant->getId()] ?? null;

        return new ArticleDraft(
            number:         $variant->getNumber(),
            name:           $product->getName(),
            unit:           $variant->getUnit(),
            price:          $price?->getAmount()->toDecimal() ?? '',
            priceMode:      $price?->priceMode()->value ?? 'net',
            validFrom:      max($today, $price?->getValidFrom() ?? $today)->format('Y-m-d'),
            taxCode:        $product->getTaxCode(),
            revenueAccount: $product->getRevenueAccount(),
            stockRelevant:  $product->isStockRelevant(),
            productGroup:   (int) $product->getProductGroup()->getId(),
        );
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id = (int) DI::getRequest()->getGetParameter('id');
        if ($id <= 0 || $this->articleProducts()->find($id) === null) {
            return $this->fetchError('Artikel nicht gefunden');
        }
        $body = DI::getRequest()->getJsonBody();
        try {
            $product = $this->articleService()->setActive($id, (bool) ($body['value'] ?? false));
        } catch (ArticleConflictException) {
            return $this->fetchError('Artikel nicht gefunden');
        }

        return $this->articleRowAnswer($product);
    }

    // ── delete (in the window) ───────────────────────────────────────────

    /** «Löschen …» of the article window — the confirmation, loaded into the same window. */
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $product = $id ? $this->articleProducts()->find($id) : null;
        if ($product === null) {
            return $this->articleRefuse('Artikel nicht gefunden');
        }

        return $this->articlePage('confirmDelete', [
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'product'    => $product,
            'variant'    => $this->articleVariants()->defaultOf($product),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('article', $product->getId()),
            'actionBase' => $this->articleListBase(),
        ]);
    }

    /** Deletes an article nothing references (the database decides — module-order's foreign key). */
    #[Csrf]
    protected function deleteAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->getPostParameters();
        $id      = (int) $request->getGetParameter('id');
        if ($id <= 0 || !$request->isPost() || !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'article', $id)) {
            return $this->articleRefuse(self::ARTICLE_CONFLICT_MESSAGE);
        }
        try {
            $this->articleService()->delete($id, (int) ($post['version'] ?? 0));
        } catch (ArticleConflictException) {
            return $this->articleRefuse(self::ARTICLE_CONFLICT_MESSAGE);
        } catch (ArticleInUseException) {
            return $this->articleRefuse('Der Artikel wird verwendet und kann nicht gelöscht werden — mit dem Schalter «Aktiv» deaktivieren.');
        }

        return $this->articleSaved(null, 'Artikel gelöscht', true);
    }

    // ── answers ──────────────────────────────────────────────────────────

    /**
     * A full page or a window's content other than the list: the layout pins the body to
     * `listAction`, so the section is swapped for the page's own template (the invoice model).
     *
     * @param array<string, mixed> $context
     */
    private function articlePage(string $template, array $context): HtmlResponse
    {
        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/ArticleController', self::ARTICLE_NS);

        return $response;
    }

    /**
     * The answer to a successful write from the window (ADR-047): close the window, reload the
     * list region it was opened from (`region:article-find-list`); an EDITED row is also
     * replaced in place wherever it is shown. Without the script: flash and back to the list.
     */
    private function articleSaved(?Product $product, string $flash, bool $listChanged): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->articleListBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $product !== null && !$listChanged ? $this->articleRowAnswer($product) : $this->fetch()->setStatus('success');
        $response->addCommand('close-window');
        $origin = WindowOrigin::of($request);
        if ($listChanged && str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    private function articleRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->articleListBase() . '/list', 303);
    }

    // ── rows ─────────────────────────────────────────────────────────────

    /**
     * Per product what its row shows: the default variant, the price valid today (else the
     * next one, «ab …») and the path of its group. Two queries for a page (no N+1); the tree is
     * read once by the caller.
     *
     * @param list<Product> $products
     * @return array<int, array{variant: ?Variant, price: ?Price, path: string}> product id → row data
     */
    private function articleRows(array $products, ProductGroupTree $tree): array
    {
        $variants = $this->articleVariants()->defaultsFor($products);
        $prices   = $this->articlePrices()->shownAt(array_values($variants), new \DateTimeImmutable('today'));
        $rows     = [];
        foreach ($products as $product) {
            $variant = $variants[$product->getId()] ?? null;
            $rows[$product->getId()] = [
                'variant' => $variant,
                'price'   => $variant !== null ? ($prices[$variant->getId()] ?? null) : null,
                'path'    => $tree->path((int) $product->getProductGroup()->getId()),
            ];
        }

        return $rows;
    }

    /**
     * A save or a switch that changed one article answers with its row, not with `reload`
     * (ADR-047 addendum 2026-10-10): `_row` — the partial the list renders — replaces the node
     * `article:<id>`. Push the flash BEFORE calling: `fetch()` takes the flashes pushed so far.
     */
    private function articleRowAnswer(Product $product): FetchResponse
    {
        $row  = $this->articleRows([$product], $this->articleGroups()->tree())[$product->getId()];
        $html = (new TemplateRenderer(self::ARTICLE_NS))->partial('Backend/ArticleController/_row', [
            'product'    => $product,
            'variant'    => $row['variant'],
            'price'      => $row['price'],
            'path'       => $row['path'],
            'definition' => ArticleListing::definition(),
            'actionBase' => $this->articleListBase(),
        ]);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $product->getId()])
            ->replaceRow('article', (int) $product->getId(), $html);
    }

    private function articleTaxCodes(): TaxCodeRepository
    {
        return $this->em()->getRepository(TaxCode::class);
    }
}
