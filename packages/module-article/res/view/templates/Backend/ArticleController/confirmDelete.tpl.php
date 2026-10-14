<?php
/**
 * «Löschen …» of the article window — the confirmation, loaded into the SAME window
 * (`data-window-link`), a question with its answers (ADR-049: the action row may stand at the
 * bottom for a confirm without input). An article nothing references is deleted with its
 * variants and price history; one that a line references is refused by the database
 * (module-order's foreign key) — the answer then says to deactivate it.
 *
 * @var \Z77\Module\Article\Entities\Product $product
 * @var \Z77\Module\Article\Entities\Variant|null $variant
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE;
$window     = !empty($window);
$name       = trim(($variant?->getNumber() ?? '') . ' — ' . $product->getName(), ' —');
$winAttr    = $window
    ? ' data-window="article-delete" data-window-entity="article:' . (int) $product->getId() . '" data-window-title="' . e('Artikel löschen · ' . $name) . '"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/delete?id=' . (int) $product->getId()) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) $product->getVersion() ?>">
        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Artikel löschen <small class="be-list__cell--muted">· <?= e($name) ?></small></h2>
        </div>
        <?php endif; ?>
        <p>Den Artikel «<?= e($name) ?>» mit seinem Preisverlauf endgültig löschen?</p>
        <p class="be-form__hint">Gelöscht wird nur ein Artikel, den keine Position verwendet. Wird er verwendet, bleibt er stehen — dann mit dem Schalter «Aktiv» deaktivieren.</p>
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Löschen',
            'kind'       => 'danger',
            'cancelHref' => $actionBase . '/edit?id=' . (int) $product->getId(),
            'end'        => true,
        ], 'Z77\\Shared') ?>
    </form>
</div>
