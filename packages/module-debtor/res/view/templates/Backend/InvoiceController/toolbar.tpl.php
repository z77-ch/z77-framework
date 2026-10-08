<?php
/**
 * Documents — the toolbar (hc2): the three views as tabs (`.be-viewtabs`,
 * each with its count), «Rechnung erstellen» and — in the invoicing view —
 * «Definitiv stellen …», a submit OUTSIDE the list's selection form
 * (`form="invoice-finalize"`, no script). All act on the list in the work
 * area (ADR-033 rev. 2026-09-28).
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var array<string, string> $views   view → label
 * @var array<string, int>    $counts  view → documents
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$current    = $state->extra('view');
?>
<nav class="be-viewtabs" aria-label="Dokumente">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $current ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === 'invoicing' ? '' : '?view=' . $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?> <small><?= (int) ($counts[$key] ?? 0) ?></small></a>
    <?php endforeach; ?>
</nav>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/add">Rechnung erstellen</a>
<?php if ($current === 'invoicing'): ?>
<button type="submit" form="invoice-finalize" class="be-btn be-btn--primary be-btn--sm">
    <span class="be-btn__label">Definitiv stellen …</span>
</button>
<?php endif; ?>
