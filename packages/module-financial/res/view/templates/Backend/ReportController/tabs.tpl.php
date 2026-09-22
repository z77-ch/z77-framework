<?php
/**
 * The report pages as a tab row (shell slot `tabs`, `.be-viewtabs`): five
 * views of one ledger. Each link carries the current fiscal year and range,
 * so switching the report keeps what is being looked at. No JavaScript.
 *
 * @var array<string,string> $reportTabs  URL action → label
 * @var string $tab
 * @var callable $link
 */
?>
<nav class="be-viewtabs" aria-label="Auswertungen">
    <?php foreach ($reportTabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($link($key)) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
