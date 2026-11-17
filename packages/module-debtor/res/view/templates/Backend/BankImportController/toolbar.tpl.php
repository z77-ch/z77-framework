<?php
/**
 * Zahlungseingänge — the toolbar (hc2): the two tabs (`.be-viewtabs`, no counts like the other
 * lists of the area — owner 2026-10-16: «less is more»), nothing else. «Offen» (the plain
 * `/list`, the default) holds the messages with a transaction not yet booked or ignored,
 * «Erledigt» the rest (owner 2026-11-15). The upload stays in the action cell on both.
 *
 * `data-bank-tabs`: an upload answers this nav together with the messages region — it shows
 * «Offen», where a new message lands ({@see \Z77\Module\Debtor\Ui\BankImportControllerTrait}).
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $view
 * @var array<string, string> $views   view → label
 * «Bankabgleich …» (ADR-061 stage C3b): only while a bank account reads camt.053 (`$bankMatch`) —
 * opens the window of the camt.054 entries and their camt.053 credits. It stands OUTSIDE the
 * `data-bank-tabs` nav: an upload's answer replaces the nav only, the button stays.
 *
 * @var string $actionBase
 * @var bool   $bankMatch
 */
$actionBase = $actionBase ?? '/backend/finance/bank-import';
$default    = array_key_first($views);
?>
<nav class="be-viewtabs" aria-label="Zahlungseingänge" data-bank-tabs>
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === $default ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!empty($bankMatch)): $matchUrl = $actionBase . '/bank-match'; ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($matchUrl) ?>" data-window-open="<?= e($matchUrl) ?>" data-bank-match>Bankabgleich …</a>
<?php endif; ?>
