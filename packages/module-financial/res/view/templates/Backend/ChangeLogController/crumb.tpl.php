<?php
/**
 * Finanzen › Änderungsprotokoll › Abschlüsse und MWST — the crumb line (hc3) of the second tab
 * (board S2-9). Position only (ADR-033); the first tab keeps the default crumb.
 *
 * @var array<string, string> $changeLogTabs
 * @var string $changeLogTab
 * @var callable(string): string $changeLogTabHref
 */
?>
<nav class="be-crumb" aria-label="Pfad">
    <span>Finanzen</span>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <a href="<?= e($changeLogTabHref('list')) ?>">Änderungsprotokoll</a>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <span class="be-crumb__here"><?= e($changeLogTabs[$changeLogTab] ?? '') ?></span>
</nav>
