<?php
/**
 * The options of the shared VAT select (board S5-1, part 2; Rule 9 — ONE building block of the
 * receipt window and the template window): the groups `ReceiptChoices::taxChoices()` builds, each
 * option's value a token `ReceiptFields::vatChoice()` parses («-», a code, `VM:nur-steuer`). The
 * group «Bezugsteuer» (board S6-2; only when the caller asked `taxChoices(…, reverseCharge: true)`)
 * renders like any other: its options are plain codes (`BM`). What a choice does to «Soll» is the
 * server's answer to the select's `tax-fill` (core.js «fill»), not markup here. The placeholder
 * option (if any) is the caller's.
 *
 * @var list<array{group: string, options: list<array{value: string, label: string}>}> $groups
 * @var string $selected  the token to select
 */
$option = static fn(array $o): string
    => '<option value="' . e($o['value']) . '"' . ($o['value'] === $selected ? ' selected' : '') . '>' . e($o['label']) . '</option>';
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
