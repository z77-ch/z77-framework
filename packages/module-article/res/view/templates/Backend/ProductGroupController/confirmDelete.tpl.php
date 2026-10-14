<?php
/**
 * «Löschen …» of the product-group window — the confirmation, loaded into the SAME window
 * (`data-window-link`), a question with its answers (ADR-049: the action row may stand at the
 * bottom for a confirm without input). Only an EMPTY group is deleted; one that still carries
 * sub-groups or articles says so here and offers no delete (the service refuses it anyway).
 *
 * @var \Z77\Module\Article\Entities\ProductGroup $group
 * @var string $path
 * @var int $children
 * @var int $products
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ProductGroupLayout::BASE;
$window     = !empty($window);
$empty      = $children === 0 && $products === 0;
$winAttr    = $window
    ? ' data-window="product-group-delete" data-window-entity="product-group:' . (int) $group->getId() . '" data-window-title="' . e('Warengruppe löschen · ' . $path) . '"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/delete?id=' . (int) $group->getId()) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) $group->getVersion() ?>">
        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Warengruppe löschen <small class="be-list__cell--muted">· <?= e($path) ?></small></h2>
        </div>
        <?php endif; ?>
        <?php if ($empty): ?>
        <p>Die leere Warengruppe «<?= e($path) ?>» endgültig löschen?</p>
        <?php else: ?>
        <p>Die Warengruppe «<?= e($path) ?>» enthält noch <?= e(implode(' und ', array_filter([
            $children > 0 ? ($children === 1 ? '1 Untergruppe' : $children . ' Untergruppen') : '',
            $products > 0 ? ($products === 1 ? '1 Artikel' : $products . ' Artikel') : '',
        ]))) ?> und kann nicht gelöscht werden.</p>
        <p class="be-form__hint">Zuerst die Untergruppen und Artikel in eine andere Gruppe verschieben — oder die Gruppe mit dem Schalter «Aktiv» deaktivieren: dann wird sie für neue Artikel nicht mehr angeboten.</p>
        <?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $empty ? 'Löschen' : '',
            'kind'       => 'danger',
            'cancel'     => $empty ? 'Abbrechen' : 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . (int) $group->getId(),
            'end'        => true,
        ], 'Z77\\Shared') ?>
    </form>
</div>
