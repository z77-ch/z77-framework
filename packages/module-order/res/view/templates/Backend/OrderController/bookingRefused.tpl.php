<?php
/**
 * «Buchungsdruck» refused — the PAGE the new tab of the booking print shows instead of the PDF:
 * nothing was booked. Says why (naming the order), names the orders of the selection that ARE
 * booked by now (another booking print, a stale repeat of an older selection), and leads back
 * to the lists. A repeated submit of the SAME selection never lands here — it reprints the PDF
 * (`OrderControllerTrait::bookAction()`).
 *
 * @var string        $message
 * @var list<string>  $already  «Auftrag 12 (Rechnung 26100009)»
 * @var string        $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="z77-form-actions">
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/list?view=delivered') ?>">Zur Liste «Geliefert»</a>
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/list?view=closed') ?>">Zur Liste «Abgeschlossen»</a>
        </div>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Fakturieren <small class="be-list__cell--muted">· nichts fakturiert</small></h2>
        </div>
        <div class="be-modal__alert be-modal__alert--error"><?= e($message) ?></div>
        <?php if ($already !== []): ?>
        <p class="be-form__hint">Bereits fakturiert: <?= e(implode(', ', $already)) ?> — die Rechnung lässt sich im Auftrag unter «Belege» jederzeit wieder öffnen.</p>
        <?php endif; ?>
    </div>
</div>
