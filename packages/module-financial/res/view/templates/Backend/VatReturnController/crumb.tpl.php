<?php
/**
 * One VAT return — the crumb line (hc3): Finanzen › MWST-Abrechnungen › 3. Quartal 2026 (›
 * Ziffer 303 on the entries list). Position only (ADR-033).
 *
 * @var string $base
 * @var string $periodLabel   '' when no period could be named (a refusal)
 * @var string $crumbField    the field of the entries list, '' elsewhere
 * @var callable $tabUrl
 */
$crumbField = $crumbField ?? '';
?>
<nav class="be-crumb" aria-label="Pfad">
    <span>Finanzen</span>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <?php if ($periodLabel === ''): ?>
    <a class="be-crumb__here" href="<?= e($base . '/list') ?>">MWST-Abrechnungen</a>
    <?php else: ?>
    <a href="<?= e($base . '/list') ?>">MWST-Abrechnungen</a>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <?php if ($crumbField === ''): ?>
    <span class="be-crumb__here"><?= e($periodLabel) ?></span>
    <?php else: ?>
    <a href="<?= e($tabUrl('origin')) ?>"><?= e($periodLabel) ?></a>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <span class="be-crumb__here">Ziffer <?= e($crumbField) ?></span>
    <?php endif; ?>
    <?php endif; ?>
</nav>
