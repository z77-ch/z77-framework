<?php
/**
 * Finanzen › «Bankkonto» — the toolbar (hc2): the four views of the ONE list «Bewegungen»
 * (`.be-viewtabs`, ADR-061 stage C2): «Alle» · «Zu prüfen» (with its count — the work that is
 * left) · «Geschäftlich» · «Privat» (`?state=review|business|private`). The selected bank account
 * is kept. For a BANK-DRIVEN account (ADR-061 stage C4) the tool «Abgleich …» follows — the window
 * `reconcile`. «Hilfe» is written by the shell at the right end (HELP-003).
 *
 * Part of the fragment: added by the trait.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount|null $account
 * @var string|null $view  the state shown, null = all
 * @var array<string, int> $counts  state → number of movements of the bank account
 * @var string $actionBase
 */
use Z77\Module\Financial\Repositories\BankMovementRepository;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::MOVEMENT_BASE;
$view       = $view ?? null;
$review     = (int) ($counts[BankMovementRepository::STATE_REVIEW] ?? 0);
$code       = isset($account) && $account !== null ? ['account' => $account->getCode()] : [];
$tabs       = [
    ['Alle', null],
    ['Zu prüfen' . ($review > 0 ? ' (' . $review . ')' : ''), BankMovementRepository::STATE_REVIEW],
];
// «Geschäftlich» / «Privat» only on a «gemischt geführt» account (owner 2026-11-19); «Zu prüfen» stays — the open work.
if (!isset($account) || $account === null || $account->isMixedUse()) {
    $tabs[] = ['Geschäftlich', BankMovementRepository::STATE_BUSINESS];
    $tabs[] = ['Privat', BankMovementRepository::STATE_PRIVATE];
}
?>
<nav class="be-viewtabs" aria-label="Bewegungen">
    <?php foreach ($tabs as [$label, $key]): ?>
    <?php $query = $code + ($key !== null ? ['state' => $key] : []); ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($query !== [] ? '?' . http_build_query($query) : '')) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (isset($account) && $account !== null && $account->isBankDriven()): $reconcileUrl = $actionBase . '/reconcile?' . http_build_query($code); ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($reconcileUrl) ?>" data-window-open="<?= e($reconcileUrl) ?>" data-bank-reconcile-open title="Buchhaltung und Bank vergleichen">Abgleich …</a>
<?php endif; ?>
