<?php
/** The receipt screen of the app shell (board «z77 App», approved 2026-11-12; E2, E5–E7, E10):
 *  contributed through `appScreens` in receiptConfig, rendered inside the shell's start page and
 *  driven by `_Z77.app.receipt` (res/assets/js/app/receipt.js). Three views on one page —
 *  tiles → capture → confirmation — so a tile tap can open the camera at once.
 *
 *  The server renders the frame only: the tiles (`GET /api/v1/receipt-templates`) and «Bezahlt mit»
 *  (`GET /api/v1/payment-methods`) are built by the script with DOM nodes and textContent — the
 *  device cookie lives on /api, so this page cannot know the device. No account on the phone (E2,
 *  E10). The symbols are an inline sprite (CSP: no external `<use>`, no `style=`). */
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Services\ReceiptIcons;
?>
<svg class="za-sprite" aria-hidden="true" focusable="false">
    <?php foreach (ReceiptIcons::GLYPHS as $key => $path): ?>
        <symbol id="receipt-icon-<?= e($key) ?>" viewBox="0 0 24 24"><path d="<?= e($path) ?>"/></symbol>
    <?php endforeach; ?>
</svg>

<div class="za-pad za-stack" data-receipt-view="tiles">
    <p class="za-muted">Was hast du bezahlt?</p>
    <p class="za-notice" data-receipt-tiles-notice hidden></p>
    <div class="za-tiles" data-receipt-tiles>
        <button type="button" class="za-tile za-tile-wide" data-receipt-template="">
            <span class="za-tile-icon"><svg class="za-icon" aria-hidden="true"><use href="#receipt-icon-<?= e(ReceiptIcons::DEFAULT) ?>"></use></svg></span>
            <span>Ohne Vorlage</span>
        </button>
    </div>
    <div class="za-card" data-receipt-sent hidden>
        <span class="za-small za-muted">Heute gesendet · <span data-receipt-sent-count></span></span>
        <ul class="za-list" data-receipt-sent-list></ul>
    </div>
</div>

<form class="za-pad za-stack" data-receipt-view="capture" method="post" action="/api/v1/receipts" enctype="multipart/form-data" novalidate hidden>
    <button type="button" class="za-back" data-receipt-back>
        <svg class="za-icon" aria-hidden="true"><use href="#za-i-back"></use></svg>Neuer Beleg
    </button>
    <label class="za-photo">
        <input type="file" name="photo" accept="image/*" capture="environment" class="za-photo-input" data-receipt-photo-input>
        <canvas class="za-photo-preview" data-receipt-preview hidden></canvas>
        <span class="za-photo-empty" data-receipt-photo-empty>
            <svg class="za-icon za-icon-lg" aria-hidden="true"><use href="#za-i-camera"></use></svg>
            Foto aufnehmen
        </span>
        <span class="za-photo-again" data-receipt-photo-again hidden>Neu aufnehmen</span>
    </label>
    <p class="za-error" data-error-for="photo" role="alert" hidden></p>

    <div class="za-chiprow">
        <span class="za-chip">
            <svg class="za-icon za-icon-sm" aria-hidden="true"><use href="#receipt-icon-<?= e(ReceiptIcons::DEFAULT) ?>" data-receipt-chip-icon></use></svg>
            <span data-receipt-chip-label>Ohne Vorlage</span>
        </span>
        <button type="button" class="za-link" data-receipt-change>ändern</button>
    </div>
    <p class="za-small za-muted" data-receipt-no-template>Konten ergänzt du im Journal.</p>
    <input type="hidden" name="template" value="" data-receipt-template-input>
    <p class="za-error" data-error-for="template" role="alert" hidden></p>

    <label class="za-field">
        <span class="za-label">Betrag CHF</span>
        <input class="za-input za-amount" name="amount" inputmode="decimal" autocomplete="off" required data-receipt-amount>
    </label>
    <p class="za-error" data-error-for="amount" role="alert" hidden></p>

    <label class="za-field">
        <span class="za-label">Datum</span>
        <input class="za-input" type="date" name="date" required data-receipt-date>
    </label>
    <p class="za-error" data-error-for="date" role="alert" hidden></p>

    <label class="za-field">
        <span class="za-label">Text</span>
        <input class="za-input" name="text" maxlength="<?= (int) Receipt::TEXT_LENGTH ?>" autocomplete="off" data-receipt-text>
    </label>
    <p class="za-error" data-error-for="text" role="alert" hidden></p>

    <fieldset class="za-field za-choice" data-receipt-payment hidden>
        <legend class="za-label">Bezahlt mit</legend>
        <div class="za-chips" data-receipt-payment-options></div>
    </fieldset>
    <p class="za-error" data-error-for="payment_method" role="alert" hidden></p>

    <p class="za-error" data-error-for="_" role="alert" hidden></p>
    <button type="submit" class="za-primary" data-receipt-submit>Senden</button>
</form>

<div class="za-pad za-stack za-done" data-receipt-view="done" hidden>
    <span class="za-done-mark" data-receipt-done-mark><svg class="za-icon" aria-hidden="true"><use href="#za-i-check"></use></svg></span>
    <h1 class="za-h1" data-receipt-done-title>Beleg erfasst</h1>
    <div class="za-card">
        <span class="za-row"><strong data-receipt-done-label></strong><span class="za-mono" data-receipt-done-amount></span></span>
        <span class="za-row za-muted"><span data-receipt-done-text></span><span class="za-mono" data-receipt-done-date></span></span>
    </div>
    <p class="za-muted" data-receipt-done-note>Liegt im Belegjournal. Verbucht wird Ende Monat im Backend.</p>
    <button type="button" class="za-primary" data-receipt-next>Nächster Beleg</button>
    <button type="button" class="za-secondary" data-receipt-finish>Fertig</button>
</div>
