<?php
/**
 * Journal — the toolbar (hc2) of the capture area (FIN-JOURNAL-CAPTURE-001,
 * owner 2026-09-28): «Einzel | Sammel», «MwSt», «Buchen». All three act on the
 * capture form in the work area, so they stand here, left-aligned in that
 * order (ADR-033 rev. 2026-09-28) — and on a phone they stay visible while the
 * drawer is closed.
 *
 *   - Einzel | Sammel — two links (the `.be-lang-switch` anatomy): the mode is
 *     a page state (`?mode=`), the date travels along;
 *   - MwSt — a second `<label>` of the one-line form's `#journal-vat` checkbox
 *     (the CSS reveal, `.be-reveal`); not in the Sammelbuchung, whose rows
 *     carry their own code;
 *   - Buchen — a submit OUTSIDE the form (`form="journal-capture"`, no
 *     script). It is the first submit button of the form in document order,
 *     so Enter in any field posts — in the Sammelbuchung too, where it sends
 *     `op=save` ahead of «Weitere Zeilen».
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var string $mode        'einzel' | 'sammel'
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm|\Z77\Module\Financial\Ui\ManualEntryForm $form
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$modeLink   = static fn(string $m): string => $actionBase . '/list?' . http_build_query(array_filter([
    'mode' => $m === 'sammel' ? 'sammel' : '',
    'date' => $form->date(),
]));
?>
<div class="be-lang-switch" role="group" aria-label="Erfassung">
    <div class="be-lang-switch__options">
        <?php foreach (['einzel' => 'Einzel', 'sammel' => 'Sammel'] as $key => $label): ?>
        <a class="be-lang-switch__option<?= $mode === $key ? ' be-lang-switch__option--active' : '' ?>"
           href="<?= e($modeLink($key)) ?>"<?= $mode === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php if ($mode === 'einzel'): ?>
<label class="be-btn be-btn--ghost be-btn--sm be-reveal__trigger" for="journal-vat">MwSt</label>
<?php endif; ?>
<button type="submit" form="journal-capture" class="be-btn be-btn--primary be-btn--sm" name="op" value="save">
    <span class="be-btn__label">Buchen</span>
</button>
