<?php
/**
 * «Buchungsdruck» refused (owner 2026-10-10) — the PAGE the new tab of the
 * booking print shows instead of the PDF: nothing was booked. Says why (the
 * service's German message), names the documents of the selection that ARE
 * booked by now (another batch, or a stale repeat of an older selection),
 * and leads back to the lists. A repeated submit of the SAME selection never
 * lands here — it reprints the PDF (`InvoiceControllerTrait::finalizeAction()`).
 *
 * @var string        $message
 * @var list<string>  $already  names of documents of the selection that are booked now
 * @var string        $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="z77-form-actions">
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/list') ?>">Zur Liste «In Fakturierung»</a>
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/list?view=final') ?>">Zur Liste «Verbucht»</a>
        </div>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Buchungsdruck <small class="be-list__cell--muted">· nichts verbucht</small></h2>
        </div>
        <div class="be-modal__alert be-modal__alert--error"><?= e($message) ?></div>
        <?php if ($already !== []): ?>
        <p class="be-form__hint">Bereits verbucht: <?= e(implode(', ', $already)) ?> — ihr PDF lässt sich auf dem Dokument jederzeit wieder öffnen.</p>
        <?php endif; ?>
    </div>
</div>
