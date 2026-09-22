<?php
/**
 * «Variante anlegen»: copies a live document into a variant set (ADR-044 addendum).
 * Posts back to the URL it was loaded from (create-variant?slug=…&language=…).
 *
 * @var \Z77\Shared\Entities\Content $content  the live copy
 * @var array<int,string> $sets  existing sets that do not contain this document yet
 * @var string $entityCsrf
 */
?>
<form data-fetch-post>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Variante anlegen — «<?= e($content->getTitle() !== '' ? $content->getTitle() : $content->getSlug()) ?>»</h2>
        <span class="be-lang-tag" title="Sprache"><?= e(strtoupper($content->getLanguage())) ?></span>
    </div>
    <div class="be-modal__body">
        <p>Die Live-Fassung wird kopiert. Die Kopie ist im Frontend nur mit dem Vorschau-Link des Satzes sichtbar, bis der Satz veröffentlicht wird.</p>

        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Satz</label>
                <select name="set">
                    <option value="" selected>Neuer Satz</option>
                    <?php foreach ($sets as $key): ?>
                    <option value="<?= e($key) ?>"><?= e($key) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="be-form__hint">Alle Dokumente mit demselben Satz werden zusammen angezeigt und veröffentlicht.</small>
            </div>

            <div class="be-form__field" data-z77-field-wrapper>
                <label>Name des neuen Satzes</label>
                <input type="text" name="name" value="" autocomplete="off" placeholder="z.B. herbst">
                <small class="be-form__hint">Nur für «Neuer Satz». Kleinbuchstaben, Ziffern und Bindestrich; eine Zufallsendung wird angehängt (herbst-a7f3k2).</small>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Anlegen</button>
    </div>
</form>
