<?php
/**
 * The article window (plan §4.1 / §9.5, P6 slim) — ONE flat article: one form, one save. The
 * product and its hidden default variant are one mask (owner Q2 2026-10-14); the price is ONE
 * field with «gültig ab» (owner Q3) — the history is shown below the form, read-only. A plain
 * form, no JavaScript of its own (Rule 7): `csrf_token` (`#[Csrf]`), an edit also carries the
 * entity token and the VERSION it was rendered from (`ArticleService::update()`).
 *
 * Fetched, a WINDOW (ADR-047): the root declares the mask `article-edit` and the record, the
 * title moves into the title bar, the form carries `_origin` back. The action row (ADR-049) is
 * the shared `partials/modalActions`, first in the form: Speichern · «Löschen …» (an existing
 * article — the confirmation loads into the same window) · Abbrechen · «N Fehler». No
 * explanations in the form — the help (ADR-048, `edit.help`) has a section per field.
 *
 * @var \Z77\Module\Article\Entities\Product|null $product  null = new
 * @var \Z77\Module\Article\Entities\Variant|null $variant
 * @var \Z77\Module\Article\Services\ArticleDraft $draft    what the form shows (stored or typed)
 * @var int $version
 * @var \Z77\Module\Article\Validators\ArticleValidator $validator
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $codes      `TaxCodeRepository::selectable()`
 * @var list<array{group: \Z77\Module\Article\Entities\ProductGroup, depth: int}> $groups  `ProductGroupTree::selectable()`
 * @var list<array{number: string, label: string}> $accounts  `LedgerAccountCheck::documentAccounts()`
 * @var bool $ledger      module-financial is there — the account is checked
 * @var list<\Z77\Module\Article\Entities\Price> $history  newest first
 * @var callable $fmt
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Article\Entities\AbstractProduct;
use Z77\Module\Article\Entities\AbstractVariant;
use Z77\Module\Vat\Calculation\PriceMode;

$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE;
$isNew      = $product === null;
$window     = !empty($window);
$title      = $isNew ? 'Artikel anlegen' : 'Artikel ' . ($variant?->getNumber() ?? '') . ' — ' . $product->getName();
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . (int) $product->getId());
$fields     = ['product_group', 'number', 'name', 'unit', 'description','price', 'valid_from', 'price_mode', 'tax_code', 'revenue_account', 'stock_relevant'];
$invalidIds = array_values(array_map(static fn(string $f) => 'article-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$winAttr    = $window
    ? ' data-window="article-edit"' . ($isNew ? '' : ' data-window-entity="article:' . (int) $product->getId() . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="46rem"'
    : '';
$delete     = $isNew ? '' : '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . (int) $product->getId()) . '" data-window-link>Löschen …</a>';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="article-form" autocomplete="off" novalidate>
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
                <label for="article-product_group">Warengruppe</label>
                <select id="article-product_group" name="product_group" required aria-invalid="<?= $invalid('product_group') ?>">
                    <option value="0"<?= $draft->productGroup === 0 ? ' selected' : '' ?>>– wählen –</option>
                    <?= $this->partial('partials/productGroupOptions', ['rows' => $groups, 'selected' => $draft->productGroup], 'Z77\\Module\\Article') ?>
                </select>
                <?= raw($fieldError('product_group')) ?>
            </div>
        </div>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-number">Artikelnummer</label>
                <input type="text" id="article-number" name="number" value="<?= e($draft->number) ?>" maxlength="<?= AbstractVariant::NUMBER_LENGTH ?>" required spellcheck="false" aria-invalid="<?= $invalid('number') ?>">
                <?= raw($fieldError('number')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-name">Bezeichnung</label>
                <input type="text" id="article-name" name="name" value="<?= e($draft->name) ?>" maxlength="<?= AbstractProduct::NAME_LENGTH ?>" required aria-invalid="<?= $invalid('name') ?>">
                <?= raw($fieldError('name')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-unit">Einheit</label>
                <input type="text" id="article-unit" name="unit" value="<?= e($draft->unit) ?>" maxlength="<?= AbstractVariant::UNIT_LENGTH ?>" aria-invalid="<?= $invalid('unit') ?>">
                <?= raw($fieldError('unit')) ?>
            </div>
        </div>

        <div class="be-form__grid be-form__grid--single">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-description">Beschreibung</label>
                <textarea id="article-description" name="description" rows="4" maxlength="<?= AbstractProduct::DESCRIPTION_LENGTH ?>" aria-invalid="<?= $invalid('description') ?>"><?= e($draft->description) ?></textarea>
                <?= raw($fieldError('description')) ?>
            </div>
        </div>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-price">Preis</label>
                <input type="text" id="article-price" name="price" value="<?= e($draft->price) ?>" inputmode="decimal" required aria-invalid="<?= $invalid('price') ?>">
                <?= raw($fieldError('price')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-valid_from">Gültig ab</label>
                <input type="date" id="article-valid_from" name="valid_from" value="<?= e($draft->validFrom) ?>" required aria-invalid="<?= $invalid('valid_from') ?>">
                <?= raw($fieldError('valid_from')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-price_mode">Preis ist</label>
                <select id="article-price_mode" name="price_mode" aria-invalid="<?= $invalid('price_mode') ?>">
                    <option value="<?= PriceMode::Net->value ?>"<?= $draft->priceMode === PriceMode::Net->value ? ' selected' : '' ?>>netto</option>
                    <option value="<?= PriceMode::Gross->value ?>"<?= $draft->priceMode === PriceMode::Gross->value ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                </select>
                <?= raw($fieldError('price_mode')) ?>
            </div>
        </div>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-tax_code">MWST-Code</label>
                <?= $this->partial('partials/taxCodeSelect', [
                    'codes'     => $codes,
                    'name'      => 'tax_code',
                    'selected'  => $draft->taxCode,
                    'id'        => 'article-tax_code',
                    'label'     => 'MWST-Code',
                    'invalid'   => $validator->hasFieldError('tax_code'),
                    'allowNone' => true,
                ], 'Z77\\Module\\Vat') ?>
                <?= raw($fieldError('tax_code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="article-revenue_account">Ertragskonto</label>
                <input type="text" id="article-revenue_account" name="revenue_account" value="<?= e($draft->revenueAccount) ?>" maxlength="<?= AbstractProduct::ACCOUNT_LENGTH ?>" inputmode="numeric" required list="article-accounts" aria-invalid="<?= $invalid('revenue_account') ?>">
                <?= raw($fieldError('revenue_account')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="article-stock_relevant" name="stock_relevant" value="1"<?= $draft->stockRelevant ? ' checked' : '' ?>>
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Führt Bestand</span>
                </label>
                <?= raw($fieldError('stock_relevant')) ?>
            </div>
        </div>
        <?= $this->partial('partials/accountDatalist', ['id' => 'article-accounts', 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>

        <?php if (!$isNew): ?>
        <div class="be-form__section">Preisverlauf <small>(neuster zuerst — ein neuer Preis mit «Gültig ab» ergänzt ihn, am selben Tag ersetzt er den Eintrag des Tages)</small></div>
        <?php if ($history === []): ?>
        <p class="be-list__empty">Noch kein Preis.</p>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: 8rem 8rem minmax(6rem, 1fr)">
            <?php foreach ($history as $row): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell">ab <?= e($row->getValidFrom()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($row->getAmount())) ?></span>
                    <span class="be-list__cell be-list__cell--muted"><?= $row->priceMode() === PriceMode::Gross ? 'brutto' : 'netto' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </form>
</div>
