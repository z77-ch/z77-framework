<?php
/**
 * The product-group window (plan §4b A1) — one form, one save: name, parent group, the default
 * tax code and revenue account for NEW articles. A plain form, no JavaScript of its own (Rule 7):
 * `csrf_token` (`#[Csrf]`), an edit also carries the entity token and the VERSION it was
 * rendered from (`ProductGroupService::update()`).
 *
 * Fetched, a WINDOW (ADR-047): the root declares the mask `product-group-edit` and the record,
 * the title moves into the title bar, the form carries `_origin` back. The action row (ADR-049)
 * is the shared `partials/modalActions`, first in the form: Anlegen / Speichern · «Löschen …» (an
 * existing group — the confirmation loads into the same window) · Abbrechen · «N Fehler».
 *
 * The parent picker offers the tree WITHOUT the group's own subtree (`ProductGroupTree::
 * selectable(without:)`); choosing another parent moves the group with everything in it. An
 * empty default says what is inherited from above in its «none» option / placeholder — the
 * explanation itself is in the help (ADR-048, `edit.help`).
 *
 * @var \Z77\Module\Article\Entities\ProductGroup|null $group  null = new
 * @var \Z77\Module\Article\Services\ProductGroupDraft $draft
 * @var int $version
 * @var \Z77\Module\Article\Validators\ProductGroupValidator $validator
 * @var list<array{group: \Z77\Module\Article\Entities\ProductGroup, depth: int}> $parents
 * @var array{taxCode: string, revenueAccount: string} $inherited  what the parent chain gives
 * @var string $path   the group's path (edit)
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $codes
 * @var list<array{number: string, label: string}> $accounts
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Article\Entities\AbstractProduct;
use Z77\Module\Article\Entities\ProductGroup;

$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ProductGroupLayout::BASE;
$isNew      = $group === null;
$window     = !empty($window);
$title      = $isNew ? 'Warengruppe anlegen' : 'Warengruppe ' . $path;
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . (int) $group->getId());
$fields     = ['name', 'parent_id', 'default_tax_code', 'default_revenue_account'];
$invalidIds = array_values(array_map(static fn(string $f) => 'group-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$winAttr    = $window
    ? ' data-window="product-group-edit"' . ($isNew ? '' : ' data-window-entity="product-group:' . (int) $group->getId() . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="40rem"'
    : '';
$delete     = $isNew ? '' : '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . (int) $group->getId()) . '" data-window-link>Löschen …</a>';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="product-group-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) $version ?>">
        <?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => $isNew ? 'Anlegen' : 'Speichern',
            'extra'      => $delete,
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
        <?php endif; ?>

        <?php if ($validator->getErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="group-name">Name</label>
                <input type="text" id="group-name" name="name" value="<?= e($draft->name) ?>" maxlength="<?= ProductGroup::NAME_LENGTH ?>" required aria-invalid="<?= $invalid('name') ?>">
                <?= raw($fieldError('name')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="group-parent_id">Übergeordnete Gruppe</label>
                <select id="group-parent_id" name="parent_id" aria-invalid="<?= $invalid('parent_id') ?>">
                    <option value="0"<?= $draft->parentId === 0 ? ' selected' : '' ?>>– keine (oberste Ebene) –</option>
                    <?= $this->partial('partials/productGroupOptions', ['rows' => $parents, 'selected' => $draft->parentId], 'Z77\\Module\\Article') ?>
                </select>
                <?= raw($fieldError('parent_id')) ?>
            </div>
        </div>

        <div class="be-form__section">Vorgaben für neue Artikel</div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="group-default_tax_code">MWST-Code</label>
                <?= $this->partial('partials/taxCodeSelect', [
                    'codes'     => $codes,
                    'name'      => 'default_tax_code',
                    'selected'  => $draft->defaultTaxCode,
                    'id'        => 'group-default_tax_code',
                    'label'     => 'MWST-Code',
                    'invalid'   => $validator->hasFieldError('default_tax_code'),
                    'allowNone' => true,
                    'noneLabel' => $inherited['taxCode'] !== '' ? '– geerbt: ' . $inherited['taxCode'] . ' –' : '–',
                ], 'Z77\\Module\\Vat') ?>
                <?= raw($fieldError('default_tax_code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="group-default_revenue_account">Ertragskonto</label>
                <input type="text" id="group-default_revenue_account" name="default_revenue_account" value="<?= e($draft->defaultRevenueAccount) ?>" maxlength="<?= AbstractProduct::ACCOUNT_LENGTH ?>" inputmode="numeric" list="group-accounts"<?= $inherited['revenueAccount'] !== '' ? ' placeholder="geerbt: ' . e($inherited['revenueAccount']) . '"' : '' ?> aria-invalid="<?= $invalid('default_revenue_account') ?>">
                <?= raw($fieldError('default_revenue_account')) ?>
            </div>
        </div>
        <?= $this->partial('partials/accountDatalist', ['id' => 'group-accounts', 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
    </form>
</div>
