<?php
/**
 * The body of an address card: one case per choice of its select (`_ship`), only the chosen one
 * shown — core.js «small triggers» (`select[data-switch]`) shows another case when the choice
 * changes, without a round trip (owner 2026-10-18: «die Adresse ändert sich nicht, wenn ich eine
 * andere auswähle»). Every case is rendered here by the server; the script only toggles `hidden`.
 *
 * One partial for the window and for the answer to a customer choice (`replace-html` on
 * `#order-{field}-cases`): the cases belong to the customer's addresses.
 *
 * @var string                      $field     'ship' | 'bill'
 * @var array<string, list<string>> $cases     choice → its address lines ([] = none)
 * @var string                      $selected
 * @var bool                        $adjusted  the stored snapshot («keep») is none of the customer's addresses
 * @var string                      $fields    the «angepasst» fields (HTML) — '' while the card is not adjusted
 */
use Z77\Module\Order\Ui\OrderForm;

?>
<div id="order-<?= e($field) ?>-cases">
    <?php foreach ($cases as $value => $lines): ?>
    <?php $value = (string) $value; ?>
    <div data-switch-case="<?= e($value) ?>"<?= $value === $selected ? '' : ' hidden' ?>>
        <?php if ($value === OrderForm::ADDRESS_CUSTOM): ?>
        <?= raw($fields) ?>
        <?php elseif ($lines === []): ?>
        <div class="be-form__fact"><?= e(OrderForm::ADDRESS_NONE[$field] ?? '') ?></div>
        <?php else: ?>
        <?php if ($adjusted && $value === OrderForm::SHIP_KEEP): ?><div><span class="badge badge--warning">angepasst</span></div><?php endif; ?>
        <div class="be-form__address"><?php foreach ($lines as $line): ?><div><?= e($line) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
