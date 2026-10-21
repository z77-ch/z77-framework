<?php
namespace Z77\Module\Article\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Article\Entities\Product,
    Z77\Module\Article\Entities\ProductGroup,
    Z77\Module\Article\Entities\Variant,
    Z77\Module\Article\Repositories\ProductGroupRepository,
    Z77\Module\Article\Repositories\ProductRepository,
    Z77\Module\Article\Repositories\VariantRepository,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Listing\ListDefinition
;

/**
 * «Artikel wählen …» ({@see ArticlePicker}) — mounted by the controller of a SOURCE that has
 * article lines (`use ArticlePickerTrait;` — module-order's `OrderControllerTrait` is the first),
 * so the action `article-pick` lives at the source's URL root. Article knows no source: the
 * source says what a row costs and what a choice does.
 *
 *   - GET  — the picker: a WINDOW over the editor (fetched; mask `article-pick`), else a page.
 *            The toolbar (group, search) and the list are ONE fetch region inside the window:
 *            a search or a group reloads only it, every link and form carrying `_origin`,
 *            `target` and the source's context;
 *   - POST `article=<number>` — the choice, judged again ({@see ArticlePicker::isChoosable()}).
 *            Fetched: opened for a field, `close-modal` (this window) and the source's commands
 *            for the editor ({@see articlePickedCommands()} — an order: the row's number + its
 *            fill); opened without a target («+ Artikel»), the window STAYS for the next pick
 *            and the source adds a row (no message — the list grows). Without the
 *            script: back to the picker page with the number to type.
 *
 * The using trait provides `articlePickBase()`, `articlePickContextKeys()`,
 * `articlePickPrices()` and `articlePickedCommands()`, plus the host's `html()`, `fetch()`,
 * `fetchError()`, `redirect()`, `em()`, `$layoutManager`, `$messageService`.
 */
trait ArticlePickerTrait
{
    /** The source's URL root — `article-pick` lives there. */
    abstract protected function articlePickBase(): string;

    /**
     * The names of the source's context parameters that ride along on every link (an order:
     * `order_date`, `service_from`, `price_mode` — what its price needs).
     *
     * @return list<string>
     */
    abstract protected function articlePickContextKeys(): array;

    /**
     * The «Preis» column: its head (e.g. «Preis netto») and the price of each shown variant as
     * text ('' = none on that day), from the source's own price rule.
     *
     * @param list<Variant>         $variants
     * @param array<string, string> $context  the context keys' values (plain, not yet judged)
     * @return array{label: string, prices: array<int, string>}  variant id → price
     */
    abstract protected function articlePickPrices(array $variants, array $context): array;

    /**
     * What the editor does with the choice — commands for the origin window: $target = the DOM
     * id of the field the picker was opened for, '' = «+ Artikel» (a new row).
     */
    abstract protected function articlePickedCommands(FetchResponse $response, Variant $variant, string $origin, string $target): void;

    #[Csrf]
    protected function articlePickAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $fetch   = ListDefinition::isFetch($request);
        $origin  = WindowOrigin::of($request);
        $pickUrl = $this->articlePickBase() . '/article-pick';

        if ($request->isPost()) {
            $post    = $request->getPostParameters();
            $number  = trim((string) ($post['article'] ?? ''));
            $variant = $number !== '' ? $this->em()->getRepository(Variant::class)->findByNumber($number) : null;
            if (!ArticlePicker::isChoosable($variant)) {
                $message = 'Diesen Artikel gibt es nicht mehr, oder er ist inaktiv — bitte neu suchen.';
                if ($fetch) {
                    return $this->fetchError($message);
                }
                $this->messageService->pushFlashAfterRedirect('error', $message);

                return $this->redirect($pickUrl, 303);
            }
            if (!$fetch) {
                // Without the script the editor cannot be filled from here: the number is shown to type.
                $this->messageService->pushFlashAfterRedirect('success', 'Artikel ' . $variant->getNumber() . ' — «' . $variant->displayName() . '»: die Nummer in der Zeile eintragen.');

                return $this->redirect(ArticlePicker::url($pickUrl, ['q' => $variant->getNumber()]), 303);
            }
            // Opened for a field: this window closes first, then the editor fills that field. Opened
            // from «+ Artikel» (no target, owner 2026-10-20): the window STAYS — every pick adds a
            // row, the same article again or another one, until Schliessen / a click outside. No
            // message: the growing list behind says it (owner 2026-10-21).
            $target   = ArticlePicker::target($post['target'] ?? '');
            $response = $this->fetch()->setStatus('success')->setData(['article' => $variant->getNumber()]);
            if ($target !== '') {
                $response->addCommand('close-modal');
            }
            $this->articlePickedCommands($response, $variant, $origin, $target);

            return $response;
        }

        /** @var ProductGroupRepository $groups */
        $groups   = $this->em()->getRepository(ProductGroup::class);
        /** @var ProductRepository $products */
        $products = $this->em()->getRepository(Product::class);
        /** @var VariantRepository $variants */
        $variants = $this->em()->getRepository(Variant::class);

        $tree    = $groups->tree();
        $query   = ArticlePicker::query($request->getGetParameter('q'));
        $group   = ArticlePicker::group($request->getGetParameter('group'), $tree);
        $target  = ArticlePicker::target($request->getGetParameter('target'));
        $context = [];
        foreach ($this->articlePickContextKeys() as $key) {
            $context[$key] = ArticlePicker::contextValue($request->getGetParameter($key));
        }
        $search    = ArticlePicker::search($query, $group, $tree);
        $total     = $products->countSearch($search);
        $found     = $total === 0 ? [] : $products->search($search, 0, ArticlePicker::LIMIT);
        $byProduct = $variants->defaultsFor($found);
        $rows      = [];
        foreach ($found as $product) {
            $variant = $byProduct[(int) $product->getId()] ?? null;
            if ($variant !== null) {
                $rows[] = $variant;
            }
        }
        $price = $this->articlePickPrices($rows, $context);

        $response = $this->html([
            'window'     => $fetch,
            'tree'       => $tree,
            'query'      => $query,
            'group'      => $group,
            'rows'       => $rows,
            'total'      => $total,
            'prices'     => $price['prices'],
            'priceLabel' => $price['label'],
            'pickUrl'    => $pickUrl,
            // What every link and form carries: the editor window, the field, the source's context.
            'keep'       => array_filter(['_origin' => $origin === WindowOrigin::PAGE ? '' : $origin, 'target' => $target] + $context, static fn($v) => $v !== ''),
            'origin'     => $origin,
            'target'     => $target,
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('pick', 'Backend/ArticlePicker', ArticlePicker::NS);

        return $response;
    }
}
