<?php
/**
 * Confirm blocking a country. The reason field arrives PREFILLED with the
 * tally from the log — that is the point of blocking from this page: the
 * record keeps the evidence, not just the verdict. The prefilled sentence
 * names its counting window, so the number cannot be mistaken for a total.
 *
 * @var string $code
 * @var string $reason
 * @var string $entityCsrf
 * @var string $actionBase
 */
?>
<form data-fetch-post="<?= e($actionBase) ?>/block">
    <input type="hidden" name="code"        value="<?= e($code) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Land «<?= e($code) ?>» sperren</h2>
    </div>
    <div class="be-modal__body">
        <p>Übermittlungen aus <strong><?= e($code) ?></strong> werden ab sofort
           abgewiesen — auf jedem Formular mit eingeschaltetem Geo-Guard, andere
           Formulare sind nicht betroffen. Wessen Herkunft der Datenbestand nicht
           kennt, kommt weiterhin durch.</p>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">
            Ein Kunde in den Ferien, hinter einem VPN oder bei einem Anbieter, der
            über das Ausland routet, sieht von hier aus wie eine Anfrage aus diesem
            Land. Sperren Sie, was die Auszählung belegt — nicht, was plausibel klingt.
        </p>
        <div class="be-form__field" data-z77-field-wrapper>
            <label>Grund <small>(steht später im Protokoll der Sperrliste)</small></label>
            <textarea name="reason" rows="2" required><?= e($reason) ?></textarea>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Land sperren</button>
    </div>
</form>
