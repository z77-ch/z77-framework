<?php
/**
 * The decisive action(s) of the current context, at the head of the rail
 * (ADR-033: the action cell decides, the toolbar operates).
 *
 * It sits ABOVE the list because that is what it acts on: «Neues Snippet»
 * lengthens the list, «Bestand aktualisieren» refills it. In the profile the
 * area has no action of its own, so the SECTION supplies it. A FORM's save
 * lives here too — with «Abbrechen» beside it: a submit at the end of a long
 * body has to be searched for, and with tabs its distance from the eye even
 * depends on which tab is open. Max two actions; the cell is a decision, not
 * a menu.
 *
 * ⚠️ The weight follows the MEANING, not the position: an action that ENDS
 * something renders quiet, never as the accent button. Same rule as the login
 * confirmation page (B8, security review 2026-08-07). Without it «Alle
 * abmelden» looks exactly like «Jetzt einrichten», and whoever remembers the
 * position instead of reading the label eventually hits the wrong one.
 *
 * Kinds, decided by the keys an action carries:
 * - `submit` names a `<form id>` elsewhere on the page — the button submits
 *   it from OUTSIDE via the HTML `form` attribute, no script involved; the
 *   form's hidden tab panels travel with it.
 * - `dialog` names a `<dialog>` this action OPENS. The dialog then carries
 *   its own «Speichern», and it has to: a modal makes the rest of the
 *   document inert, so a submit parked in this cell would be dead.
 * - `method: post` brings its own form and CSRF field.
 * - otherwise `href` renders as a link.
 *
 * @var array<int,array{label:string, href?:string, method?:string, quiet?:bool,
 *      confirm?:string, dialog?:string, submit?:string}> $actions
 * @var string $csrfToken
 */
?>
<?php foreach ($actions as $action): ?>
<?php $class = 'me-btn' . (!empty($action['quiet']) ? ' me-btn--quiet' : ''); ?>
<?php if (!empty($action['submit'])): ?>
<button type="submit" form="<?= e($action['submit']) ?>" class="<?= e($class) ?>"><?= e($action['label']) ?></button>
<?php elseif (!empty($action['dialog'])): ?>
<button type="button" class="<?= e($class) ?>" data-dialog-open="<?= e($action['dialog']) ?>"><?= e($action['label']) ?></button>
<?php elseif (($action['method'] ?? 'get') === 'post'): ?>
<form method="post" action="<?= e($action['href'] ?? '') ?>" class="me-shell__act-form"
      <?= !empty($action['confirm']) ? 'data-confirm="' . e($action['confirm']) . '"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <button type="submit" class="<?= e($class) ?>"><?= e($action['label']) ?></button>
</form>
<?php else: ?>
<a class="<?= e($class) ?>" href="<?= e($action['href'] ?? '#') ?>"><?= e($action['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
