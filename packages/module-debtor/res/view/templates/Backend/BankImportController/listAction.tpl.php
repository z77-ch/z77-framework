<?php
/**
 * Zahlungseingänge (P4 part 2, plan §6.4) — the imported CAMT.054 messages,
 * newest first, each with its counts per state; the tab of the toolbar (`toolbar.tpl.php`)
 * picks «Offen» or «Erledigt» (owner 2026-11-15).
 *
 * The upload itself lives in the ACTION CELL (`act.tpl.php`, UPLOAD-001): a click opens
 * the file dialog, a drop anywhere on this work area is taken, and the queue of that
 * component runs in a window (UPLOAD-DIALOG-001, owner 2026-11-17) — nothing of it in this
 * page. Owner 2026-10-10, after the live look: an upload box in the middle of the page is a
 * ceremony this everyday task does not need.
 *
 * Styling: the shared backend list classes only.
 *
 * @var list<\Z77\Module\Debtor\Entities\BankMessage> $messages
 * @var array<string, string> $states
 * @var string $view  `open` | `done`
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/bank-import';
?>
<div class="be-list">
    <?php // NO upload box in the middle (owner 2026-10-10, after the live look): reading a
          // camt.054 is everyday work, the action cell's button is the whole surface it
          // needs. What stays here is the AREA — files dropped anywhere on the work area
          // are taken; the progress runs in a window (`upload.js`, UPLOAD-DIALOG-001). ?>
    <div class="be-list__section" id="bank-upload" data-upload-area>
        <p class="be-form__hint">Die Meldung wird gespeichert und jede Gutschrift über die QR-Referenz oder die Mitteilung einer verbuchten Rechnung zugeordnet. Verbucht wird erst auf der Meldung — nichts passiert beim Einlesen.</p>
    </div>

    <?= $this->partial('Backend/BankImportController/messages', ['messages' => $messages, 'view' => $view ?? 'open', 'actionBase' => $actionBase], 'Z77\Module\Debtor') ?>
</div>
