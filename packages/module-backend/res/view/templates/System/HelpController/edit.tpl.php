<?php
/**
 * «Hilfe ergänzen» (owner 2026-11-15): the installation's own text to one help, opened from the
 * pencil in the help window's head ({@see \Z77\Module\Backend\Ui\Controllers\System\HelpController}).
 * One textarea, plain text — a blank line starts a new paragraph; no HTML (the output escapes it).
 * Empty + «Speichern» removes the addition.
 *
 * Fetched, a WINDOW (ADR-047): mask `help-addition`, one per help (`data-window-entity`), the
 * title in the title bar. The action row (ADR-049) is the shared `partials/modalActions`, first
 * in the form: Speichern · Schliessen. A plain form, no script of its own (Rule 7):
 * `csrf_token` for the page POST (`#[Csrf]`), a fetch POST carries `X-CSRF-Token` (AccessGuard).
 *
 * @var string $topic     the help's topic id
 * @var string $title     the help's title (its static heading)
 * @var string $text      the addition as it stands (or as posted, after a refusal)
 * @var string $error     '' or why the save was refused
 * @var int    $maxChars
 * @var string $action    the form's address (edit URL + topic)
 * @var string $entity    `help-addition:<topic>`
 * @var bool   $window
 * @var string $origin
 * @var string $csrfToken
 */
$window  = !empty($window);
$heading = 'Hilfe ergänzen: ' . $title;
$invalid = $error !== '';
$winAttr = $window
    ? ' data-window="help-addition" data-window-entity="' . e($entity) . '" data-window-title="' . e($heading) . '" data-window-width="44rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="help-addition-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit' => 'Speichern',
            'errors' => ['count' => $invalid ? 1 : 0, 'target' => $invalid ? 'help-addition-text' : ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($heading) ?></h2>
        </div>
        <?php endif; ?>

        <?php /* Owner 2026-11-15 (ADR-055 addendum): the addition goes to the AI service — said up front, not only in the hint. */ ?>
        <div class="be-modal__alert" role="note" data-help-addition-notice>
            <strong>Achtung, KI-Dienst:</strong> Diese Ergänzung wird Teil des Wissens des KI-Assistenten und mit jeder passenden Frage an den KI-Dienst gesendet. Keine Kunden-, Personen- oder Zugangsdaten eintragen.
        </div>

        <div class="be-form__grid be-form__grid--single">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="help-addition-text">Ergänzung</label>
                <textarea id="help-addition-text" name="addition" rows="14" maxlength="<?= (int) $maxChars ?>" aria-invalid="<?= $invalid ? 'true' : 'false' ?>" autofocus><?= e($text) ?></textarea>
                <?php if ($invalid): ?><small class="be-form__field-error" data-z77-field-error><?= e($error) ?></small><?php endif; ?>
                <small class="be-form__hint">Steht in der Hilfe unter «Ergänzung» und gehört zum Wissen des KI-Assistenten. Nur Text, eine Leerzeile beginnt einen neuen Absatz. Leer speichern entfernt die Ergänzung.</small>
            </div>
        </div>
    </form>
</div>
