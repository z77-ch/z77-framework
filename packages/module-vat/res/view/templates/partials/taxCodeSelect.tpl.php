<?php
/**
 * The tax-code picker — a shared building block (Rule 8; deferred by `vat.md`
 * to the first document editor, built with debtor's invoice editor in P3
 * part 3). A plain `<select>`, no JavaScript: the codes a NEW reference may
 * take (active ones) plus the code the record already carries — the list
 * comes from `TaxCodeRepository::selectable($keep)`, so the reference rule
 * (ADR-043 decision 19) is decided there, not in the template. A kept but
 * deactivated code says «inaktiv»; a code the list does not know (a hand-edited
 * file) stays selected with «?» so a re-render never silently changes it.
 *
 * Rendered with `$this->partial('partials/taxCodeSelect', […], 'Z77\\Module\\Vat')`.
 *
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $codes  `TaxCodeRepository::selectable()`
 * @var string      $name      the field name (`tax_code[]`)
 * @var string      $selected  the current value, '' = none
 * @var string      $id        the element id (for a label / the action bar's error link)
 * @var string      $label     the accessible name
 * @var bool        $invalid   the field carries an error
 * @var bool        $allowNone offer «–» (no code) — a text line has none
 * @var string      $noneLabel optional text of that option (default «–») — a product group says
 *                             what it inherits there («– geerbt: UN –», module-article)
 * @var array<string, string|true> $attrs optional further attributes (`disabled` => true,
 *                             `data-fill-key` => 'tax_code' — an order line locked to its article)
 */
$selected  = (string) ($selected ?? '');
$allowNone = $allowNone ?? true;
$noneLabel = (string) ($noneLabel ?? '–');
$known     = false;
$extra     = '';
foreach ($attrs ?? [] as $attr => $value) {
    $extra .= ' ' . e($attr) . ($value === true ? '' : '="' . e((string) $value) . '"');
}
?>
<select class="be-input be-input--sm" name="<?= e($name) ?>"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?> aria-label="<?= e($label ?? 'MWST-Code') ?>" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>"<?= raw($extra) ?>>
    <?php if ($allowNone): ?>
    <option value=""<?= $selected === '' ? ' selected' : '' ?>><?= e($noneLabel) ?></option>
    <?php endif; ?>
    <?php foreach ($codes as $code): $known = $known || $code->getCode() === $selected; ?>
    <option value="<?= e($code->getCode()) ?>"<?= $code->getCode() === $selected ? ' selected' : '' ?> title="<?= e($code->getLabel()) ?>"><?= e($code->getCode()) ?><?= $code->isActive() ? '' : ' (inaktiv)' ?></option>
    <?php endforeach; ?>
    <?php if ($selected !== '' && !$known): ?>
    <option value="<?= e($selected) ?>" selected><?= e($selected) ?> (?)</option>
    <?php endif; ?>
</select>
