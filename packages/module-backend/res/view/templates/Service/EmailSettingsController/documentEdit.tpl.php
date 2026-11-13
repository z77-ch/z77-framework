<?php
/**
 * Document-mail editor («Beleg-Mails», step 3 of the mail plan, owner 2026-10-22): subject, fact
 * line (owner 2026-11-13, board «E-Mail-Standard» — the bold line on top, optional) and text per
 * declared language (German first and required — the others fall back to it when left empty),
 * the kind's placeholders listed for reference. Plain text: the mail escapes it, a blank line
 * starts a new paragraph. The closing («maschinell erstellt · Fragen beantwortet gerne:» + the
 * «Senden als» identity) and the company foot are set by the templates, not edited here; the
 * document goes along as a PDF attachment.
 *
 * @var \Z77\Shared\Entities\EmailDocumentSetting $entry
 * @var string $label
 * @var list<string> $languages
 * @var array<string, string> $placeholders  name => description
 * @var string $entityCsrf
 * @var bool $isOverride
 * @var bool $isDormant
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 */
$names      = ['de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch', 'it' => 'Italienisch'];
$fieldError = fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$subjects   = $entry->getSubjects();
$bodies     = $entry->getBodies();
$facts      = $entry->getFacts();
$first      = $languages[0] ?? 'de';
$fieldIds   = ['subjects' => 'ed-subject-' . $first, 'facts' => 'ed-facts-' . $first, 'bodies' => 'ed-body-' . $first];
$invalid    = array_values(array_filter(array_keys($fieldIds), [$validator, 'hasFieldError']));
$errors     = ['count' => count($validator->getFieldErrors()), 'target' => $fieldIds[$invalid[0] ?? ''] ?? ''];
?>
<form data-fetch-post>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <input type="hidden" name="kind" value="<?= e($entry->getKind()) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Beleg-Mail «<?= e($label) ?>»</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Speichern', 'errors' => $errors], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">Bitte überprüfe die markierten Eingaben.</div>
        <?php endif; ?>
        <?php if (!$isOverride): ?>
        <p class="be-form__hint">Aktuell gilt die Vorgabe des Moduls. Speichern legt einen eigenen Text an (aktiv).</p>
        <?php elseif ($isDormant): ?>
        <div class="be-modal__alert">Dieser eigene Text ist derzeit <strong>inaktiv</strong> — es gilt die Vorgabe des Moduls.
            Änderungen werden gespeichert, aber erst wirksam, wenn du sie über den Schalter in der Liste aktivierst.</div>
        <?php endif; ?>
        <?php foreach ($languages as $language): ?>
        <div class="be-form__section"><?= e($names[$language] ?? $language) ?> <small><?= $language === 'de' ? '(erforderlich — andere Sprachen fallen darauf zurück)' : '(leer = Deutsch)' ?></small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="ed-subject-<?= e($language) ?>">Betreff</label>
            <input type="text" id="ed-subject-<?= e($language) ?>" name="subjects[<?= e($language) ?>]" value="<?= e((string) ($subjects[$language] ?? '')) ?>" autocomplete="off"
                   maxlength="<?= \Z77\Shared\Validators\EmailDocumentSettingValidator::SUBJECT_LENGTH ?>"<?= $language === 'de' ? ' required' : '' ?>
                   aria-invalid="<?= $language === $first && $validator->hasFieldError('subjects') ? 'true' : 'false' ?>">
            <?= $language === $first ? raw($fieldError('subjects')) : '' ?>
        </div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="ed-facts-<?= e($language) ?>">Faktenzeile <small>(fett zuoberst im Mail; ein Abschnitt zwischen « · » mit leeren Platzhaltern fällt weg)</small></label>
            <input type="text" id="ed-facts-<?= e($language) ?>" name="facts[<?= e($language) ?>]" value="<?= e((string) ($facts[$language] ?? '')) ?>" autocomplete="off"
                   maxlength="<?= \Z77\Shared\Validators\EmailDocumentSettingValidator::FACTS_LENGTH ?>"
                   aria-invalid="<?= $language === $first && $validator->hasFieldError('facts') ? 'true' : 'false' ?>">
            <?= $language === $first ? raw($fieldError('facts')) : '' ?>
        </div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="ed-body-<?= e($language) ?>">Text</label>
            <textarea id="ed-body-<?= e($language) ?>" name="bodies[<?= e($language) ?>]" rows="7"<?= $language === 'de' ? ' required' : '' ?>
                      aria-invalid="<?= $language === $first && $validator->hasFieldError('bodies') ? 'true' : 'false' ?>"><?= e((string) ($bodies[$language] ?? '')) ?></textarea>
            <?= $language === $first ? raw($fieldError('bodies')) : '' ?>
        </div>
        <?php endforeach; ?>
        <?php if ($placeholders !== []): ?>
        <div class="be-form__section">Platzhalter</div>
        <p class="be-form__hint"><?php foreach ($placeholders as $name => $description): ?><code>{<?= e($name) ?>}</code> <?= e($description) ?> &nbsp; <?php endforeach; ?></p>
        <?php endif; ?>
        <p class="be-form__hint">Der Text beginnt mit <code>{briefanrede}</code> und bleibt neutral (kein «Sie», kein «du» — die Briefanrede der Adresse bestimmt den Ton); Gruss und Unterschrift gehören nicht hinein: Den Abschluss «Diese Nachricht wurde maschinell erstellt. Fragen beantwortet gerne:» mit Name, E-Mail und Telefon des Absenders («Senden als») und die Firmenadresse setzt das Mail selbst. Absender ist das unter Service › E-Mail eingerichtete Konto; der Beleg geht als PDF mit.</p>
    </div>
</form>
