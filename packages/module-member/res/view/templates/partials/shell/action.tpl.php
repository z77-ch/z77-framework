<?php
/**
 * The one action of the current context, at the head of the rail.
 *
 * It sits ABOVE the list because that is what it acts on: «Neues Snippet»
 * lengthens the list, «Bestand aktualisieren» refills it. In the profile the
 * area has no action of its own, so the SECTION supplies it («Bearbeiten»,
 * «Jetzt einrichten», «Alle abmelden») — the cell carries the action of the
 * context, whatever the context is.
 *
 * ⚠️ The weight follows the MEANING, not the position: an action that ENDS
 * something renders quiet, never as the accent button. Same rule as the login
 * confirmation page (B8, security review 2026-08-07). Without it «Alle
 * abmelden» looks exactly like «Jetzt einrichten», and whoever remembers the
 * position instead of reading the label eventually hits the wrong one.
 *
 * A POST action brings its own form and CSRF field; a plain target is a link.
 *
 * `dialog` names a `<dialog>` on the page (its id) that this action OPENS —
 * editing the account works that way. The dialog then carries its own
 * «Speichern», and it has to: a modal dialog makes the rest of the document
 * inert, so a submit button parked in this cell would no longer be clickable.
 *
 * @var array{label:string, href?:string, method?:string, quiet?:bool, confirm?:string, dialog?:string} $action
 * @var string $csrfToken
 */
$class = 'me-btn' . (!empty($action['quiet']) ? ' me-btn--quiet' : '');
?>
<?php if (!empty($action['dialog'])): ?>
<button type="button" class="<?= e($class) ?>" data-dialog-open="<?= e($action['dialog']) ?>"><?= e($action['label']) ?></button>
<?php elseif (($action['method'] ?? 'get') === 'post'): ?>
<form method="post" action="<?= e($action['href'] ?? '') ?>" style="width:100%"
      <?= !empty($action['confirm']) ? 'data-confirm="' . e($action['confirm']) . '"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <button type="submit" class="<?= e($class) ?>"><?= e($action['label']) ?></button>
</form>
<?php else: ?>
<a class="<?= e($class) ?>" href="<?= e($action['href'] ?? '#') ?>"><?= e($action['label']) ?></a>
<?php endif; ?>
