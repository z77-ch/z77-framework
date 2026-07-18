<?php
/**
 * Form-mail settings edit modal — to/cc (one address per line), subject, and
 * the route rows (parallel route_key[i]/route_to[i]/route_subject[i] maps —
 * core.js supports one bracket level). Existing routes render first, then
 * $emptyRouteRows blank rows for additions; a row with an empty Auswahl-Wert
 * is ignored on save. Deleting a route = clearing its Auswahl-Wert.
 *
 * @var \Z77\Shared\Entities\EmailFormSetting $entry
 * @var string $entityCsrf
 * @var bool $isOverride  true when a backend record exists (vs. config seed values)
 * @var bool $isDormant   true when the override exists but is toggled off (config applies)
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var int $emptyRouteRows
 */

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};

$routeRows = [];
foreach ($entry->getRoutes() as $routeKey => $route) {
    $routeRows[] = [
        'key'     => (string) $routeKey,
        'to'      => implode(', ', $route['to']),
        'subject' => (string) ($route['subject'] ?? ''),
    ];
}
for ($i = 0; $i < $emptyRouteRows; $i++) {
    $routeRows[] = ['key' => '', 'to' => '', 'subject' => ''];
}
?>
<form data-fetch-post>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <input type="hidden" name="form_key" value="<?= e($entry->getFormKey()) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">E-Mail-Einstellungen «<?= e($entry->getFormKey()) ?>»</h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <?php if (!$isOverride): ?>
        <p style="font-size:.75rem;color:var(--be-muted,#94a3b8);margin:0 0 .75rem">
            Aktuell gilt die Entwickler-Vorgabe (Config). Speichern legt eine Backend-Übersteuerung an (aktiv).
        </p>
        <?php elseif ($isDormant): ?>
        <div class="be-modal__alert" style="margin-bottom:.75rem">
            Diese Übersteuerung ist derzeit <strong>inaktiv</strong> — es gilt die Config. Änderungen werden
            gespeichert, aber erst wirksam, wenn du sie über den Schalter in der Liste aktivierst.
        </div>
        <?php endif; ?>
        <div class="be-form__grid" style="grid-template-columns:1fr 1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Empfänger <small>(eine Adresse pro Zeile)</small></label>
                <textarea name="to" rows="3" required
                          aria-invalid="<?= $validator->hasFieldError('to') ? 'true' : 'false' ?>"><?= e(implode("\n", $entry->getTo())) ?></textarea>
                <?= raw($fieldError('to')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>CC <small>(optional, eine Adresse pro Zeile)</small></label>
                <textarea name="cc" rows="3"
                          aria-invalid="<?= $validator->hasFieldError('cc') ? 'true' : 'false' ?>"><?= e(implode("\n", $entry->getCc())) ?></textarea>
                <?= raw($fieldError('cc')) ?>
            </div>
        </div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label>Betreff</label>
            <input type="text" name="subject" value="<?= e($entry->getSubject()) ?>" autocomplete="off"
                   aria-invalid="<?= $validator->hasFieldError('subject') ? 'true' : 'false' ?>">
            <?= raw($fieldError('subject')) ?>
        </div>
        <div class="be-form__section">
            Routen <small>(optional: die im Formular gewählte Option bestimmt Empfänger + Betreff; ohne Treffer gelten die Standardwerte)</small>
        </div>
        <div class="be-form__field" data-z77-field-wrapper>
            <?php foreach ($routeRows as $i => $row): ?>
            <div class="be-form__grid" style="grid-template-columns:1fr 2fr 1fr;margin-bottom:.4rem">
                <input type="text" name="route_key[<?= $i ?>]" value="<?= e($row['key']) ?>"
                       placeholder="Auswahl-Wert" autocomplete="off">
                <input type="text" name="route_to[<?= $i ?>]" value="<?= e($row['to']) ?>"
                       placeholder="empfaenger@domain.ch, weitere@domain.ch" autocomplete="off">
                <input type="text" name="route_subject[<?= $i ?>]" value="<?= e($row['subject']) ?>"
                       placeholder="Betreff (optional)" autocomplete="off">
            </div>
            <?php endforeach; ?>
            <?= raw($fieldError('routes')) ?>
            <small style="color:var(--be-muted,#94a3b8)">Leere Zeilen werden ignoriert; für weitere Routen speichern und erneut öffnen.</small>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
