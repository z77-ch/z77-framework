<?php
/**
 * The yearly overview — the crumb line (hc3): Finanzen › MWST-Abrechnungen › Jahresübersicht 2026.
 * Position only (ADR-033).
 *
 * @var ?int $taxYear  null when no tax year could be named (a refusal without a fiscal year)
 * @var string $returnBase
 */
$taxYear = $taxYear ?? null;
?>
<nav class="be-crumb" aria-label="Pfad">
    <span>Finanzen</span>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <a href="<?= e($returnBase . '/list') ?>">MWST-Abrechnungen</a>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <span class="be-crumb__here">Jahresübersicht<?= $taxYear === null ? '' : ' ' . (int) $taxYear ?></span>
</nav>
