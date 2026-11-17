<?php
/**
 * Finanzen › «Bankkonto» — the toolbar (hc2): the two views of the ONE list «Bewegungen»
 * (`.be-viewtabs`, ADR-061 stage C1): «Alle» and «Ohne Zuordnung» — the movements nothing is
 * linked to yet (`?link=free`). The selected bank account is kept. «Hilfe» is written by the
 * shell at the right end (HELP-003).
 *
 * Part of the fragment: added by the trait.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount|null $account
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $actionBase
 */
use Z77\Module\Financial\Ui\BankMovementListing;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::MOVEMENT_BASE;
$free       = isset($state) && $state->extra('link') === BankMovementListing::LINK_FREE;
$code       = isset($account) && $account !== null ? ['account' => $account->getCode()] : [];
$tabs       = [
    ['Alle', $code, !$free],
    ['Ohne Zuordnung', $code + ['link' => BankMovementListing::LINK_FREE], $free],
];
?>
<nav class="be-viewtabs" aria-label="Bewegungen">
    <?php foreach ($tabs as [$label, $query, $active]): ?>
    <a class="be-viewtabs__tab<?= $active ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($query !== [] ? '?' . http_build_query($query) : '')) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
