<?php
/**
 * Confirm «KMU-Kontenrahmen übernehmen». Offered only while the chart is
 * empty; the service refuses it otherwise (owner, 2026-09-22).
 *
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/account';
?>
<form data-fetch-post="<?= e($actionBase) ?>/adopt-kmu-chart">
    <div class="be-modal__header">
        <h2 class="be-modal__title">KMU-Kontenrahmen übernehmen</h2>
    </div>
    <div class="be-modal__body">
        <p>Der Schweizer KMU-Kontenrahmen wird als Ausgangslage in den leeren Kontenplan übernommen: die Klassen 1–9,
           ihre Haupt- und Untergruppen und die gebräuchlichen Konten.</p>
        <p class="be-form__hint">Danach lassen sich Konten ergänzen, umbenennen und deaktivieren. Eine Installation, die ihren
           bisherigen Kontenplan übernimmt, braucht diesen Schritt nicht.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Übernehmen', 'end' => true], 'Z77\\Shared') ?>
</form>
