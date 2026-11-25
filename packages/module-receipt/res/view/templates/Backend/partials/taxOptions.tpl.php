<?php
/**
 * The options of the shared VAT select (board S5-1, part 2; Rule 9 — ONE building block of the
 * receipt window and the template window): the groups `ReceiptChoices::taxChoices()` builds, each
 * option's value a token `ReceiptFields::vatChoice()` parses («-», a code, `VM:nur-steuer`). A «nur
 * Steuer» option names the input account its code posts on (`data-tax-only-account`, VM → 1170) —
 * what the script `backend/tax-only` shows in the locked «Soll». The placeholder option (if any) is
 * the caller's.
 *
 * @var list<array{group: string, options: list<array{value: string, label: string}>}> $groups
 * @var string $selected  the token to select
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 */
use Z77\Module\Receipt\Services\ReceiptFields;

$option = static function (array $o) use ($selected, $choices): string {
    $parts   = ReceiptFields::vatTokenParts($o['value']);
    $account = $parts['taxOnly'] ? ' data-tax-only-account="' . e((string) $choices->taxOnlyAccount($parts['code'])) . '"' : '';

    return '<option value="' . e($o['value']) . '"' . $account . ($o['value'] === $selected ? ' selected' : '') . '>' . e($o['label']) . '</option>';
};
?>
<?php foreach ($groups as $group): ?>
<?php if ($group['group'] === ''): ?>
<?php foreach ($group['options'] as $o): ?><?= raw($option($o)) ?>
<?php endforeach; ?>
<?php else: ?>
<optgroup label="<?= e($group['group']) ?>">
<?php foreach ($group['options'] as $o): ?><?= raw($option($o)) ?>
<?php endforeach; ?>
</optgroup>
<?php endif; ?>
<?php endforeach; ?>
