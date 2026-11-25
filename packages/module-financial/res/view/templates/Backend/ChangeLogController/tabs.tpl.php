<?php
/**
 * Finanzen › Änderungsprotokoll — the two protocols as tabs in the TOOLBAR (hc2, `.be-viewtabs`,
 * owner 2026-11-25, board S2-9): «Buchungen» (the journal's change log) · «Abschlüsse und MWST»
 * (the year close and the VAT return). One page each, like the report tabs; a link keeps the
 * fiscal year or «Alle Jahre». No JavaScript.
 *
 * @var array<string, string> $changeLogTabs  URL action → label
 * @var string $changeLogTab
 * @var callable(string): string $changeLogTabHref
 */
?>
<nav class="be-viewtabs" aria-label="Änderungsprotokoll">
    <?php foreach ($changeLogTabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $changeLogTab ? ' is-active' : '' ?>" href="<?= e($changeLogTabHref($key)) ?>"<?= $key === $changeLogTab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
